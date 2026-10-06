<?php
/**
 * The core's CategoryScope (docs/EXTENSIONS-v2.md §5.3): the available
 * categories of a category change, ascending term ids of product_cat,
 * handed to Provider::state_token() only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\CategoryScope;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable category scope.
 */
final class CategoryScopeView implements CategoryScope {
	/**
	 * The term ids, ascending and distinct.
	 *
	 * @var list<int>
	 */
	private readonly array $ids;

	/**
	 * Over some term ids, in any order.
	 *
	 * @param list<int> $ids Term ids.
	 */
	public function __construct( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$this->ids = $ids;
	}

	/**
	 * How many categories.
	 */
	public function count(): int {
		return count( $this->ids );
	}

	/**
	 * The term ids in ascending lists of at most $size.
	 *
	 * @param int $size Most ids in one list.
	 * @return iterable<list<int>>
	 */
	public function chunks( int $size ): iterable {
		return [] === $this->ids ? [] : array_chunk( $this->ids, max( 1, $size ) );
	}

	/**
	 * `product_cat`.
	 */
	public function taxonomy(): string {
		return TermSubject::TAXONOMY;
	}
}
