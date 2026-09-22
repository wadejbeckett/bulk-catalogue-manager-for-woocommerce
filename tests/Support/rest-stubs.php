<?php
/**
 * Minimal WP_Error, WP_REST_Request, WP_REST_Response and WP_REST_Server for
 * unit tests. Under WordPress this file defines nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// WordPress's own class names, declared together inside one guard.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Generic.Classes.OpeningBraceSameLine

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! class_exists( 'WP_Error' ) ) {

	class WP_Error {
		private array $errors = [];
		private array $data   = [];

		public function __construct( string|int $code = '', string $message = '', mixed $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function get_error_code(): string|int {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message( string|int $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string|int $code = '' ): mixed {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->data[ $code ] ?? null;
		}
	}

	class WP_REST_Request {
		private array $params = [];

		public function __construct( private string $method = 'GET', private string $route = '' ) {}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_params(): array {
			return $this->params;
		}
	}

	class WP_REST_Response {
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}

	/**
	 * The routes register_rest_route() recorded, shaped as core's get_routes()
	 * returns them: each route a list of endpoints, route options such as
	 * `schema` dropped, methods as a map, plus the namespace index core adds
	 * for each namespace (public, with no permission callback, as in core).
	 */
	class WP_REST_Server {
		public function get_routes( string $route_namespace = '' ): array {
			$routes = [];
			foreach ( array_keys( $GLOBALS['bcm_test']['namespaces'] ) as $space ) {
				$routes[ '/' . $space ] = [
					[
						'methods'   => [ 'GET' => true ],
						'callback'  => [ $this, 'get_namespace_index' ],
						'namespace' => $space,
					],
				];
			}
			foreach ( $GLOBALS['bcm_test']['routes'] as $route => $args ) {
				$handlers = isset( $args['callback'] ) ? [ $args ] : array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY );
				$space    = '';
				foreach ( array_keys( $GLOBALS['bcm_test']['namespaces'] ) as $candidate ) {
					$space = str_starts_with( $route, '/' . $candidate . '/' ) ? $candidate : $space;
				}
				foreach ( $handlers as $key => $handler ) {
					$methods              = is_string( $handler['methods'] ?? '' ) ? explode( ',', (string) ( $handler['methods'] ?? '' ) ) : (array) $handler['methods'];
					$handler['methods']   = array_fill_keys( array_map( static fn( $m ) => strtoupper( trim( $m ) ), $methods ), true );
					$handler['namespace'] = $space;
					$handlers[ $key ]     = $handler + [ 'args' => [] ];
				}
				$routes[ $route ] = array_values( $handlers );
			}
			if ( '' === $route_namespace ) {
				return $routes;
			}
			return array_filter( $routes, static fn( array $handlers ) => ( $handlers[0]['namespace'] ?? '' ) === $route_namespace );
		}

		public function get_namespace_index(): array {
			return [];
		}

		/**
		 * Core's dispatch order for one request on a recorded route: match the
		 * endpoint, fill defaults, refuse a missing required argument, run
		 * every present argument's validate_callback (any failure answers 400
		 * `rest_invalid_param` naming each), run the sanitize_callbacks, then
		 * the permission callback, then the handler.
		 */
		public function dispatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$handler = null;
			foreach ( $this->get_routes()[ $request->get_route() ] ?? [] as $candidate ) {
				if ( isset( $candidate['methods'][ $request->get_method() ] ) ) {
					$handler = $candidate;
					break;
				}
			}
			if ( null === $handler ) {
				return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', [ 'status' => 404 ] );
			}
			$args = $handler['args'] ?? [];
			foreach ( $args as $key => $arg ) {
				if ( null === $request->get_param( $key ) && array_key_exists( 'default', $arg ) ) {
					$request->set_param( $key, $arg['default'] );
				}
			}
			$missing = [];
			$invalid = [];
			$details = [];
			foreach ( $args as $key => $arg ) {
				$value = $request->get_param( $key );
				if ( null === $value ) {
					if ( ! empty( $arg['required'] ) ) {
						$missing[] = $key;
					}
					continue;
				}
				if ( ! empty( $arg['validate_callback'] ) ) {
					$valid = call_user_func( $arg['validate_callback'], $value, $request, $key );
					if ( false === $valid ) {
						$invalid[ $key ] = 'Invalid parameter.';
					} elseif ( $valid instanceof WP_Error ) {
						$invalid[ $key ] = $valid->get_error_message();
						$details[ $key ] = [
							'code'    => $valid->get_error_code(),
							'message' => $valid->get_error_message(),
							'data'    => $valid->get_error_data(),
						];
					}
				}
			}
			if ( [] !== $missing ) {
				return new WP_Error(
					'rest_missing_callback_param',
					'Missing parameter(s): ' . implode( ', ', $missing ),
					[
						'status' => 400,
						'params' => $missing,
					]
				);
			}
			if ( [] !== $invalid ) {
				return new WP_Error(
					'rest_invalid_param',
					'Invalid parameter(s): ' . implode( ', ', array_keys( $invalid ) ),
					[
						'status'  => 400,
						'params'  => $invalid,
						'details' => $details,
					]
				);
			}
			foreach ( $args as $key => $arg ) {
				$value = $request->get_param( $key );
				if ( null !== $value && ! empty( $arg['sanitize_callback'] ) ) {
					$request->set_param( $key, call_user_func( $arg['sanitize_callback'], $value, $request, $key ) );
				}
			}
			$permission = call_user_func( $handler['permission_callback'], $request );
			if ( $permission instanceof WP_Error ) {
				return $permission;
			}
			if ( true !== $permission ) {
				return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', [ 'status' => rest_authorization_required_code() ] );
			}
			return rest_ensure_response( call_user_func( $handler['callback'], $request ) );
		}
	}
}
