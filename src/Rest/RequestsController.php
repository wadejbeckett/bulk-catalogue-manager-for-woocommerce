<?php
/**
 * Guarded durable capture issuance/resolution. Later slices add operation kinds.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Requests\RequestService;
defined( 'ABSPATH' ) || exit;

/**
 * `POST /requests` issues a server grant for a capture, composition, Apply
 * or undo intent (the kinds in RequestService::OPERATIONS) without acting on
 * it; `POST /requests/resolve` answers what became of a grant — the original
 * outcome, a definite refusal, or `not_accepted` — and never creates work.
 * Both share the existing write authorization boundary.
 */
final class RequestsController implements Controller {
	/** Registry-derived arguments. */
	private RouteSchema $schema;

	/**
	 * Construct over the field registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( Registry $registry ) {
		$this->schema = new RouteSchema( $registry );
	}

	/**
	 * Register issuance and resolution through the common registrar.
	 *
	 * @param Routes $routes Registrar.
	 */
	public function register_routes( Routes $routes ): void {
		foreach ( [
			'/requests'         => 'issue',
			'/requests/resolve' => 'resolve',
		] as $path => $method ) {
			$routes->add(
				$path,
				[
					[
						'methods'             => 'POST',
						'callback'            => [ $this, $method ],
						'permission_callback' => [ $this, 'permission' ],
						'args'                => $this->schema->request_args( 'issue' === $method ) + ( 'resolve' === $method ? [
							'operation' => [
								'type' => 'string',
								'enum' => RequestService::OPERATIONS,
							],
						] : [] ),
					],
				]
			);
		}
	}

	/**
	 * Inline resolution uses disclosure rights; new issuance still requires write rights.
	 *
	 * @param \WP_REST_Request $request Transport request.
	 * @return bool|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ): bool|\WP_Error {
		return 'product.inline' === $request->get_param( 'operation' ) && str_ends_with( $request->get_route(), '/resolve' ) ? Access::can_read() : Access::can_family_request( $request );
	}

	/**
	 * Issue a new identity, without executing its intent.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function issue( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::known( $request, [ 'operation', 'intent' ] ) ) {
			return self::unknown();
		}
		$result = ( new RequestService() )->issue( (string) $request->get_param( 'operation' ), (array) $request->get_param( 'intent' ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * Lookup only; never capture or enqueue work.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::perform( $request, false );
	}

	/**
	 * Opt-in grant capture from the existing selection endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function capture( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::perform( $request, true );
	}

	/**
	 * Keep unknown input from silently broadening selection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool $accept Whether capture may execute.
	 */
	private static function perform( \WP_REST_Request $request, bool $accept ): \WP_REST_Response|\WP_Error {
		$keys = $accept ? [ 'uuid', 'request_proof', 'intent' ] : [ 'uuid', 'request_proof', 'intent', 'operation' ];
		if ( ! self::known( $request, $keys ) ) {
			return self::unknown();
		}
		$service = new RequestService();
		$input   = array_intersect_key( $request->get_params(), array_flip( $keys ) );
		$result  = $accept ? $service->capture( $input ) : $service->resolve( $input );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * Transport-only WordPress parameters are never mutation arguments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[] $keys Accepted mutation fields.
	 */
	public static function known( \WP_REST_Request $request, array $keys ): bool {
		return [] === array_diff_key( $request->get_params(), array_flip( [ ...$keys, '_locale', '_wpnonce', 'rest_route' ] ) );
	}

	/** Plain unknown-input refusal. */
	public static function unknown(): \WP_Error {
		return Refusal::invalid( 'bulkcat_invalid_value', __( 'This request contains an unknown option. Reload the catalogue and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}
}
