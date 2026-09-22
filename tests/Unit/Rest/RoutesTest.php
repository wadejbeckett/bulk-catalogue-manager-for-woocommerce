<?php
/**
 * The REST bootstrap (SPEC §12.1, §13): every bcm/v1 endpoint the plugin
 * registers has a real permission callback, the registrar refuses one that
 * does not, it is the only caller of register_rest_route(), and it hands the
 * screen a wp_rest nonce.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Controller;
use BulkCatalogueManager\Rest\Routes;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	/**
	 * A well-formed GET endpoint with the given permission callback.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint( mixed $permission ): array {
		return [
			'methods'             => 'GET',
			'callback'            => static fn() => [],
			'permission_callback' => $permission,
		];
	}

	public function test_every_bcm_endpoint_has_a_real_permission_callback(): void {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes( Access::REST_NAMESPACE );

		$this->assertArrayHasKey( '/bcm/v1/fields', $routes, 'The walk must see the fields route.' );
		$walked = 0;
		foreach ( $routes as $route => $endpoints ) {
			if ( '/' . Access::REST_NAMESPACE === $route ) {
				continue; // Core's namespace index, which lists the routes and runs none of them.
			}
			$this->assertStringStartsWith( '/bcm/v1/', $route );
			$this->assertNotEmpty( $endpoints, "$route has no endpoint." );
			foreach ( $endpoints as $endpoint ) {
				$this->assertArrayHasKey( 'permission_callback', $endpoint, "$route has an endpoint without a permission callback." );
				$callback = $endpoint['permission_callback'];
				$this->assertTrue( is_callable( $callback ), "$route has a permission callback that cannot be called." );
				$this->assertNotSame( '__return_true', $callback, "$route lets anyone through." );
				$this->assertTrue( Routes::is_guarded( $callback ), "$route lets anyone through." );
				$this->assertNotSame( [], $endpoint['methods'] );
				++$walked;
			}
		}
		$this->assertGreaterThan( 0, $walked );
		$this->assertSame( [], $GLOBALS['bcm_test']['wrong'], 'No plugin route was refused.' );
	}

	public function test_every_bcm_endpoint_refuses_an_anonymous_visitor(): void {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		foreach ( rest_get_server()->get_routes( Access::REST_NAMESPACE ) as $route => $endpoints ) {
			if ( '/' . Access::REST_NAMESPACE === $route ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$result = call_user_func( $endpoint['permission_callback'], new \WP_REST_Request( 'GET', $route ) );
				$this->assertInstanceOf( \WP_Error::class, $result, "$route let an anonymous visitor through." );
				$this->assertSame( 'bcm_forbidden', $result->get_error_code() );
				$this->assertSame( 401, $result->get_error_data()['status'] );
			}
		}
	}

	public function test_the_registrar_refuses_a_route_without_a_real_permission_callback(): void {
		$routes  = new Routes( [] );
		$missing = self::endpoint( null );
		unset( $missing['permission_callback'] );
		$cases = [
			'/missing'         => $missing,
			'/open'            => self::endpoint( '__return_true' ),
			'/open-rooted'     => self::endpoint( '\__return_true' ),
			'/open-upper'      => self::endpoint( '__RETURN_TRUE' ),
			'/not-callable'    => self::endpoint( 'bcm_no_such_function' ),
			'/null'            => self::endpoint( null ),
			'/true'            => self::endpoint( true ),
			'/one-of-two'      => [
				self::endpoint( [ Access::class, 'can_read' ] ),
				self::endpoint( '__return_true' ),
				'schema' => static fn() => [],
			],
			'/no-endpoints'    => [ 'schema' => static fn() => [] ],
			'no-leading-slash' => self::endpoint( [ Access::class, 'can_read' ] ),
		];
		foreach ( $cases as $route => $args ) {
			$this->assertFalse( $routes->add( $route, $args ), "$route was registered." );
		}
		$this->assertSame( array_keys( $cases ), $routes->refused() );
		$this->assertSame( [], $GLOBALS['bcm_test']['routes'], 'A refused route reaches register_rest_route().' );
		$this->assertCount( count( $cases ), $GLOBALS['bcm_test']['wrong'] );
		$this->assertSame( Routes::class . '::add', $GLOBALS['bcm_test']['wrong'][0][0] );
		$this->assertStringContainsString( 'bcm/v1/missing', $GLOBALS['bcm_test']['wrong'][0][1] );
		$this->assertStringContainsString( 'no permission_callback', $GLOBALS['bcm_test']['wrong'][0][1] );
		$this->assertStringContainsString( 'lets anyone through', $GLOBALS['bcm_test']['wrong'][1][1] );

		$this->assertTrue( $routes->add( '/guarded', self::endpoint( [ Access::class, 'can_read' ] ) ) );
		$this->assertTrue(
			$routes->add(
				'/pair',
				[
					self::endpoint( [ Access::class, 'can_read' ] ),
					self::endpoint( [ Access::class, 'can_write' ] ),
					'schema' => static fn() => [],
				]
			)
		);
		$this->assertSame( [ '/bcm/v1/guarded', '/bcm/v1/pair' ], array_keys( $GLOBALS['bcm_test']['routes'] ) );
		$this->assertCount( 2, rest_get_server()->get_routes( 'bcm/v1' )['/bcm/v1/pair'] );
	}

	public function test_the_registrar_registers_every_controller_on_rest_api_init(): void {
		$seen       = [];
		$controller = new class( $seen ) implements Controller {
			public function __construct( private array &$seen ) {}

			public function register_routes( Routes $routes ): void {
				$this->seen[] = $routes;
				$routes->add(
					'/probe',
					[
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					]
				);
			}
		};
		$registrar  = new Routes( [ $controller ] );
		$registrar->register();
		$this->assertSame( [ $registrar ], $seen );
		$this->assertSame( [ '/probe' ], $registrar->refused(), 'A controller cannot slip an open route past the registrar.' );

		( new Plugin() )->boot();
		$this->assertSame( [], $GLOBALS['bcm_test']['routes'], 'Routes wait for rest_api_init.' );
		do_action( 'rest_api_init' );
		$this->assertArrayHasKey( '/bcm/v1/fields', $GLOBALS['bcm_test']['routes'] );
	}

	public function test_only_the_registrar_calls_register_rest_route(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge( (array) glob( $root . '/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE ), [ $root . '/bulk-catalogue-manager-for-woocommerce.php', $root . '/uninstall.php' ] );
		$found = [];
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) && 'register_rest_route' === ltrim( $token[1], '\\' ) ) {
					$found[] = substr( $file, strlen( $root ) + 1 );
				}
			}
		}
		$this->assertSame( [ 'src/Rest/Routes.php' ], $found, 'Register routes through Routes::add() only.' );
	}

	public function test_the_client_settings_carry_a_wp_rest_nonce_and_the_namespace_root(): void {
		$settings = Routes::client_settings();
		$this->assertSame( 'nonce-wp_rest', $settings['nonce'] );
		$this->assertSame( [ 'wp_rest' ], $GLOBALS['bcm_test']['nonces'] );
		$this->assertSame( 'https://example.test/wp-json/bcm/v1/', $settings['root'] );
		$this->assertSame( 'nonce-wp_rest', Routes::nonce() );
	}
}
