<?php
/**
 * Explicit export rows: a selection's or an ID list's members (SPEC §10).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\ProductQuery;

defined( 'ABSPATH' ) || exit;

/**
 * The rows of a selection or an explicit ID list, as a RowSource.
 *
 * Presence is resolved once, before the response, through
 * `ProductQuery::present_members()` (statements of at most 1,000 ids), so
 * refusals and the left-out count come before the first CSV byte, and
 * nothing is read of a member that is not present beyond the fact that the
 * presence query does not return it (ticket 10, R12). Pages then follow the
 * present members, never the raw ones: CsvExport stops at the first empty
 * page, so paging over raw ids would drop every row after a page whose
 * members were all left out. Each page is hydrated by the same Hydrator
 * `GET /products` uses, from `{ id, parent_id }`, so a row's cells are what
 * a filter export writes for it. The read's filter arguments play no part:
 * a selection is never narrowed or widened (R10), and its rows are always
 * in ascending id order.
 *
 * At 100,000 members the present ones are kept as one integer-to-integer
 * array (`id => parent_id`, amendment 7), and pages are walked from it in
 * order, 100 at a time, without copying it.
 */
final class IdRows implements RowSource {
	/**
	 * The page an in-order walk reaches next, from 1.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * The walk over the present members, when one is under way.
	 *
	 * @var \Generator<int, int>|null
	 */
	private ?\Generator $walk = null;

	/**
	 * Build over resolved presence.
	 *
	 * @param Hydrator        $hydrator The rows' hydrator.
	 * @param array<int, int> $present  Present id => parent id, ascending.
	 * @param int             $members  Distinct members asked for, present or not.
	 */
	private function __construct(
		private readonly Hydrator $hydrator,
		private readonly array $present,
		private readonly int $members
	) {}

	/**
	 * Resolve which of these members are present.
	 *
	 * @param array<int, int> $ids      Member ids: distinct, positive (a selection's, or a validated ID list).
	 * @param Hydrator        $hydrator The rows' hydrator.
	 * @throws \RuntimeException On a database error.
	 */
	public static function resolve( array $ids, Hydrator $hydrator ): self {
		$members = count( array_unique( $ids ) );
		return new self( $hydrator, ProductQuery::present_members( $ids ), $members );
	}

	/**
	 * Rows the file will hold.
	 */
	public function rows(): int {
		return count( $this->present );
	}

	/**
	 * Members left out: gone, binned, unlisted, not a product or variation,
	 * or a variation whose product is gone or unlisted.
	 */
	public function left_out(): int {
		return $this->members - $this->rows();
	}

	/**
	 * The present members' ids, ascending.
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		return array_keys( $this->present );
	}

	/**
	 * The present members, `id => parent_id`, ascending.
	 *
	 * @return array<int, int>
	 */
	public function present(): array {
		return $this->present;
	}

	/**
	 * One page of present members, hydrated with the read's `fields`.
	 *
	 * @param array<string, mixed> $params   The read's parameters; only `fields` is used.
	 * @param int                  $page     Page, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function page( array $params, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		if ( null === $this->walk || $page !== $this->next ) {
			$this->walk = $this->walk( ( $page - 1 ) * $per_page );
		}
		$rows = [];
		$walk = $this->walk;
		while ( $walk->valid() ) {
			$rows[] = [
				'id'        => $walk->key(),
				'parent_id' => $walk->current(),
			];
			$walk->next();
			if ( count( $rows ) >= $per_page ) {
				break;
			}
		}
		$this->next = $page + 1;
		$fields     = is_array( $params['fields'] ?? null ) ? array_values( array_filter( $params['fields'], 'is_string' ) ) : [];
		return [
			'items' => $this->hydrator->hydrate( $rows, $fields ),
			'total' => $this->rows(),
		];
	}

	/**
	 * The present members from an offset on, in order, without copying them.
	 *
	 * @param int $offset Members to pass over first.
	 * @return \Generator<int, int>
	 */
	private function walk( int $offset ): \Generator {
		foreach ( $this->present as $id => $parent ) {
			if ( $offset > 0 ) {
				--$offset;
				continue;
			}
			yield $id => $parent;
		}
	}
}
