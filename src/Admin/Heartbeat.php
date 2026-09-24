<?php
/**
 * The screen's nonce refresh through the Heartbeat API (SPEC §13, T39). The
 * screen's script depends on core's `heartbeat` script and adds a `bulkcat_nonce`
 * key to every tick; the `heartbeat_received` filter here answers that key,
 * for a user holding the screen capability only, with a fresh `wp_rest` nonce
 * that the client installs on apiFetch's nonce middleware. Heartbeat requests
 * are authenticated by core (logged-in cookie plus its own heartbeat nonce),
 * so a tick from a signed-out browser never reaches the filter.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Admin;

use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Heartbeat nonce refresh.
 */
final class Heartbeat {
	/**
	 * The key the screen sends on each tick and the key of the answer.
	 */
	public const KEY = 'bulkcat_nonce';

	/**
	 * Core's script handle for the Heartbeat API, a dependency of the screen's script.
	 */
	public const SCRIPT = 'heartbeat';

	/**
	 * The filter the answer is added on.
	 */
	public const HOOK = 'heartbeat_received';

	/**
	 * Add a fresh `wp_rest` nonce under KEY when the tick asked for one and
	 * the user holds the screen capability; otherwise leave the response alone.
	 *
	 * @param mixed $response The Heartbeat response so far.
	 * @param mixed $data     The data the browser sent with the tick.
	 * @return mixed The response.
	 */
	public static function received( mixed $response, mixed $data = [] ): mixed {
		if ( ! is_array( $response ) || ! is_array( $data ) || ! array_key_exists( self::KEY, $data ) ) {
			return $response;
		}
		if ( ! is_user_logged_in() || ! current_user_can( Access::capability() ) ) {
			return $response;
		}
		$response[ self::KEY ] = Routes::nonce();
		return $response;
	}
}
