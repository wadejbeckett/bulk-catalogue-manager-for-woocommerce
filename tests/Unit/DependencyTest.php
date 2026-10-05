<?php
/**
 * WooCommerce dependency guard (SPEC §15 Behaviour when inactive) and the
 * compatibility declarations (SPEC §15 Headers). WC_VERSION is a constant, so
 * each scenario boots the plugin in its own PHP process.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Data\Cleanup;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Engine\Pruner;
use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class DependencyTest extends TestCase {
	private const PLUGIN_FILE = 'bulk-catalogue-manager-for-woocommerce.php';

	private const NOTICE_TEXT = 'Bulk Catalogue Manager for WooCommerce needs WooCommerce 11.1 or later. Install or update WooCommerce to use it.';

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Boot the plugin in a clean process and return what the probe saw.
	 *
	 * @return array{hooks: array<string, int>, notice: string, features: array<int, array{0: string, 1: string, 2: bool}>, gettext: array<int, array{0: string, 1: string}>, settings: string[], activate: array<int, array{0: string, 1: string[]}>, deactive: array<int, array{0: string, 1: string[]}>}
	 */
	private static function boot( string $wc_version, string $features = 'stub' ): array {
		$output  = [];
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::root() . '/tests/Support/dependency-probe.php' ) . ' ' . escapeshellarg( $wc_version ) . ' ' . escapeshellarg( $features ) . ' 2>&1';
		exec( $command, $output, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs PHP in a clean process.
		$text   = implode( "\n", $output );
		$result = json_decode( $text, true );

		self::assertSame( 0, $exit, $text );
		self::assertTrue( is_array( $result ), $text );
		return $result;
	}

	private static function assert_inactive( array $result ): void {
		self::assertSame(
			[
				'admin_notices'  => 1,
				'plugins_loaded' => 1,
			],
			$result['hooks'],
			'Only the notice may be registered.'
		);
		self::assertSame( '<div class="notice notice-error"><p>' . self::NOTICE_TEXT . '</p></div>', $result['notice'] );
		self::assertSame( [], $result['features'] );
		self::assertSame( [], $result['settings'], 'No setting may be registered without a supported WooCommerce.' );
		self::assertSame( [ [ self::NOTICE_TEXT, 'bulk-catalogue-manager-for-woocommerce' ] ], $result['gettext'] );
	}

	public function test_without_woocommerce_only_the_notice_is_registered(): void {
		self::assert_inactive( self::boot( 'none', 'nostub' ) );
	}

	public function test_with_woocommerce_9_9_only_the_notice_is_registered(): void {
		self::assert_inactive( self::boot( '9.9' ) );
	}

	public function test_below_woocommerce_11_1_only_the_notice_is_registered(): void {
		// Ticket 04's minimum: below 11.1 the Store API can show an unpublished product by id.
		foreach ( [ '10.0', '10.9.4', '11.0', '11.0.9' ] as $version ) {
			self::assert_inactive( self::boot( $version ) );
		}
	}

	public function test_with_woocommerce_11_1_the_plugin_boots_and_declares_compatibility(): void {
		$result = self::boot( '11.1' );
		$file   = realpath( self::root() . '/' . self::PLUGIN_FILE );

		$this->assertSame(
			[
				'admin_action_duplicate_product' => 1,
				'admin_enqueue_scripts'          => 1,
				'admin_init'                     => 1,
				'admin_menu'                     => 1,
				'before_woocommerce_init'        => 1,
				'bulkcat_batch_created'          => 1,
				'bulkcat_prune_history'          => 1,
				'bulkcat_prune_requests'         => 1,
				'bulkcat_prune_selections'       => 1,
				'bulkcat_run_chunk'              => 1,
				'heartbeat_received'             => 1,
				'init'                           => 3,
				'load-post.php'                  => 1,
				'plugins_loaded'                 => 1,
				'rest_api_init'                  => 1,
				'woocommerce_duplicate_product_exclude_meta' => 1,
			],
			$result['hooks']
		);
		$this->assertSame( '', $result['notice'] );
		$this->assertSame( [ 'bulkcat_settings' ], $result['settings'] );
		$this->assertSame(
			[
				[ 'custom_order_tables', $file, true ],
				[ 'cart_checkout_blocks', $file, true ],
			],
			$result['features']
		);
	}

	public function test_lifecycle_hooks_are_registered_against_the_plugin_file_whatever_woocommerce(): void {
		$file = self::root() . '/' . self::PLUGIN_FILE;
		foreach ( [ [ 'none', 'nostub' ], [ '11.0', 'stub' ], [ '11.1', 'stub' ] ] as [ $version, $features ] ) {
			$result = self::boot( $version, $features );
			$this->assertSame(
				[
					[ $file, [ Migrator::class, 'activate' ] ],
					[ $file, [ Pruner::class, 'schedule' ] ],
					[ $file, [ \BulkCatalogueManager\Requests\Cleanup::class, 'schedule' ] ],
					[ $file, [ \BulkCatalogueManager\Engine\ChunkRunner::class, 'resume_interrupted' ] ],
				],
				$result['activate'],
				"WooCommerce $version."
			);
			$this->assertSame( [ [ $file, [ Cleanup::class, 'deactivate' ] ] ], $result['deactive'], "WooCommerce $version." );
		}
	}

	public function test_later_woocommerce_versions_boot(): void {
		foreach ( [ '11.1.0', '11.1.2', '11.2', '12.0' ] as $version ) {
			$this->assertArrayHasKey( 'before_woocommerce_init', self::boot( $version )['hooks'], "WooCommerce $version." );
		}
	}

	public function test_missing_features_util_is_not_fatal(): void {
		$result = self::boot( '11.1', 'nostub' );

		$this->assertSame(
			[
				'admin_action_duplicate_product' => 1,
				'admin_enqueue_scripts'          => 1,
				'admin_init'                     => 1,
				'admin_menu'                     => 1,
				'before_woocommerce_init'        => 1,
				'bulkcat_batch_created'          => 1,
				'bulkcat_prune_history'          => 1,
				'bulkcat_prune_requests'         => 1,
				'bulkcat_prune_selections'       => 1,
				'bulkcat_run_chunk'              => 1,
				'heartbeat_received'             => 1,
				'init'                           => 3,
				'load-post.php'                  => 1,
				'plugins_loaded'                 => 1,
				'rest_api_init'                  => 1,
				'woocommerce_duplicate_product_exclude_meta' => 1,
			],
			$result['hooks']
		);
		$this->assertSame( [], $result['features'] );
	}

	public function test_minimum_version_equals_the_header(): void {
		$source = (string) file_get_contents( self::root() . '/' . self::PLUGIN_FILE );
		$this->assertSame( 1, preg_match( '/^[ \t\/*#@]*WC requires at least:(.*)$/mi', $source, $match ) );
		$this->assertSame( trim( $match[1] ), Plugin::MIN_WC_VERSION );
		$this->assertStringContainsString( 'WooCommerce ' . Plugin::MIN_WC_VERSION . ' or later', self::NOTICE_TEXT );
	}

	public function test_notice_escapes_its_translation(): void {
		$GLOBALS['bulkcat_test']['caps']['activate_plugins']       = true;
		$GLOBALS['bulkcat_test']['translate'][ self::NOTICE_TEXT ] = 'Needs <script>alert(1)</script> & more';

		ob_start();
		( new Plugin() )->render_requirement_notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="notice notice-error"><p>Needs &lt;script&gt;alert(1)&lt;/script&gt; &amp; more</p></div>', $html );
	}

	public function test_notice_is_silent_for_users_who_cannot_manage_plugins(): void {
		ob_start();
		( new Plugin() )->render_requirement_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_recording_stub_stands_in_only_for_an_absent_woocommerce_class(): void {
		$source = (string) file_get_contents( self::root() . '/tests/Support/features-util-stub.php' );
		$this->assertStringContainsString( 'if ( ! class_exists( FeaturesUtil::class ) ) {', $source );
		$this->assertSame( self::root() . '/tests/Support/features-util-stub.php', ( new \ReflectionClass( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) )->getFileName() );
	}
}
