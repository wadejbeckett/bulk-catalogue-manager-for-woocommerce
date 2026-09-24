<?php
/**
 * A sentinel WC_Product for unit tests: constructing one counts in
 * $GLOBALS['bulkcat_test']['products_built'] and fires `woocommerce_product_read`,
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
			$GLOBALS['bulkcat_test']['products_built'] = ( $GLOBALS['bulkcat_test']['products_built'] ?? 0 ) + 1;
			do_action( 'woocommerce_product_read', $product );
		}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- T32's stand-ins sit beside the sentinel.

// T32: the smallest faithful stand-ins for the writer. WooCommerce's
// WC_Product_Variable::sync( $product, $save = true ) loads the parent, runs
// its data store's sync_price() and sync_stock_status(), and saves it; the
// stub records the id in $GLOBALS['bulkcat_test']['variable_syncs'] instead.
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	/**
	 * Records each parent resynchronisation.
	 */
	class WC_Product_Variable {
		/**
		 * Same signature as WooCommerce's.
		 *
		 * @param mixed $product Parent id or object.
		 * @param bool  $save    Whether to save the parent.
		 */
		public static function sync( mixed $product, bool $save = true ): mixed {
			$GLOBALS['bulkcat_test']['variable_syncs'][] = $product;
			return $product;
		}
	}
}

// WooCommerce's CRUD setters throw this for a value they refuse, such as a duplicate SKU.
if ( ! class_exists( 'WC_Data_Exception' ) ) {
	/**
	 * A setter's refusal.
	 */
	class WC_Data_Exception extends Exception {
		/**
		 * Same parameters as WooCommerce's.
		 *
		 * @param string $error_code       Error code.
		 * @param string $message          Error message.
		 * @param int    $http_status_code HTTP status.
		 * @param array  $error_data       Extra data.
		 */
		public function __construct( protected string $error_code, string $message, int $http_status_code = 400, protected array $error_data = [] ) {
			parent::__construct( $message, $http_status_code );
		}

		/**
		 * The error code.
		 */
		public function getErrorCode(): string {
			return $this->error_code;
		}
	}
}
