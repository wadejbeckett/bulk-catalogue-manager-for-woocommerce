<?php
/**
 * A selection (SPEC §3): all rows matching a view's conditions, or an
 * explicit list of object ids. A filter selection keeps the conditions, not
 * the rows, with the count they matched when it was made; an id selection
 * keeps its ids in the order given, each once. A change may cover at most
 * MAX_OBJECTS objects (SPEC §12.1); too_large() says when one would not.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Query\Criteria;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable selection.
 */
final class Selection {
	/**
	 * Most objects one selection may cover (SPEC §12.1).
	 */
	public const MAX_OBJECTS = 100000;

	/**
	 * Build through of_filter() or of_ids().
	 *
	 * @param Criteria|null $criteria The view's criteria for a filter selection; null for ids.
	 * @param int[]         $ids      The ids of an id selection; empty for a filter.
	 * @param int           $count    Objects covered: the filter's count when made, or the number of ids.
	 */
	private function __construct(
		public readonly ?Criteria $criteria,
		public readonly array $ids,
		public readonly int $count
	) {}

	/**
	 * Every row matching a view's conditions.
	 *
	 * @param Criteria $criteria The view's criteria; only its conditions are kept.
	 * @param int      $count    How many objects they match now.
	 * @throws \InvalidArgumentException When the count is negative.
	 */
	public static function of_filter( Criteria $criteria, int $count ): self {
		if ( $count < 0 ) {
			throw new \InvalidArgumentException( 'A count cannot be negative.' );
		}
		return new self( $criteria, [], $count );
	}

	/**
	 * An explicit list of object ids, kept in the order given with each id
	 * once (its first place). Ids may be given as whole numbers or their
	 * decimal text.
	 *
	 * @param array<mixed> $ids Object ids.
	 * @throws \InvalidArgumentException When the list is empty or holds anything but positive ids.
	 */
	public static function of_ids( array $ids ): self {
		if ( [] === $ids || ! array_is_list( $ids ) ) {
			throw new \InvalidArgumentException( 'An id selection needs a list of ids.' );
		}
		$clean = [];
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $id ) ) {
				$id = (int) $id;
			}
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new \InvalidArgumentException( 'Every id must be a positive whole number.' );
			}
			$clean[ $id ] = $id;
		}
		return new self( null, array_values( $clean ), count( $clean ) );
	}

	/**
	 * Whether this is a filter selection.
	 */
	public function is_filter(): bool {
		return null !== $this->criteria;
	}

	/**
	 * Whether it covers more objects than one change may.
	 */
	public function too_large(): bool {
		return $this->count > self::MAX_OBJECTS;
	}
}
