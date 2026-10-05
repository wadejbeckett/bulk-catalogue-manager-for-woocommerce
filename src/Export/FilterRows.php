<?php
/**
 * The rows of a filtered catalogue export, walked by id (SQR-3).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Query\CountCache;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\ProductQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Every row matching an export's filter, as a RowSource, in ascending id
 * order whatever the view's sort.
 *
 * Each page is the next rows after the last id written
 * (ProductQuery::after()), never an OFFSET into a result that can be
 * re-sorted while the file is written: a row whose sort key changes (a
 * stock change, an Apply running meanwhile), or a product created or
 * removed, cannot make a row repeat or drop. Each statement starts where the
 * last stopped, so the whole walk reads the matches once rather than
 * re-sorting them for every page. A page reads one row more than it gives,
 * to tell CsvExport whether `more` follows, so rows that come to match
 * while the file is written cannot push one off the last counted page.
 * Rows are hydrated by the same Hydrator `GET /products` uses, and nothing
 * else of them is read (no row actions, SQR-4). The total is the export's
 * count, read once, on the first page.
 */
final class FilterRows implements RowSource {
	/**
	 * The page a walk reaches next, from 1.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * The last id given.
	 *
	 * @var int
	 */
	private int $after = 0;

	/**
	 * The count the first page reported.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * Build over the export's query.
	 *
	 * @param ProductQuery $query    The filter's query.
	 * @param Hydrator     $hydrator The rows' hydrator.
	 */
	public function __construct(
		private readonly ProductQuery $query,
		private readonly Hydrator $hydrator
	) {}

	/**
	 * One page, hydrated with the read's `fields`.
	 *
	 * @param array<string, mixed> $params   The read's parameters; only `fields` is used (the filter is the query's).
	 * @param int                  $page     Page, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{items: list<array<string, mixed>>, total: int, more: bool}
	 * @throws \RuntimeException On a database error.
	 */
	public function page( array $params, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		if ( 1 === $page || $page !== $this->next ) {
			$this->after = 0;
			$this->total = 1 === $page ? ( new CountCache() )->total( $this->query ) : $this->total;
			// Out of order: walk again from the start to the page asked for.
			for ( $skip = 1; $skip < $page; ++$skip ) {
				$rows = $this->query->after( $this->after, $per_page );
				if ( [] === $rows ) {
					break;
				}
				$this->after = $rows[ count( $rows ) - 1 ]['id'];
			}
		}
		if ( 1 === $page && 0 === $this->total ) {
			// Nothing matched when the export began: no page to read.
			return [
				'items' => [],
				'total' => 0,
				'more'  => false,
			];
		}
		$rows = $this->query->after( $this->after, $per_page + 1 );
		$more = count( $rows ) > $per_page;
		$rows = array_slice( $rows, 0, $per_page );
		if ( [] !== $rows ) {
			$this->after = $rows[ count( $rows ) - 1 ]['id'];
		}
		$this->next = $page + 1;
		$fields     = is_array( $params['fields'] ?? null ) ? array_values( array_filter( $params['fields'], 'is_string' ) ) : [];
		return [
			'items' => $this->hydrator->hydrate( $rows, $fields ),
			'total' => $this->total,
			'more'  => $more,
		];
	}
}
