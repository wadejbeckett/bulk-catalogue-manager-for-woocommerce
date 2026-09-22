<?php
/**
 * WordPress function stubs for unit tests. Under WordPress this file defines nothing.
 * The stubs record hooks and options in $GLOBALS['bcm_test'] so tests can inspect them.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! function_exists( 'add_filter' ) ) {
	function bcm_test_state(): array {
		return [
			'filters'     => [],
			'options'     => [],
			'did_actions' => [],
			'caps'        => [],
			'gettext'     => [],
			'translate'   => [],
			'features'    => [],
			'settings'    => [],
			'errors'      => [],
		];
	}

	$GLOBALS['bcm_test'] = bcm_test_state();

	function bcm_test_reset(): void {
		$GLOBALS['bcm_test'] = bcm_test_state();
	}

	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['bcm_test']['filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
		return true;
	}

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$entries = $GLOBALS['bcm_test']['filters'][ $hook ] ?? [];
		usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
		foreach ( $entries as [ $callback, , $accepted ] ) {
			$value = $callback( ...array_slice( [ $value, ...$args ], 0, max( 1, $accepted ) ) );
		}
		return $value;
	}

	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['bcm_test']['did_actions'][ $hook ] = ( $GLOBALS['bcm_test']['did_actions'][ $hook ] ?? 0 ) + 1;
		$entries                                     = $GLOBALS['bcm_test']['filters'][ $hook ] ?? [];
		usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
		foreach ( $entries as [ $callback, , $accepted ] ) {
			$callback( ...array_slice( $args, 0, $accepted ) );
		}
	}

	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['bcm_test']['did_actions'][ $hook ] ?? 0 );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['bcm_test']['options'][ $name ] ?? $default;
	}

	/**
	 * Runs the sanitize_option_{$name} filter first, as WordPress's sanitize_option() does.
	 */
	function update_option( string $name, mixed $value ): bool {
		$GLOBALS['bcm_test']['options'][ $name ] = apply_filters( "sanitize_option_{$name}", $value, $name, $value );
		return true;
	}

	/**
	 * Records the arguments and hooks the sanitiser the way WordPress's register_setting() does.
	 */
	function register_setting( string $option_group, string $option_name, array $args = [] ): void {
		$GLOBALS['bcm_test']['settings'][ $option_name ] = [ 'group' => $option_group ] + $args;
		if ( isset( $args['sanitize_callback'] ) && is_callable( $args['sanitize_callback'] ) ) {
			add_filter( "sanitize_option_{$option_name}", $args['sanitize_callback'] );
		}
	}

	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['bcm_test']['errors'][] = [ $setting, $code, $message, $type ];
	}

	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['bcm_test']['options'][ $name ] );
		return true;
	}

	/**
	 * Records the text domain of every lookup; returns the translation set in
	 * $GLOBALS['bcm_test']['translate'], or the text itself.
	 */
	function __( string $text, string $domain = 'default' ): string {
		$GLOBALS['bcm_test']['gettext'][] = [ $text, $domain ];
		return $GLOBALS['bcm_test']['translate'][ $text ] ?? $text;
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function current_user_can( string $capability, mixed ...$args ): bool {
		return (bool) ( $GLOBALS['bcm_test']['caps'][ $capability ] ?? false );
	}
}
