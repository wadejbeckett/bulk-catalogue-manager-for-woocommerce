<?php
/**
 * The category tree's cache (SPEC §7, §12.2): one `bulkcat_category_tree_`
 * transient per set of grid conditions, keyed by a hash of those conditions
 * and of a version kept in its own transient.
 *
 * The term hooks renew the version: `created_term`, `edited_term` and
 * `delete_term` for a product category, and `set_object_terms` when products
 * gain or lose one. With a persistent object cache the terms' and posts'
 * last-changed values join the key too, so any term or product edit that
 * changes the tree or which products match is seen at once. Without one
 * WordPress sets those values afresh on every request, so they stay out of
 * the key (as in Query\CountCache) or a stored tree would never be read back
 * and each request would write a new transient; there the version alone
 * covers category and assignment changes, and a product edit that changes
 * only which products match the conditions shows within TTL seconds.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

defined( 'ABSPATH' ) || exit;

/**
 * Tree cache keys, reads, writes and invalidation.
 */
final class Cache {
	/**
	 * Every transient of the tree cache starts with this (Data\Registry).
	 */
	public const PREFIX = 'bulkcat_category_tree_';

	/**
	 * The shape of a stored tree, in every key, so a tree stored before a
	 * node gained a property (2: `description`; 3: `default`, and the order
	 * read from WooCommerce's own `order` only) is never served.
	 */
	public const SHAPE = 3;

	/**
	 * The transient holding the current version.
	 */
	public const VERSION = self::PREFIX . 'version';

	/**
	 * Longest a stored tree lives, in seconds.
	 */
	public const TTL = 300;

	/**
	 * Term hooks that renew the version: hook => position of the taxonomy argument.
	 */
	public const HOOKS = [
		'created_term'     => 3,
		'edited_term'      => 3,
		'delete_term'      => 3,
		'set_object_terms' => 4,
	];

	/**
	 * Term meta hooks that renew the version when a category's sibling order
	 * (WooCommerce's `order`, which its own drag-reorder writes with no term
	 * hook), display type or image changes: hook => arguments taken.
	 */
	public const META_HOOKS = [
		'added_term_meta'   => 3,
		'updated_term_meta' => 3,
		'deleted_term_meta' => 3,
	];

	/**
	 * Term meta keys whose change shows in the tree.
	 */
	public const META_KEYS = [ CategoryTree::ORDER_META, 'display_type', 'thumbnail_id' ];

	/**
	 * Whether the version was renewed and no tree read since, so the next
	 * meta change in a run (WooCommerce renumbers every category in one
	 * drag) need not renew it again.
	 *
	 * @var bool
	 */
	private static bool $renewed = false;

	/**
	 * Add the invalidation hooks. WordPress keeps one entry per callback and
	 * priority, so a second call adds nothing.
	 */
	public static function register_hooks(): void {
		foreach ( self::HOOKS as $hook => $position ) {
			add_action( $hook, 4 === $position ? [ self::class, 'on_object_terms' ] : [ self::class, 'on_term' ], 10, $position );
		}
		foreach ( self::META_HOOKS as $hook => $accepted ) {
			add_action( $hook, [ self::class, 'on_term_meta' ], 10, $accepted );
		}
	}

	/**
	 * `added_term_meta`, `updated_term_meta` and `deleted_term_meta`: renew
	 * the version when a key the tree shows changed (ticket 23: a drag on
	 * WooCommerce's own screen shows here at once). The first change since
	 * the last tree read renews it; the rest of a run's changes need not.
	 *
	 * @param mixed $meta_id  Meta id or ids; unused.
	 * @param mixed $term_id  Term id; unused.
	 * @param mixed $meta_key Meta key.
	 */
	public static function on_term_meta( mixed $meta_id = 0, mixed $term_id = 0, mixed $meta_key = '' ): void {
		unset( $meta_id, $term_id );
		if ( ! self::$renewed && in_array( $meta_key, self::META_KEYS, true ) ) {
			self::invalidate();
		}
	}

	/**
	 * `created_term`, `edited_term` and `delete_term`: renew the version for a product category.
	 *
	 * @param mixed $term_id  Term id; unused.
	 * @param mixed $tt_id    Term taxonomy id; unused.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function on_term( mixed $term_id = 0, mixed $tt_id = 0, mixed $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( Counts::TAXONOMY === $taxonomy ) {
			self::invalidate();
		}
	}

	/**
	 * `set_object_terms`: renew the version when an object's product categories are set.
	 *
	 * @param mixed $object_id Object id; unused.
	 * @param mixed $terms     Terms given; unused.
	 * @param mixed $tt_ids    Term taxonomy ids; unused.
	 * @param mixed $taxonomy  Taxonomy.
	 */
	public static function on_object_terms( mixed $object_id = 0, mixed $terms = [], mixed $tt_ids = [], mixed $taxonomy = '' ): void {
		unset( $object_id, $terms, $tt_ids );
		if ( Counts::TAXONOMY === $taxonomy ) {
			self::invalidate();
		}
	}

	/**
	 * Make every stored tree stale by renewing the version. (Core renews the
	 * terms last-changed value itself on the same events.)
	 */
	public static function invalidate(): void {
		set_transient( self::VERSION, uniqid( '', true ), 0 );
		self::$renewed = true;
		HistoryText::reset();
	}

	/**
	 * The transient name for a set of grid conditions.
	 *
	 * @param array<string, mixed> $conditions Criteria::conditions().
	 */
	public function key( array $conditions ): string {
		// The default category is an option, not a term: a new default shows at once.
		$parts = [ self::version(), self::SHAPE, CategoryTree::default_id() ];
		if ( wp_using_ext_object_cache() ) {
			$parts[] = wp_cache_get_last_changed( 'terms' );
			$parts[] = wp_cache_get_last_changed( 'posts' );
		}
		return self::PREFIX . md5( (string) wp_json_encode( [ $parts, $conditions ] ) );
	}

	/**
	 * A stored tree, or null.
	 *
	 * @param string $key Transient name from key().
	 * @return list<array<string, mixed>>|null
	 */
	public function get( string $key ): ?array {
		self::$renewed = false;
		$stored        = get_transient( $key );
		return is_array( $stored ) && isset( $stored['tree'] ) && is_array( $stored['tree'] ) ? $stored['tree'] : null;
	}

	/**
	 * Store a tree.
	 *
	 * @param string                     $key  Transient name from key().
	 * @param list<array<string, mixed>> $tree The nested tree.
	 */
	public function set( string $key, array $tree ): void {
		set_transient( $key, [ 'tree' => $tree ], self::TTL );
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
