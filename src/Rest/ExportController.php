<?php
/**
 * `GET /bcm/v1/export` (SPEC §10, §12.1, §13): a CSV of the current view.
 * It takes the `GET /products` arguments plus `columns[]`, the visible
 * columns in order, and streams every row matching the filter, not only
 * the current page, reading 100 rows at a time so memory stays flat. It is
 * a read behind the read routes' capability, cookie and nonce, generated on
 * request and stored nowhere.
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
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\RowSource;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The export route.
 */
final class ExportController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/export';

	/**
	 * The item schema's title: one exported row.
	 */
	public const TITLE = 'bcm-export-row';

	/**
	 * The parameter naming the columns, in order.
	 */
	public const COLUMNS = 'columns';

	/**
	 * Request parameters the export does not pass to its row source: its
	 * own columns and the paging it does itself.
	 */
	private const OWN_PARAMS = [ self::COLUMNS, 'page', 'per_page' ];

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
	 * Build over a registry and, for tests or other reads, a row source.
	 *
	 * @param Registry       $registry Field registry.
	 * @param RowSource|null $source   Rows; pages of `GET /products` when null.
	 */
	public function __construct( private readonly Registry $registry, ?RowSource $source = null ) {
		$this->schema = new RouteSchema( $registry );
		$this->source = $source ?? new CallableRowSource( self::products_reader( new ProductsController( $registry ) ) );
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
		$columns                = $this->schema->fields_arg()['fields'];
		$columns['description'] = __( 'Columns to export, in order: the visible columns of the view.', 'bulk-catalogue-manager-for-woocommerce' );
		$columns['required']    = true;
		$columns['minItems']    = 1;
		return $this->schema->query_args() + [ self::COLUMNS => $columns ];
	}

	/**
	 * Validate the export and answer with it; serve() streams it.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$columns = RouteSchema::as_list( $params[ self::COLUMNS ] ?? [] );
		$refusal = Refusal::invalid(
			'bcm_invalid_value',
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
				'bcm_invalid_value',
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
