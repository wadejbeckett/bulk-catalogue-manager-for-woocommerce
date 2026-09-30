<?php
/**
 * What a walk of an undo plan leaves when it is done (T111): the counts and
 * the hash of every item, without the items.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * The totals of a plan Undo\Planner::walk() streamed: the batch, the items
 * by state and the hash binding them, the same hash Undo\Plan carries for
 * the same items. Immutable.
 */
final class PlanSummary {
	/**
	 * Build a summary.
	 *
	 * @param int                                                    $batch_id The batch undone.
	 * @param array{revertible: int, conflicted: int, skipped: int} $counts   Items by state.
	 * @param string                                                 $hash     The hash binding the plan.
	 */
	public function __construct( private readonly int $batch_id, private readonly array $counts, private readonly string $hash ) {}

	/**
	 * The batch the plan undoes.
	 */
	public function batch_id(): int {
		return $this->batch_id;
	}

	/**
	 * Items by state.
	 *
	 * @return array{revertible: int, conflicted: int, skipped: int}
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * How many items the plan has.
	 */
	public function total(): int {
		return array_sum( $this->counts );
	}

	/**
	 * The hash binding the plan to its batch, its contents and the settings.
	 */
	public function hash(): string {
		return $this->hash;
	}
}
