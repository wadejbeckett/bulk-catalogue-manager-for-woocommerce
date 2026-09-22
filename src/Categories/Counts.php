<?php
/**
 * Product counts for the whole category tree in one grouped statement
 * (SPEC §7, §12.2), honouring the grid's active filters.
 *
 * The rows the grid would list come from ProductQuery itself: the statement
 * embeds the FROM and WHERE of ProductQuery::count_statement() for the same
 * criteria, so the tree and the grid can never disagree about which rows
 * match. Each matching row is reduced to its product (a variation to its
 * parent), each product to the sorted list of its `product_cat` term ids, and
 * the products are grouped by that list:
 *
 *     SELECT g.terms, COUNT(*) AS products FROM (
 *       SELECT cr.object_id, GROUP_CONCAT(DISTINCT ct.term_id ...) AS terms
 *       FROM term_relationships cr JOIN term_taxonomy ct ...
 *       WHERE ct.taxonomy = 'product_cat' AND cr.object_id IN (
 *         SELECT <product of the row> FROM ... WHERE ...   -- ProductQuery's
 *       ) GROUP BY cr.object_id
 *     ) g GROUP BY g.terms LIMIT n
 *
 * One row per distinct combination of categories, far fewer than products,
 * from which Tree::counts() derives both counts exactly. A product in more
 * categories than MySQL's default `group_concat_max_len` (1024 bytes, over a
 * hundred category ids) would have its list cut short; no real catalogue
 * nears that.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SPEC §7, §12.2: one deliberate grouped statement for the whole tree; CategoryTree caches its result in a transient.

/**
 * The grouped category count.
 */
final class Counts {
	/**
	 * The taxonomy counted.
	 */
	public const TAXONOMY = 'product_cat';

	/**
	 * Most combinations the statement returns; each is at least one product.
	 */
	public const MAX_GROUPS = 1000000;

	/**
	 * What ProductQuery::count_statement() starts with; the rest up to the
	 * closing LIMIT is its FROM and WHERE.
	 */
	private const COUNT_HEAD = 'SELECT COUNT(*) ';

	/**
	 * What ProductQuery::count_statement() ends with.
	 */
	private const COUNT_TAIL = ' LIMIT 1';

	/**
	 * The query whose conditions are counted.
	 *
	 * @var ProductQuery
	 */
	private ProductQuery $query;

	/**
	 * Build over the grid's criteria; sort and paging are ignored.
	 *
	 * @param Criteria $criteria Validated criteria.
	 */
	public function __construct( Criteria $criteria ) {
		$this->query = new ProductQuery( $criteria );
	}

	/**
	 * The grouped statement, prepared.
	 *
	 * @throws \LogicException When ProductQuery's count statement no longer has the shape this reuses.
	 */
	public function statement(): string {
		global $wpdb;
		$head = $wpdb->prepare(
			'SELECT g.terms, COUNT(*) AS products FROM (SELECT cr.object_id, GROUP_CONCAT(DISTINCT ct.term_id ORDER BY ct.term_id SEPARATOR %s) AS terms FROM %i AS cr INNER JOIN %i AS ct ON ct.term_taxonomy_id = cr.term_taxonomy_id WHERE ct.taxonomy = %s AND cr.object_id IN (SELECT (CASE WHEN p.post_type = %s THEN p.post_parent ELSE p.ID END) ',
			',',
			$wpdb->prefix . ProductQuery::TABLES['relationships'],
			$wpdb->prefix . ProductQuery::TABLES['taxonomy'],
			self::TAXONOMY,
			'product_variation'
		);
		$tail = $wpdb->prepare( ') GROUP BY cr.object_id) AS g GROUP BY g.terms LIMIT %d', self::MAX_GROUPS );
		return $head . $this->conditions() . $tail;
	}

	/**
	 * Run the statement.
	 *
	 * @return list<array{0: list<int>, 1: int}> Each distinct set of category ids and how many products carry exactly it.
	 */
	public function groups(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement() is assembled from prepared fragments only.
		$rows   = $wpdb->get_results( $this->statement(), ARRAY_A );
		$groups = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $row['terms'] ?? '' ) ) ), static fn( int $id ): bool => $id > 0 ) );
			if ( [] !== $ids ) {
				$groups[] = [ $ids, (int) ( $row['products'] ?? 0 ) ];
			}
		}
		return $groups;
	}

	/**
	 * ProductQuery's prepared FROM and WHERE for the criteria: its count
	 * statement without the leading COUNT(*) and the closing LIMIT.
	 *
	 * @throws \LogicException When the count statement's shape has changed.
	 */
	private function conditions(): string {
		$count = $this->query->count_statement();
		if ( ! str_starts_with( $count, self::COUNT_HEAD . 'FROM ' ) || ! str_ends_with( $count, self::COUNT_TAIL ) ) {
			throw new \LogicException( 'ProductQuery::count_statement() no longer has the shape the category counts reuse.' );
		}
		return substr( $count, strlen( self::COUNT_HEAD ), -strlen( self::COUNT_TAIL ) );
	}
}
