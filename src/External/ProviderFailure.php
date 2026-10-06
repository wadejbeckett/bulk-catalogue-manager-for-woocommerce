<?php
/**
 * A provider call that threw (Guard::run()). Its message is for the log
 * only; callers show a plain sentence instead.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

defined( 'ABSPATH' ) || exit;

/**
 * A failed provider call.
 */
final class ProviderFailure extends \RuntimeException {
	/**
	 * Wrap what the provider threw.
	 *
	 * @param string     $provider The provider id.
	 * @param string     $method   The method called.
	 * @param \Throwable $previous What it threw.
	 */
	public function __construct( public readonly string $provider, public readonly string $method, \Throwable $previous ) {
		parent::__construct( sprintf( 'Add-on "%s" failed in %s.', $provider, $method ), 0, $previous );
	}
}
