<?php
/** Scoped provider discovery; no changes to native global fields. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\External\V2\{Discovery, TypedScope, Rules};
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
final class ProviderActionsController implements Controller {
	public function __construct( private readonly ?Discovery $discovery = null ) {}
	public function register_routes( Routes $routes ): void {
		$routes->add(
			'/provider-actions',
			[
				'methods'             => 'POST',
				'callback'            => [ $this,'create_item' ],
				'permission_callback' => [ Access::class,'can_read' ],
			]
		);}
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$access = Access::can_read();
		if ( true !== $access ) {
			return $access;}
		$input = ProviderChoicesController::parameters( $request );
		if ( is_wp_error( $input ) ) {
			return $input;}
		try {
			Rules::ensure( [] === array_diff( array_keys( $input ), [ 'location','selection','args' ] ) && isset( $input['location'], $input['selection'] ) && is_string( $input['location'] ) && is_array( $input['selection'] ) && ( ! isset( $input['args'] ) || is_array( $input['args'] ) ), 'Invalid discovery.' );
			$scope = TypedScope::from_request( $input['selection'], get_current_user_id() );
			if ( is_wp_error( $scope ) ) {
					return Refusals::invalid_lookup();}
			$result = ( $this->discovery ?? new Discovery() )->for_scope( $input['location'], $scope, $input['args'] ?? [], get_current_user_id() );
			return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200, [ 'Cache-Control' => 'private, no-store' ] );
		} catch ( \Throwable $error ) {
			return Refusals::invalid_lookup();}
	}
}
