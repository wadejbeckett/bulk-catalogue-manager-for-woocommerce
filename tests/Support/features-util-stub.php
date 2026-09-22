<?php
/**
 * Recording stand-in for WooCommerce's FeaturesUtil. Declared only when
 * WooCommerce's own class is absent, so under WooCommerce it defines nothing.
 * Each declaration is appended to $GLOBALS['bcm_test']['features'].
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

// Declared inside the condition so PHP does not hoist it before the check.
if ( ! class_exists( FeaturesUtil::class ) ) {
	/**
	 * Records declare_compatibility() calls instead of acting on them.
	 */
	final class FeaturesUtil {
		/**
		 * Same signature as WooCommerce's method.
		 */
		public static function declare_compatibility( string $feature_id, string $plugin_file, bool $positive_compatibility = true ): bool {
			$GLOBALS['bcm_test']['features'][] = [ $feature_id, $plugin_file, $positive_compatibility ];
			return true;
		}
	}
}
