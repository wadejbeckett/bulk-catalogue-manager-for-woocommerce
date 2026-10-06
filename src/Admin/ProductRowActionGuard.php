<?php
/** Fresh authorization for manager links; native handlers still own mutations. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Admin;

defined( 'ABSPATH' ) || exit;

/** Runs before the native admin action, never replaces it. */
final class ProductRowActionGuard {
	/** WooCommerce runs its original duplication callback after this guard. */
	public static function duplicate(): void {
		if ( ! isset( $_REQUEST['bulkcat_row_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marked traffic enters the nonce-verified guard below.
			return;
		}
		$id = self::check( 'duplicate', 'duplicate_product', 'woocommerce-duplicate-product_' );
		unset( $id );
		global $wp_filter;
		foreach ( $wp_filter['admin_action_duplicate_product']->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( $priority > 0 && is_array( $callback ) && ( $callback[0] ?? null ) instanceof \WC_Admin_Duplicate_Product && 'duplicate_product_action' === ( $callback[1] ?? null ) && is_callable( $callback ) ) {
					return;
				}
			}
		}
		self::stop( __( 'Native product duplication is unavailable. Return to the product list and try again after WooCommerce is available.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
	}

	/** post.php performs its own native lock, Trash, hook and redirect handling next. */
	public static function trash(): void {
		if ( ! isset( $_REQUEST['bulkcat_row_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marked traffic enters the nonce-verified guard below.
			return;
		}
		$id    = self::check( 'trash', 'trash', 'trash-post_' );
		$flag  = self::scalar( 'bulkcat_row_sales' );
		$nonce = self::scalar( 'bulkcat_row_nonce' );
		if ( ! in_array( $flag, [ '0', '1' ], true ) || ! wp_verify_nonce( $nonce, 'bulkcat_row_trash_' . $id . '_sales_' . $flag ) ) {
			self::stop( __( 'This product action has expired or changed. Reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
		}
		if ( ProductRowActions::needs_sales_warning( get_post( $id ) ) && '1' !== $flag ) {
			self::stop( __( 'This product now has sales. Reload the list and review the warning before moving it to Trash.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
		}
	}

	/** Accept only the signed GET link and the same target the native handler will use. */
	private static function check( string $marker, string $native_action, string $nonce_action ): int {
		// Read-only parsing; both native and manager nonces are checked before dispatch.
		if ( [] !== $_POST || 'GET' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || self::scalar( 'bulkcat_row_action' ) !== $marker || self::scalar( 'action' ) !== $native_action ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict method comparison rejects mixed POST input before validating the signed GET request below.
			self::stop( __( 'This product action does not match its link. Reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 400 );
		}
		$raw = self::scalar( 'post' );
		$id  = filter_var( $raw, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		if ( false === $id || (string) $id !== $raw ) {
			self::stop( __( 'This product link has no valid product. Reload the list.', 'bulk-catalogue-manager-for-woocommerce' ), 400 );
		}
		if ( ! wp_verify_nonce( self::scalar( '_wpnonce' ), $nonce_action . $id ) ) {
			self::stop( __( 'This product action has expired or changed. Reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
		}
		clean_post_cache( $id );
		$allowed = ProductRowActions::authorise( $marker, $id );
		if ( true !== $allowed ) {
			self::stop( $allowed->get_error_message(), (int) $allowed->get_error_data()['status'] );
		}
		return $id;
	}

	/** Exact scalar GET/request agreement prevents different native and guard targets. */
	private static function scalar( string $key ): string {
		// Raw values are compared/validated here, never used as markup or a replacement nonce.
		$get     = $_GET[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict scalar comparison precedes action-specific nonce verification.
		$request = $_REQUEST[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Must match the actual native request exactly, not a sanitized alternative.
		return ( is_string( $get ) || is_int( $get ) ) && $get === $request ? (string) wp_unslash( $get ) : '';
	}

	/** Native failure page, never a success toast or automatic retry. */
	private static function stop( string $message, int $status ): never {
		wp_die(
			esc_html( $message ),
			'',
			[
				'response'  => intval( $status ),
				'back_link' => true,
			]
		);
	}
}
