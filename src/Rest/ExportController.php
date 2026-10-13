<?php
/**
 * `/bulkcat/v1/export` (SPEC §10, §12.1, §13): CSV exports, streamed 100
 * rows at a time so memory stays flat, behind the read routes' capability,
 * cookie and nonce, generated on request and stored nowhere. The screen
 * posts every export, the catalogue's and History's, with the nonce in the
 * body; `GET` still exports History's rows for a client sending the nonce
 * as a header. A `_wpnonce` in the address is refused on either method
 * (SECURITY-1: an address lands in access logs and browser history).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\ColumnPlan;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\ExportName;
use BulkCatalogueManager\Export\ExportRequest;
use BulkCatalogueManager\Export\ExportRows;
use BulkCatalogueManager\Export\FilterRows;
use BulkCatalogueManager\Export\GuardedValues;
use BulkCatalogueManager\Export\HistoryColumns;
use BulkCatalogueManager\Export\IdRows;
use BulkCatalogueManager\Export\ReimportIdentifiers;
use BulkCatalogueManager\Export\RowSource;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\CountCache;
use BulkCatalogueManager\Query\Hydrator;
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
 * Rows come from a RowSource. For a filter export the default one,
 * Export\FilterRows, walks the filter's matches by id (SQR-3) and hydrates
 * them with the grid's Hydrator, so the file carries the rows and values the
 * grid shows for the same filter, each once, in id order.
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
 * `source` defaults to `products`: since the export dialog (ticket 10,
 * ruling R9) a catalogue `GET` is refused 400 `bulkcat_invalid_value` with
 * a sentence asking for a reload, which is what a screen from before the
 * dialog sees.
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
 *
 * A History export may be posted too (SECURITY-1), as the screen does: the
 * same `source` and filters in the body, each argument validated as the
 * history's when `source` names a history source and as the catalogue's
 * otherwise (post_args()), and answered by the same history export.
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
	 * The history list, built on first use.
	 *
	 * @var BatchesController|null
	 */
	private ?BatchesController $history = null;

	/**
	 * The history export lock this request holds until its file is streamed
	 * (HeavyReadLock), or ''.
	 *
	 * @var string
	 */
	private string $held = '';

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
	 * @var RowSource|null
	 */
	private ?RowSource $source;

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
	 * @param RowSource|null              $source   Catalogue rows for a filter export; the filter's matches walked by id (FilterRows) when null.
	 * @param BatchesController|null      $history  The history list's rows; the plugin's when null.
	 * @param AuditController|null        $audit    The audit log's rows; the plugin's when null.
	 * @param BatchChangesController|null $changes  A batch's changes; the plugin's when null.
	 * @param \Closure|null               $selection A POST export's selection resolver, ( token, owner ) => Selection|\WP_Error; the stored snapshot when null.
	 */
	public function __construct( private readonly Registry $registry, ?RowSource $source = null, ?BatchesController $history = null, ?AuditController $audit = null, ?BatchChangesController $changes = null, ?\Closure $selection = null ) {
		$this->schema    = new RouteSchema( $registry );
		$this->source    = $source;
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
					'args'                => $this->post_args(),
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
	 * `POST /export`'s arguments (SECURITY-1): the catalogue export's
	 * (RouteSchema::export_args()), each one the history also takes checked
	 * as the history's when `source` names a history source, plus the
	 * history's own filters, which only a history export reads. Those take
	 * no default, so a catalogue post is checked exactly as before.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function post_args(): array {
		$catalogue = $this->schema->export_args();
		$history   = $this->args();
		$args      = [];
		foreach ( $catalogue as $key => $arg ) {
			$args[ $key ] = isset( $history[ $key ] ) ? self::per_source( $arg, $history[ $key ] ) : $arg;
		}
		// Paging and fields are not export arguments: an export pages itself.
		foreach ( array_diff_key( $history, $catalogue, array_flip( [ 'page', 'per_page', 'fields' ] ) ) as $key => $arg ) {
			unset( $arg['default'], $arg['required'] );
			$args[ $key ] = $arg;
		}
		return $args;
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
		$arg                = self::per_source( $products, $history );
		$arg['description'] = __( 'For the catalogue, the status filter; for the change history, only changes with this outcome.', 'bulk-catalogue-manager-for-woocommerce' );
		return $arg;
	}

	/**
	 * $products's argument, validated and sanitised as $history's when the
	 * request's `source` names a history source.
	 *
	 * @param array<string, mixed> $products The catalogue's argument.
	 * @param array<string, mixed> $history  The history's argument.
	 * @return array<string, mixed>
	 */
	private static function per_source( array $products, array $history ): array {
		$pick = static fn( mixed $request ): array => $request instanceof \WP_REST_Request && is_string( $request->get_param( self::SOURCE ) ) && self::PRODUCTS !== $request->get_param( self::SOURCE ) ? $history : $products;
		$arg  = $products;
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
	 * `GET /export`: a History export (a change's rows, the audit log or the
	 * change history), which serve() streams. The catalogue is exported by
	 * `POST` from the export dialog; a catalogue `GET` is refused with a
	 * sentence asking for a reload (ruling R9).
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$address = self::nonce_in_address( $request );
		if ( null !== $address ) {
			return $address;
		}
		$params = $request->get_params();
		$source = (string) ( $params[ self::SOURCE ] ?? self::PRODUCTS );
		if ( in_array( $source, HistoryColumns::SOURCES, true ) ) {
			return $this->history_export( $source, $params );
		}
		return Refusal::invalid(
			'bulkcat_invalid_value',
			__( 'The catalogue is now exported from the Export dialog. Reload the page and export again.', 'bulk-catalogue-manager-for-woocommerce' ),
			[ 'param' => self::SOURCE ]
		);
	}

	/**
	 * A refusal when the request's address carries `_wpnonce` (SECURITY-1):
	 * the screen sends the nonce in a posted body, and a nonce in an address
	 * lands in access logs and browser history. Null when it does not.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private static function nonce_in_address( \WP_REST_Request $request ): ?\WP_Error {
		if ( ! array_key_exists( '_wpnonce', $request->get_query_params() ) ) {
			return null;
		}
		return Refusal::invalid(
			'bulkcat_invalid_value',
			__( 'The security code must not be sent in the page address. Reload the page and export again.', 'bulk-catalogue-manager-for-woocommerce' ),
			[ 'param' => '_wpnonce' ]
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
		$address = self::nonce_in_address( $request );
		if ( null !== $address ) {
			return $address;
		}
		$source = $request->get_param( self::SOURCE );
		if ( is_string( $source ) && in_array( $source, HistoryColumns::SOURCES, true ) ) {
			return $this->history_export( $source, $request->get_params() );
		}
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
				return new \WP_REST_Response( $this->summary( $export, $rows, $plan, $filename ), 200 );
			}
		} catch ( \RuntimeException $error ) {
			return Refusal::unexpected(
				'bulkcat_export_unavailable',
				__( 'The export could not be prepared. Export again; if this continues, ask an administrator to check the site\'s error log.', 'bulk-catalogue-manager-for-woocommerce' ),
				503,
				$error
			);
		}

		$source = $rows ?? $this->source ?? new FilterRows( new ProductQuery( $export->criteria ), new Hydrator( $this->registry ) );
		$read   = ( null === $rows ? $export->filter : [] ) + [ 'fields' => $plan->fields() ];
		return new \WP_REST_Response(
			new CsvExport(
				$plan->identifiers() ? new ReimportIdentifiers( $source ) : $source,
				$this->registry,
				$plan->columns(),
				$read,
				Settings::load()->csv_separator(),
				$filename,
				$plan->labels(),
				$plan->identifiers()
			),
			200
		);
	}

	/**
	 * The export dialog's counts: the rows the file will hold, the members
	 * left out, and with the identifier switch on the guarded SKUs and names.
	 *
	 * @param ExportRequest $export   The checked request.
	 * @param IdRows|null   $rows     The explicit rows; null for the filter.
	 * @param ColumnPlan    $plan     The file's columns: the counts need the switch on, and names need the Name column.
	 * @param string        $filename The file's name.
	 * @return array{rows: int, left_out: int, guarded_identifiers: int, guarded_names: int, whole_catalogue: bool, filename: string}
	 * @throws \RuntimeException On a database error.
	 */
	private function summary( ExportRequest $export, ?IdRows $rows, ColumnPlan $plan, string $filename ): array {
		$query   = null === $rows && null !== $export->criteria ? new ProductQuery( $export->criteria ) : null;
		$guarded = [
			'identifiers' => 0,
			'names'       => 0,
		];
		if ( $plan->identifiers() ) {
			$counter = new GuardedValues( GuardedValues::PAGE, in_array( 'name', $plan->columns(), true ) );
			$guarded = null === $rows ? $counter->of_query( $query ) : $counter->of_ids( $rows->ids() );
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

		// A history export reads every row with no time limit, so one streams at a time per user.
		$lock = HeavyReadLock::acquire( HeavyReadLock::EXPORT );
		if ( $lock instanceof \WP_Error ) {
			return $lock;
		}
		$this->held = $lock;

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
	 * Anything else passes through untouched. A failure after streaming has
	 * begun stops the file where it is and is written to the WooCommerce log
	 * (lead review L1): it never reaches the body.
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
		try {
			$export->stream( $stream );
		} catch ( \Throwable $error ) {
			// The headers and part of the file are already sent, so no refusal can follow. Stop writing,
			// and keep the error out of the body: left to escape, it would bring WordPress's
			// critical-error page into a cut-off CSV. The WooCommerce log says what happened.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'The export stopped after it had started: %s: %s (%s:%d)', get_class( $error ), $error->getMessage(), $error->getFile(), $error->getLine() ),
					[ 'source' => Refusal::LOG_SOURCE ]
				);
			}
		} finally {
			HeavyReadLock::release( $this->held );
			$this->held = '';
		}
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
}
