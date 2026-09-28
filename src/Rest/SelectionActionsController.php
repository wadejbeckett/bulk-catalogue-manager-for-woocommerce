<?php
/**
 * Guarded snapshot composition and lifecycle endpoints.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Requests\RequestService;
defined( 'ABSPATH' ) || exit;

/** Thin transports share current capabilities and strict mutation parameters. */
final class SelectionActionsController implements Controller {
	/** Schema source. */
	private RouteSchema $schema;
	/**
	 * Construct with the registry-derived schema owner.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( Registry $registry ) {
		$this->schema = new RouteSchema( $registry );
	}
	/**
	 * Register the four POST operations through the common registrar.
	 *
	 * @param Routes $routes Registrar.
	 */
	public function register_routes( Routes $routes ): void {
		foreach ( [ 'compose', 'inspect', 'renew', 'release' ] as $operation ) {
			$args = $this->schema->selection_handle_args( $operation );
			$routes->add(
				'/selection/' . $operation,
				[
					[
						'methods'             => 'POST',
						'permission_callback' => [ Access::class, 'can_write' ],
						'args'                => $args,
						'callback'            => static function ( \WP_REST_Request $request ) use ( $operation, $args ): \WP_REST_Response|\WP_Error {
							if ( ! RequestsController::known( $request, array_keys( $args ) ) ) {
								return RequestsController::unknown();
							}
							$input = array_intersect_key( $request->get_params(), $args );
							if ( 'compose' === $operation ) {
								$intent = $input['intent'];
								foreach ( [
									'add_token'    => 'add_snapshot',
									'remove_token' => 'remove_snapshot',
								] as $credential => $operand ) {
									if ( isset( $input[ $credential ] ) && ! isset( $intent[ $operand ] ) ) {
										return RequestsController::unknown();
									}
								}
							}
							$service = new RequestService();
							$result  = 'compose' === $operation ? $service->compose( $input ) : $service->handle( $operation, $input );
							return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
						},
					],
				]
			);
		}
	}
}
