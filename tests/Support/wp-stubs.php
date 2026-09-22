<?php
/**
 * WordPress function stubs for unit tests. Under WordPress this file defines nothing.
 * The stubs record hooks, options, deletions, dbDelta() statements and
 * unscheduled actions in $GLOBALS['bcm_test'] so tests can inspect them, and
 * bcm_test_reset() puts a fresh recording $wpdb in $GLOBALS['wpdb'].
 * Defining BCM_TEST_NO_ACTION_SCHEDULER before loading leaves the
 * Action Scheduler functions undefined, as on a site without it. REST routes,
 * login state, existing terms and SKUs in use are set and read in the same
 * global; the REST classes are in rest-stubs.php.
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
			'deleted'     => [],
			'unscheduled' => [],
			'dbdelta'     => [],
			'dbdelta_ok'  => true,
			'activation'  => [],
			'deactivate'  => [],
			'routes'      => [],
			'logged_in'   => false,
			'terms'       => [],
			'skus'        => [],
			'decimals'    => 2,
			'separator'   => '.',
		];
	}

	$GLOBALS['bcm_test'] = bcm_test_state();

	function bcm_test_reset(): void {
		$GLOBALS['bcm_test'] = bcm_test_state();
		if ( class_exists( \BulkCatalogueManager\Tests\Support\WpdbStub::class ) ) {
			$GLOBALS['wpdb'] = new \BulkCatalogueManager\Tests\Support\WpdbStub();
		}
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
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
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
		$GLOBALS['bcm_test']['deleted'][] = [ 'option', $name ];
		unset( $GLOBALS['bcm_test']['options'][ $name ] );
		return true;
	}

	function delete_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		$GLOBALS['bcm_test']['deleted'][] = [ 'metadata', $meta_type, $object_id, $meta_key, $meta_value, $delete_all ];
		return true;
	}

	function delete_transient( string $transient ): bool {
		$GLOBALS['bcm_test']['deleted'][] = [ 'transient', $transient ];
		return true;
	}

	function delete_site_transient( string $transient ): bool {
		$GLOBALS['bcm_test']['deleted'][] = [ 'site_transient', $transient ];
		return true;
	}

	function register_activation_hook( string $file, mixed $callback ): void {
		$GLOBALS['bcm_test']['activation'][] = [ $file, $callback ];
	}

	function register_deactivation_hook( string $file, mixed $callback ): void {
		$GLOBALS['bcm_test']['deactivate'][] = [ $file, $callback ];
	}

	/**
	 * Records the statements. Unless $GLOBALS['bcm_test']['dbdelta_ok'] is
	 * false, each CREATE TABLE creates its table in the stub $wpdb.
	 *
	 * @param string[]|string $queries Statements.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress's own name.
	function dbDelta( array|string $queries = '', bool $execute = true ): array {
		$queries                          = (array) $queries;
		$GLOBALS['bcm_test']['dbdelta'][] = $queries;
		foreach ( $queries as $query ) {
			if ( $GLOBALS['bcm_test']['dbdelta_ok'] && isset( $GLOBALS['wpdb'] ) && 1 === preg_match( '/^CREATE TABLE (\S+) \(/', $query, $match ) ) {
				$GLOBALS['wpdb']->tables[ $match[1] ] = true;
			}
		}
		return [];
	}

	if ( ! defined( 'BCM_TEST_NO_ACTION_SCHEDULER' ) ) {
		function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
			$GLOBALS['bcm_test']['unscheduled'][] = [ $hook, $args, $group ];
		}
	}

	/**
	 * Records the text domain of every lookup; returns the translation set in
	 * $GLOBALS['bcm_test']['translate'], or the text itself.
	 */
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}

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

	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['bcm_test']['logged_in'];
	}

	/**
	 * Records the route under "namespace/route" as WordPress keys it, with each
	 * endpoint's arguments. Refuses a missing permission_callback, as WordPress
	 * 5.5+ warns.
	 */
	function register_rest_route( string $route_namespace, string $route, array $args = [], bool $override = false ): bool {
		$endpoints = isset( $args['methods'] ) || isset( $args['callback'] ) ? [ $args ] : array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY );
		foreach ( $endpoints as $endpoint ) {
			if ( ! array_key_exists( 'permission_callback', $endpoint ) ) {
				return false;
			}
		}
		$GLOBALS['bcm_test']['routes'][ '/' . trim( $route_namespace, '/' ) . '/' . ltrim( $route, '/' ) ] = $args;
		return true;
	}

	function rest_authorization_required_code(): int {
		return is_user_logged_in() ? 403 : 401;
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function rest_ensure_response( mixed $response ): mixed {
		return $response instanceof WP_REST_Response || $response instanceof WP_Error ? $response : new WP_REST_Response( $response );
	}

	function sanitize_text_field( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $text ) ) );
	}

	function wp_kses_post( string $text ): string {
		return strip_tags( $text, '<a><strong><em><b><i><br><span>' );
	}

	/**
	 * Whether a term id exists in a taxonomy, from $GLOBALS['bcm_test']['terms'][ taxonomy ].
	 */
	function term_exists( int|string $term, string $taxonomy = '', ?int $parent_term = null ): mixed {
		return in_array( (int) $term, $GLOBALS['bcm_test']['terms'][ $taxonomy ] ?? [], true ) ? [ 'term_id' => (int) $term ] : null;
	}

	/**
	 * WooCommerce's rounding when given decimal places; otherwise the number as given.
	 */
	function wc_format_decimal( mixed $number, mixed $dp = false, bool $trim_zeros = false ): string {
		$number = (string) $number;
		if ( '' !== $number && is_numeric( $dp ) && $dp >= 0 ) {
			$number = number_format( (float) $number, (int) $dp, '.', '' );
		}
		if ( $trim_zeros && str_contains( $number, '.' ) ) {
			$number = rtrim( rtrim( $number, '0' ), '.' );
		}
		return $number;
	}

	function wc_get_price_decimals(): int {
		return (int) $GLOBALS['bcm_test']['decimals'];
	}

	function wc_get_price_decimal_separator(): string {
		return (string) $GLOBALS['bcm_test']['separator'];
	}

	/**
	 * False when another object id holds the SKU in $GLOBALS['bcm_test']['skus'] (sku => id).
	 */
	function wc_product_has_unique_sku( int $product_id, string $sku ): bool {
		$holder = $GLOBALS['bcm_test']['skus'][ $sku ] ?? null;
		return null === $holder || $holder === $product_id;
	}
}
