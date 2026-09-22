<?php
/**
 * `GET /bcm/v1/products` (SPEC §12.1): the catalogue read. Its arguments and
 * its item schema are generated from the field registry by RouteSchema, so
 * core refuses a malformed argument with 400 `rest_invalid_param` before the
 * handler runs.
 *
 * T14 registers the route with its generated arguments and schema, and
 * answers with truthful pagination from the query builder's `COUNT(*)` and
 * an empty `items` list. T18 fills `items` with hydrated rows (T16, T17).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;

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
	 * Build over a registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( Registry $registry ) {
		$this->schema = new RouteSchema( $registry );
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
	 * Until T18, `items` is empty and the pagination counts the matching rows.
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
		$total = ( new ProductQuery( $criteria ) )->total();
		return new \WP_REST_Response(
			[
				'items'      => [],
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
		return $this->schema->item_schema( self::TITLE );
	}
}
