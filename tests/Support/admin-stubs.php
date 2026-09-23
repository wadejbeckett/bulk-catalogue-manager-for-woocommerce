<?php
/**
 * Admin screen stubs for unit tests (T8): the menu, script and style APIs
 * record each call in $GLOBALS['bcm_test']['admin'][ function ][] so a test
 * can assert what was registered and enqueued. The WordPress version comes
 * from $GLOBALS['bcm_test']['wp_version'] (default 7.1), the current user's
 * display name from $GLOBALS['bcm_test']['display_name'], and
 * add_submenu_page() answers false when the current user lacks the
 * capability, as core does. wp_die() throws BcmTestDied. Under WordPress
 * this file defines nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- A test support file: one exception class beside its stubs.

if ( ! class_exists( 'BcmTestDied' ) ) {
	/**
	 * Thrown by the wp_die() stub, carrying its arguments.
	 */
	final class BcmTestDied extends RuntimeException {
		/**
		 * The wp_die() arguments.
		 *
		 * @var array<string, mixed>
		 */
		public array $args;

		/**
		 * Record the call.
		 *
		 * @param string               $message Message.
		 * @param array<string, mixed> $args    Arguments.
		 */
		public function __construct( string $message, array $args ) {
			parent::__construct( $message );
			$this->args = $args;
		}
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Record one call to an admin stub.
	 *
	 * @param string            $name Function name.
	 * @param array<int, mixed> $args Arguments.
	 */
	function bcm_test_admin_call( string $name, array $args ): void {
		$GLOBALS['bcm_test']['admin'][ $name ][] = $args;
	}

	/**
	 * Every recorded call to one admin stub.
	 *
	 * @param string $name Function name.
	 * @return list<array<int, mixed>>
	 */
	function bcm_test_admin_calls( string $name ): array {
		return $GLOBALS['bcm_test']['admin'][ $name ] ?? [];
	}

	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed $position = null ): string|false {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
		if ( ! current_user_can( $capability ) ) {
			return false;
		}
		return 'product_page_' . $menu_slug;
	}

	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $plugin ) ) . '/' . ltrim( $path, '/' );
	}

	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/' ) . '/';
	}

	function wp_register_script( string $handle, string|false $src, array $deps = [], string|bool|null $ver = false, array|bool $args = [] ): bool {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
		return true;
	}

	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, array|bool $args = [] ): void {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
	}

	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all' ): void {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
	}

	function wp_style_add_data( string $handle, string $key, mixed $value ): bool {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
		return true;
	}

	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
		return true;
	}

	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		bcm_test_admin_call( __FUNCTION__, func_get_args() );
		return true;
	}

	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		return 'version' === $show ? (string) ( $GLOBALS['bcm_test']['wp_version'] ?? '7.1' ) : '';
	}

	function wp_get_current_user(): WP_User {
		$user               = new WP_User();
		$user->ID           = get_current_user_id();
		$user->display_name = (string) ( $GLOBALS['bcm_test']['display_name'] ?? '' );
		return $user;
	}

	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function wp_die( mixed $message = '', mixed $title = '', mixed $args = [] ): never {
		throw new BcmTestDied( (string) $message, is_array( $args ) ? $args : [] );
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * The two WP_User properties the screen reads.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub beside BcmTestDied.
	class WP_User {
		/**
		 * User id.
		 *
		 * @var int
		 */
		public $ID = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Core's name.

		/**
		 * Display name.
		 *
		 * @var string
		 */
		public $display_name = '';
	}
}
