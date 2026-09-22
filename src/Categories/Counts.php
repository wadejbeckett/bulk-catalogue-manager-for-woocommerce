<?php
/**
 * Product counts for the whole category tree in one grouped statement
 * (SPEC §7, §12.2), honouring the grid's active filters.
 *
 * The rows the grid would list come from ProductQuery itself: the statement
 * embeds ProductQuery::conditions_sql() (its FROM and WHERE with their
 * arguments) for the same criteria and prepares the whole once, so the tree
 * and the grid can never disagree about which rows
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
	 * The grouped statement, prepared once over ProductQuery's conditions.
	 */
	public function statement(): string {
		global $wpdb;
		$conditions = $this->query->conditions_sql();
		$sql        = 'SELECT g.terms, COUNT(*) AS products FROM (SELECT cr.object_id, GROUP_CONCAT(DISTINCT ct.term_id ORDER BY ct.term_id SEPARATOR %s) AS terms FROM %i AS cr INNER JOIN %i AS ct ON ct.term_taxonomy_id = cr.term_taxonomy_id WHERE ct.taxonomy = %s AND cr.object_id IN (SELECT (CASE WHEN p.post_type = %s THEN p.post_parent ELSE p.ID END) '
			. $conditions['sql']
			. ') GROUP BY cr.object_id) AS g GROUP BY g.terms LIMIT %d';
		$args       = [
			',',
			$wpdb->prefix . ProductQuery::TABLES['relationships'],
			$wpdb->prefix . ProductQuery::TABLES['taxonomy'],
			self::TAXONOMY,
			'product_variation',
			...$conditions['args'],
			self::MAX_GROUPS,
		];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only literals and ProductQuery's placeholders; every value is an argument.
		return (string) $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Run the statement.
	 *
	 * @return list<array{0: list<int>, 1: int}> Each distinct set of category ids and how many products carry exactly it.
	 */
	public function groups(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement() returns a prepared statement.
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
}
