<?php
/**
 * One CSV export of a read (SPEC §10): a header of the requested columns'
 * registry labels in the requested order, then every row matching the
 * read's parameters, not only one page. Rows are read from a RowSource 100
 * at a time and written as each page arrives, and the runtime object cache
 * is released after each page (Engine\Memory; T76: what each page primed
 * stayed cached, and a 20,000-row export died of a 128 MB limit at about
 * 12,800 rows), so memory stays flat whatever the total, and nothing is
 * stored.
 *
 * History exports (a batch's changes, the audit log, the history list) use
 * the same class with their own column headers (HistoryColumns).
 *
 * The number of pages is fixed by the total the first page reports, so a
 * source is asked ceil( total / 100 ) times (once when nothing matches);
 * reading stops early if a page comes back empty. A source that walks its
 * rows by key (Export\FilterRows) may say a page has `more` after it; the
 * last counted page then does not end the file, so rows that came to match
 * meanwhile cannot push one off the end.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Engine\Memory;
use BulkCatalogueManager\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * A CSV export.
 */
final class CsvExport {
	/**
	 * Rows read from the source at a time.
	 */
	public const PER_PAGE = 100;

	/**
	 * Build an export. Columns must be registry keys, or with $labels keys of
	 * $labels (a history export's own columns); the controller checks them first.
	 *
	 * @param RowSource                  $source    Where rows come from.
	 * @param Registry                   $registry  Field registry, for column labels.
	 * @param list<string>               $columns   Field keys (or keys of $labels), in column order.
	 * @param array<string, mixed>       $params    The read's parameters, without paging.
	 * @param string                     $separator "," or ";".
	 * @param string                     $filename  Download name.
	 * @param array<string, string>|null $labels    Headers by column key for columns that are not fields (HistoryColumns::labels()); null for field columns.
	 * @param bool                       $bare_first Whether the first heading is written unquoted when it is plain letters (CsvWriter::row_bare_first()): a file meant for WooCommerce's importer, whose first heading is `ID`.
	 * @throws \InvalidArgumentException When there is no column, a column repeats or is not a field.
	 */
	public function __construct(
		private readonly RowSource $source,
		private readonly Registry $registry,
		private readonly array $columns,
		private readonly array $params,
		private readonly string $separator,
		private readonly string $filename,
		private readonly ?array $labels = null,
		private readonly bool $bare_first = false
	) {
		if ( [] === $columns || count( array_unique( $columns ) ) !== count( $columns ) ) {
			throw new \InvalidArgumentException( 'An export needs one or more distinct columns.' );
		}
		foreach ( $columns as $column ) {
			if ( null === $labels ? ! $registry->has( $column ) : ! isset( $labels[ $column ] ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown column "%s".', $column ) ) );
			}
		}
	}

	/**
	 * A dated download name, such as `catalogue-export-2026-09-23.csv`.
	 *
	 * @param \DateTimeInterface $now The site's current time.
	 */
	public static function filename( \DateTimeInterface $now ): string {
		return 'catalogue-export-' . $now->format( 'Y-m-d' ) . '.csv';
	}

	/**
	 * The response headers: CSV in UTF-8, a download with the dated name, never cached.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return [
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $this->filename . '"',
			'Cache-Control'       => 'no-store, max-age=0',
		];
	}

	/**
	 * The column keys, in order.
	 *
	 * @return list<string>
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * The header row: each column's registry label, or its given header.
	 *
	 * @return list<string>
	 */
	public function header(): array {
		return array_map( fn( string $key ): string => null === $this->labels ? $this->registry->get( $key )->label() : $this->labels[ $key ], $this->columns );
	}

	/**
	 * The read's parameters, without paging, as the source is given them.
	 *
	 * @return array<string, mixed>
	 */
	public function params(): array {
		return $this->params;
	}

	/**
	 * Write the whole CSV to a stream, page by page.
	 *
	 * @param resource $stream Writable stream.
	 * @return int Data rows written.
	 */
	public function stream( $stream ): int {
		$writer = new CsvWriter( $stream, $this->separator );
		if ( $this->bare_first ) {
			$writer->row_bare_first( $this->header() );
		} else {
			$writer->row( $this->header() );
		}
		$writer->flush();

		$written = 0;
		$pages   = 1;
		for ( $page = 1; $page <= $pages; ++$page ) {
			$result = $this->source->page( $this->params, $page, self::PER_PAGE );
			if ( 1 === $page ) {
				$pages = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
			}
			if ( $page === $pages && ! empty( $result['more'] ) ) {
				++$pages;
			}
			if ( [] === $result['items'] ) {
				break;
			}
			foreach ( $result['items'] as $item ) {
				$writer->row( $this->cells( $item ) );
				++$written;
			}
			$writer->flush();
			Memory::release();
		}
		return $written;
	}

	/**
	 * One row's cells, in column order; a missing value is an empty cell.
	 *
	 * @param array<string, mixed> $item Row keyed by field key.
	 * @return list<mixed>
	 */
	private function cells( array $item ): array {
		return array_map( static fn( string $key ): mixed => $item[ $key ] ?? null, $this->columns );
	}
}
