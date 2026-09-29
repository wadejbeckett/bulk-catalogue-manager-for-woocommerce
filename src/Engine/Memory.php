<?php
/**
 * Bounded memory for requests and queue runs that load thousands of
 * products (T76, ticket 11).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded memory for a request that loads thousands of products (T76): the
 * preview loads up to 10,000 with wc_get_product(), and without a persistent
 * object cache every post, its meta, its terms and WooCommerce's own reads
 * stay in the request's runtime cache until it ends.
 *
 * next_page() releases what the previous page cached, when the object cache
 * can release its runtime copy (`wp_cache_supports( 'flush_runtime' )`;
 * WordPress's own cache can, and a persistent cache that can drops only its
 * in-request copy, never the stored values), then primes the next page's
 * posts, post meta and terms in one query each, as WP-CLI and Action
 * Scheduler's runners do between batches. With a cache that cannot release
 * its runtime copy it only primes.
 *
 * release() alone ends a background chunk (ChunkRunner): Action Scheduler's
 * WP-CLI runner frees memory only every 50 actions of one claim, and a
 * chained batch claims one action at a time, so without it a `wp
 * action-scheduler run` kept every chunk's objects cached, 0.7 MB more per
 * chunk of 50 products (70 MB over 5,000).
 *
 * WordPress also counts every hook name it fires, for the life of the
 * process (`$wp_actions` for did_action(), `$wp_filters` for did_filter()),
 * and WooCommerce's product save clears transients named after the product
 * (`delete_transient_wc_var_prices_{id}` and four more, plus the
 * `delete_option_…` and `pre_transient_…` names of those that exist), so
 * one queue run kept about 250 to 375 new names a chunk of 50: +34 MB over
 * 401 Hide chunks (T85; ticket 11). A chunk marks the counters when it
 * starts (mark()) and release( $mark ) forgets the names first counted
 * since that end in a number — per-object names, never asked about again —
 * and nothing else: every name counted before the chunk, and every other
 * new name, keeps its count.
 */
final class Memory {
	/**
	 * Start the next page of objects.
	 *
	 * @param list<int> $ids     The page's product and variation ids.
	 * @param bool      $release Whether an earlier page was loaded and can be released.
	 */
	public static function next_page( array $ids, bool $release ): void {
		if ( $release ) {
			self::release();
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
	}

	/**
	 * How many hook names WordPress has counted so far: `$wp_actions`
	 * (did_action()) and `$wp_filters` (did_filter()).
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function mark(): array {
		return [ self::counted( 'wp_actions' ), self::counted( 'wp_filters' ) ];
	}

	/**
	 * Release the runtime object cache, where the cache can: what a finished
	 * page or chunk loaded is not needed again in this request. With a
	 * mark(), also forget the per-object hook names first counted since it.
	 *
	 * @param array{0: int, 1: int}|null $mark What mark() returned when the chunk started.
	 */
	public static function release( ?array $mark = null ): void {
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( null !== $mark ) {
			self::forget( 'wp_actions', (int) ( $mark[0] ?? 0 ) );
			self::forget( 'wp_filters', (int) ( $mark[1] ?? 0 ) );
		}
	}

	/**
	 * How many names a hook counter holds.
	 *
	 * @param string $counter `wp_actions` or `wp_filters`.
	 */
	private static function counted( string $counter ): int {
		return isset( $GLOBALS[ $counter ] ) && is_array( $GLOBALS[ $counter ] ) ? count( $GLOBALS[ $counter ] ) : 0;
	}

	/**
	 * Forget the names a hook counter gained after its first $from, where
	 * they end in a number: an array keeps its keys in the order they were
	 * added, so those are the names first counted since the mark.
	 *
	 * @param string $counter `wp_actions` or `wp_filters`.
	 * @param int    $from    How many names it held at the mark.
	 */
	private static function forget( string $counter, int $from ): void {
		if ( self::counted( $counter ) <= $from ) {
			return;
		}
		foreach ( array_keys( array_slice( $GLOBALS[ $counter ], $from, null, true ) ) as $name ) {
			if ( 1 === preg_match( '/\d$/', (string) $name ) ) {
				// Only a name first counted during the chunk, never asked about again; WordPress's own counter.
				unset( $GLOBALS[ $counter ][ $name ] );
			}
		}
	}
}
