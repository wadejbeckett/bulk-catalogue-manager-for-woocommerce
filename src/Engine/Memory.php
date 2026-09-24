<?php
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
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Page-at-a-time loading.
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
	 * Release the runtime object cache, where the cache can: what a finished
	 * page or chunk loaded is not needed again in this request.
	 */
	public static function release(): void {
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
