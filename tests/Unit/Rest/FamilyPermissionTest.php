<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Routes;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class FamilyPermissionTest extends TestCase {
	public function test_registered_request_callbacks_accept_typed_preflight_without_product_rights(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = 3;
		$GLOBALS['bulkcat_test']['caps']      = [
			'manage_woocommerce'   => true,
			'manage_product_terms' => true,
		];
		Routes::plugin()->register();
		foreach ( [ '/requests', '/requests/resolve' ] as $path ) {
			$request = new \WP_REST_Request( 'POST', '/bulkcat/v1' . $path );
			$request->set_param( 'operation', 'batch.apply' );
			$request->set_param(
				'intent',
				[
					'version'    => 1,
					'serializer' => 2,
					'family'     => 'category',
					'source'     => 'bulk',
					'entry'      => 'category.bulk',
					'external'   => [
						'contract' => 2,
						'provider' => 'sample',
						'identity' => 'org.example.sample',
					],
					'selection'  => [
						'targets' => [
							[
								'kind'     => 'term',
								'taxonomy' => 'product_cat',
								'id'       => 41,
							],
						],
					],
					'operations' => [
						[
							'action' => 'x_sample_assign_category',
							'args'   => [ 'field' => 'x_sample_category_composite' ],
						],
					],
					'scope_hash' => str_repeat( 'a', 64 ),
				]
			);
			$callback = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . $path ][0]['permission_callback'];
			$this->assertTrue( true === call_user_func( $callback, $request ), $path . ' is preflight only; service family checks remain mandatory.' );
		}
	}

	public function test_category_only_actor_passes_typed_route_preflight(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = 3;
		$GLOBALS['bulkcat_test']['caps']      = [
			'manage_woocommerce'   => true,
			'manage_product_terms' => true,
		];
		Routes::plugin()->register();
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
		$request->set_param( 'serializer', 2 );
		$request->set_param( 'family', 'category' );
		$request->set_param( 'entry', 'category.bulk' );
		$request->set_param(
			'external',
			[
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			]
		);
		$request->set_param(
			'targets',
			[
				[
					'kind'     => 'term',
					'taxonomy' => 'product_cat',
					'id'       => 41,
				],
			]
		);
		$request->set_param(
			'operations',
			[
				[
					'action' => 'x_sample_assign_category',
					'args'   => [ 'field' => 'x_sample_category_composite' ],
				],
			]
		);
		$endpoint = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/preview'][0];
		$this->assertTrue( call_user_func( $endpoint['permission_callback'], $request ) );
		$this->assertTrue( Access::can_write() instanceof \WP_Error );
	}
	public function test_apply_cancel_and_undo_registered_callbacks_use_stored_family(): void {
		$s = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$p        = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$accepted = $s->accept( $p );
		$this->assertTrue( is_array( $accepted ) );
		Routes::plugin()->register();
		unset( $GLOBALS['bulkcat_test']['caps']['edit_products'], $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] );
		foreach ( [ \BulkCatalogueManager\Rest\BatchesController::CANCEL_ROUTE, \BulkCatalogueManager\Rest\UndoController::ROUTE ] as $route ) {
			$request = new \WP_REST_Request( 'POST', '/bulkcat/v1' . $route );
			$request->set_param( 'id', $accepted['batch_id'] );
			$callback = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . $route ][0]['permission_callback'];
			$this->assertInstanceOf( \WP_Error::class, call_user_func( $callback, $request ) );
			$request->set_param(
				'intent',
				[
					'serializer' => 2,
					'family'     => 'category',
				]
			);
			$this->assertTrue( call_user_func( $callback, $request ) );
			$request->set_param( 'preview', true );
			$handler = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . $route ][0]['callback'];
			$this->assertInstanceOf( \WP_Error::class, call_user_func( $handler, $request ) );
		}
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/batches' );
		$request->set_param(
			'intent',
			[
				'serializer' => 2,
				'family'     => 'category',
			]
		);
		$endpoints = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/batches'];
		foreach ( $endpoints as $endpoint ) {
			if ( is_array( $endpoint ) && 'POST' === ( $endpoint['methods'] ?? null ) ) {
				$this->assertTrue( call_user_func( $endpoint['permission_callback'], $request ) ); }
		}
		$this->assertArrayHasKey( 'typed_token', \BulkCatalogueManager\Rest\BatchesController::args() );
	}

	public function test_typed_history_never_exposes_binding_or_args_to_an_unauthorized_reader(): void {
		$s = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$p        = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$accepted = $s->accept( $p );
		$request  = new \WP_REST_Request( 'GET', '/bulkcat/v1/batches/' . $accepted['batch_id'] );
		$request->set_param( 'id', $accepted['batch_id'] );
		foreach ( [ 'unauthorized', 'missing' ] as $mode ) {
			if ( 'missing' === $mode ) {
				$s->remove_provider();
			} else {
				unset( $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] ); }
			$response = ( new \BulkCatalogueManager\Rest\BatchesController() )->get_item( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$data = $response->get_data();
			$this->assertArrayNotHasKey( 'values', $data['operations'][0]['args'] );
			$wire = wp_json_encode( $data );
			foreach ( [ 'validation', 'state_token', '"policy"', '"v1"' ] as $secret ) {
				$this->assertStringNotContainsString( $secret, $wire ); }
		}
	}

	public function test_fix1_native_and_v1_cancellation_cannot_borrow_typed_preflight(): void {
		foreach ( [ null, [ 'sample' => [ 'contract' => 1 ] ] ] as $manifest ) {
			$s  = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
			$b  = new \BulkCatalogueManager\Data\BatchRepository();
			$id = $b->create( wp_generate_uuid4(), 3, 'bulk', [], [], 1, null, null, $manifest );
			Routes::plugin()->register();
			unset( $GLOBALS['bulkcat_test']['caps']['edit_products'], $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] );
			$route   = $GLOBALS['bulkcat_test']['routes'][ '/bulkcat/v1' . \BulkCatalogueManager\Rest\BatchesController::CANCEL_ROUTE ][0];
			$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/batches/' . $id . '/cancel' );
			$request->set_param( 'id', $id );
			$this->assertInstanceOf( \WP_Error::class, call_user_func( $route['permission_callback'], $request ) );
			$request->set_param(
				'intent',
				[
					'serializer' => 2,
					'family'     => 'category',
				]
			);
			$this->assertTrue( call_user_func( $route['permission_callback'], $request ) );
			$answer = call_user_func( $route['callback'], $request );
			$this->assertInstanceOf( \WP_Error::class, $answer );
			$this->assertSame( 403, $answer->get_error_data()['status'] );
			$this->assertSame( 'queued', $b->find_strict( $id )['status'] );
			$GLOBALS['bulkcat_test']['caps']['edit_products']         = true;
			$GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] = true;
			$this->assertInstanceOf( \WP_REST_Response::class, call_user_func( $route['callback'], $request ) );
			$this->assertSame( 'cancelled', $b->find_strict( $id )['status'] );
		}
	}
}
