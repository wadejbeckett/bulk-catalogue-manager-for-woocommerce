<?php
/**
 * The external action contract's version (docs/EXTENSIONS.md §12): an
 * integer independent of the plugin's own version. A provider reads it from
 * the constant BULKCAT_EXTENSION_CONTRACT before adding its callback, and
 * asks Registrar::supports() before registering.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Contract versions.
 */
final class Contract {
	/**
	 * The newest contract version this core implements; also BULKCAT_EXTENSION_CONTRACT.
	 */
	public const VERSION = 1;

	/**
	 * The contract versions a provider may target.
	 */
	public const SUPPORTED = [ 1 ];

	/**
	 * The action hook a provider registers on (docs/EXTENSIONS.md §3.1).
	 */
	public const HOOK = 'bulkcat_register_providers';
}
