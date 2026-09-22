<?php
/**
 * `GET /bcm/v1/products` (SPEC §12.1): the catalogue read. Its arguments and
 * its item schema are generated from the field registry by RouteSchema, so
 * core refuses a malformed argument with 400 `rest_invalid_param` before the
 * handler runs.
 *
 * The handler reads one page through Query\Catalogue: ProductQuery's count
 * and page statements, hydrated by Query\Hydrator in a fixed number of
 * queries without instantiating a product, cached per last-changed window.
 * Each item carries the requested `fields[]` (every field when absent) plus
 * `parent_id` and, with the thumbnail column, `thumbnail_url`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Catalogue;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Hydrator;

defined( 'ABSPATH' ) || exit;

/**
 * The products route.
 */
final class ProductsController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/products';

	/**
	 * The item schema's title.
	 */
	public const TITLE = 'bcm-product';

	/**
	 * Arguments and schema generator.
	 *
	 * @var RouteSchema
	 */
	private RouteSchema $schema;

	/**
	 * The cached page reader.
	 *
	 * @var Catalogue
	 */
	private Catalogue $catalogue;

	/**
	 * Build over a registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( Registry $registry ) {
		$this->schema    = new RouteSchema( $registry );
		$this->catalogue = new Catalogue( new Hydrator( $registry ) );
	}

	/**
	 * Add the route through the registrar.
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
					'args'                => $this->schema->query_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * One page of the catalogue: `{ items, pagination: { page, perPage, total, totalPages } }`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$criteria = Criteria::from_array( $this->schema->criteria_input( $request->get_params() ) );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bcm_invalid_value',
				__( 'These filters or this sort could not be used. Clear them and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		$fields = $request->get_param( 'fields' );
		$page   = $this->catalogue->page( $criteria, is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : [] );
		$total  = $page['total'];
		return new \WP_REST_Response(
			[
				'items'      => $page['items'],
				'pagination' => [
					'page'       => $criteria->page,
					'perPage'    => $criteria->per_page,
					'total'      => $total,
					'totalPages' => (int) ceil( $total / $criteria->per_page ),
				],
			],
			200
		);
	}

	/**
	 * The schema of one product row, generated from the registry.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return $this->schema->item_schema(
			self::TITLE,
			[
				Hydrator::PARENT_ID     => [
					'description' => __( 'The product a variation belongs to; 0 for a product.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				Hydrator::THUMBNAIL_URL => [
					'description' => __( 'Address of the product image, or of the shop\'s placeholder image when there is none.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}
}
