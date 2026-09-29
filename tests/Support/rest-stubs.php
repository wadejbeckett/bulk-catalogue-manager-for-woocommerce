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

	/**
	 * Parameters set with set_param() win; then, as core orders them for a
	 * POST, the JSON body (with a JSON content type), the form body and the
	 * query string, each also readable on its own as core offers them.
	 */
	class WP_REST_Request {
		private array $params = [];

		private array $query = [];

		private array $body_params = [];

		private array $headers = [];

		private string $body = '';

		public function __construct( private string $method = 'GET', private string $route = '' ) {}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_param( string $key ): mixed {
			return $this->get_params()[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_params(): array {
			return $this->params + ( $this->get_json_params() ?? [] ) + $this->body_params + $this->query;
		}

		public function set_query_params( array $params ): void {
			$this->query = $params;
		}

		public function get_query_params(): array {
			return $this->query;
		}

		public function set_body_params( array $params ): void {
			$this->body_params = $params;
		}

		public function get_body_params(): array {
			return $this->body_params;
		}

		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_json_params(): ?array {
			if ( ! str_starts_with( (string) $this->get_header( 'content-type' ), 'application/json' ) ) {
				return null;
			}
			$json = json_decode( $this->body, true );
			return is_array( $json ) ? $json : null;
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
			foreach ( array_keys( $GLOBALS['bulkcat_test']['namespaces'] ) as $space ) {
				$routes[ '/' . $space ] = [
					[
						'methods'   => [ 'GET' => true ],
						'callback'  => [ $this, 'get_namespace_index' ],
						'namespace' => $space,
					],
				];
			}
			foreach ( $GLOBALS['bulkcat_test']['routes'] as $route => $args ) {
				$handlers = isset( $args['callback'] ) ? [ $args ] : array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY );
				$space    = '';
				foreach ( array_keys( $GLOBALS['bulkcat_test']['namespaces'] ) as $candidate ) {
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
		 * endpoint (a route is a pattern; its named groups become parameters), fill defaults, refuse a missing required argument, run
		 * every present argument's validate_callback (any failure answers 400
		 * `rest_invalid_param` naming each), run the sanitize_callbacks, then
		 * the permission callback, then the handler.
		 */
		public function dispatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$handler = null;
			foreach ( $this->get_routes() as $route => $candidates ) {
				// Core matches each route as a case-insensitive pattern and passes its named groups as URL parameters.
				if ( 1 !== preg_match( '@^' . $route . '$@i', $request->get_route(), $matches ) ) {
					continue;
				}
				foreach ( $candidates as $candidate ) {
					if ( isset( $candidate['methods'][ $request->get_method() ] ) ) {
						$handler = $candidate;
						foreach ( $matches as $name => $value ) {
							if ( is_string( $name ) ) {
								$request->set_param( $name, $value );
							}
						}
						break 2;
					}
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
