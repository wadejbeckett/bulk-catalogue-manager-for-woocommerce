<?php
/**
 * `GET /bulkcat/v1/products` (SPEC §12.1): the catalogue read. Its arguments and
 * its item schema are generated from the field registry by RouteSchema, so
 * core refuses a malformed argument with 400 `rest_invalid_param` before the
 * handler runs.
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
 *
 * The handler reads one page through Query\Catalogue: ProductQuery's count
 * and page statements, hydrated by Query\Hydrator in a fixed number of
 * queries without instantiating a product, cached per last-changed window.
 * Each item carries the requested `fields[]` (every field when absent) plus
 * `parent_id`, with the thumbnail column `thumbnail_url`, and on a variation
 * row `parent_name`, `attribute_summary` and `attributes`, the summary's
 * `{ label, value }` pairs (RouteSchema::row_extras()).
 *
 * `mode` switches between products, products and variations, and variations
 * only; any other value is refused 400 by its generated enum before the
 * handler runs. A `category_ids` filter includes the chosen categories'
 * descendants unless it carries `include_children: false` ("this category
 * only", T62; Query\ProductQuery).
 *
 * `parent` (a product id) lists only that product's variations, paged,
 * sorted and narrowed by `fields[]` like any list; it needs
 * `mode=variations`, and with any other mode the read is refused 400
 * `bulkcat_invalid_value`. Without `orderby` the variations keep WooCommerce's
 * order; an id with no listed variations answers no items.
 *
 * `search` matches each listed row's own name and SKU. In the
 * products_and_variations and variations modes a variation is therefore
 * found by its own SKU (the history's product filter uses this to reach a
 * variation, then filters `GET /audit` by its id); in the products mode no
 * variation is listed, so a variation's SKU finds nothing.
 */
final class ProductsController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/products';

	/**
	 * The item schema's title.
	 */
	public const TITLE = 'bulkcat-product';

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
				'bulkcat_invalid_value',
				__( 'These filters or this sort could not be used. Clear them and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		$fields = $request->get_param( 'fields' );
		return self::response( $criteria, $this->catalogue->page( $criteria, is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : [] ) );
	}

	/**
	 * A page as every list route answers it: `{ items, pagination: { page, perPage, total, totalPages } }`.
	 * A page past the last has no items and the same truthful totals.
	 *
	 * @param Criteria                                                         $criteria The read's criteria.
	 * @param array{items: array<int, array<string, mixed>>, total: int} $page     Catalogue::page().
	 */
	public static function response( Criteria $criteria, array $page ): \WP_REST_Response {
		$total = $page['total'];
		return new \WP_REST_Response(
			[
				'items'      => \BulkCatalogueManager\Admin\ProductRowActions::decorate( $page['items'] ),
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
		return $this->schema->item_schema( self::TITLE, RouteSchema::row_extras() );
	}
}
