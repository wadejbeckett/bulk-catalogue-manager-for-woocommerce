<?php
/**
 * The Heartbeat nonce refresh (T39; SPEC §13): the `heartbeat_received`
 * filter answers a `bulkcat_nonce` tick with a fresh `wp_rest` nonce only for a
 * signed-in user holding the screen capability, leaves every other tick and
 * user untouched, is hooked by the plugin, and the screen's script depends
 * on core's `heartbeat`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Admin\Heartbeat;
use BulkCatalogueManager\Admin\Screen;
use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase {
	private const SCREEN_ID = 'product_page_bulk-catalogue-manager-for-woocommerce';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = 7;
		$GLOBALS['bulkcat_test']['caps']      = [ 'manage_woocommerce' => true ];
	}

	public function test_a_capable_user_gets_a_fresh_rest_nonce_for_a_bulkcat_tick(): void {
		$response = Heartbeat::received( [ 'server_time' => 1 ], [ 'bulkcat_nonce' => 1 ] );
		$this->assertSame(
			[
				'server_time'   => 1,
				'bulkcat_nonce' => 'nonce-wp_rest',
			],
			$response
		);
		$this->assertSame( [ 'wp_rest' ], $GLOBALS['bulkcat_test']['nonces'] );
	}

	public function test_a_tick_without_the_key_is_left_alone(): void {
		foreach ( [ [], [ 'wp-auth-check' => true ], [ 'bulkcat_nonces' => 1 ] ] as $data ) {
			$this->assertSame( [ 'server_time' => 1 ], Heartbeat::received( [ 'server_time' => 1 ], $data ) );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['nonces'], 'No nonce is made for a tick that did not ask.' );
	}

	public function test_a_user_without_the_screen_capability_gets_nothing(): void {
		$GLOBALS['bulkcat_test']['caps'] = [
			'edit_products' => true,
			'read'          => true,
		];
		$this->assertSame( [], Heartbeat::received( [], [ 'bulkcat_nonce' => 1 ] ) );

		$GLOBALS['bulkcat_test']['caps']      = [ 'manage_woocommerce' => true ];
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$this->assertSame( [], Heartbeat::received( [], [ 'bulkcat_nonce' => 1 ] ), 'A signed-out tick gets nothing.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['nonces'] );
	}

	public function test_the_screen_capability_decides_under_administrators_only(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$this->assertSame( [], Heartbeat::received( [], [ 'bulkcat_nonce' => 1 ] ), 'A shop manager is shut out under administrators only.' );

		$GLOBALS['bulkcat_test']['caps'] = [ 'manage_options' => true ];
		$this->assertSame( [ 'bulkcat_nonce' => 'nonce-wp_rest' ], Heartbeat::received( [], [ 'bulkcat_nonce' => 1 ] ) );
	}

	public function test_a_malformed_response_or_data_passes_through(): void {
		$this->assertSame( 'odd', Heartbeat::received( 'odd', [ 'bulkcat_nonce' => 1 ] ) );
		$this->assertSame( [ 'a' => 1 ], Heartbeat::received( [ 'a' => 1 ], 'bulkcat_nonce' ) );
	}

	public function test_the_plugin_hooks_the_filter_with_both_arguments(): void {
		$hooks = array_values( array_filter( ( new Plugin() )->hooks(), static fn( array $hook ) => 'heartbeat_received' === $hook[0] ) );
		$this->assertSame( [ [ 'heartbeat_received', [ Heartbeat::class, 'received' ], 10, 2 ] ], $hooks );

		( new Plugin() )->boot();
		$this->assertSame( [ 'bulkcat_nonce' => 'nonce-wp_rest' ], apply_filters( 'heartbeat_received', [], [ 'bulkcat_nonce' => 1 ], self::SCREEN_ID ) );
	}

	public function test_the_screen_script_depends_on_heartbeat(): void {
		$screen = new Screen( dirname( __DIR__, 2 ) . '/bulk-catalogue-manager-for-woocommerce.php' );
		$screen->register();
		$screen->enqueue( self::SCREEN_ID );
		$scripts = bulkcat_test_admin_calls( 'wp_register_script' );
		$this->assertCount( 1, $scripts );
		$this->assertSame( 'bulkcat-app', $scripts[0][0] );
		$this->assertContains( 'heartbeat', $scripts[0][2] );
		$this->assertSame( [ 'wp-api-fetch', 'heartbeat' ], Screen::dependencies( [ 'wp-api-fetch', 'heartbeat' ] ), 'Listed once even if the build names it.' );
	}
}
