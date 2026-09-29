<?php
/**
 * Isolation of provider code (docs/EXTENSIONS.md §3.3): every call into a
 * provider goes through run(), so an exception or PHP error from it becomes
 * a ProviderFailure the caller turns into a refusal of that request or a
 * failed result for that object. The details go to the WooCommerce log and
 * never reach the browser; one failing provider never disables another
 * provider or any native action.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps provider calls.
 */
final class Guard {
	/**
	 * Run one provider call.
	 *
	 * @param string   $provider The provider id, or '' before it is known.
	 * @param string   $method   What was called, for the log.
	 * @param callable $call     The call.
	 * @return mixed What it returned.
	 * @throws ProviderFailure When it threw.
	 */
	public static function run( string $provider, string $method, callable $call ): mixed {
		try {
			return $call();
		} catch ( \Throwable $error ) {
			self::log( sprintf( 'Add-on "%s" failed in %s: %s: %s (%s:%d)', $provider, $method, get_class( $error ), $error->getMessage(), $error->getFile(), $error->getLine() ) );
			throw new ProviderFailure( $provider, $method, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never shown: callers turn it into a plain sentence.
		}
	}

	/**
	 * Write a line to the WooCommerce log under the plugin's source.
	 *
	 * @param string $message The line.
	 * @param string $level   A log level.
	 */
	public static function log( string $message, string $level = 'error' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, [ 'source' => Refusal::LOG_SOURCE ] );
		}
	}
}
