<?php
/**
 * `GET /bulkcat/v1/export` (SPEC §10, §12.1, §13): a CSV of the current view.
 * It takes the `GET /products` arguments plus `columns[]`, the visible
 * columns in order, and streams every row matching the filter, not only
 * the current page, reading 100 rows at a time so memory stays flat. It is
 * a read behind the read routes' capability, cookie and nonce, generated on
 * request and stored nowhere.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\ExportName;
use BulkCatalogueManager\Export\ExportRequest;
use BulkCatalogueManager\Export\ExportRows;
use BulkCatalogueManager\Export\GuardedValues;
use BulkCatalogueManager\Export\HistoryColumns;
use BulkCatalogueManager\Export\IdRows;
use BulkCatalogueManager\Export\ReimportIdentifiers;
use BulkCatalogueManager\Export\RowSource;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\CountCache;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The export route.
 *
 * The handler validates the request and answers with a response whose data
 * is the CsvExport; `rest_pre_serve_request` then sends the CSV headers and
 * streams the body in place of JSON. A refusal before that point is an
 * ordinary JSON error.
 *
 * Rows come from a RowSource. The default one reads pages of `GET /products`
 * through ProductsController, so the export carries exactly the rows and
 * values the grid shows for the same filter.
 *
 * History exports (T58, SPEC §4.6, §10) take `source`: `changes` with
 * `batch` (a batch's changes, optionally narrowed by `result`), `audit`
 * with the audit log's filters (`product`, `field`, `user`, `after`,
 * `before`), or `batches` with the history list's (`status`, `user`,
 * `after`, `before`, `action`). Their rows are read page by page through the
 * same controller methods the history routes answer with
 * (BatchChangesController::rows(), AuditController::rows(),
 * BatchesController::rows()), counted once, and written through the same
 * CsvExport and CsvWriter; `columns[]` then names that source's columns
 * (HistoryColumns::KEYS), each written as its label. The `status` argument
 * is the catalogue's status filter for products and the batch outcome for
 * the history list; it is validated as whichever the `source` asks for.
 * `source` defaults to `products`, so a catalogue export is unchanged.
 *
 * `POST /export` (ticket 10, SPEC §10) is the catalogue export the export
 * dialog uses: the same route, behind the same read permission, with
 * RouteSchema::export_args(). Export\ExportRequest checks the request, then
 * the rows are a selection token's or an ID list's present members
 * (Export\ExportRows, Export\IdRows) or the filter's rows through the same
 * `GET /products` reader; every refusal is JSON, sent before any CSV byte.
 * With `summary` the route answers `{ rows, left_out, guarded_identifiers,
 * guarded_names, whole_catalogue, filename }` and streams nothing. The
 * columns and headings come from Export\ColumnPlan, and with the identifier
 * switch on the rows pass through Export\ReimportIdentifiers. The export
 * writes nothing but the grid's own count cache.
 */
final class ExportController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/export';

	/**
	 * The item schema's title: one exported row.
	 */
	public const TITLE = 'bulkcat-export-row';

	/**
	 * The parameter naming the columns, in order.
	 */
	public const COLUMNS = 'columns';

	/**
	 * The parameter naming what is exported.
	 */
	public const SOURCE = 'source';

	/**
	 * The catalogue, the default source.
	 */
	public const PRODUCTS = 'products';

	/**
	 * Every source: the catalogue and the history sources.
	 */
	public const SOURCES = [ self::PRODUCTS, ...HistoryColumns::SOURCES ];

	/**
	 * History filter arguments that are not `/products` arguments.
	 */
	public const HISTORY_ARGS = [ 'batch', 'result', 'product', 'field', 'user', 'after', 'before', 'action' ];

	/**
	 * Request parameters a catalogue export does not pass to its row
	 * source: its own columns and source, the paging it does itself and the
	 * history filters.
	 */
	private const OWN_PARAMS = [ self::COLUMNS, self::SOURCE, 'page', 'per_page', ...self::HISTORY_ARGS ];

	/**
	 * The history list, built on first use.
	 *
	 * @var BatchesController|null
	 */
	private ?BatchesController $history = null;

	/**
	 * The audit log, built on first use.
	 *
	 * @var AuditController|null
	 */
	private ?AuditController $audit = null;

	/**
	 * A batch's changes, built on first use.
	 *
	 * @var BatchChangesController|null
	 */
	private ?BatchChangesController $changes = null;

	/**
	 * Arguments and schema generator.
	 *
	 * @var RouteSchema
	 */
	private RouteSchema $schema;

	/**
	 * Where rows come from.
	 *
	 * @var RowSource
	 */
	private RowSource $source;

	/**
	 * Resolves a selection token for a POST export, ( token, owner ) =>
	 * Selection|\WP_Error; the stored snapshot when null.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $selection;

	/**
	 * Build over a registry and, for tests or other reads, a row source.
	 *
	 * @param Registry                    $registry Field registry.
	 * @param RowSource|null              $source   Catalogue rows; pages of `GET /products` when null.
	 * @param BatchesController|null      $history  The history list's rows; the plugin's when null.
	 * @param AuditController|null        $audit    The audit log's rows; the plugin's when null.
	 * @param BatchChangesController|null $changes  A batch's changes; the plugin's when null.
	 * @param \Closure|null               $selection A POST export's selection resolver, ( token, owner ) => Selection|\WP_Error; the stored snapshot when null.
	 */
	public function __construct( private readonly Registry $registry, ?RowSource $source = null, ?BatchesController $history = null, ?AuditController $audit = null, ?BatchChangesController $changes = null, ?\Closure $selection = null ) {
		$this->schema    = new RouteSchema( $registry );
		$this->source    = $source ?? new CallableRowSource( self::products_reader( new ProductsController( $registry ) ) );
		$this->history   = $history;
		$this->audit     = $audit;
		$this->changes   = $changes;
		$this->selection = $selection;
	}

	/**
	 * Add the route through the registrar, and the hook that streams its body.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => $this->args(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => $this->schema->export_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
		add_filter( 'rest_pre_serve_request', [ $this, 'serve' ], 10, 4 );
	}

	/**
	 * The route's arguments: every `GET /products` argument (paging is
	 * accepted and ignored) plus the required `columns[]`, registry keys
	 * in the order the columns appear.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		$columns                      = $this->schema->fields_arg()['fields'];
		$columns['description']       = __( 'Columns to export, in order: the visible columns of the view.', 'bulk-catalogue-manager-for-woocommerce' );
		$columns['required']          = true;
		$columns['minItems']          = 1;
		$columns['items']['enum']     = array_values( array_unique( [ ...$this->registry->keys(), ...HistoryColumns::all_keys() ] ) );
		$columns['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $columns ): bool|\WP_Error {
			unset( $request );
			$schema = array_diff_key( $columns, array_flip( [ 'validate_callback', 'sanitize_callback' ] ) );
			return rest_validate_value_from_schema( RouteSchema::as_list( $value ), $schema, $param );
		};

		$args = $this->schema->query_args();
		$list = $this->history()->list_args();
		// `status` is the catalogue's filter, or for the history list the batch outcome.
		$args['status'] = self::by_source( $args['status'], $list['status'] );
		$audit          = $this->audit()->args();
		$changes        = $this->changes()->args();
		return $args + [
			self::COLUMNS => $columns,
			self::SOURCE  => [
				'description'       => __( 'What to export: the catalogue (products, the default), a change\'s rows (changes, with batch), the audit log (audit) or the change history (batches).', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'string',
				'enum'              => self::SOURCES,
				'default'           => self::PRODUCTS,
				'validate_callback' => static fn( mixed $value, mixed $request = null, string $param = '' ): bool|\WP_Error => rest_validate_value_from_schema(
					$value,
					[
						'type' => 'string',
						'enum' => self::SOURCES,
					],
					$param
				),
				'sanitize_callback' => static fn( mixed $value ): string => (string) $value,
			],
			'batch'       => [
				'description' => __( 'For source changes: the change whose rows to export, by its number.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required'    => false,
			] + $changes['id'],
			'result'      => $changes['result'],
			'product'     => $audit['product'],
			'field'       => $audit['field'],
			'user'        => $audit['user'],
			'after'       => $audit['after'],
			'before'      => $audit['before'],
			'action'      => $list['action'],
		];
	}

	/**
	 * An argument validated and sanitised as $products for a catalogue
	 * export and as $history for any other source.
	 *
	 * @param array<string, mixed> $products The catalogue's argument.
	 * @param array<string, mixed> $history  The history's argument.
	 * @return array<string, mixed>
	 */
	private static function by_source( array $products, array $history ): array {
		$pick               = static fn( mixed $request ): array => $request instanceof \WP_REST_Request && is_string( $request->get_param( self::SOURCE ) ) && self::PRODUCTS !== $request->get_param( self::SOURCE ) ? $history : $products;
		$arg                = $products;
		$arg['description'] = __( 'For the catalogue, the status filter; for the change history, only changes with this outcome.', 'bulk-catalogue-manager-for-woocommerce' );
		unset( $arg['type'], $arg['properties'], $arg['additionalProperties'], $arg['enum'] );
		$arg['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $pick ): mixed {
			$chosen = $pick( $request );
			return isset( $chosen['validate_callback'] ) ? call_user_func( $chosen['validate_callback'], $value, $request, $param ) : true;
		};
		$arg['sanitize_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $pick ): mixed {
			$chosen = $pick( $request );
			return isset( $chosen['sanitize_callback'] ) ? call_user_func( $chosen['sanitize_callback'], $value, $request, $param ) : $value;
		};
		return $arg;
	}

	/**
	 * Validate the export and answer with it; serve() streams it.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params();
		$source = (string) ( $params[ self::SOURCE ] ?? self::PRODUCTS );
		if ( in_array( $source, HistoryColumns::SOURCES, true ) ) {
			return $this->history_export( $source, $params );
		}
		$columns = RouteSchema::as_list( $params[ self::COLUMNS ] ?? [] );
		$refusal = Refusal::invalid(
			'bulkcat_invalid_value',
			__( 'These columns could not be exported. Choose the columns again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			[ 'param' => self::COLUMNS ]
		);
		if ( [] === $columns ) {
			return $refusal;
		}
		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) || ! $this->registry->has( $column ) ) {
				return $refusal;
			}
		}
		if ( count( array_unique( $columns ) ) !== count( $columns ) ) {
			return $refusal;
		}

		try {
			Criteria::from_array( $this->schema->criteria_input( $params ) );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'These filters or this sort could not be used. Clear them and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}

		$read = array_diff_key( $params, array_flip( self::OWN_PARAMS ) );
		// Each row carries just the exported columns.
		$read['fields'] = $columns;

		return new \WP_REST_Response(
			new CsvExport(
				$this->source,
				$this->registry,
				$columns,
				$read,
				Settings::load()->csv_separator(),
				CsvExport::filename( current_datetime() )
			),
			200
		);
	}

	/**
	 * `POST /export`: a catalogue export, or with `summary` its counts. The
	 * request is checked and the rows resolved before any byte, so every
	 * refusal is JSON; a streamed answer is a CsvExport, which serve() sends.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$export = ExportRequest::from( $request, $this->registry );
		if ( $export instanceof \WP_Error ) {
			return $export;
		}
		$plan = $export->plan( $this->registry );
		try {
			$rows = match ( $export->rows ) {
				ExportRequest::FROM_SELECTION => ExportRows::token( $export->token, get_current_user_id(), $this->registry, $this->selection ),
				ExportRequest::FROM_IDS       => ExportRows::ids( $export->ids, $this->registry ),
				default                       => null,
			};
			if ( $rows instanceof \WP_Error ) {
				return $rows;
			}
			$filename = ExportName::filename( $export->name(), $plan->identifiers(), current_datetime() );
			if ( $export->summary ) {
				return new \WP_REST_Response( $this->summary( $export, $rows, $plan->identifiers(), $filename ), 200 );
			}
		} catch ( \RuntimeException $error ) {
			return Refusal::unexpected(
				'bulkcat_export_unavailable',
				__( 'The export could not be prepared. Export again; if this continues, ask an administrator to check the site\'s error log.', 'bulk-catalogue-manager-for-woocommerce' ),
				503,
				$error
			);
		}

		$source = $rows ?? $this->source;
		$read   = ( null === $rows ? $export->filter : [] ) + [ 'fields' => $plan->fields() ];
		return new \WP_REST_Response(
			new CsvExport(
				$plan->identifiers() ? new ReimportIdentifiers( $source ) : $source,
				$this->registry,
				$plan->columns(),
				$read,
				Settings::load()->csv_separator(),
				$filename,
				$plan->labels()
			),
			200
		);
	}

	/**
	 * The export dialog's counts: the rows the file will hold, the members
	 * left out, and with the identifier switch on the guarded SKUs and names.
	 *
	 * @param ExportRequest $export      The checked request.
	 * @param IdRows|null   $rows        The explicit rows; null for the filter.
	 * @param bool          $identifiers Whether the identifier switch is on.
	 * @param string        $filename    The file's name.
	 * @return array{rows: int, left_out: int, guarded_identifiers: int, guarded_names: int, whole_catalogue: bool, filename: string}
	 * @throws \RuntimeException On a database error.
	 */
	private function summary( ExportRequest $export, ?IdRows $rows, bool $identifiers, string $filename ): array {
		$query   = null === $rows && null !== $export->criteria ? new ProductQuery( $export->criteria ) : null;
		$guarded = [
			'identifiers' => 0,
			'names'       => 0,
		];
		if ( $identifiers ) {
			$guarded = null === $rows ? ( new GuardedValues() )->of_query( $query ) : ( new GuardedValues() )->of_ids( $rows->ids() );
		}
		return [
			'rows'                => null === $rows ? ( new CountCache() )->total( $query ) : $rows->rows(),
			'left_out'            => null === $rows ? 0 : $rows->left_out(),
			'guarded_identifiers' => $guarded['identifiers'],
			'guarded_names'       => $guarded['names'],
			'whole_catalogue'     => $export->whole_catalogue(),
			'filename'            => $filename,
		];
	}

	/**
	 * A history export: the source's columns checked, a batch's existence
	 * checked, then an export whose rows are read page by page through the
	 * history route's own row method, the total counted on the first page only.
	 *
	 * @param string               $source One of HistoryColumns::SOURCES.
	 * @param array<string, mixed> $params The validated, sanitised parameters.
	 */
	private function history_export( string $source, array $params ): \WP_REST_Response|\WP_Error {
		$labels  = HistoryColumns::labels( $source );
		$columns = RouteSchema::as_list( $params[ self::COLUMNS ] ?? [] );
		if ( [] === $columns || count( array_unique( $columns ) ) !== count( $columns ) || [] !== array_diff( $columns, array_keys( $labels ) ) ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'These columns could not be exported. Choose the columns again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'param' => self::COLUMNS ]
			);
		}

		$batch_id = (int) ( $params['batch'] ?? 0 );
		switch ( $source ) {
			case HistoryColumns::CHANGES:
				if ( $batch_id < 1 ) {
					return Refusal::invalid(
						'bulkcat_invalid_value',
						__( 'This export does not say which change to export. Open the change in the history and export it again.', 'bulk-catalogue-manager-for-woocommerce' ),
						[ 'param' => 'batch' ]
					);
				}
				if ( ! $this->changes()->exists( $batch_id ) ) {
					return BatchesController::not_found();
				}
				$filters = [ 'result' => BatchChangesController::results( $params['result'] ?? null ) ];
				$changes = $this->changes();
				$read    = static fn( int $page, int $size ): array => $changes->rows( $batch_id, $filters['result'], $page, $size, 1 === $page );
				break;
			case HistoryColumns::AUDIT:
				$filters = AuditController::filters( $params );
				$audit   = $this->audit();
				$read    = static fn( int $page, int $size ): array => $audit->rows( $filters, $page, $size, 1 === $page );
				break;
			default:
				$filters = BatchesController::filters( $params );
				$history = $this->history();
				$read    = static fn( int $page, int $size ): array => $history->rows( $filters, $page, $size, 1 === $page );
		}

		$rows = new CallableRowSource(
			static function ( array $unused, int $page, int $size ) use ( $read, $source ): array {
				unset( $unused );
				$result = $read( $page, $size );
				return [
					'items' => array_map( static fn( array $row ): array => HistoryColumns::cells( $source, $row ), $result['items'] ),
					'total' => $result['total'],
				];
			}
		);
		return new \WP_REST_Response(
			new CsvExport(
				$rows,
				$this->registry,
				$columns,
				$filters,
				Settings::load()->csv_separator(),
				HistoryColumns::filename( $source, current_datetime(), $batch_id ),
				$labels
			),
			200
		);
	}

	/**
	 * The history list's controller.
	 */
	private function history(): BatchesController {
		$this->history ??= new BatchesController( null, null, null, $this->registry );
		return $this->history;
	}

	/**
	 * The audit log's controller.
	 */
	private function audit(): AuditController {
		$this->audit ??= new AuditController( null, $this->registry );
		return $this->audit;
	}

	/**
	 * The changes feed's controller.
	 */
	private function changes(): BatchChangesController {
		$this->changes ??= new BatchChangesController( new \BulkCatalogueManager\Data\BatchRepository(), new \BulkCatalogueManager\Data\ChangeRepository(), $this->registry );
		return $this->changes;
	}

	/**
	 * `rest_pre_serve_request`: stream an export's CSV in place of JSON.
	 * Anything else passes through untouched.
	 *
	 * @param mixed $served  Whether the request has been served.
	 * @param mixed $result  The response.
	 * @param mixed $request The request; unused.
	 * @param mixed $server  The REST server, which sends the headers.
	 * @param mixed $stream  Stream to write to; the response body when null.
	 * @return mixed True once the export is streamed, else $served.
	 */
	public function serve( mixed $served, mixed $result = null, mixed $request = null, mixed $server = null, mixed $stream = null ): mixed {
		unset( $request );
		if ( $served || ! $result instanceof \WP_REST_Response || ! $result->get_data() instanceof CsvExport ) {
			return $served;
		}
		$export = $result->get_data();
		if ( is_object( $server ) && method_exists( $server, 'send_header' ) ) {
			foreach ( $export->headers() as $name => $value ) {
				$server->send_header( $name, $value );
			}
		}
		if ( ! is_resource( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the response body; WP_Filesystem cannot write to php://output.
			$stream = fopen( 'php://output', 'wb' );
		}
		if ( function_exists( 'wc_set_time_limit' ) ) {
			// A large export can outlast a host's 30-second limit (ticket 10, amendment 7); lifted where the host allows.
			wc_set_time_limit( 0 );
		}
		$export->stream( $stream );
		return true;
	}

	/**
	 * The schema of one exported row: the catalogue row's, since each CSV
	 * line holds a product row's values for the requested columns.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return $this->schema->item_schema( self::TITLE );
	}

	/**
	 * A page reader over `GET /products`: its handler, given the export's
	 * parameters with paging added, reduced to its rows and total.
	 *
	 * @param ProductsController $products The products route.
	 * @return callable(array<string, mixed>, int, int): array{items: list<array<string, mixed>>, total: int}
	 */
	private static function products_reader( ProductsController $products ): callable {
		return static function ( array $params, int $page, int $per_page ) use ( $products ): array {
			$request = new \WP_REST_Request( 'GET', '/' . Access::REST_NAMESPACE . ProductsController::ROUTE );
			foreach ( [
				'page'     => $page,
				'per_page' => $per_page,
			] + $params as $key => $value ) {
				$request->set_param( (string) $key, $value );
			}
			$response = $products->get_items( $request );
			if ( ! $response instanceof \WP_REST_Response ) {
				throw new \UnexpectedValueException( 'The products read refused an export page.' );
			}
			$data = (array) $response->get_data();
			return [
				'items' => array_values( (array) ( $data['items'] ?? [] ) ),
				'total' => (int) ( $data['pagination']['total'] ?? 0 ),
			];
		};
	}
}
