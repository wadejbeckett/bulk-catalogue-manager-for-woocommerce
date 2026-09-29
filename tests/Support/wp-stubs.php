<?php
/**
 * WordPress function stubs for unit tests. Under WordPress this file defines nothing.
 * The stubs record hooks, options, deletions, dbDelta() statements and
 * unscheduled actions in $GLOBALS['bulkcat_test'] so tests can inspect them, and
 * bulkcat_test_reset() puts a fresh recording $wpdb in $GLOBALS['wpdb'].
 * Defining BULKCAT_TEST_NO_ACTION_SCHEDULER before loading leaves the
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
	function bulkcat_test_state(): array {
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
			'namespaces'  => [],
			'wrong'       => [],
			'nonces'      => [],
			'user_caps'   => [],
			'logged_in'   => false,
			'terms'       => [],
			'skus'        => [],
			'decimals'    => 2,
			'separator'   => '.',
		];
	}

	$GLOBALS['bulkcat_test'] = bulkcat_test_state();

	function bulkcat_test_reset(): void {
		$GLOBALS['bulkcat_test'] = bulkcat_test_state();
		if ( class_exists( \BulkCatalogueManager\Tests\Support\WpdbStub::class ) ) {
			$GLOBALS['wpdb'] = new \BulkCatalogueManager\Tests\Support\WpdbStub();
		}
	}

	/**
	 * Multisite: off unless $GLOBALS['bulkcat_test']['multisite'] is set; the site
	 * ids come from $GLOBALS['bulkcat_test']['sites']. switch_to_blog() and
	 * restore_current_blog() record themselves in `switched`, move
	 * $wpdb->prefix as WordPress does (site 1 keeps the base prefix) and swap
	 * in each site's own options from `site_options`.
	 */
	function is_multisite(): bool {
		return true === ( $GLOBALS['bulkcat_test']['multisite'] ?? false );
	}

	function get_sites( array $args = [] ): array {
		$GLOBALS['bulkcat_test']['get_sites_args'][] = $args;
		return $GLOBALS['bulkcat_test']['sites'] ?? [];
	}

	function switch_to_blog( int $site_id ): bool {
		$state                             = &$GLOBALS['bulkcat_test'];
		$current                           = $state['site'] ?? 1;
		$state['switched'][]               = [ 'switch', $site_id ];
		$state['site_stack'][]             = $current;
		$state['site_options'][ $current ] = $state['options'];
		$state['options']                  = $state['site_options'][ $site_id ] ?? [];
		$state['site']                     = $site_id;
		$GLOBALS['wpdb']->prefix           = 1 === $site_id ? 'wp_' : 'wp_' . $site_id . '_';
		return true;
	}

	function restore_current_blog(): bool {
		$state                                   = &$GLOBALS['bulkcat_test'];
		$previous                                = array_pop( $state['site_stack'] ) ?? 1;
		$state['switched'][]                     = [ 'restore' ];
		$state['site_options'][ $state['site'] ] = $state['options'];
		$state['options']                        = $state['site_options'][ $previous ] ?? [];
		$state['site']                           = $previous;
		$GLOBALS['wpdb']->prefix                 = 1 === $previous ? 'wp_' : 'wp_' . $previous . '_';
		return true;
	}

	/**
	 * The product taxonomies with the capabilities WooCommerce registers them
	 * with; any other taxonomy is unknown (false).
	 */
	function get_taxonomy( string $taxonomy ): object|false {
		if ( ! in_array( $taxonomy, [ 'product_cat', 'product_tag', 'product_shipping_class' ], true ) ) {
			return false;
		}
		return (object) [
			'name' => $taxonomy,
			'cap'  => (object) [
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			],
		];
	}

	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['bulkcat_test']['filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
		return true;
	}

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$entries = $GLOBALS['bulkcat_test']['filters'][ $hook ] ?? [];
		usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
		foreach ( $entries as [ $callback, , $accepted ] ) {
			$value = $callback( ...array_slice( [ $value, ...$args ], 0, max( 1, $accepted ) ) );
		}
		return $value;
	}

	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['bulkcat_test']['did_actions'][ $hook ] = ( $GLOBALS['bulkcat_test']['did_actions'][ $hook ] ?? 0 ) + 1;
		$entries = $GLOBALS['bulkcat_test']['filters'][ $hook ] ?? [];
		usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
		foreach ( $entries as [ $callback, , $accepted ] ) {
			$callback( ...array_slice( $args, 0, $accepted ) );
		}
	}

	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['bulkcat_test']['did_actions'][ $hook ] ?? 0 );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['bulkcat_test']['options'][ $name ] ?? $default;
	}

	/**
	 * Runs the sanitize_option_{$name} filter first, as WordPress's sanitize_option() does.
	 */
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['bulkcat_test']['options'][ $name ] = apply_filters( "sanitize_option_{$name}", $value, $name, $value );
		return true;
	}

	/**
	 * Records the arguments and hooks the sanitiser the way WordPress's register_setting() does.
	 */
	function register_setting( string $option_group, string $option_name, array $args = [] ): void {
		$GLOBALS['bulkcat_test']['settings'][ $option_name ] = [ 'group' => $option_group ] + $args;
		if ( isset( $args['sanitize_callback'] ) && is_callable( $args['sanitize_callback'] ) ) {
			add_filter( "sanitize_option_{$option_name}", $args['sanitize_callback'] );
		}
	}

	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['bulkcat_test']['errors'][] = [ $setting, $code, $message, $type ];
	}

	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	function delete_option( string $name ): bool {
		$GLOBALS['bulkcat_test']['deleted'][] = [ 'option', $name ];
		unset( $GLOBALS['bulkcat_test']['options'][ $name ] );
		return true;
	}

	function delete_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		$GLOBALS['bulkcat_test']['deleted'][] = [ 'metadata', $meta_type, $object_id, $meta_key, $meta_value, $delete_all ];
		return true;
	}

	function delete_transient( string $transient ): bool {
		$GLOBALS['bulkcat_test']['deleted'][] = [ 'transient', $transient ];
		return true;
	}

	function delete_site_transient( string $transient ): bool {
		$GLOBALS['bulkcat_test']['deleted'][] = [ 'site_transient', $transient ];
		return true;
	}

	function register_activation_hook( string $file, mixed $callback ): void {
		$GLOBALS['bulkcat_test']['activation'][] = [ $file, $callback ];
	}

	function register_deactivation_hook( string $file, mixed $callback ): void {
		$GLOBALS['bulkcat_test']['deactivate'][] = [ $file, $callback ];
	}

	/**
	 * Records the statements. Unless $GLOBALS['bulkcat_test']['dbdelta_ok'] is
	 * false, each CREATE TABLE creates its table in the stub $wpdb.
	 *
	 * @param string[]|string $queries Statements.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress's own name.
	function dbDelta( array|string $queries = '', bool $execute = true ): array {
		$queries                              = (array) $queries;
		$GLOBALS['bulkcat_test']['dbdelta'][] = $queries;
		foreach ( $queries as $query ) {
			if ( $GLOBALS['bulkcat_test']['dbdelta_ok'] && isset( $GLOBALS['wpdb'] ) && 1 === preg_match( '/^CREATE TABLE (\S+) \(/', $query, $match ) ) {
				$GLOBALS['wpdb']->tables[ $match[1] ] = true;
			}
		}
		return [];
	}

	if ( ! defined( 'BULKCAT_TEST_NO_ACTION_SCHEDULER' ) ) {
		function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
			$GLOBALS['bulkcat_test']['unscheduled'][] = [ $hook, $args, $group ];
		}

		/**
		 * T34: the scheduling calls are recorded in order in
		 * $GLOBALS['bulkcat_test']['scheduled'] as [ function, hook, args, group, timestamp or null ].
		 */
		function as_enqueue_async_action( string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
			$GLOBALS['bulkcat_test']['scheduled'][] = [ 'enqueue', $hook, $args, $group, null ];
			return count( $GLOBALS['bulkcat_test']['scheduled'] );
		}

		/**
		 * T83: whether an enqueued or single action with this hook (and args
		 * and group, when given) was recorded and not unscheduled since.
		 */
		function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
			$found = false;
			foreach ( $GLOBALS['bulkcat_test']['scheduled'] ?? [] as [ $kind, $entry_hook, $entry_args, $entry_group ] ) {
				if ( $hook !== $entry_hook || ( null !== $args && $args !== $entry_args ) || ( '' !== $group && $group !== $entry_group ) ) {
					continue;
				}
				$found = in_array( $kind, [ 'enqueue', 'single' ], true ) ? true : ( 'unschedule' === $kind ? false : $found );
			}
			return $found;
		}

		function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
			$GLOBALS['bulkcat_test']['scheduled'][] = [ 'single', $hook, $args, $group, $timestamp ];
			return count( $GLOBALS['bulkcat_test']['scheduled'] );
		}

		function as_unschedule_action( string $hook, array $args = [], string $group = '' ): ?int {
			$GLOBALS['bulkcat_test']['scheduled'][] = [ 'unschedule', $hook, $args, $group, null ];
			return null;
		}
	}

	/**
	 * Records the text domain of every lookup; returns the translation set in
	 * $GLOBALS['bulkcat_test']['translate'], or the text itself.
	 */
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}

	function __( string $text, string $domain = 'default' ): string {
		$GLOBALS['bulkcat_test']['gettext'][] = [ $text, $domain ];
		return $GLOBALS['bulkcat_test']['translate'][ $text ] ?? $text;
	}

	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		$GLOBALS['bulkcat_test']['gettext'][] = [ $single, $domain ];
		return 1 === $number ? $single : $plural;
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( __( $text, $domain ) ); // phpcs:ignore WordPress.WP.I18n -- The stub passes its caller's literals through.
	}

	/**
	 * A capability from $GLOBALS['bulkcat_test']['caps']; with an object id, the
	 * key "capability:id" wins when set, as map_meta_cap decides per object.
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		$caps = $GLOBALS['bulkcat_test']['caps'];
		if ( [] !== $args && array_key_exists( $capability . ':' . $args[0], $caps ) ) {
			return (bool) $caps[ $capability . ':' . $args[0] ];
		}
		return (bool) ( $caps[ $capability ] ?? false );
	}

	/**
	 * The same for a given user, from $GLOBALS['bulkcat_test']['user_caps'][ user id ].
	 */
	function user_can( int|object $user, string $capability, mixed ...$args ): bool {
		$caps = $GLOBALS['bulkcat_test']['user_caps'][ is_object( $user ) ? (int) $user->ID : $user ] ?? [];
		if ( [] !== $args && array_key_exists( $capability . ':' . $args[0], $caps ) ) {
			return (bool) $caps[ $capability . ':' . $args[0] ];
		}
		return (bool) ( $caps[ $capability ] ?? false );
	}

	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['bulkcat_test']['wrong'][] = [ $function_name, $message, $version ];
	}

	/**
	 * A predictable nonce, recording the action it was made for.
	 */
	function wp_create_nonce( string|int $action = -1 ): string {
		$GLOBALS['bulkcat_test']['nonces'][] = $action;
		return 'nonce-' . $action;
	}

	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}

	function esc_url_raw( string $url ): string {
		return $url;
	}

	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['bulkcat_test']['logged_in'];
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
		$key = '/' . trim( $route_namespace, '/' ) . '/' . ltrim( $route, '/' );
		// As core, a second registration of a route adds its endpoints to the
		// first's unless it overrides; here an endpoint for methods the route
		// already has replaces that one, so a test may boot the plugin twice.
		if ( isset( $GLOBALS['bulkcat_test']['routes'][ $key ] ) && ! $override ) {
			$as_list = static fn( array $given ): array => isset( $given['methods'] ) || isset( $given['callback'] ) ? [ $given ] : $given;
			$merged  = [];
			foreach ( [ $as_list( $GLOBALS['bulkcat_test']['routes'][ $key ] ), $as_list( $args ) ] as $part ) {
				foreach ( $part as $name => $entry ) {
					$merged[ is_int( $name ) ? 'endpoint:' . strtoupper( implode( ',', (array) ( $entry['methods'] ?? '' ) ) ) : $name ] = $entry;
				}
			}
			$args = [];
			foreach ( $merged as $name => $entry ) {
				if ( str_starts_with( (string) $name, 'endpoint:' ) ) {
					$args[] = $entry;
				} else {
					$args[ $name ] = $entry;
				}
			}
		}
		$GLOBALS['bulkcat_test']['routes'][ $key ]                              = $args;
		$GLOBALS['bulkcat_test']['namespaces'][ trim( $route_namespace, '/' ) ] = true;
		return true;
	}

	/**
	 * A server over the recorded routes; WP_REST_Server is in rest-stubs.php.
	 */
	function rest_get_server(): WP_REST_Server {
		return new WP_REST_Server();
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
	 * Whether a term id exists in a taxonomy, from $GLOBALS['bulkcat_test']['terms'][ taxonomy ].
	 */
	function term_exists( int|string $term, string $taxonomy = '', ?int $parent_term = null ): mixed {
		return in_array( (int) $term, $GLOBALS['bulkcat_test']['terms'][ $taxonomy ] ?? [], true ) ? [ 'term_id' => (int) $term ] : null;
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
		return (int) $GLOBALS['bulkcat_test']['decimals'];
	}

	function wc_get_price_decimal_separator(): string {
		return (string) $GLOBALS['bulkcat_test']['separator'];
	}

	/**
	 * False when another object id holds the SKU in $GLOBALS['bulkcat_test']['skus'] (sku => id).
	 */
	function wc_product_has_unique_sku( int $product_id, string $sku ): bool {
		$holder = $GLOBALS['bulkcat_test']['skus'][ $sku ] ?? null;
		return null === $holder || $holder === $product_id;
	}

	/**
	 * Core's rest_is_array(): an array list, or comma-separated text.
	 */
	function bulkcat_test_rest_list( mixed $value ): ?array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		return is_array( $value ) && array_is_list( $value ) ? $value : null;
	}

	/**
	 * Whether a value passes one JSON Schema type as core reads a request
	 * (numeric text is a number, '1'/'true' a boolean, comma text a list).
	 */
	function bulkcat_test_rest_is_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'null'    => null === $value,
			'boolean' => in_array( $value, [ true, false, 1, 0, '1', '0', 'true', 'false' ], true ),
			'integer' => is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) || ( is_float( $value ) && floor( $value ) === $value ),
			'number'  => is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ),
			'string'  => is_string( $value ),
			'array'   => null !== bulkcat_test_rest_list( $value ),
			'object'  => ( is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ) ) || $value instanceof stdClass || '' === $value,
			default   => false,
		};
	}

	/**
	 * A minimal rest_validate_value_from_schema(): type (one or a list, in
	 * core's priority order), enum, required and additionalProperties on
	 * object properties, items, minimum, maxItems and maxLength. True, or a
	 * WP_Error with core's codes.
	 */
	function rest_validate_value_from_schema( mixed $value, array $args, string $param = '' ): bool|WP_Error {
		$types = (array) ( $args['type'] ?? [] );
		$type  = null;
		foreach ( [ 'array', 'object', 'integer', 'number', 'boolean', 'string', 'null' ] as $candidate ) {
			if ( null === $type && in_array( $candidate, $types, true ) && bulkcat_test_rest_is_type( $value, $candidate ) ) {
				$type = $candidate;
			}
		}
		if ( null === $type ) {
			return new WP_Error( 'rest_invalid_type', sprintf( '%s is not of type %s.', $param, implode( ',', $types ) ), [ 'param' => $param ] );
		}
		if ( 'array' === $type ) {
			$value = bulkcat_test_rest_list( $value );
			if ( isset( $args['maxItems'] ) && count( $value ) > $args['maxItems'] ) {
				return new WP_Error( 'rest_too_many_items', sprintf( '%s must contain at most %d items.', $param, $args['maxItems'] ), [ 'param' => $param ] );
			}
			foreach ( $value as $index => $item ) {
				$valid = rest_validate_value_from_schema( $item, $args['items'] ?? [ 'type' => [ 'array', 'object', 'integer', 'number', 'boolean', 'string', 'null' ] ], $param . '[' . $index . ']' );
				if ( true !== $valid ) {
					return $valid;
				}
			}
			return true;
		}
		if ( 'object' === $type ) {
			$value      = (array) $value;
			$properties = $args['properties'] ?? [];
			foreach ( $properties as $name => $property ) {
				if ( ! array_key_exists( $name, $value ) ) {
					if ( ! empty( $property['required'] ) ) {
						return new WP_Error( 'rest_property_required', sprintf( '%s is a required property of %s.', $name, $param ), [ 'param' => $param ] );
					}
					continue;
				}
				$valid = rest_validate_value_from_schema( $value[ $name ], $property, $param . '[' . $name . ']' );
				if ( true !== $valid ) {
					return $valid;
				}
			}
			if ( false === ( $args['additionalProperties'] ?? true ) && [] !== array_diff_key( $value, $properties ) ) {
				return new WP_Error( 'rest_additional_properties_forbidden', sprintf( '%s is not a valid property of Object.', (string) array_key_first( array_diff_key( $value, $properties ) ) ), [ 'param' => $param ] );
			}
			return true;
		}
		if ( isset( $args['enum'] ) && ! in_array( $value, $args['enum'], true ) && ! ( is_string( $value ) && in_array( $value, array_map( 'strval', $args['enum'] ), true ) && in_array( $type, [ 'integer', 'number' ], true ) ) ) {
			return new WP_Error( 'rest_not_in_enum', sprintf( '%s is not one of %s.', $param, implode( ', ', array_map( 'strval', $args['enum'] ) ) ), [ 'param' => $param ] );
		}
		if ( isset( $args['minimum'] ) && in_array( $type, [ 'integer', 'number' ], true ) && $value < $args['minimum'] ) {
			return new WP_Error( 'rest_out_of_bounds', sprintf( '%s must be greater than or equal to %d', $param, $args['minimum'] ), [ 'param' => $param ] );
		}
		if ( isset( $args['maxLength'] ) && 'string' === $type && mb_strlen( $value ) > $args['maxLength'] ) {
			return new WP_Error( 'rest_too_long', sprintf( '%s must be at most %d characters long.', $param, $args['maxLength'] ), [ 'param' => $param ] );
		}
		return true;
	}
}

// T21: terms, term meta and transients for the category tree. State lives
// under bulkcat_test keys that bulkcat_test_reset() drops: 'term_list' (what
// get_terms() returns), 'get_terms_calls' (each call's arguments),
// 'term_meta' (term id => key => value), 'transients' (name => value),
// 'transient_ttl' (name => expiration) and 'ext_object_cache' (whether a
// persistent object cache is in use). wp_cache_get_last_changed() is the one
// in read-stubs.php, reading and writing 'last_changed' (group => value), so
// a test may set a group's value directly.
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( array|string $args = [], mixed $deprecated = '' ): array|WP_Error {
		$GLOBALS['bulkcat_test']['get_terms_calls'][] = $args;
		return $GLOBALS['bulkcat_test']['term_list'] ?? [];
	}

	function get_term_meta( int $term_id, string $key = '', bool $single = false ): mixed {
		$value = $GLOBALS['bulkcat_test']['term_meta'][ $term_id ][ $key ] ?? null;
		return null === $value ? ( $single ? '' : [] ) : ( $single ? $value : [ $value ] );
	}

	function get_transient( string $transient ): mixed {
		return $GLOBALS['bulkcat_test']['transients'][ $transient ] ?? false;
	}

	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['bulkcat_test']['transients'][ $transient ]    = $value;
		$GLOBALS['bulkcat_test']['transient_ttl'][ $transient ] = $expiration;
		return true;
	}

	function wp_using_ext_object_cache( ?bool $using = null ): bool {
		return (bool) ( $GLOBALS['bulkcat_test']['ext_object_cache'] ?? false );
	}
}

// T22: the site's current time, from $GLOBALS['bulkcat_test']['now'] (any strtotime() text) when set.
if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime(): DateTimeImmutable {
		return new DateTimeImmutable( $GLOBALS['bulkcat_test']['now'] ?? 'now', new DateTimeZone( 'UTC' ) );
	}
}

// T19: selection tokens. The current user's id is $GLOBALS['bulkcat_test']['user_id']
// when set, else 1 for a logged-in user and 0 for a visitor; the auth salt is
// $GLOBALS['bulkcat_test']['salt'] when set, so a test can rotate it.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return $scheme . ':' . ( $GLOBALS['bulkcat_test']['salt'] ?? 'test-salt' );
	}

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['bulkcat_test']['user_id'] ?? ( is_user_logged_in() ? 1 : 0 ) );
	}

	function number_format_i18n( float|int $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals );
	}
}

// T25: core's wp_filter_post_kses() — kses with the post rules over slashed
// data, as core defines it — over the wp_kses_post() stub above.
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( string $data ): string {
		return addslashes( wp_kses_post( stripslashes( $data ) ) );
	}
}

// T26: WooCommerce's rounding precision, the price decimals plus two and at least 6.
if ( ! function_exists( 'wc_get_rounding_precision' ) ) {
	function wc_get_rounding_precision(): int {
		return max( 6, wc_get_price_decimals() + 2 );
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	/**
	 * Records each call in $GLOBALS['bulkcat_test']['set_object_terms'] as
	 * [ object id, terms, taxonomy, append ] and answers the terms given, or
	 * the WP_Error in $GLOBALS['bulkcat_test']['set_object_terms_error'] when a
	 * test sets one (T27).
	 */
	function wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|\WP_Error {
		$GLOBALS['bulkcat_test']['set_object_terms'][] = [ $object_id, $terms, $taxonomy, $append ];
		return $GLOBALS['bulkcat_test']['set_object_terms_error'] ?? array_values( (array) $terms );
	}
}

if ( ! function_exists( 'wp_defer_term_counting' ) ) {
	/**
	 * T34: records each call in $GLOBALS['bulkcat_test']['term_counting'] as
	 * [ defer, number of products loaded so far ], so a test can see the
	 * writes happen between turning deferral on and flushing it.
	 */
	function wp_defer_term_counting( ?bool $defer = null ): bool {
		if ( null !== $defer ) {
			$GLOBALS['bulkcat_test']['term_counting'][] = [ $defer, count( $GLOBALS['bulkcat_test']['products_loaded'] ?? [] ) ];
		}
		return (bool) ( $defer ?? false );
	}
}

// T32: the writer's WooCommerce and meta entry points.
if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Answers the object a test put in $GLOBALS['bulkcat_test']['products'][ $id ]
	 * (a SavedProduct, say), or false as WooCommerce does for an id that is
	 * not a product; records each id asked for in
	 * $GLOBALS['bulkcat_test']['products_loaded'].
	 */
	function wc_get_product( mixed $the_product = false, array $deprecated = [] ): mixed {
		$GLOBALS['bulkcat_test']['products_loaded'][] = $the_product;
		return $GLOBALS['bulkcat_test']['products'][ (int) $the_product ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	/**
	 * WooCommerce's logger: every entry is recorded in
	 * $GLOBALS['bulkcat_test']['logged'] as [ level, message, context ].
	 */
	function wc_get_logger(): object {
		return new class() {
			public function log( string $level, string $message, array $context = [] ): void {
				$GLOBALS['bulkcat_test']['logged'][] = [ $level, $message, $context ];
			}

			public function error( string $message, array $context = [] ): void {
				$this->log( 'error', $message, $context );
			}
		};
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Records each call in $GLOBALS['bulkcat_test']['post_meta_updates'] as
	 * [ post id, key, value ], so a test can assert a write path made none.
	 */
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		$GLOBALS['bulkcat_test']['post_meta_updates'][] = [ $post_id, $meta_key, $meta_value ];
		return true;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * T37: a version 4 UUID, as core makes one.
	 */
	function wp_generate_uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	/**
	 * T89: a post field as the database holds it. post_status answers
	 * $GLOBALS['bulkcat_test']['stored_status'][ $id ] when a test sets it
	 * (WordPress stored something other than what was set, as it does for a
	 * publish whose date is ahead), else the status of the product a test
	 * registered, else ''.
	 */
	function get_post_field( string $field, int|object $post, string $context = 'display' ): mixed {
		unset( $context );
		$id = is_object( $post ) ? (int) $post->ID : $post;
		if ( 'post_status' === $field && isset( $GLOBALS['bulkcat_test']['stored_status'][ $id ] ) ) {
			return $GLOBALS['bulkcat_test']['stored_status'][ $id ];
		}
		$product = $GLOBALS['bulkcat_test']['products'][ $id ] ?? null;
		if ( 'post_status' === $field && is_object( $product ) && isset( $product->props['status'] ) ) {
			return $product->props['status'];
		}
		return '';
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	/**
	 * T37: forgets a post's primed post and meta caches and moves the posts'
	 * last-changed value, as core does; records each id in
	 * $GLOBALS['bulkcat_test']['cleaned_posts'].
	 */
	function clean_post_cache( int|object $post ): void {
		$id = is_object( $post ) ? (int) $post->ID : $post;
		$GLOBALS['bulkcat_test']['cleaned_posts'][] = $id;
		unset( $GLOBALS['bulkcat_test']['primed_posts'][ $id ], $GLOBALS['bulkcat_test']['primed_meta'][ $id ] );
		wp_cache_set_last_changed( 'posts' );
	}
}

if ( ! defined( 'BULKCAT_TEST_NO_ACTION_SCHEDULER' ) && ! function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * T60: recorded in $GLOBALS['bulkcat_test']['scheduled'] as [ 'recurring',
	 * hook, args, group, timestamp, interval, the number of
	 * as_unschedule_all_actions() calls recorded so far ].
	 */
	function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
		$GLOBALS['bulkcat_test']['scheduled'][] = [ 'recurring', $hook, $args, $group, $timestamp, $interval_in_seconds, count( $GLOBALS['bulkcat_test']['unscheduled'] ?? [] ) ];
		return count( $GLOBALS['bulkcat_test']['scheduled'] );
	}

	/**
	 * T60: the timestamp of the latest recurring action recorded for the hook
	 * (and args and group, when given) that no later as_unschedule_all_actions()
	 * call for its hook or its whole group removed, or false.
	 */
	function as_next_scheduled_action( string $hook, ?array $args = null, string $group = '' ): int|bool {
		foreach ( array_reverse( $GLOBALS['bulkcat_test']['scheduled'] ?? [] ) as $entry ) {
			if ( 'recurring' !== $entry[0] || $hook !== $entry[1] || ( null !== $args && $args !== $entry[2] ) || ( '' !== $group && $group !== $entry[3] ) ) {
				continue;
			}
			foreach ( array_slice( $GLOBALS['bulkcat_test']['unscheduled'] ?? [], $entry[6] ) as [ $unscheduled_hook, , $unscheduled_group ] ) {
				if ( ( '' === $unscheduled_hook || $hook === $unscheduled_hook ) && ( '' === $unscheduled_group || $entry[3] === $unscheduled_group ) ) {
					continue 2;
				}
			}
			return $entry[4];
		}
		return false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * T88: core's wp_unslash(), which add_metadata() applies to what it stores.
	 */
	function wp_unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	/**
	 * T63: core's wp_slash(), adding slashes to a string or each string in an array.
	 */
	function wp_slash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * T63: a slug as core's sanitize_title() makes one from plain text:
	 * lower case, runs of anything but letters, digits and hyphens as one hyphen.
	 */
	function sanitize_title( string $title, string $fallback_title = '', string $context = 'save' ): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $title ) ), '-' );
		return '' === $slug ? $fallback_title : $slug;
	}
}

// T63, T64: term writes over the T21 term list. wp_insert_term() and
// wp_update_term() take slashed data as core does, change
// $GLOBALS['bulkcat_test']['term_list'] (a new term's id is one above the
// highest), record each call in 'inserted_terms' and 'updated_terms', fire
// created_term or edited_term as core does, and answer the WP_Error in
// 'insert_term_error' or 'update_term_error' when a test sets one.
// update_term_meta() writes 'term_meta' and records 'term_meta_updates';
// wp_delete_term() removes a term and records 'deleted_terms'.
if ( ! function_exists( 'wp_insert_term' ) ) {
	function get_term( int|object $term, string $taxonomy = '', string $output = 'OBJECT', string $filter = 'raw' ): mixed {
		$id = is_object( $term ) ? (int) $term->term_id : $term;
		foreach ( $GLOBALS['bulkcat_test']['term_list'] ?? [] as $candidate ) {
			if ( (int) $candidate->term_id === $id && ( '' === $taxonomy || ( $candidate->taxonomy ?? 'product_cat' ) === $taxonomy ) ) {
				return $candidate;
			}
		}
		return null;
	}

	function wp_insert_term( string $term, string $taxonomy, array|string $args = [] ): array|WP_Error {
		$args                                        = (array) $args;
		$GLOBALS['bulkcat_test']['inserted_terms'][] = [ $term, $taxonomy, $args ];
		if ( isset( $GLOBALS['bulkcat_test']['insert_term_error'] ) ) {
			return $GLOBALS['bulkcat_test']['insert_term_error'];
		}
		$id = 1;
		foreach ( $GLOBALS['bulkcat_test']['term_list'] ?? [] as $candidate ) {
			$id = max( $id, (int) $candidate->term_id + 1 );
		}
		$name                                   = stripslashes( $term );
		$GLOBALS['bulkcat_test']['term_list'][] = (object) [
			'term_id'     => $id,
			'name'        => $name,
			'slug'        => '' !== (string) ( $args['slug'] ?? '' ) ? stripslashes( (string) $args['slug'] ) : sanitize_title( $name ),
			'parent'      => (int) ( $args['parent'] ?? 0 ),
			'taxonomy'    => $taxonomy,
			'description' => stripslashes( (string) ( $args['description'] ?? '' ) ),
		];
		do_action( 'created_term', $id, $id, $taxonomy );
		return [
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		];
	}

	function wp_update_term( int $term_id, string $taxonomy, array $args = [] ): array|WP_Error {
		$GLOBALS['bulkcat_test']['updated_terms'][] = [ $term_id, $taxonomy, $args ];
		if ( isset( $GLOBALS['bulkcat_test']['update_term_error'] ) ) {
			return $GLOBALS['bulkcat_test']['update_term_error'];
		}
		$term = get_term( $term_id, $taxonomy );
		if ( null === $term ) {
			return new WP_Error( 'invalid_term', 'Empty Term.' );
		}
		foreach ( [ 'name', 'slug', 'description' ] as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$term->$key = stripslashes( (string) $args[ $key ] );
			}
		}
		if ( array_key_exists( 'parent', $args ) ) {
			$term->parent = (int) $args['parent'];
		}
		do_action( 'edited_term', $term_id, $term_id, $taxonomy );
		return [
			'term_id'          => $term_id,
			'term_taxonomy_id' => $term_id,
		];
	}

	function update_term_meta( int $term_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		$GLOBALS['bulkcat_test']['term_meta'][ $term_id ][ $meta_key ] = $meta_value;
		$GLOBALS['bulkcat_test']['term_meta_updates'][]                = [ $term_id, $meta_key, $meta_value ];
		return true;
	}

	function wp_delete_term( int $term, string $taxonomy, array|string $args = [] ): bool|int|WP_Error {
		$GLOBALS['bulkcat_test']['deleted_terms'][] = [ $term, $taxonomy ];
		$GLOBALS['bulkcat_test']['term_list']       = array_values( array_filter( $GLOBALS['bulkcat_test']['term_list'] ?? [], static fn( object $t ): bool => (int) $t->term_id !== $term ) );
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {
		if ( array_key_exists( $name, $GLOBALS['bulkcat_test']['options'] ) ) {
			return false;
		}
		$GLOBALS['bulkcat_test']['options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 1; }
}
