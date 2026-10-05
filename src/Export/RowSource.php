<?php
/**
 * Where an export reads its rows: one page at a time of the rows matching a
 * read's parameters, with the total that match. The catalogue export reads
 * `GET /products`' rows through it; later exports (a batch's changes, the
 * audit list) supply their own.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

defined( 'ABSPATH' ) || exit;

/**
 * A paged source of rows.
 */
interface RowSource {
	/**
	 * One page of the rows matching the parameters, in the read's order,
	 * and how many rows match in all.
	 *
	 * @param array<string, mixed> $params   The read's validated parameters, without paging.
	 * @param int                  $page     Page, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{items: list<array<string, mixed>>, total: int, more?: bool} Rows keyed by field key; `more` when rows are known to follow this page whatever the total said.
	 */
	public function page( array $params, int $page, int $per_page ): array;
}
