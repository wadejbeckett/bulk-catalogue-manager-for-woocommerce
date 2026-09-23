<?php
/**
 * The category tree's cache (SPEC §7, §12.2): one `bcm_category_tree_`
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
	public const PREFIX = 'bcm_category_tree_';

	/**
	 * The shape of a stored tree, in every key, so a tree stored before a
	 * node gained a property (2: `description`) is never served.
	 */
	public const SHAPE = 2;

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
	 * Add the invalidation hooks. WordPress keeps one entry per callback and
	 * priority, so a second call adds nothing.
	 */
	public static function register_hooks(): void {
		foreach ( self::HOOKS as $hook => $position ) {
			add_action( $hook, 4 === $position ? [ self::class, 'on_object_terms' ] : [ self::class, 'on_term' ], 10, $position );
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
	}

	/**
	 * The transient name for a set of grid conditions.
	 *
	 * @param array<string, mixed> $conditions Criteria::conditions().
	 */
	public function key( array $conditions ): string {
		$parts = [ self::version(), self::SHAPE ];
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
		$stored = get_transient( $key );
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
