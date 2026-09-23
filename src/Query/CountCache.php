<?php
/**
 * The filtered count's cache (SPEC §12.2): ProductQuery's `COUNT(*)` over a
 * read's conditions, kept 60 seconds in a `bcm_count_` transient whose name
 * is a hash of the normalised conditions (Criteria::conditions(): mode,
 * search, filters and parent, never sort or paging), so every page, sort and
 * page size of one view shares one count, and any change to the conditions
 * is a different transient. `SQL_CALC_FOUND_ROWS` is never used.
 *
 * Invalidation. With a persistent object cache the hash also carries the
 * posts' and terms' last-changed values, which WordPress moves whenever a
 * product, a variation, their meta or their terms change (every WooCommerce
 * CRUD save ends in `clean_post_cache()`), so a changed catalogue is a new
 * key at once. Without one those values are new on every request and a
 * transient keyed on them would never be read back, so the hash carries a
 * version kept in its own transient instead, which the hooks below renew:
 * `clean_post_cache` for a product or variation, `set_object_terms` for a
 * product taxonomy, and `edited_term` and `delete_term` for one (a moved or
 * deleted category changes which products a category filter's branch holds).
 * The version is in the hash in both cases.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Cached totals.
 */
final class CountCache {
	/**
	 * Every transient of the count cache starts with this (Data\Registry).
	 */
	public const PREFIX = 'bcm_count_';

	/**
	 * The transient holding the current version.
	 */
	public const VERSION = self::PREFIX . 'version';

	/**
	 * Longest a stored count lives, in seconds (SPEC §12.2).
	 */
	public const TTL = 60;

	/**
	 * Post types whose changes renew the version.
	 */
	public const POST_TYPES = [ 'product', 'product_variation' ];

	/**
	 * Hooks that renew the version: hook => [ callback method, accepted arguments ].
	 */
	public const HOOKS = [
		'clean_post_cache' => [ 'on_post', 2 ],
		'set_object_terms' => [ 'on_object_terms', 4 ],
		'edited_term'      => [ 'on_term', 3 ],
		'delete_term'      => [ 'on_term', 3 ],
	];

	/**
	 * Add the invalidation hooks. WordPress keeps one entry per callback and
	 * priority, so a second call adds nothing.
	 */
	public static function register_hooks(): void {
		foreach ( self::HOOKS as $hook => [ $method, $accepted ] ) {
			add_action( $hook, [ self::class, $method ], 10, $accepted );
		}
	}

	/**
	 * `clean_post_cache`: renew the version for a product or a variation.
	 *
	 * @param mixed $post_id Post id; unused.
	 * @param mixed $post    The post.
	 */
	public static function on_post( mixed $post_id = 0, mixed $post = null ): void {
		unset( $post_id );
		if ( is_object( $post ) && isset( $post->post_type ) && in_array( $post->post_type, self::POST_TYPES, true ) ) {
			self::invalidate();
		}
	}

	/**
	 * `set_object_terms`: renew the version when a product taxonomy's terms are set.
	 *
	 * @param mixed $object_id Object id; unused.
	 * @param mixed $terms     Terms given; unused.
	 * @param mixed $tt_ids    Term taxonomy ids; unused.
	 * @param mixed $taxonomy  Taxonomy.
	 */
	public static function on_object_terms( mixed $object_id = 0, mixed $terms = [], mixed $tt_ids = [], mixed $taxonomy = '' ): void {
		unset( $object_id, $terms, $tt_ids );
		self::on_taxonomy( $taxonomy );
	}

	/**
	 * `edited_term` and `delete_term`: renew the version for a product taxonomy's term.
	 *
	 * @param mixed $term_id  Term id; unused.
	 * @param mixed $tt_id    Term taxonomy id; unused.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function on_term( mixed $term_id = 0, mixed $tt_id = 0, mixed $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		self::on_taxonomy( $taxonomy );
	}

	/**
	 * Make every stored count stale by renewing the version.
	 */
	public static function invalidate(): void {
		set_transient( self::VERSION, uniqid( '', true ), 0 );
	}

	/**
	 * The total matching a query's criteria: the stored count when there is
	 * one, else ProductQuery's `COUNT(*)`, stored for TTL seconds.
	 *
	 * @param ProductQuery $query The query whose count is wanted.
	 */
	public function total( ProductQuery $query ): int {
		$key    = $this->key( $query->criteria() );
		$stored = get_transient( $key );
		if ( is_array( $stored ) && isset( $stored['total'] ) && is_int( $stored['total'] ) ) {
			return $stored['total'];
		}
		$total = $query->total();
		set_transient( $key, [ 'total' => $total ], self::TTL );
		return $total;
	}

	/**
	 * The transient name for a read's conditions.
	 *
	 * @param Criteria $criteria Criteria; only their conditions count.
	 */
	public function key( Criteria $criteria ): string {
		$parts = [ self::version() ];
		if ( wp_using_ext_object_cache() ) {
			$parts[] = wp_cache_get_last_changed( 'posts' );
			$parts[] = wp_cache_get_last_changed( 'terms' );
		}
		return self::PREFIX . md5( (string) wp_json_encode( [ $parts, $criteria->conditions() ] ) );
	}

	/**
	 * Renew the version for a product taxonomy (`product_cat`, `product_tag`,
	 * `product_type`, `product_visibility`, `product_shipping_class`).
	 *
	 * @param mixed $taxonomy Taxonomy.
	 */
	private static function on_taxonomy( mixed $taxonomy ): void {
		if ( is_string( $taxonomy ) && str_starts_with( $taxonomy, 'product_' ) ) {
			self::invalidate();
		}
	}

	/**
	 * The current version, created on first use.
	 */
	private static function version(): string {
		$version = get_transient( self::VERSION );
		if ( is_string( $version ) && '' !== $version ) {
			return $version;
		}
		$version = uniqid( '', true );
		set_transient( self::VERSION, $version, 0 );
		return $version;
	}
}
