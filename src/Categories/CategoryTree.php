<?php
/**
 * The category tree with counts for a set of grid conditions (SPEC §7): one
 * cached answer per set, and on a miss exactly one read of the term tables
 * (unfiltered, terms()) and one grouped count statement for the whole tree.
 *
 * The counts honour every active grid condition except a category filter:
 * the tree summarises the result the other filters give, so choosing a
 * category never empties its siblings (SPEC §7, "the other active filters").
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

use BulkCatalogueManager\Query\Criteria;

defined( 'ABSPATH' ) || exit;

/**
 * The tree service.
 */
final class CategoryTree {
	/**
	 * The filter the tree itself sets, left out of its counts.
	 */
	public const OWN_FILTER = 'category_ids';

	/**
	 * WooCommerce's term meta key for a category's position among its siblings.
	 */
	public const ORDER_META = 'order';

	/**
	 * The plugin's old term meta key for a category's position in this tree
	 * only (1.3.2 and earlier). Retired by ticket 23: the tree reads and a
	 * move writes WooCommerce's own ORDER_META, so this is never read or
	 * written again, and never copied into the shop's order; values already
	 * stored stay as they were until uninstall removes them (Data\Registry).
	 */
	public const TREE_ORDER_META = 'bulkcat_order';

	/**
	 * WooCommerce's option holding the default product category.
	 */
	public const DEFAULT_OPTION = 'default_product_cat';

	/**
	 * Build over a cache.
	 *
	 * @param Cache $cache Tree cache.
	 */
	public function __construct( private readonly Cache $cache = new Cache() ) {}

	/**
	 * Criteria for the counts from a read's input: mode, search and every
	 * filter except the category filter; sort and paging are dropped.
	 *
	 * @param array<string, mixed> $input Input in the shape Criteria::from_array() reads.
	 * @throws \InvalidArgumentException On anything Criteria refuses.
	 */
	public static function criteria( array $input ): Criteria {
		$kept = array_intersect_key( $input, array_flip( [ 'mode', 'search', 'filters' ] ) );
		if ( isset( $kept['filters'] ) && is_array( $kept['filters'] ) ) {
			$kept['filters'] = array_values(
				array_filter(
					$kept['filters'],
					static fn( mixed $filter ): bool => ! is_array( $filter ) || self::OWN_FILTER !== ( $filter['field'] ?? null )
				)
			);
		}
		return Criteria::from_array( $kept );
	}

	/**
	 * The nested tree for the criteria, from the cache when it is there.
	 *
	 * @param Criteria $criteria Criteria from criteria().
	 * @return list<array<string, mixed>>
	 */
	public function get( Criteria $criteria ): array {
		$key    = $this->cache->key( $criteria->conditions() );
		$cached = $this->cache->get( $key );
		if ( null !== $cached ) {
			return $cached;
		}
		$tree   = self::tree();
		$result = $tree->is_empty() ? [] : $tree->to_array_counted( ( new Counts( $criteria ) )->totals( $tree ) );
		$this->cache->set( $key, $result );
		return $result;
	}

	/**
	 * Every product category, from one read of the term tables that also
	 * primes the term meta cache WooCommerce's order is read from; never
	 * filtered for the viewer (terms(), 1.4.2).
	 */
	public static function tree(): Tree {
		return self::tree_of( self::terms() );
	}

	/**
	 * Every product category as the term tables hold it (1.3.4), for the
	 * writes that must judge whether a category exists, and what it holds,
	 * whoever is asking: undo, delete and merge. A term query is filtered
	 * for the viewer (another plugin may hide categories from someone, or
	 * from a background run with no user), so this reads the tables
	 * directly and is never filtered. Its order is WooCommerce's stored
	 * `order` meta, then the database's name order, as tree() reads it.
	 */
	public static function complete(): Tree {
		return self::tree_of( self::stored_terms() );
	}

	/**
	 * Every product category straight from the term tables, unfiltered, in
	 * the database's name order (ORDER BY t.name, which WooCommerce's own
	 * order falls back to), with the term meta cache primed for their order.
	 *
	 * @return list<object> Objects with term_id, name, slug, taxonomy, description and parent.
	 */
	public static function stored_terms(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.name ASC, t.term_id ASC', $wpdb->terms, $wpdb->term_taxonomy, Counts::TAXONOMY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An unfiltered read on purpose: term queries are filtered for the viewer.
		$rows = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : [];
		if ( [] !== $rows ) {
			update_meta_cache( 'term', array_map( static fn( object $row ): int => (int) $row->term_id, $rows ) );
		}
		return $rows;
	}

	/**
	 * One product category straight from the term tables, unfiltered; null
	 * when there is none with that id.
	 *
	 * @param int $id Term id.
	 */
	public static function stored_term( int $id ): ?object {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.term_id = %d', $wpdb->terms, $wpdb->term_taxonomy, Counts::TAXONOMY, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An unfiltered read on purpose: term queries are filtered for the viewer.
		return is_array( $rows ) && isset( $rows[0] ) && is_object( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Every product category the Categories tab lists, reads and writes:
	 * the term tables read directly, in the database's name order, with the
	 * term meta cache primed (1.4.2). A term query is filtered for the
	 * viewer, so another plugin hiding categories from it would hide them
	 * from the tab, its details and its moves, even for an administrator;
	 * the routes still check the person's term capabilities.
	 *
	 * @return list<object>
	 */
	public static function terms(): array {
		return self::stored_terms();
	}

	/**
	 * The tree of a term list, in WooCommerce's order, with its default category marked.
	 *
	 * @param list<object> $terms Terms from terms() or stored_terms(), in the database's name order.
	 */
	public static function tree_of( array $terms ): Tree {
		$orders = [];
		$ranks  = [];
		foreach ( $terms as $term ) {
			if ( isset( $term->term_id ) ) {
				$orders[ (int) $term->term_id ]  = get_term_meta( (int) $term->term_id, self::ORDER_META, true );
				$ranks[ (int) $term->term_id ] ??= count( $ranks );
			}
		}
		return Tree::from_terms( $terms, $orders, self::default_id(), $ranks );
	}

	/**
	 * The shop's default product category id, as WooCommerce reads it; 0 when none.
	 */
	public static function default_id(): int {
		return max( 0, (int) get_option( self::DEFAULT_OPTION, 0 ) );
	}
}
