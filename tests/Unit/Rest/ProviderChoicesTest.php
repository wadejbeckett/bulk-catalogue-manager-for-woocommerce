<?php
/** Registered discovery and choice controllers. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use BulkCatalogueManager\Rest\{Routes, ProviderChoicesController, ProviderActionsController, ProviderCapabilitiesController};
require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';
final class ProviderChoicesTest extends TestCase {
	public function test_registered_routes_are_guarded_and_handlers_use_scoped_services(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$s->choices( 601 );
		Routes::plugin()->register();
		foreach ( [ '/provider-actions', '/provider-choices/search', '/provider-choices/resolve', '/provider-capabilities' ] as $route ) {
			$this->assertArrayHasKey( '/bulkcat/v1' . $route, $GLOBALS['bulkcat_test']['routes'] ); }
		$request = new \WP_REST_Request( 'POST' );
		$request->set_body_params(
			[
				'provider'     => 'sample',
				'action'       => 'x_sample_assign',
				'argument'     => 'values',
				'selection'    => [
					'targets' => [
						[
							'kind'     => 'product',
							'taxonomy' => null,
							'id'       => 41,
						],
					],
				],
				'dependencies' => [
					'part'      => 'a',
					'operation' => 'add',
				],
				'query'        => '',
				'cursor'       => null,
				'limit'        => 50,
			]
		);
		$response = ( new ProviderChoicesController() )->search( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertCount( 50, $response->get_data()['items'] );
		$request->set_body_params(
			[
				'location'  => 'product.row',
				'selection' => [
					'targets' => [
						[
							'kind'     => 'product',
							'taxonomy' => null,
							'id'       => 41,
						],
					],
				],
			]
		);
		$result = ( new ProviderActionsController() )->create_item( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$payload = $result->get_data();
		$this->assertSame( 'org.example.sample', $payload['actions'][0]['identity'] );
		$this->assertSame( 'lookup', $payload['actions'][0]['arguments']['values']['type'] );
		$caps = ( new ProviderCapabilitiesController() )->get_items();
		$this->assertEquals(
			[
				'contract'     => 2,
				'capabilities' => (object) [],
			],
			$caps->get_data()
		);
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post'] = false;
		$this->assertInstanceOf( \WP_Error::class, ( new ProviderActionsController() )->create_item( $request ) );
		$this->assertSame( [], $s->store->reads );
	}

	public function test_actual_callbacks_deny_members_and_bound_the_raw_body(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		Routes::plugin()->register();
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/provider-actions'];
		$this->assertTrue( is_callable( $route['callback'] ) );
		$this->assertTrue( is_callable( $route['permission_callback'] ) );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'content-type', 'application/json' );
		$body = [
			'location'  => 'product.bulk',
			'selection' => [
				'targets' => [
					[
						'kind'     => 'product',
						'taxonomy' => null,
						'id'       => 41,
					],
				],
			],
		];
		$request->set_body( wp_json_encode( $body ) );
		$this->assertTrue( ( $route['permission_callback'] )( $request ) );
		$this->assertInstanceOf( \WP_REST_Response::class, ( $route['callback'] )( $request ) );
		$request->set_body( str_repeat( ' ', 65536 ) . wp_json_encode( $body ) );
		$this->assertSame( 'bulkcat_invalid_lookup', ( $route['callback'] )( $request )->get_error_code() );
		$body['args'] = [
			'x_sample_assign' => [
				'operation' => 'clear',
				'values'    => [ 'v1' ],
			],
		];
		$request->set_body( wp_json_encode( $body ) );
		$this->assertSame( 400, ( $route['callback'] )( $request )->get_error_data()['status'] );
		unset( $body['args'] );
		$body['selection']['targets'][] = [
			'kind'     => 'product',
			'taxonomy' => null,
			'id'       => 404,
		];
		$request->set_body( wp_json_encode( $body ) );
		$this->assertSame( 403, ( $route['callback'] )( $request )->get_error_data()['status'] );
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = false;
		$this->assertInstanceOf( \WP_Error::class, ( $route['permission_callback'] )( $request ) );
	}
}
