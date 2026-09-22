<?php
/**
 * One cached page of the catalogue (SPEC §12.2): ProductQuery's count and
 * page statements, then the Hydrator, with the result kept in the
 * `bcm_catalogue` object-cache group. The key carries the posts' and terms'
 * last-changed values, which WordPress moves whenever a product, a
 * variation, their meta or their terms change (every WooCommerce CRUD save
 * ends in `clean_post_cache()`), so a second identical read inside the same
 * window issues no catalogue query and any product change makes the old
 * entries unreachable. The key also carries a formatted sample price, so a
 * change to the shop's currency or price format is never served stale.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads pages of the catalogue.
 */
final class Catalogue {
	/**
	 * Object-cache group for hydrated pages. Not stored data: entries expire
	 * and are unreachable once the last-changed values move.
	 */
	public const CACHE_GROUP = 'bcm_catalogue';

	/**
	 * Longest an entry is kept, in seconds, where the object cache persists.
	 */
	public const CACHE_TTL = 3600;

	/**
	 * Build over a hydrator.
	 *
	 * @param Hydrator $hydrator Row hydrator.
	 */
	public function __construct( private readonly Hydrator $hydrator ) {}

	/**
	 * One page: its hydrated rows and the total matching the criteria. The
	 * page statement is skipped when the page lies past the last row.
	 *
	 * @param Criteria $criteria Validated criteria.
	 * @param string[] $keys     Field keys each row carries; empty for every field.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function page( Criteria $criteria, array $keys ): array {
		$key    = $this->cache_key( $criteria, $keys );
		$found  = false;
		$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) && isset( $cached['items'], $cached['total'] ) ) {
			return $cached;
		}

		$query = new ProductQuery( $criteria );
		$total = $query->total();
		$page  = [
			'items' => $total > $criteria->offset() ? $this->hydrator->hydrate( $query->rows(), $keys ) : [],
			'total' => $total,
		];
		wp_cache_set( $key, $page, self::CACHE_GROUP, self::CACHE_TTL );
		return $page;
	}

	/**
	 * The cache key: a hash of the criteria, the fields and the shop's price
	 * formatting, then the posts' and terms' last-changed values.
	 *
	 * @param Criteria $criteria Criteria.
	 * @param string[] $keys     Field keys.
	 */
	public function cache_key( Criteria $criteria, array $keys ): string {
		sort( $keys );
		$hash = md5( (string) wp_json_encode( [ $criteria, $keys, wc_price( 1234.5 ) ] ) );
		return 'page:' . $hash . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
	}
}
