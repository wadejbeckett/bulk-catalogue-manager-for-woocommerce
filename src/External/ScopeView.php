<?php
/**
 * The core's Scope (docs/EXTENSIONS.md §6, §7.2): the available members of a
 * change, ascending, handed to Provider::state_token() only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable scope.
 */
final class ScopeView implements Scope {
	/**
	 * The member ids, ascending and distinct.
	 *
	 * @var list<int>
	 */
	private readonly array $ids;

	/**
	 * Over some ids, in any order.
	 *
	 * @param list<int> $ids Products and variations.
	 */
	public function __construct( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$this->ids = $ids;
	}

	/**
	 * How many members.
	 */
	public function count(): int {
		return count( $this->ids );
	}

	/**
	 * The ids in ascending lists of at most $size.
	 *
	 * @param int $size Most ids in one list.
	 * @return iterable<list<int>>
	 */
	public function chunks( int $size ): iterable {
		return [] === $this->ids ? [] : array_chunk( $this->ids, max( 1, $size ) );
	}
}
