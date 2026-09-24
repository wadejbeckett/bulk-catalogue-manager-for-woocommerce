<?php
/**
 * The category tree with counts for a set of grid conditions (SPEC §7): one
 * cached answer per set, and on a miss exactly one `get_terms()` call and
 * one grouped count statement for the whole tree.
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
	 * The plugin's own term meta key for a category's position among its
	 * siblings in this tree only, written by a move (Categories\Writer) and
	 * never read by the storefront (SPEC §4.7, §7; Data\Registry).
	 */
	public const TREE_ORDER_META = 'bulkcat_order';

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
	 * Every product category, from one get_terms() call that also primes the
	 * term meta cache both stored orders are read from.
	 */
	public static function tree(): Tree {
		return self::tree_of( self::terms() );
	}

	/**
	 * Every product category as get_terms() returns them, in one call that
	 * primes the term meta cache.
	 *
	 * @return list<object>
	 */
	public static function terms(): array {
		$terms = get_terms(
			[
				'taxonomy'               => Counts::TAXONOMY,
				'hide_empty'             => false,
				'update_term_meta_cache' => true,
			]
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, 'is_object' ) ) : [];
	}

	/**
	 * The tree of a term list, with WooCommerce's order and the plugin's tree order.
	 *
	 * @param list<object> $terms Terms from terms().
	 */
	public static function tree_of( array $terms ): Tree {
		$orders      = [];
		$tree_orders = [];
		foreach ( $terms as $term ) {
			if ( isset( $term->term_id ) ) {
				$orders[ (int) $term->term_id ]      = get_term_meta( (int) $term->term_id, self::ORDER_META, true );
				$tree_orders[ (int) $term->term_id ] = get_term_meta( (int) $term->term_id, self::TREE_ORDER_META, true );
			}
		}
		return Tree::from_terms( $terms, $orders, $tree_orders );
	}
}
