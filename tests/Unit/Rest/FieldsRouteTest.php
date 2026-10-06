<?php
/**
 * `GET /bulkcat/v1/fields` (SPEC §12.1, §13): registered under bulkcat/v1 on
 * rest_api_init with a real permission callback, refusing with `bulkcat_forbidden`
 * and returning `{ fields, actions, groups }`: every field of the registry,
 * every action descriptor (T24) and the five panel groups.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\FieldsController;
use PHPUnit\Framework\TestCase;

final class FieldsRouteTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Boot the plugin and fire rest_api_init, returning the fields endpoint.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint(): array {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$routes = $GLOBALS['bulkcat_test']['routes'];
		self::assertArrayHasKey( '/bulkcat/v1/fields', $routes );
		return $routes['/bulkcat/v1/fields'][0];
	}

	private static function allowed( mixed $result ): bool {
		return true === $result;
	}

	public function test_the_route_registers_on_rest_api_init_with_a_real_permission_callback(): void {
		$this->assertArrayNotHasKey( '/bulkcat/v1/fields', $GLOBALS['bulkcat_test']['routes'] );
		$endpoint = self::endpoint();
		$this->assertSame( 'GET', $endpoint['methods'] );
		$this->assertTrue( is_callable( $endpoint['callback'] ) );
		$this->assertTrue( is_callable( $endpoint['permission_callback'] ) );
		$this->assertNotSame( '__return_true', $endpoint['permission_callback'] );
		$this->assertSame( [ Access::class, 'can_read' ], $endpoint['permission_callback'] );
		foreach ( array_keys( $GLOBALS['bulkcat_test']['routes'] ) as $route ) {
			$this->assertStringStartsWith( '/bulkcat/v1/', $route );
		}
	}

	public function test_an_anonymous_request_is_refused_with_401(): void {
		$result = call_user_func( self::endpoint()['permission_callback'], new \WP_REST_Request( 'GET', '/bulkcat/v1/fields' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( [ 'status' => 401 ], $result->get_error_data() );
		$this->assertSame( 'You need to log in to use Bulk Catalogue Manager. Log in and try again.', $result->get_error_message() );
	}

	public function test_a_user_without_the_capability_is_refused_with_403(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$result                               = call_user_func( self::endpoint()['permission_callback'], new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $result->get_error_data() );
		$this->assertStringContainsString( 'Ask an administrator', $result->get_error_message() );
	}

	public function test_the_capability_follows_the_administrators_only_setting(): void {
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$this->assertSame( 'manage_woocommerce', Access::capability() );
		$this->assertTrue( self::allowed( Access::can_read() ), 'A shop manager may read.' );

		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$this->assertSame( 'manage_options', Access::capability() );
		$this->assertSame( 403, Access::can_read()->get_error_data()['status'], 'A shop manager is refused under administrators only.' );

		$GLOBALS['bulkcat_test']['caps']['manage_options'] = true;
		$this->assertTrue( self::allowed( Access::can_read() ) );
	}

	public function test_the_capability_is_filtered_through_bulkcat_capability(): void {
		$GLOBALS['bulkcat_test']['logged_in']             = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products'] = true;
		$seen = [];
		add_filter(
			'bulkcat_capability',
			static function ( string $capability, bool $administrators_only ) use ( &$seen ): string {
				$seen[] = [ $capability, $administrators_only ];
				return 'edit_products';
			},
			10,
			2
		);
		$this->assertTrue( self::allowed( Access::can_read() ) );
		$this->assertSame( [ [ 'manage_woocommerce', false ] ], $seen );

		bulkcat_test_reset();
		add_filter( 'bulkcat_capability', static fn() => '' );
		$this->assertSame( 'manage_woocommerce', Access::capability(), 'An empty filtered capability falls back rather than opening the route.' );
		add_filter( 'bulkcat_capability', static fn() => null, 20 );
		$this->assertSame( 'manage_woocommerce', Access::capability() );
	}

	public function test_the_route_returns_every_field_action_and_group_in_order(): void {
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$endpoint = self::endpoint();
		$this->assertTrue( call_user_func( $endpoint['permission_callback'], new \WP_REST_Request() ) );
		$response = call_user_func( $endpoint['callback'], new \WP_REST_Request( 'GET', '/bulkcat/v1/fields' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'fields', 'actions', 'groups' ], array_keys( $data ) );

		$this->assertTrue( array_is_list( $data['fields'] ) );
		$this->assertSame( ( new Registry() )->keys(), array_column( $data['fields'], 'key' ) );
		$this->assertCount( 30, $data['fields'] );
		$this->assertSame( ( new Registry() )->to_client(), $data['fields'] );

		$actions = new Actions( new Registry() );
		$this->assertTrue( array_is_list( $data['actions'] ) );
		$this->assertSame( $actions->ids(), array_column( $data['actions'], 'id' ) );
		$this->assertSame( [ 'set_value', 'set_status', 'clear', 'adjust', 'round', 'copy_price', 'find_replace', 'affix_sku', 'add_terms', 'remove_terms', 'replace_terms' ], array_column( $data['actions'], 'id' ) );
		$this->assertSame( $actions->to_client(), $data['actions'] );

		$this->assertSame( Group::ALL, array_column( $data['groups'], 'id' ) );
		$this->assertSame( [ 'Pricing', 'Stock', 'Organisation', 'Shipping and tax', 'Publishing' ], array_column( $data['groups'], 'label' ) );
	}

	public function test_the_plugin_route_serves_the_shared_action_registry(): void {
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$data = call_user_func( self::endpoint()['callback'], new \WP_REST_Request( 'GET', '/bulkcat/v1/fields' ) )->get_data();
		$this->assertSame( Actions::instance()->to_client(), $data['actions'] );
	}

	public function test_the_schema_describes_every_key_of_the_response(): void {
		$controller = new FieldsController( new Registry() );
		$schema     = $controller->get_item_schema();
		$payload    = $controller->payload();
		$this->assertSame( array_keys( $payload ), array_keys( $schema['properties'] ) );
		foreach ( [ 'fields', 'actions', 'groups' ] as $list ) {
			$properties = $schema['properties'][ $list ]['items']['properties'];
			foreach ( $payload[ $list ] as $item ) {
				foreach ( array_keys( $item ) as $key ) {
					$this->assertArrayHasKey( $key, $properties, "$list item key $key is not in the schema." );
				}
			}
		}
		$this->assertTrue( rest_validate_value_from_schema( $payload, $schema, 'fields' ) );
	}
}
