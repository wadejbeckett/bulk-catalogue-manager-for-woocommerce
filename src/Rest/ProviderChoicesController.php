<?php
/** Bounded choices under the existing authenticated REST namespace. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\External\V2\{LookupService, Rules};
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
final class ProviderChoicesController implements Controller {
	public function __construct( private readonly ?LookupService $service = null ) {}
	public function register_routes( Routes $routes ): void {
		foreach ( [ 'search', 'resolve' ] as $operation ) {
			$routes->add(
				'/provider-choices/' . $operation,
				[
					'methods'             => 'POST',
					'callback'            => [ $this,$operation ],
					'permission_callback' => [ Access::class,'can_read' ],
				]
			);}
	}
	/** Check raw JSON bytes as well as the decoded value, without coercing bounds. */
	public static function parameters( \WP_REST_Request $request ): array|\WP_Error {
		try {
			if ( method_exists( $request, 'get_body' ) ) {
				Rules::ensure( strlen( $request->get_body() ) <= 65536, 'Request too large.' );}
			$params = array_diff_key( $request->get_params(), array_flip( [ '_locale','_wpnonce','rest_route' ] ) );
			Rules::json( $params, 65536 );
			return $params;
		} catch ( \Throwable $error ) {
			return Refusals::invalid_lookup();}
	}
	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->answer( $request, false );}
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->answer( $request, true );}
	private function answer( \WP_REST_Request $request, bool $resolve ): \WP_REST_Response|\WP_Error {
		$access = Access::can_read();
		if ( true !== $access ) {
			return $access;}
		$input = self::parameters( $request );
		if ( is_wp_error( $input ) ) {
			return $input;}
		$service = $this->service ?? new LookupService();
		$result  = $resolve ? $service->resolve( $input, get_current_user_id() ) : $service->search( $input, get_current_user_id() );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200, [ 'Cache-Control' => 'private, no-store' ] );
	}
}
