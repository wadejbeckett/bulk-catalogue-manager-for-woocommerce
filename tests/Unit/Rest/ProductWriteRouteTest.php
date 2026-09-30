<?php
/**
 * `PATCH /bulkcat/v1/products/{id}` (T37; SPEC §12.1, §9.3, §8, §13) through the
 * REST dispatcher: registered behind Access::can_write with one argument per
 * editable registry field, each the field's own rest_arg(); a price edit
 * answers 200 with the re-read row in the `/products` item shape, the batch
 * id, its final status and one outcome per step, the object's caches
 * cleared, and nothing queued; a malformed value is refused 400 by its
 * argument, naming the field with its sentence; a value the object cannot
 * take is refused 400 by the handler naming the field; a parent-only field
 * on a variation is refused with its reason; an object the user may not edit
 * answers 403, a missing id 404, a visitor 401 — each with nothing written.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ProductWriteController;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use BulkCatalogueManager\Tests\Unit\Engine\StoredProduct;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class ProductWriteRouteTest extends TestCase {
	private InlineStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products']      = true;
		$GLOBALS['bulkcat_test']['caps']['edit_post']          = true;
		$GLOBALS['bulkcat_test']['scheduled']                  = [];
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->store                              = new InlineStore();
		$GLOBALS['bulkcat_test']['products'][101] = new StoredProduct(
			'simple',
			[
				'id'            => 101,
				'regular_price' => '20',
			]
		);
		$GLOBALS['bulkcat_test']['products'][201] = new StoredProduct(
			'variation',
			[
				'id'            => 201,
				'parent_id'     => 103,
				'regular_price' => '60',
			]
		);
	}

	/**
	 * Dispatch a PATCH to one product.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private static function patch( int $id, array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'PATCH', '/bulkcat/v1/products/' . $id );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function assert_refused( mixed $response, string $code, int $status ): \WP_Error {
		global $wpdb;
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertSame( [], $wpdb->writes, 'Nothing was written.' );
		$this->assertSame( [], $this->store->items );
		foreach ( $GLOBALS['bulkcat_test']['products'] as $product ) {
			$this->assertSame( 0, $product->saves, 'Nothing was saved.' );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		return $response;
	}

	public function test_the_route_needs_the_write_capability_and_takes_the_registry_fields(): void {
		$route = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . ProductWriteController::ROUTE ] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'PATCH', $route[0]['methods'] );

		$registry = Registry::instance();
		$this->assertSame( array_merge( [ 'id', 'source', 'fields', 'keep_changed_status' ], array_keys( $registry->editable() ) ), array_keys( $route[0]['args'] ) );
		$this->assertSame( 'boolean', $route[0]['args']['keep_changed_status']['type'] );
		$this->assertFalse( $route[0]['args']['keep_changed_status']['default'] );
		$this->assertArrayNotHasKey( 'availability_off', $route[0]['args'], 'The internal Off record is never an argument.' );
		foreach ( $registry->editable() as $key => $field ) {
			$expected = $field->rest_arg();
			$this->assertSame( $expected['type'], $route[0]['args'][ $key ]['type'], $key );
			$this->assertSame( [ $registry->get( $key ), 'validate' ], $route[0]['args'][ $key ]['validate_callback'], $key );
			$this->assertSame( [ $registry->get( $key ), 'sanitise' ], $route[0]['args'][ $key ]['sanitize_callback'], $key );
		}
		$this->assertSame( [ 'inline', 'bulk' ], $route[0]['args']['source']['enum'] );
		$this->assertArrayNotHasKey( 'date_modified', $route[0]['args'], 'A read-only column is not an argument.' );
	}

	public function test_a_price_edit_answers_the_updated_row_and_its_batch_of_one(): void {
		$response = self::patch( 101, [ 'regular_price' => '25' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( [ 'item', 'batch_id', 'status', 'results' ], array_keys( $data ) );
		$this->assertSame( 1, $data['batch_id'] );
		$this->assertSame( 'complete', $data['status'] );
		$this->assertSame( [ 'regular_price' ], array_column( $data['results'], 'field' ) );
		$this->assertSame( [ 'changed' ], array_column( $data['results'], 'state' ) );

		$item = $data['item'];
		$this->assertSame( 101, $item['id'] );
		$this->assertSame( '25.00', $item['regular_price'], 'The row is re-read after the write.' );
		$this->assertSame( 'Blue Shirt', $item['name'] );
		$this->assertSame( 0, $item[ Hydrator::PARENT_ID ] );
		$list = new \WP_REST_Request( 'GET', '/bulkcat/v1/products' );
		$list->set_param( 'per_page', 100 );
		$rows = array_column( rest_get_server()->dispatch( $list )->get_data()['items'], null, 'id' );
		$this->assertSame( $rows[101], $item, 'The same row, in the same shape, as /products lists it now.' );
		$this->assertContains( 101, $GLOBALS['bulkcat_test']['cleaned_posts'], 'The object\'s caches were cleared before the re-read.' );

		$batch = $this->store->batch( 1 );
		$this->assertSame( [ 'inline', 'complete', 12 ], [ $batch['source'], $batch['status'], $batch['user_id'] ] );
		$this->assertSame( [ [ 101, 'regular_price', '20', '25.00', 'applied' ] ], $this->store->journal() );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'], 'No as_enqueue_async_action call, nor any other scheduling.' );
	}

	public function test_native_status_and_refused_old_keep_intent_through_the_route(): void {
		// New Draft changes only Status and never creates a remembered record.
		$product                                  = new StoredProduct(
			'simple',
			[
				'id'     => 106,
				'status' => 'publish',
			]
		);
		$GLOBALS['bulkcat_test']['products'][106] = $product;
		$response                                 = self::patch( 106, [ 'status' => 'draft' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( [ 'status' ], array_column( $response->get_data()['results'], 'field' ) );
		$this->assertSame( '', $response->get_data()['results'][0]['note'] );
		$this->assertSame( [ [ 106, 'status', 'publish', 'draft', 'applied' ] ], $this->store->journal() );
		$this->assertSame( [], $product->meta );

		// A legacy record does not turn a new Publish into a restore or conflict.
		$product->props['status']                   = 'private';
		$product->meta['_bulkcat_availability_off'] = \BulkCatalogueManager\Availability\OffRecord::create( 106, 'publish', '2026-09-01T08:00:00Z', 7 )->to_json();
		$response                                   = self::patch( 106, [ 'status' => 'publish' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( [ 'status' ], array_column( $response->get_data()['results'], 'field' ), 'Internal cleanup is never a public result.' );
		$this->assertSame( 'publish', $product->props['status'] );
		$this->assertArrayNotHasKey( '_bulkcat_availability_off', $product->meta );
		$this->assertSame( [ 'status', 'status', 'availability_off' ], array_column( $this->store->journal(), 1 ) );
		$writes = $GLOBALS['wpdb']->writes;
		$saves  = $product->saves;
		$kept   = self::patch(
			106,
			[
				'status'              => 'publish',
				'keep_changed_status' => true,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $kept );
		$this->assertSame( 400, $kept->get_error_data()['status'] );
		$this->assertStringContainsString( 'Reload and review', $kept->get_error_message() );
		$this->assertSame( $saves, $product->saves );
		$this->assertSame( $writes, $GLOBALS['wpdb']->writes );
	}

	public function test_fields_chooses_the_columns_and_source_can_say_bulk(): void {
		$response = self::patch(
			101,
			[
				'sku'    => 'SHIRT-NEW',
				'fields' => [ 'sku' ],
				'source' => 'bulk',
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'id', Hydrator::PARENT_ID, 'sku' ], array_keys( $response->get_data()['item'] ) );
		$this->assertSame( 'bulk', $this->store->batch( 1 )['source'] );
	}

	public function test_a_malformed_value_is_refused_by_its_argument_naming_the_field(): void {
		$error = $this->assert_refused( self::patch( 101, [ 'stock_quantity' => '1.5' ] ), 'rest_invalid_param', 400 );
		$this->assertSame( [ 'stock_quantity' => Registry::instance()->get( 'stock_quantity' )->rule_text() ], $error->get_error_data()['params'] );

		$this->assert_refused( self::patch( 101, [ 'source' => 'undo' ] + [ 'regular_price' => '25' ] ), 'rest_invalid_param', 400 );
	}

	public function test_a_value_the_product_cannot_take_is_refused_naming_the_field(): void {
		$error = $this->assert_refused( self::patch( 101, [ 'sale_price' => '30' ] ), 'bulkcat_invalid_value', 400 );
		$this->assertSame( 'sale_price', $error->get_error_data()['field'] );
	}

	public function test_a_variation_is_accepted_and_its_parent_only_fields_refused_with_their_reason(): void {
		$error = $this->assert_refused( self::patch( 201, [ 'featured' => true ] ), 'bulkcat_not_available', 400 );
		$this->assertSame( 'featured', $error->get_error_data()['field'] );
		$this->assertSame( Registry::instance()->get( 'featured' )->unavailable_reason( $GLOBALS['bulkcat_test']['products'][201] ), $error->get_error_message() );

		$response = self::patch( 201, [ 'regular_price' => '65' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 201, $response->get_data()['item']['id'] );
		$this->assertSame( 103, $response->get_data()['item'][ Hydrator::PARENT_ID ] );
		$this->assertSame( 'variation', array_values( $this->store->items )[0]['object_type'] );
	}

	public function test_an_object_the_user_may_not_edit_is_refused_403(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		$this->assert_refused( self::patch( 101, [ 'regular_price' => '25' ] ), 'bulkcat_cannot_edit', 403 );
	}

	public function test_a_missing_id_is_refused_404(): void {
		$this->assert_refused( self::patch( 999, [ 'regular_price' => '25' ] ), 'bulkcat_not_found', 404 );
	}

	public function test_a_visitor_and_a_user_who_cannot_edit_products_are_refused(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_products'] = false;
		$this->assert_refused( self::patch( 101, [ 'regular_price' => '25' ] ), 'bulkcat_forbidden', 403 );

		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$this->assert_refused( self::patch( 101, [ 'regular_price' => '25' ] ), 'bulkcat_forbidden', 401 );
	}
}
