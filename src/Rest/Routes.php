<?php
/**
 * The REST bootstrap (SPEC §12.1, §13). Every `bcm/v1` route goes through
 * add(), the only call to `register_rest_route()` in the plugin. add() refuses
 * a route unless every endpoint names a callable permission callback other
 * than `__return_true`; a refused route is skipped with a `_doing_it_wrong()`
 * notice rather than thrown, so one faulty route cannot take down the site's
 * REST API. Authentication is WordPress's logged-in cookie plus the `wp_rest`
 * nonce, checked by core (`rest_cookie_check_errors`) before any permission
 * callback runs; client_settings() gives the screen the nonce to send.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Route registrar.
 */
final class Routes {
	/**
	 * Permission callbacks that let anyone through, refused whatever route names them.
	 */
	private const OPEN_CALLBACKS = [ '__return_true' ];

	/**
	 * Routes add() refused, in order.
	 *
	 * @var list<string>
	 */
	private array $refused = [];

	/**
	 * Build over the controllers whose routes register().
	 *
	 * @param list<Controller> $controllers Route controllers.
	 */
	public function __construct( private readonly array $controllers ) {}

	/**
	 * The plugin's registrar with every controller. New controllers are added here.
	 */
	public static function plugin(): self {
		return new self(
			[
				new FieldsController( Registry::instance(), Actions::instance() ),
				new ProductsController( Registry::instance() ),
				new VariationsController( Registry::instance() ),
				new CategoriesController( Registry::instance() ),
				new ExportController( Registry::instance() ),
				new SelectionController( Registry::instance() ),
				new BatchesController(),
			]
		);
	}

	/**
	 * Register every controller's routes. Runs on rest_api_init.
	 */
	public function register(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes( $this );
		}
	}

	/**
	 * Register one route under `bcm/v1`, or refuse it. Takes the same
	 * arguments as `register_rest_route()`: one endpoint, or a list of
	 * endpoints plus route options such as `schema`.
	 *
	 * @param string               $route Route below the namespace, starting with a slash.
	 * @param array<string, mixed> $args  Endpoint or endpoints.
	 * @return bool Whether the route was registered.
	 */
	public function add( string $route, array $args ): bool {
		$problem = self::problem( $route, $args );
		if ( '' !== $problem ) {
			$this->refused[] = $route;
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'The REST route %1$s was not registered: %2$s Every bcm/v1 endpoint needs a permission callback that checks a capability.', Access::REST_NAMESPACE . $route, $problem ) ),
				esc_html( Plugin::VERSION )
			);
			return false;
		}
		return register_rest_route( Access::REST_NAMESPACE, $route, $args );
	}

	/**
	 * Routes add() has refused.
	 *
	 * @return list<string>
	 */
	public function refused(): array {
		return $this->refused;
	}

	/**
	 * The endpoints of a route's arguments, as WordPress reads them: the
	 * arguments themselves when they are one endpoint, else their numbered entries.
	 *
	 * @param array<string|int, mixed> $args Route arguments.
	 * @return list<mixed>
	 */
	public static function endpoints( array $args ): array {
		if ( isset( $args['methods'] ) || isset( $args['callback'] ) ) {
			return [ $args ];
		}
		return array_values( array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY ) );
	}

	/**
	 * Whether a permission callback really checks something: callable and not
	 * one of the callbacks that let anyone through.
	 *
	 * @param mixed $callback Permission callback.
	 */
	public static function is_guarded( mixed $callback ): bool {
		if ( ! is_callable( $callback ) ) {
			return false;
		}
		if ( is_string( $callback ) && in_array( strtolower( ltrim( $callback, '\\' ) ), self::OPEN_CALLBACKS, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * What the screen's script needs to call the routes: the namespace root
	 * and a fresh `wp_rest` nonce for apiFetch's nonce middleware (SPEC §13).
	 * T8 localises this at page load; Heartbeat refreshes the nonce.
	 *
	 * @return array{root: string, nonce: string}
	 */
	public static function client_settings(): array {
		return [
			'root'  => esc_url_raw( rest_url( Access::REST_NAMESPACE . '/' ) ),
			'nonce' => self::nonce(),
		];
	}

	/**
	 * A `wp_rest` nonce for the current user, the one core's cookie check expects.
	 */
	public static function nonce(): string {
		return wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Why a route may not be registered, or '' when it may.
	 *
	 * @param string                   $route Route below the namespace.
	 * @param array<string|int, mixed> $args  Route arguments.
	 */
	private static function problem( string $route, array $args ): string {
		if ( ! str_starts_with( $route, '/' ) || '/' === $route ) {
			return 'the route must start with a slash and name a path.';
		}
		$endpoints = self::endpoints( $args );
		if ( [] === $endpoints ) {
			return 'it declares no endpoint.';
		}
		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) || ! array_key_exists( 'permission_callback', $endpoint ) ) {
				return 'an endpoint has no permission_callback.';
			}
			if ( ! self::is_guarded( $endpoint['permission_callback'] ) ) {
				return 'an endpoint\'s permission_callback is not callable or lets anyone through.';
			}
		}
		return '';
	}
}
