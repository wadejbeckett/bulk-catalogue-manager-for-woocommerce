<?php
/**
 * The one admin screen, Products → Bulk Catalogue Manager (SPEC §4, §13,
 * §15): registered here and nowhere else, behind the read capability
 * (`Rest\Access::capability()`: `manage_woocommerce`, `manage_options` under
 * administrators only, filtered by `bcm_capability`), rendering one mount
 * element for the React application built into assets/build. Its script and
 * style load on this screen alone, with the bcm/v1 root and the `wp_rest`
 * nonce localised beside them; the script depends on core's `heartbeat`,
 * through which the nonce is refreshed (Heartbeat). The bundle carries DataViews at the tag pinned
 * for one WordPress major; on any other major the screen shows a notice
 * naming the mismatch instead of mounting.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Admin;

use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Menu entry, capability gate, assets and mount element.
 */
final class Screen {
	/**
	 * The page's slug, as in `edit.php?post_type=product&page=…`.
	 */
	public const SLUG = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * The menu the page sits under: Products.
	 */
	public const PARENT = 'edit.php?post_type=product';

	/**
	 * Handle of the screen's script and style, and the id of its mount element.
	 */
	public const HANDLE = 'bcm-app';

	/**
	 * The @wordpress/dataviews version bundled (package.json pins it exactly).
	 */
	public const DATAVIEWS_VERSION = '12.0.1';

	/**
	 * The npm dist-tag that version is published under: the WordPress release
	 * line it was built for.
	 */
	public const DATAVIEWS_TAG = 'wp-7.0';

	/**
	 * The screen's hook suffix once registered: its screen id.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * The main plugin file, for URLs and paths.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Remember the main plugin file. Calls no WordPress function, so building
	 * the hook list stays side-effect free.
	 *
	 * @param string|null $plugin_file The main plugin file; BCM_FILE when null.
	 */
	public function __construct( ?string $plugin_file = null ) {
		$this->plugin_file = $plugin_file ?? ( defined( 'BCM_FILE' ) ? (string) BCM_FILE : dirname( __DIR__, 2 ) . '/bulk-catalogue-manager-for-woocommerce.php' );
	}

	/**
	 * The plugin's directory, with a trailing slash.
	 */
	private function plugin_dir(): string {
		return plugin_dir_path( $this->plugin_file );
	}

	/**
	 * The WordPress major version the bundled DataViews was built for.
	 */
	public static function supported_major(): int {
		return (int) substr( self::DATAVIEWS_TAG, 3 );
	}

	/**
	 * The host's WordPress major version: the number before the first dot.
	 */
	public static function host_major(): int {
		return (int) strtok( self::host_version(), '.' );
	}

	/**
	 * The host's WordPress version as it reports it.
	 */
	public static function host_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Whether the host runs the WordPress major the bundle was built for.
	 */
	public static function host_supported(): bool {
		return self::host_major() === self::supported_major();
	}

	/**
	 * Add Products → Bulk Catalogue Manager, once, for users with the read capability.
	 */
	public function register(): void {
		if ( '' !== $this->hook_suffix ) {
			return;
		}
		$hook              = add_submenu_page(
			self::PARENT,
			__( 'Bulk Catalogue Manager', 'bulk-catalogue-manager-for-woocommerce' ),
			__( 'Bulk Catalogue Manager', 'bulk-catalogue-manager-for-woocommerce' ),
			Access::capability(),
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * The screen id WordPress gave the page, or '' before register() or for a user without access.
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Load the screen's script, translations, settings and style on this screen only.
	 *
	 * @param string $hook_suffix The admin page being loaded.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		if ( ! self::host_supported() || ! current_user_can( Access::capability() ) ) {
			return;
		}
		$asset = $this->asset();
		if ( null === $asset ) {
			return;
		}
		$dir = $this->plugin_dir();

		wp_register_script( 'bcm-app', plugins_url( 'assets/build/index.js', $this->plugin_file ), self::dependencies( $asset['dependencies'] ), $asset['version'], [ 'in_footer' => true ] );
		wp_set_script_translations( 'bcm-app', 'bulk-catalogue-manager-for-woocommerce', $dir . 'languages' );
		wp_add_inline_script( 'bcm-app', 'window.bcmSettings = ' . wp_json_encode( $this->client_settings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		wp_enqueue_script( 'bcm-app' );

		wp_enqueue_style( 'bcm-app', plugins_url( 'assets/build/style-index.css', $this->plugin_file ), [], $asset['version'] );
		wp_style_add_data( 'bcm-app', 'rtl', 'replace' );
	}

	/**
	 * The script's dependencies: the build's list plus core's Heartbeat API,
	 * which refreshes the REST nonce (SPEC §13; see Heartbeat).
	 *
	 * @param list<string> $build The build's dependency list.
	 * @return list<string>
	 */
	public static function dependencies( array $build ): array {
		return array_values( array_unique( [ ...$build, Heartbeat::SCRIPT ] ) );
	}

	/**
	 * The capabilities the screen tells the client about, so a panel can hide
	 * what the current user may not do instead of failing on submit. Access is
	 * still decided by every route on the server.
	 */
	public const CLIENT_CAPABILITIES = [
		'manage_woocommerce',
		Access::WRITE_CAPABILITY,
		Access::TERMS_CAPABILITY,
		Access::SETTINGS_CAPABILITY,
	];

	/**
	 * What the application is given at page load: the bcm/v1 root and nonce,
	 * the settings, the current user with the capabilities in
	 * CLIENT_CAPABILITIES (each true or false), and the host's versions.
	 *
	 * @return array<string, mixed>
	 */
	public function client_settings(): array {
		$user   = wp_get_current_user();
		$client = Routes::client_settings();
		return [
			'root'           => $client['root'],
			'nonce'          => $client['nonce'],
			'settings'       => Settings::load()->values(),
			'fieldsEndpoint' => $client['root'] . 'fields',
			'user'           => [
				'id'           => (int) $user->ID,
				'name'         => (string) $user->display_name,
				'capabilities' => array_combine(
					self::CLIENT_CAPABILITIES,
					array_map( static fn( string $capability ): bool => current_user_can( $capability ), self::CLIENT_CAPABILITIES )
				),
			],
			'wpMajor'        => self::host_major(),
			'dataviews'      => self::DATAVIEWS_VERSION,
		];
	}

	/**
	 * The page: its heading and the mount element, or a notice saying why it cannot mount.
	 */
	public function render(): void {
		if ( ! current_user_can( Access::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Bulk Catalogue Manager.', 'bulk-catalogue-manager-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Bulk Catalogue Manager', 'bulk-catalogue-manager-for-woocommerce' ) );
		echo '<hr class="wp-header-end">';
		if ( ! self::host_supported() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: the WordPress major version this copy of the plugin was built for, such as 7; 2: this site's WordPress version, such as 8.0. */
						__( 'This copy of Bulk Catalogue Manager was built for WordPress %1$s, but this site runs WordPress %2$s, so the screen cannot open. Update the plugin to a version made for this WordPress.', 'bulk-catalogue-manager-for-woocommerce' ),
						(string) self::supported_major(),
						self::host_version()
					)
				)
			);
		} elseif ( null === $this->asset() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'This copy of Bulk Catalogue Manager is missing its screen files. Reinstall the plugin.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		} else {
			printf( '<div id="%s"></div>', esc_attr( self::HANDLE ) );
		}
		echo '</div>';
	}

	/**
	 * The build's dependency list and version (assets/build/index.asset.php), or null when it is missing.
	 *
	 * @return array{dependencies: list<string>, version: string}|null
	 */
	private function asset(): ?array {
		$file = $this->plugin_dir() . 'assets/build/index.asset.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$asset = require $file;
		if ( ! is_array( $asset ) ) {
			return null;
		}
		return [
			'dependencies' => array_values( array_filter( (array) ( $asset['dependencies'] ?? [] ), 'is_string' ) ),
			'version'      => (string) ( $asset['version'] ?? '' ),
		];
	}
}
