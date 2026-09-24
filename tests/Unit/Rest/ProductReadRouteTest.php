<?php
/**
 * `GET /bulkcat/v1/products/{id}` over the seeded catalogue: one product or
 * variation as `{ item }`, the row the list builds (Catalogue::row()),
 * narrowed by `fields[]` like `/products`; registered on the single-object
 * route behind Access::can_read beside the write; 404 `bulkcat_not_found` for an
 * id the list could not show (missing, trashed, another post type). A
 * visitor is refused by RoutesTest's sweep of every endpoint.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ProductWriteController;
use BulkCatalogueManager\Rest\RouteSchema;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ProductReadRouteTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		SeededCatalogue::seed();
	}

	/**
	 * Dispatch a GET to /bulkcat/v1/{route}.
	 *
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Query parameters.
	 */
	private static function get( string $route, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_read_registers_beside_the_write_behind_the_read_permission(): void {
		$route = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . ProductWriteController::ROUTE ] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ 'PATCH', 'GET' ], [ $route[0]['methods'], $route[1]['methods'] ] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[1]['permission_callback'] );
		$this->assertSame( [ 'id', 'fields' ], array_keys( $route[1]['args'] ) );
		$this->assertSame( ( new RouteSchema( Registry::instance() ) )->fields_arg()['fields']['items'], $route[1]['args']['fields']['items'], 'The same fields argument as /products.' );
	}

	public function test_a_product_and_a_variation_read_as_their_list_rows(): void {
		foreach ( [
			101 => [ 'mode' => 'products' ],
			201 => [ 'mode' => 'variations' ],
		] as $id => $list ) {
			$response = self::get( '/products/' . $id, [ 'fields' => 'name,sku,regular_price' ] );
			$this->assertInstanceOf( \WP_REST_Response::class, $response, "Object $id." );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( [ 'item' ], array_keys( $response->get_data() ) );

			$rows = array_column(
				self::get(
					'/products',
					$list + [
						'fields'   => 'name,sku,regular_price',
						'per_page' => 100,
					]
				)->get_data()['items'],
				null,
				'id'
			);
			$this->assertSame( $rows[ $id ], $response->get_data()['item'], "Object $id reads as its list row." );
		}

		$variation = self::get( '/products/201' )->get_data()['item'];
		$this->assertSame( array_merge( [ 'id', 'parent_id' ], array_diff( Registry::instance()->keys(), [ 'id' ] ) ), array_slice( array_keys( $variation ), 0, count( Registry::instance()->keys() ) + 1 ), 'Every field when fields[] is absent.' );
		$this->assertSame( 'Blue / Large', $variation['attribute_summary'] );
		$this->assertSame( 103, $variation['parent_id'] );
	}

	public function test_an_id_the_list_cannot_show_is_refused_404(): void {
		foreach ( [ 999, 108, 203, 5000 ] as $id ) {
			$refused = self::get( '/products/' . $id );
			$this->assertInstanceOf( \WP_Error::class, $refused, "Object $id." );
			$this->assertSame( 'bulkcat_not_found', $refused->get_error_code() );
			$this->assertSame( 404, $refused->get_error_data()['status'] );
		}
	}
}
