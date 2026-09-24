<?php
/**
 * A group of `bulkcat/v1` routes. A controller never calls `register_rest_route()`
 * itself: it hands each route to the registrar, which refuses one without a
 * real permission callback (SPEC §12.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Route controller.
 */
interface Controller {
	/**
	 * Add this controller's routes through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void;
}
