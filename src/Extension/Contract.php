<?php
/**
 * The external action contract's version (docs/EXTENSIONS.md §12,
 * docs/EXTENSIONS-v2.md §2): an integer independent of the plugin's own
 * version. A provider reads it from the constant BULKCAT_EXTENSION_CONTRACT
 * before adding its callback, and asks Registrar::supports() before
 * registering. Version 2 is additive: a version 1 provider registers and
 * behaves exactly as before.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Contract versions and version 2 features.
 */
final class Contract {
	/**
	 * The newest contract version this core implements; also BULKCAT_EXTENSION_CONTRACT.
	 */
	public const VERSION = 2;

	/**
	 * The contract versions a provider may target.
	 */
	public const SUPPORTED = [ 1, 2 ];

	/**
	 * The action hook a provider registers on (docs/EXTENSIONS.md §3.1).
	 */
	public const HOOK = 'bulkcat_register_providers';

	/**
	 * Version 2 feature: the `lookup` argument kind, searched and paged
	 * through LookupSource (docs/EXTENSIONS-v2.md §4).
	 */
	public const FEATURE_LOOKUP = 'lookup';

	/**
	 * Version 2 feature: an action offered on one row (docs/EXTENSIONS-v2.md §6).
	 */
	public const FEATURE_ROW_ENTRY = 'row_entry';

	/**
	 * Version 2 feature: actions whose target is product categories
	 * (docs/EXTENSIONS-v2.md §5).
	 */
	public const FEATURE_CATEGORY_TARGET = 'category_target';

	/**
	 * Every version 2 feature the contract defines.
	 */
	public const DEFINED_FEATURES = [ self::FEATURE_LOOKUP, self::FEATURE_ROW_ENTRY, self::FEATURE_CATEGORY_TARGET ];

	/**
	 * The version 2 features this core implements now. A provider asks
	 * Registrar::supports_feature() before declaring one; the registrar
	 * refuses a provider that declares a feature missing from this list.
	 */
	public const FEATURES = [ self::FEATURE_LOOKUP ];
}
