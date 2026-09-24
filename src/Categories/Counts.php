<?php
/**
 * Product counts for the whole category tree in one grouped statement
 * (SPEC §7, §12.2), honouring the grid's active filters.
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
 *
 * The rows the grid would list come from ProductQuery itself: the statement
 * embeds ProductQuery::conditions_sql() (its FROM and WHERE with their
 * arguments) for the same criteria and prepares the whole once, so the tree
 * and the grid can never disagree about which rows match. Each matching row
 * is reduced to its product (a variation to its parent) through
 * ProductQuery::product_owner(): the row itself in products mode, so the
 * sub-query reads only the posts' type and status index (T76: the CASE
 * expression it replaced read every post row, which at 20,000 products was
 * most of the tree's cold time), and each product's
 * `product_cat` relationships are joined to the tree's own (category,
 * ancestor) pairs — every node paired with itself and each node above it, as
 * Tree::pairs() gives them, so orphans and loops count where the panel draws
 * them — then counted per ancestor:
 *
 *     SELECT a.ancestor AS term_id,
 *       COUNT(DISTINCT CASE WHEN a.term_id = a.ancestor THEN cr.object_id END) AS own,
 *       COUNT(DISTINCT cr.object_id) AS total
 *     FROM term_relationships cr JOIN term_taxonomy ct ...
 *     JOIN ( SELECT 4 AS term_id, 4 AS ancestor UNION ALL SELECT 4, 3 ... ) a
 *       ON a.term_id = ct.term_id
 *     WHERE ct.taxonomy = 'product_cat' AND cr.object_id IN (
 *       SELECT <product of the row> FROM ... WHERE ...   -- ProductQuery's
 *     ) GROUP BY a.ancestor LIMIT <nodes>
 *
 * One row per category, whatever the catalogue holds: `own` counts the
 * products filed directly in it, `total` the products in it or anywhere
 * below it, each once (a product in a child and a grandchild counts once on
 * the parent). Nothing is concatenated, so a product in any number of
 * categories counts exactly (the GROUP_CONCAT form this replaces cut a list
 * at MySQL's default 1024 bytes).
 */
final class Counts {
	/**
	 * The taxonomy counted.
	 */
	public const TAXONOMY = 'product_cat';

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
	 * The grouped statement over a tree's pairs, prepared once over ProductQuery's conditions.
	 *
	 * @param Tree $tree The category tree; not empty.
	 * @throws \InvalidArgumentException For an empty tree.
	 */
	public function statement( Tree $tree ): string {
		global $wpdb;
		$pairs = $tree->pairs();
		if ( [] === $pairs ) {
			throw new \InvalidArgumentException( 'An empty tree has nothing to count.' );
		}
		$conditions             = $this->query->conditions_sql();
		[ $owner, $owner_args ] = $this->query->product_owner();
		$ancestors              = 'SELECT %d AS term_id, %d AS ancestor' . str_repeat( ' UNION ALL SELECT %d, %d', count( $pairs ) - 1 );
		$sql                    = 'SELECT a.ancestor AS term_id, COUNT(DISTINCT CASE WHEN a.term_id = a.ancestor THEN cr.object_id END) AS own, COUNT(DISTINCT cr.object_id) AS total FROM %i AS cr INNER JOIN %i AS ct ON ct.term_taxonomy_id = cr.term_taxonomy_id INNER JOIN ('
			. $ancestors
			. ') AS a ON a.term_id = ct.term_id WHERE ct.taxonomy = %s AND cr.object_id IN (SELECT ' . $owner . ' '
			. $conditions['sql']
			. ') GROUP BY a.ancestor LIMIT %d';
		$args                   = [
			$wpdb->prefix . ProductQuery::TABLES['relationships'],
			$wpdb->prefix . ProductQuery::TABLES['taxonomy'],
			...array_merge( ...$pairs ),
			self::TAXONOMY,
			...$owner_args,
			...$conditions['args'],
			$tree->size(),
		];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only literals and placeholders; every value is an argument.
		return (string) $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Run the statement: each category's own count and its count including
	 * descendants, 0 for a category no matching product reaches.
	 *
	 * @param Tree $tree The category tree; not empty.
	 * @return array{own: array<int, int>, total: array<int, int>}
	 */
	public function totals( Tree $tree ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement() returns a prepared statement.
		$rows   = $wpdb->get_results( $this->statement( $tree ), ARRAY_A );
		$counts = [
			'own'   => [],
			'total' => [],
		];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id = (int) ( $row['term_id'] ?? 0 );
			if ( null !== $tree->node( $id ) ) {
				$counts['own'][ $id ]   = (int) ( $row['own'] ?? 0 );
				$counts['total'][ $id ] = (int) ( $row['total'] ?? 0 );
			}
		}
		return $counts;
	}
}
