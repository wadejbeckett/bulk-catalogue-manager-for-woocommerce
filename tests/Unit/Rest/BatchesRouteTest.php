<?php
/**
 * `POST /bcm/v1/batches` (T31; SPEC §9.2, §12.1, §13) through the REST
 * dispatcher: registered behind Access::can_write; an id list answers 201
 * with the queued batch; a selection token is materialised through its
 * filter; the same request id again answers 200 with the same batch id and
 * writes nothing; a visitor is refused 401 and a user who may not edit
 * products 403; a body without exactly one of `ids` and `token`, a bad
 * token, a bad request id, too many operations or an `undo` source is
 * refused with nothing written.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use BulkCatalogueManager\Tests\Unit\Engine\BatchStore;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';
require_once dirname( __DIR__ ) . '/Engine/BatchStore.php';

final class BatchesRouteTest extends TestCase {
	private const UUID = '9b2e6f1c-1d2a-4c3b-8e4f-5a6b7c8d9e0f';

	private BatchStore $store;

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->store = new BatchStore( SeededCatalogue::seed() );
	}

	/**
	 * Dispatch a POST to the route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function post( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bcm/v1/batches' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid body over an id list.
	 *
	 * @return array<string, mixed>
	 */
	private static function body(): array {
		return [
			'uuid'       => self::UUID,
			'operations' => [
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'status',
						'value' => 'draft',
					],
				],
			],
			'ids'        => [ 104, 201 ],
		];
	}

	private function assert_refused( mixed $response, string $code, int $status ): void {
		global $wpdb;
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertSame( [], $wpdb->writes, 'Nothing was written.' );
		$this->assertSame( [], $this->store->items );
	}

	public function test_the_route_needs_the_write_capability(): void {
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/batches'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ 'uuid', 'operations', 'ids', 'token', 'source' ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['uuid']['required'] );
		$this->assertSame( BatchesController::SOURCES, $route[0]['args']['source']['enum'] );
	}

	public function test_an_id_list_queues_a_batch_and_a_repeat_answers_the_same_one(): void {
		global $wpdb;
		$response = self::post( self::body() );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			[
				'batch_id'    => 1,
				'status'      => 'queued',
				'total_items' => 2,
				'replayed'    => false,
			],
			$response->get_data()
		);
		$this->assertSame( [ 104, 201 ], $this->store->object_ids( 1 ) );
		$this->assertSame( [ 'product', 'variation' ], array_column( $this->store->items, 'object_type' ) );
		$this->assertSame( 12, $this->store->batches[1]['user_id'] );
		$this->assertSame( 'bulk', $this->store->batches[1]['source'] );

		$writes  = $wpdb->writes;
		$queries = count( $wpdb->queries );
		$again   = self::post( [ 'ids' => [ 101 ] ] + self::body() );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 1, $again->get_data()['batch_id'] );
		$this->assertTrue( $again->get_data()['replayed'] );
		$this->assertSame( $writes, $wpdb->writes, 'The double-click inserts no batch.' );
		$this->assertSame( [], preg_grep( '/^INSERT/', array_slice( $wpdb->queries, $queries ) ), 'The double-click inserts no items.' );
		$this->assertCount( 2, $this->store->items );
	}

	public function test_a_selection_token_is_materialised_through_its_filter(): void {
		$token    = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_VARIATIONS ] ), 2 ), 12 );
		$body     = self::body();
		$response = self::post( [ 'token' => $token ] + array_diff_key( $body, [ 'ids' => 0 ] ) + [ 'source' => 'inline' ] );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['total_items'] );
		$this->assertSame( SeededCatalogue::VARIATIONS, $this->store->object_ids( 1 ) );
		$this->assertSame( 'inline', $this->store->batches[1]['source'] );
	}

	public function test_a_visitor_and_a_user_who_cannot_edit_products_are_refused(): void {
		$GLOBALS['bcm_test']['caps']['edit_products'] = false;
		$this->assert_refused( self::post( self::body() ), 'bcm_forbidden', 403 );

		$GLOBALS['bcm_test']['logged_in'] = false;
		$GLOBALS['bcm_test']['caps']      = [];
		$this->assert_refused( self::post( self::body() ), 'bcm_forbidden', 401 );
	}

	public function test_a_body_must_name_exactly_one_selection(): void {
		$body = self::body();
		$this->assert_refused( self::post( array_diff_key( $body, [ 'ids' => 0 ] ) ), BatchesController::SELECTION_REQUIRED, 400 );
		$this->assert_refused( self::post( [ 'token' => 'abc.def' ] + $body ), BatchesController::SELECTION_REQUIRED, 400 );
	}

	public function test_a_token_that_cannot_be_used_is_refused(): void {
		$body  = array_diff_key( self::body(), [ 'ids' => 0 ] );
		$other = SelectionToken::mint( Selection::of_ids( [ 101 ] ), 13 );
		$this->assert_refused( self::post( [ 'token' => $other ] + $body ), SelectionToken::INVALID, 403 );
		$this->assert_refused( self::post( [ 'token' => 'not-a-token' ] + $body ), SelectionToken::INVALID, 403 );
	}

	public function test_bad_arguments_are_refused_before_the_handler(): void {
		$this->assert_refused( self::post( [ 'uuid' => 'not-a-uuid' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( array_diff_key( self::body(), [ 'uuid' => 0 ] ) ), 'rest_missing_callback_param', 400 );
		$this->assert_refused( self::post( [ 'source' => 'undo' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( [ 'ids' => [ 0 ] ] + self::body() ), 'rest_invalid_param', 400 );
	}

	public function test_too_many_operations_are_refused_with_nothing_written(): void {
		$body               = self::body();
		$body['operations'] = array_fill( 0, 21, $body['operations'][0] );
		$this->assert_refused( self::post( $body ), 'bcm_too_many_operations', 400 );
	}
}
