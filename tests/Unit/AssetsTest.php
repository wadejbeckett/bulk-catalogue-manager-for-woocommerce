<?php
/**
 * The admin screen and its assets (T8; SPEC §4, §12.5, §13, §15): one menu
 * entry under Products behind the read capability, the script and style on
 * that screen id and on no other, translations from languages/, the
 * localised root and nonce, a notice instead of the mount element on a
 * WordPress major the bundled DataViews was not built for, and the pinned
 * DataViews version matching package.json, its lock file and the README.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Admin\Screen;
use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase {
	private const SCREEN_ID = 'product_page_bulk-catalogue-manager-for-woocommerce';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = 7;
		self::grant( [ 'manage_woocommerce' ] );
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Give the current user these capabilities only.
	 *
	 * @param list<string> $caps Capabilities.
	 */
	private static function grant( array $caps ): void {
		$GLOBALS['bulkcat_test']['caps'] = array_fill_keys( $caps, true );
	}

	private static function screen(): Screen {
		return new Screen( self::root() . '/bulk-catalogue-manager-for-woocommerce.php' );
	}

	private static function registered(): Screen {
		$screen = self::screen();
		$screen->register();
		return $screen;
	}

	private static function rendered( Screen $screen ): string {
		ob_start();
		try {
			$screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_the_screen_is_registered_once_under_products_with_the_read_capability(): void {
		$screen = self::registered();
		$screen->register();

		$calls = bulkcat_test_admin_calls( 'add_submenu_page' );
		$this->assertCount( 1, $calls, 'A second register() adds nothing.' );
		[ $parent, $page_title, $menu_title, $capability, $slug, $callback ] = $calls[0];
		$this->assertSame( 'edit.php?post_type=product', $parent );
		$this->assertSame( 'Bulk Catalogue Manager', $page_title );
		$this->assertSame( 'Bulk Catalogue Manager', $menu_title );
		$this->assertSame( 'manage_woocommerce', $capability );
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', $slug );
		$this->assertSame( [ $screen, 'render' ], $callback );
		$this->assertSame( self::SCREEN_ID, $screen->hook_suffix() );
	}

	public function test_add_submenu_page_is_called_nowhere_else(): void {
		$tree  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/src', \FilesystemIterator::SKIP_DOTS ) );
		$found = [];
		foreach ( $tree as $file ) {
			if ( 'php' === $file->getExtension() && preg_match( '/\b(add_submenu_page|add_menu_page|add_management_page|add_options_page)\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$found[] = substr( $file->getPathname(), strlen( self::root() ) + 1 );
			}
		}
		$this->assertSame( [ 'src/Admin/Screen.php' ], $found );
	}

	public function test_administrators_only_needs_manage_options_and_the_filter_has_the_last_word(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		self::grant( [ 'manage_woocommerce', 'manage_options' ] );
		self::registered();
		$this->assertSame( 'manage_options', bulkcat_test_admin_calls( 'add_submenu_page' )[0][3] );

		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		self::grant( [ 'edit_products' ] );
		add_filter( 'bulkcat_capability', static fn(): string => 'edit_products' );
		$screen = self::registered();
		$this->assertSame( 'edit_products', bulkcat_test_admin_calls( 'add_submenu_page' )[0][3] );
		$this->assertSame( self::SCREEN_ID, $screen->hook_suffix() );
	}

	public function test_a_shop_manager_is_shut_out_under_administrators_only(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$screen = self::registered();
		$this->assertSame( '', $screen->hook_suffix(), 'Core adds no page for a user without the capability.' );

		$screen->enqueue( self::SCREEN_ID );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_enqueue_script' ) );
	}

	public function test_render_refuses_anyone_without_the_capability(): void {
		$screen = self::registered();
		self::grant( [ 'read' ] );
		try {
			self::rendered( $screen );
			$this->fail( 'render() must wp_die for a user without the capability.' );
		} catch ( \BulkcatTestDied $died ) {
			$this->assertSame( 403, $died->args['response'] ?? null );
			$this->assertStringContainsString( 'not allowed', $died->getMessage() );
		}
	}

	public function test_the_page_renders_one_mount_element(): void {
		$html = self::rendered( self::registered() );
		$this->assertSame( 1, substr_count( $html, 'id="bulkcat-app"' ) );
		$this->assertStringContainsString( '<div id="bulkcat-app"></div>', $html );
		$this->assertStringContainsString( '<h1>Bulk Catalogue Manager</h1>', $html );
		$this->assertStringNotContainsString( 'notice', $html );
	}

	public function test_script_style_translations_and_settings_load_on_this_screen(): void {
		$screen = self::registered();
		$screen->enqueue( self::SCREEN_ID );

		$scripts = bulkcat_test_admin_calls( 'wp_register_script' );
		$this->assertCount( 1, $scripts );
		[ $handle, $src, $deps, $version, $args ] = $scripts[0];
		$this->assertSame( 'bulkcat-app', $handle );
		$this->assertStringEndsWith( '/' . basename( self::root() ) . '/assets/build/index.js', $src, 'The plugin directory, whatever the checkout is called.' );
		$asset = require self::root() . '/assets/build/index.asset.php';
		$this->assertSame( [ ...$asset['dependencies'], 'heartbeat' ], $deps, 'The build\'s dependencies plus core\'s Heartbeat API (T39).' );
		$this->assertSame( $asset['version'], $version );
		$this->assertSame( [ 'in_footer' => true ], $args );
		$this->assertSame( [ [ 'bulkcat-app' ] ], bulkcat_test_admin_calls( 'wp_enqueue_script' ) );

		$this->assertSame(
			[ [ 'bulkcat-app', 'bulk-catalogue-manager-for-woocommerce', self::root() . '/languages' ] ],
			bulkcat_test_admin_calls( 'wp_set_script_translations' )
		);

		$inline = bulkcat_test_admin_calls( 'wp_add_inline_script' );
		$this->assertCount( 1, $inline );
		$this->assertSame( 'bulkcat-app', $inline[0][0] );
		$this->assertSame( 'before', $inline[0][2] );
		$this->assertStringStartsWith( 'window.bulkcatSettings = ', $inline[0][1] );
		$settings = json_decode( substr( rtrim( $inline[0][1], ';' ), strlen( 'window.bulkcatSettings = ' ) ), true );
		$this->assertSame( 'https://example.test/wp-json/bulkcat/v1/', $settings['root'] );
		$this->assertSame( 'nonce-wp_rest', $settings['nonce'] );
		$this->assertSame( 'https://example.test/wp-json/bulkcat/v1/fields', $settings['fieldsEndpoint'] );
		$this->assertSame( 7, $settings['user']['id'] );
		$this->assertSame( 7, $settings['wpMajor'] );
		$this->assertSame( Screen::DATAVIEWS_VERSION, $settings['dataviews'] );
		$this->assertArrayNotHasKey( 'undoLimit', $settings, 'T111: undo is streamed, so the screen promises it for any change a selection can hold.' );
		$this->assertSame( 25, $settings['settings']['rows_per_page'] );

		$styles = bulkcat_test_admin_calls( 'wp_enqueue_style' );
		$this->assertCount( 1, $styles );
		$this->assertSame( 'bulkcat-app', $styles[0][0] );
		$this->assertStringEndsWith( '/assets/build/style-index.css', $styles[0][1] );
		$this->assertSame( [ [ 'bulkcat-app', 'rtl', 'replace' ] ], bulkcat_test_admin_calls( 'wp_style_add_data' ) );
	}

	public function test_the_client_is_told_the_current_users_capabilities(): void {
		self::grant( [ 'manage_woocommerce', 'edit_products', 'manage_product_terms' ] );
		$caps = self::registered()->client_settings()['user']['capabilities'];
		$this->assertSame(
			[
				'manage_woocommerce'   => true,
				'edit_products'        => true,
				'manage_product_terms' => true,
				'manage_options'       => false,
			],
			$caps,
			'A shop manager may manage categories but not the settings.'
		);

		self::grant( [ 'manage_woocommerce', 'manage_options' ] );
		$caps = self::registered()->client_settings()['user']['capabilities'];
		$this->assertTrue( $caps['manage_options'] );
		$this->assertFalse( $caps['manage_product_terms'] );
	}

	public function test_the_settings_cannot_close_the_inline_script(): void {
		$GLOBALS['bulkcat_test']['display_name'] = '</script><script>alert(1)</script>';
		$screen                                  = self::registered();
		$screen->enqueue( self::SCREEN_ID );
		$this->assertStringNotContainsString( '</script>', bulkcat_test_admin_calls( 'wp_add_inline_script' )[0][1] );
	}

	public function test_nothing_loads_on_any_other_admin_screen(): void {
		$screen = self::registered();
		foreach ( [ '', 'index.php', 'edit.php', 'post.php', 'product_page_product_attributes', 'woocommerce_page_wc-settings', 'toplevel_page_woocommerce', 'product_page_bulk-catalogue-manager', 'plugins.php' ] as $other ) {
			$screen->enqueue( $other );
		}
		foreach ( [ 'wp_register_script', 'wp_enqueue_script', 'wp_enqueue_style', 'wp_set_script_translations', 'wp_add_inline_script' ] as $function ) {
			$this->assertSame( [], bulkcat_test_admin_calls( $function ), "$function must not run off the screen." );
		}
	}

	public function test_nothing_loads_before_the_screen_is_registered(): void {
		self::screen()->enqueue( self::SCREEN_ID );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_enqueue_script' ) );
	}

	public function test_a_mismatched_wordpress_major_shows_a_notice_instead_of_mounting(): void {
		$GLOBALS['bulkcat_test']['wp_version'] = '8.0';
		$screen                                = self::registered();
		$screen->enqueue( self::SCREEN_ID );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_enqueue_script' ), 'No script on a mismatched host.' );
		$this->assertSame( [], bulkcat_test_admin_calls( 'wp_enqueue_style' ) );

		$html = self::rendered( $screen );
		$this->assertStringNotContainsString( 'bulkcat-app', $html );
		$this->assertStringContainsString( 'notice notice-error', $html );
		$this->assertStringContainsString( 'built for WordPress 7, but this site runs WordPress 8.0', $html );
	}

	public function test_every_wordpress_7_release_matches_the_pinned_tag(): void {
		foreach ( [ '7.0', '7.0.2', '7.1', '7.1-RC1' ] as $version ) {
			$GLOBALS['bulkcat_test']['wp_version'] = $version;
			$this->assertTrue( Screen::host_supported(), "WordPress $version runs the wp-7.0 DataViews." );
		}
		foreach ( [ '6.9', '8.0' ] as $version ) {
			$GLOBALS['bulkcat_test']['wp_version'] = $version;
			$this->assertFalse( Screen::host_supported(), "WordPress $version does not." );
		}
	}

	public function test_the_pinned_dataviews_is_the_one_installed_and_recorded(): void {
		$package = json_decode( (string) file_get_contents( self::root() . '/package.json' ), true );
		$this->assertSame( Screen::DATAVIEWS_VERSION, $package['dependencies']['@wordpress/dataviews'] ?? null, 'package.json pins DataViews exactly.' );
		$lock = json_decode( (string) file_get_contents( self::root() . '/package-lock.json' ), true );
		$this->assertSame( Screen::DATAVIEWS_VERSION, $lock['packages']['node_modules/@wordpress/dataviews']['version'] ?? null, 'The lock file installs it.' );
		$readme = (string) file_get_contents( self::root() . '/README.md' );
		$this->assertStringContainsString( '@wordpress/dataviews` ' . Screen::DATAVIEWS_VERSION, $readme );
		$this->assertStringContainsString( Screen::DATAVIEWS_TAG, $readme );
		$this->assertSame( 7, Screen::supported_major() );
	}

	public function test_the_build_loads_no_woocommerce_admin_script_and_fits_the_budget(): void {
		$asset = require self::root() . '/assets/build/index.asset.php';
		foreach ( $asset['dependencies'] as $handle ) {
			$this->assertFalse( str_starts_with( $handle, 'wc-' ) || str_starts_with( $handle, 'woocommerce' ), "$handle is WooCommerce's admin framework." );
		}
		$this->assertNotContains( 'wp-components', $asset['dependencies'], 'Components are bundled with DataViews, never the host copy.' );
		$compressed = strlen( (string) gzencode( (string) file_get_contents( self::root() . '/assets/build/index.js' ) ) );
		$this->assertLessThan( 400 * 1024, $compressed, 'SPEC §12.5: built JavaScript under 400 KB compressed.' );
	}

	public function test_the_plugin_hooks_the_screen(): void {
		$plugin = new Plugin();
		$hooks  = $plugin->hooks();
		$this->assertContains( [ 'admin_menu', [ $plugin->screen(), 'register' ], 10, 0 ], $hooks );
		$this->assertContains( [ 'admin_enqueue_scripts', [ $plugin->screen(), 'enqueue' ], 10, 1 ], $hooks );
		$this->assertSame( $plugin->screen(), $plugin->screen(), 'Menu and assets share one screen.' );
	}
}
