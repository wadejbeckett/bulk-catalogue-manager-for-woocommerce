<?php
/**
 * A sentinel WC_Product for unit tests: constructing one counts in
 * $GLOBALS['bcm_test']['products_built'] and fires `woocommerce_product_read`,
 * as WooCommerce's data store does when it reads a product, so a test can
 * prove the read path never builds one. Under WooCommerce this file defines
 * nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// Declared inside the condition so PHP does not hoist it before the check.
if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Records its construction.
	 */
	class WC_Product {
		/**
		 * Same first parameter as WooCommerce's constructor.
		 *
		 * @param int $product Product id.
		 */
		public function __construct( int $product = 0 ) {
			$GLOBALS['bcm_test']['products_built'] = ( $GLOBALS['bcm_test']['products_built'] ?? 0 ) + 1;
			do_action( 'woocommerce_product_read', $product );
		}
	}
}
