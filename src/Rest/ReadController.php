<?php
/**
 * A controller whose routes read: it publishes the schema of one item of
 * its responses, generated from the field registry where the item is a
 * catalogue row (SPEC §5.1, §12.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Read route controller.
 */
interface ReadController extends Controller {
	/**
	 * The JSON Schema of one item the controller's read routes return,
	 * registered as each route's `schema`.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array;
}
