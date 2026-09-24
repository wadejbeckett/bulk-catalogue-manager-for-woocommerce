<?php
/**
 * User meta stubs for unit tests, kept per user id in
 * $GLOBALS['bulkcat_test']['user_meta'], which bulkcat_test_reset() clears. Loaded
 * by the tests that need them. Under WordPress this file defines nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		$value = $GLOBALS['bulkcat_test']['user_meta'][ $user_id ][ $key ] ?? null;
		if ( null === $value ) {
			return $single ? '' : [];
		}
		return $single ? $value : [ $value ];
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		// Core unslashes the value before storing it.
		$GLOBALS['bulkcat_test']['user_meta'][ $user_id ][ $meta_key ] = is_string( $meta_value ) ? stripslashes( $meta_value ) : $meta_value;
		return true;
	}
}
