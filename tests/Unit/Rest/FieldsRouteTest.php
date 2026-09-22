<?php
/**
 * `GET /bcm/v1/fields` (SPEC §12.1, §13): registered under bcm/v1 on
 * rest_api_init with a real permission callback, refusing with `bcm_forbidden`
 * and returning every field of the registry.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\FieldsController;
use PHPUnit\Framework\TestCase;

final class FieldsRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	/**
	 * Boot the plugin and fire rest_api_init, returning the fields endpoint.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint(): array {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$routes = $GLOBALS['bcm_test']['routes'];
		self::assertArrayHasKey( '/bcm/v1/fields', $routes );
		return $routes['/bcm/v1/fields'][0];
	}

	private static function allowed( mixed $result ): bool {
		return true === $result;
	}

	public function test_the_route_registers_on_rest_api_init_with_a_real_permission_callback(): void {
		$this->assertArrayNotHasKey( '/bcm/v1/fields', $GLOBALS['bcm_test']['routes'] );
		$endpoint = self::endpoint();
		$this->assertSame( 'GET', $endpoint['methods'] );
		$this->assertTrue( is_callable( $endpoint['callback'] ) );
		$this->assertTrue( is_callable( $endpoint['permission_callback'] ) );
		$this->assertNotSame( '__return_true', $endpoint['permission_callback'] );
		$this->assertSame( [ Access::class, 'can_read' ], $endpoint['permission_callback'] );
		foreach ( array_keys( $GLOBALS['bcm_test']['routes'] ) as $route ) {
			$this->assertStringStartsWith( '/bcm/v1/', $route );
		}
	}

	public function test_an_anonymous_request_is_refused_with_401(): void {
		$result = call_user_func( self::endpoint()['permission_callback'], new \WP_REST_Request( 'GET', '/bcm/v1/fields' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bcm_forbidden', $result->get_error_code() );
		$this->assertSame( [ 'status' => 401 ], $result->get_error_data() );
		$this->assertSame( 'You need to log in to use Bulk Catalogue Manager. Log in and try again.', $result->get_error_message() );
	}

	public function test_a_user_without_the_capability_is_refused_with_403(): void {
		$GLOBALS['bcm_test']['logged_in'] = true;
		$result                           = call_user_func( self::endpoint()['permission_callback'], new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bcm_forbidden', $result->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $result->get_error_data() );
		$this->assertStringContainsString( 'Ask an administrator', $result->get_error_message() );
	}

	public function test_the_capability_follows_the_administrators_only_setting(): void {
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$this->assertSame( 'manage_woocommerce', Access::capability() );
		$this->assertTrue( self::allowed( Access::can_read() ), 'A shop manager may read.' );

		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'administrators_only' => true ];
		$this->assertSame( 'manage_options', Access::capability() );
		$this->assertSame( 403, Access::can_read()->get_error_data()['status'], 'A shop manager is refused under administrators only.' );

		$GLOBALS['bcm_test']['caps']['manage_options'] = true;
		$this->assertTrue( self::allowed( Access::can_read() ) );
	}

	public function test_the_capability_is_filtered_through_bcm_capability(): void {
		$GLOBALS['bcm_test']['logged_in']             = true;
		$GLOBALS['bcm_test']['caps']['edit_products'] = true;
		$seen = [];
		add_filter(
			'bcm_capability',
			static function ( string $capability, bool $administrators_only ) use ( &$seen ): string {
				$seen[] = [ $capability, $administrators_only ];
				return 'edit_products';
			},
			10,
			2
		);
		$this->assertTrue( self::allowed( Access::can_read() ) );
		$this->assertSame( [ [ 'manage_woocommerce', false ] ], $seen );

		bcm_test_reset();
		add_filter( 'bcm_capability', static fn() => '' );
		$this->assertSame( 'manage_woocommerce', Access::capability(), 'An empty filtered capability falls back rather than opening the route.' );
		add_filter( 'bcm_capability', static fn() => null, 20 );
		$this->assertSame( 'manage_woocommerce', Access::capability() );
	}

	public function test_the_route_returns_every_field_in_registry_order(): void {
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$endpoint = self::endpoint();
		$this->assertTrue( call_user_func( $endpoint['permission_callback'], new \WP_REST_Request() ) );
		$response = call_user_func( $endpoint['callback'], new \WP_REST_Request( 'GET', '/bcm/v1/fields' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( array_is_list( $data ) );
		$this->assertSame( ( new Registry() )->keys(), array_column( $data, 'key' ) );
		$this->assertCount( 30, $data );
		$this->assertSame( ( new Registry() )->to_client(), $data );
	}

	public function test_the_item_schema_describes_every_key_of_an_item(): void {
		$controller = new FieldsController( new Registry() );
		$properties = $controller->get_item_schema()['properties'];
		foreach ( ( new Registry() )->to_client() as $item ) {
			foreach ( array_keys( $item ) as $key ) {
				$this->assertArrayHasKey( $key, $properties, "Item key $key is not in the schema." );
			}
		}
	}
}
