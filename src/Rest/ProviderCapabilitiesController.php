<?php
/** Only qualified capabilities are advertised. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Extension\V2\Contract;
defined( 'ABSPATH' ) || exit;
final class ProviderCapabilitiesController implements Controller {
	public function register_routes( Routes $routes ): void {
		$routes->add(
			'/provider-capabilities',
			[
				'methods'             => 'GET',
				'callback'            => [ $this,'get_items' ],
				'permission_callback' => [ Access::class,'can_read' ],
			]
		);}
	public function get_items(): \WP_REST_Response|\WP_Error {
		$access = Access::can_read();
		return true !== $access ? $access : new \WP_REST_Response(
			[
				'contract'     => 2,
				'capabilities' => (object) Contract::CAPABILITIES,
			],
			200,
			[ 'Cache-Control' => 'private, no-store' ]
		);
	}
}
