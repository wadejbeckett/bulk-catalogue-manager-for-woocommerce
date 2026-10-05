<?php
/**
 * Per-site user option stubs for unit tests: get_user_option() and
 * update_user_option() store under the site's table prefix (`wp_` here) in
 * the user meta stubs, as WordPress does for a site-scoped option. Under
 * WordPress this file defines nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once __DIR__ . '/user-meta-stubs.php';

if ( ! function_exists( 'get_user_option' ) ) {
	function get_user_option( string $option, int $user = 0 ): mixed {
		$user  = 0 === $user ? (int) ( $GLOBALS['bulkcat_test']['user_id'] ?? 0 ) : $user;
		$value = $GLOBALS['bulkcat_test']['user_meta'][ $user ][ 'wp_' . $option ] ?? null;
		return null === $value ? false : $value;
	}
}

if ( ! function_exists( 'update_user_option' ) ) {
	function update_user_option( int $user_id, string $option_name, mixed $newvalue, bool $is_global = false ): int|bool {
		if ( ! empty( $GLOBALS['bulkcat_test']['user_option_fails'] ) ) {
			return false;
		}
		$key = ( $is_global ? '' : 'wp_' ) . $option_name;
		$GLOBALS['bulkcat_test']['user_meta'][ $user_id ][ $key ] = is_string( $newvalue ) ? stripslashes( $newvalue ) : $newvalue;
		return true;
	}
}
