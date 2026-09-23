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

		/**
		 * T34: the scheduling calls are recorded in order in
		 * $GLOBALS['bcm_test']['scheduled'] as [ function, hook, args, group, timestamp or null ].
		 */
		function as_enqueue_async_action( string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
			$GLOBALS['bcm_test']['scheduled'][] = [ 'enqueue', $hook, $args, $group, null ];
			return count( $GLOBALS['bcm_test']['scheduled'] );
		}

		function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
			$GLOBALS['bcm_test']['scheduled'][] = [ 'single', $hook, $args, $group, $timestamp ];
			return count( $GLOBALS['bcm_test']['scheduled'] );
		}

		function as_unschedule_action( string $hook, array $args = [], string $group = '' ): ?int {
			$GLOBALS['bcm_test']['scheduled'][] = [ 'unschedule', $hook, $args, $group, null ];
			return null;
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

	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		$GLOBALS['bcm_test']['gettext'][] = [ $single, $domain ];
		return 1 === $number ? $single : $plural;
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A capability from $GLOBALS['bcm_test']['caps']; with an object id, the
	 * key "capability:id" wins when set, as map_meta_cap decides per object.
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		$caps = $GLOBALS['bcm_test']['caps'];
		if ( [] !== $args && array_key_exists( $capability . ':' . $args[0], $caps ) ) {
			return (bool) $caps[ $capability . ':' . $args[0] ];
		}
		return (bool) ( $caps[ $capability ] ?? false );
	}

	/**
	 * The same for a given user, from $GLOBALS['bcm_test']['user_caps'][ user id ].
	 */
	function user_can( int|object $user, string $capability, mixed ...$args ): bool {
		$caps = $GLOBALS['bcm_test']['user_caps'][ is_object( $user ) ? (int) $user->ID : $user ] ?? [];
		if ( [] !== $args && array_key_exists( $capability . ':' . $args[0], $caps ) ) {
			return (bool) $caps[ $capability . ':' . $args[0] ];
		}
		return (bool) ( $caps[ $capability ] ?? false );
	}

	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['bcm_test']['wrong'][] = [ $function_name, $message, $version ];
	}

	/**
	 * A predictable nonce, recording the action it was made for.
	 */
	function wp_create_nonce( string|int $action = -1 ): string {
		$GLOBALS['bcm_test']['nonces'][] = $action;
		return 'nonce-' . $action;
	}

	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}

	function esc_url_raw( string $url ): string {
		return $url;
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
		$GLOBALS['bcm_test']['namespaces'][ trim( $route_namespace, '/' ) ]                                = true;
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

	/**
	 * Core's rest_is_array(): an array list, or comma-separated text.
	 */
	function bcm_test_rest_list( mixed $value ): ?array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		return is_array( $value ) && array_is_list( $value ) ? $value : null;
	}

	/**
	 * Whether a value passes one JSON Schema type as core reads a request
	 * (numeric text is a number, '1'/'true' a boolean, comma text a list).
	 */
	function bcm_test_rest_is_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'null'    => null === $value,
			'boolean' => in_array( $value, [ true, false, 1, 0, '1', '0', 'true', 'false' ], true ),
			'integer' => is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) || ( is_float( $value ) && floor( $value ) === $value ),
			'number'  => is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ),
			'string'  => is_string( $value ),
			'array'   => null !== bcm_test_rest_list( $value ),
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
			if ( null === $type && in_array( $candidate, $types, true ) && bcm_test_rest_is_type( $value, $candidate ) ) {
				$type = $candidate;
			}
		}
		if ( null === $type ) {
			return new WP_Error( 'rest_invalid_type', sprintf( '%s is not of type %s.', $param, implode( ',', $types ) ), [ 'param' => $param ] );
		}
		if ( 'array' === $type ) {
			$value = bcm_test_rest_list( $value );
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
// under bcm_test keys that bcm_test_reset() drops: 'term_list' (what
// get_terms() returns), 'get_terms_calls' (each call's arguments),
// 'term_meta' (term id => key => value), 'transients' (name => value),
// 'transient_ttl' (name => expiration) and 'ext_object_cache' (whether a
// persistent object cache is in use). wp_cache_get_last_changed() is the one
// in read-stubs.php, reading and writing 'last_changed' (group => value), so
// a test may set a group's value directly.
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( array|string $args = [], mixed $deprecated = '' ): array|WP_Error {
		$GLOBALS['bcm_test']['get_terms_calls'][] = $args;
		return $GLOBALS['bcm_test']['term_list'] ?? [];
	}

	function get_term_meta( int $term_id, string $key = '', bool $single = false ): mixed {
		$value = $GLOBALS['bcm_test']['term_meta'][ $term_id ][ $key ] ?? null;
		return null === $value ? ( $single ? '' : [] ) : ( $single ? $value : [ $value ] );
	}

	function get_transient( string $transient ): mixed {
		return $GLOBALS['bcm_test']['transients'][ $transient ] ?? false;
	}

	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['bcm_test']['transients'][ $transient ]    = $value;
		$GLOBALS['bcm_test']['transient_ttl'][ $transient ] = $expiration;
		return true;
	}

	function wp_using_ext_object_cache( ?bool $using = null ): bool {
		return (bool) ( $GLOBALS['bcm_test']['ext_object_cache'] ?? false );
	}
}

// T22: the site's current time, from $GLOBALS['bcm_test']['now'] (any strtotime() text) when set.
if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime(): DateTimeImmutable {
		return new DateTimeImmutable( $GLOBALS['bcm_test']['now'] ?? 'now', new DateTimeZone( 'UTC' ) );
	}
}

// T19: selection tokens. The current user's id is $GLOBALS['bcm_test']['user_id']
// when set, else 1 for a logged-in user and 0 for a visitor; the auth salt is
// $GLOBALS['bcm_test']['salt'] when set, so a test can rotate it.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return $scheme . ':' . ( $GLOBALS['bcm_test']['salt'] ?? 'test-salt' );
	}

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['bcm_test']['user_id'] ?? ( is_user_logged_in() ? 1 : 0 ) );
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
	 * Records each call in $GLOBALS['bcm_test']['set_object_terms'] as
	 * [ object id, terms, taxonomy, append ] and answers the terms given, or
	 * the WP_Error in $GLOBALS['bcm_test']['set_object_terms_error'] when a
	 * test sets one (T27).
	 */
	function wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|\WP_Error {
		$GLOBALS['bcm_test']['set_object_terms'][] = [ $object_id, $terms, $taxonomy, $append ];
		return $GLOBALS['bcm_test']['set_object_terms_error'] ?? array_values( (array) $terms );
	}
}

if ( ! function_exists( 'wp_defer_term_counting' ) ) {
	/**
	 * T34: records each call in $GLOBALS['bcm_test']['term_counting'] as
	 * [ defer, number of products loaded so far ], so a test can see the
	 * writes happen between turning deferral on and flushing it.
	 */
	function wp_defer_term_counting( ?bool $defer = null ): bool {
		if ( null !== $defer ) {
			$GLOBALS['bcm_test']['term_counting'][] = [ $defer, count( $GLOBALS['bcm_test']['products_loaded'] ?? [] ) ];
		}
		return (bool) ( $defer ?? false );
	}
}

// T32: the writer's WooCommerce and meta entry points.
if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Answers the object a test put in $GLOBALS['bcm_test']['products'][ $id ]
	 * (a SavedProduct, say), or false as WooCommerce does for an id that is
	 * not a product; records each id asked for in
	 * $GLOBALS['bcm_test']['products_loaded'].
	 */
	function wc_get_product( mixed $the_product = false, array $deprecated = [] ): mixed {
		$GLOBALS['bcm_test']['products_loaded'][] = $the_product;
		return $GLOBALS['bcm_test']['products'][ (int) $the_product ] ?? false;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Records each call in $GLOBALS['bcm_test']['post_meta_updates'] as
	 * [ post id, key, value ], so a test can assert a write path made none.
	 */
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		$GLOBALS['bcm_test']['post_meta_updates'][] = [ $post_id, $meta_key, $meta_value ];
		return true;
	}
}
