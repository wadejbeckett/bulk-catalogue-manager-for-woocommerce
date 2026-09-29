<?php
/**
 * The export dialog's warnings with the identifier switch on (SPEC §10):
 * how many rows of the file have a SKU WooCommerce's importer would not keep
 * as it is, and how many have a name the formula guard prefixes, which the
 * importer does not remove from a name either (ticket 10, R3).
 *
 * It counts exactly the rows the export writes: a filter's rows, through
 * the same conditions as the grid (ProductQuery::conditions_sql()), or the
 * present members of a selection or ID list. It reads only `ID`,
 * `post_title` and `_sku` (a correlated scalar sub-query), in keyset pages
 * of PAGE rows, and never hydrates a row; each value is judged by the
 * writer's and the importer's own functions (ReimportIdentifiers::reads_back()
 * and CsvWriter::guard()), so the count cannot disagree with the file.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Query\ProductQuery;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SPEC §12.2: the counts read the catalogue's own conditions in lean, prepared, bounded statements; a count answers one dialog request and is not cached.

/**
 * Guarded SKU and name counts.
 */
final class GuardedValues {
	/**
	 * Rows read per statement.
	 */
	public const PAGE = 5000;

	/**
	 * The select list: id, name and the row's own SKU.
	 */
	private const COLUMNS = 'SELECT p.ID AS id, p.post_title AS name, (SELECT m.meta_value FROM %i AS m WHERE m.post_id = p.ID AND m.meta_key = %s LIMIT %d) AS sku ';

	/**
	 * Count in pages of $page rows.
	 *
	 * @param int $page Rows per statement; PAGE outside tests.
	 */
	public function __construct( private readonly int $page = self::PAGE ) {}

	/**
	 * The counts for every row a filter matches.
	 *
	 * @param ProductQuery $query The filter's query.
	 * @return array{identifiers: int, names: int}
	 * @throws \RuntimeException On a database error.
	 */
	public function of_query( ProductQuery $query ): array {
		global $wpdb;
		$counts     = self::zero();
		$conditions = $query->conditions_sql();
		$after      = 0;
		$sql        = self::COLUMNS . $conditions['sql'] . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d';
		do {
			$args = [ ...self::column_args(), ...$conditions['args'], $after, $this->page ];
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- ProductQuery's own FROM and WHERE (literal fragments and placeholders); every value is an argument, spread.
			$rows  = self::checked( $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) );
			$after = self::add( $rows, $counts ) ?? $after;
			$full  = count( $rows ) === $this->page;
		} while ( $full );
		return $counts;
	}

	/**
	 * The counts for these rows: a selection's or an ID list's present members.
	 *
	 * @param list<int> $ids Row ids, ascending.
	 * @return array{identifiers: int, names: int}
	 * @throws \RuntimeException On a database error.
	 */
	public function of_ids( array $ids ): array {
		global $wpdb;
		$counts = self::zero();
		foreach ( array_chunk( $ids, $this->page ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$args = [ ...self::column_args(), $wpdb->prefix . ProductQuery::TABLES['posts'], ...array_map( 'intval', $chunk ), count( $chunk ) ];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders; every value is an argument, spread.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, p.post_title AS name, (SELECT m.meta_value FROM %i AS m WHERE m.post_id = p.ID AND m.meta_key = %s LIMIT %d) AS sku FROM %i AS p WHERE p.ID IN ({$in}) ORDER BY p.ID ASC LIMIT %d", ...$args ), ARRAY_A );
			self::add( self::checked( $rows ), $counts );
		}
		return $counts;
	}

	/**
	 * Arguments of the select list.
	 *
	 * @return list<int|string>
	 */
	private static function column_args(): array {
		global $wpdb;
		return [ $wpdb->postmeta ?? $wpdb->prefix . 'postmeta', ReimportIdentifiers::SKU, 1 ];
	}

	/**
	 * A statement's rows, or an exception when the database failed.
	 *
	 * @param mixed $rows What get_results() returned.
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException On a database error.
	 */
	private static function checked( mixed $rows ): array {
		global $wpdb;
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'The guarded values could not be counted.' );
		}
		return array_values( $rows );
	}

	/**
	 * Add a page's guarded rows to the counts.
	 *
	 * @param list<array<string, mixed>>           $rows   Rows of id, name and sku.
	 * @param array{identifiers: int, names: int} $counts Running counts.
	 * @return int|null The last row's id, or null for no rows.
	 */
	private static function add( array $rows, array &$counts ): ?int {
		$last = null;
		foreach ( $rows as $row ) {
			$sku  = is_scalar( $row['sku'] ?? null ) ? (string) $row['sku'] : '';
			$name = CsvWriter::text( $row['name'] ?? '' );
			if ( ! ReimportIdentifiers::reads_back( $sku ) ) {
				++$counts['identifiers'];
			}
			if ( CsvWriter::guard( $name ) !== $name ) {
				++$counts['names'];
			}
			$last = (int) $row['id'];
		}
		return $last;
	}

	/**
	 * No guarded rows.
	 *
	 * @return array{identifiers: int, names: int}
	 */
	private static function zero(): array {
		return [
			'identifiers' => 0,
			'names'       => 0,
		];
	}
}
