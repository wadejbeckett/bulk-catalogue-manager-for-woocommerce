<?php
/**
 * One-at-a-time guard for the reads that lift PHP's time limit (a change's
 * conflict count and a history export), per user and per kind of read. A
 * second such read from the same user while the first is still working is
 * refused with 429, so one account cannot tie up every PHP worker. The guard
 * is a MySQL named lock taken without waiting: the database releases it when
 * the request's connection ends, so a request that dies never leaves it
 * stuck. Where the server refuses named locks the read runs unguarded, as
 * before.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user lock for expensive reads.
 */
final class HeavyReadLock {
	/**
	 * Counting the conflicts an undo would meet.
	 */
	public const COUNT = 'count';

	/**
	 * Streaming a history export.
	 */
	public const EXPORT = 'export';

	/**
	 * The refusal code when the user already has such a read running.
	 */
	public const BUSY = 'bulkcat_busy';

	/**
	 * The lock name for a user's read of one kind, scoped to the site's
	 * tables; at most 64 characters, MySQL's limit.
	 *
	 * @param string $kind    COUNT or EXPORT.
	 * @param int    $user_id The user.
	 */
	public static function name( string $kind, int $user_id ): string {
		global $wpdb;
		return 'bulkcat_heavy_' . md5( $wpdb->prefix . '|' . $kind . '|' . $user_id );
	}

	/**
	 * Take the current user's lock for a kind of read, without waiting.
	 *
	 * @param string $kind COUNT or EXPORT.
	 * @return string|\WP_Error The lock name to release, '' when the server
	 *                          refuses named locks (the read runs unguarded),
	 *                          or the busy refusal.
	 */
	public static function acquire( string $kind ): string|\WP_Error {
		global $wpdb;
		$name = self::name( $kind, get_current_user_id() );
		// A named lock lives in the database connection; nothing is stored.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL named lock; no WordPress API takes one.
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			return '';
		}
		return Refusal::create(
			self::BUSY,
			self::COUNT === $kind
				? __( 'Your last check of this change is still running. Wait for it to finish, then try again.', 'bulk-catalogue-manager-for-woocommerce' )
				: __( 'Your last history export is still being prepared. Wait for it to finish, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			429
		);
	}

	/**
	 * Release a lock acquire() took. Does nothing for ''.
	 *
	 * @param string $name What acquire() answered.
	 */
	public static function release( string $name ): void {
		global $wpdb;
		if ( '' === $name ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the MySQL named lock taken above.
	}
}
