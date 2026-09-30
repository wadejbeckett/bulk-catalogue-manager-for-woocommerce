<?php
/**
 * The plan for undoing one batch (SPEC §9.4, T55): every object the batch
 * journalled, in reverse order, with the operations that put its touched
 * fields back and how it stands now — revertible, conflicted ("changed
 * since this batch ran") or skipped with its reason — plus the counts and a
 * hash binding the plan to the batch, its contents and the settings.
 * Immutable; Undo\Planner is the only producer. This is the whole plan held
 * in memory (Planner::plan(), for tests, probes and BatchFactory::
 * create_undo()); the routes walk the plan instead (Planner::walk(), T111),
 * holding a page at a time, and keep only its Undo\PlanSummary.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * An undo plan.
 */
final class Plan {
	/**
	 * Every recorded value is still in place: the operations put the old values back.
	 */
	public const REVERTIBLE = 'revertible';

	/**
	 * At least one touched field no longer holds the value this batch wrote.
	 */
	public const CONFLICTED = 'conflicted';

	/**
	 * Nothing can be put back on this object; `reason` says why.
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Every item state, in the order the counts list them.
	 */
	public const STATES = [ self::REVERTIBLE, self::CONFLICTED, self::SKIPPED ];

	/**
	 * Items by state, counted once.
	 *
	 * @var array{revertible: int, conflicted: int, skipped: int}
	 */
	private array $counts;

	/**
	 * Build a plan.
	 *
	 * @param int                                                                                                                                                                     $batch_id The batch undone.
	 * @param list<array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}> $items    Items in the order the undo writes them.
	 * @param string                                                                                                                                                                  $hash     The hash binding the plan.
	 * @throws \InvalidArgumentException On an item with an unknown state.
	 */
	public function __construct( private readonly int $batch_id, private readonly array $items, private readonly string $hash ) {
		$counts = array_fill_keys( self::STATES, 0 );
		foreach ( $items as $item ) {
			if ( ! isset( $counts[ $item['state'] ?? '' ] ) ) {
				throw new \InvalidArgumentException( 'An undo plan item has an unknown state.' );
			}
			++$counts[ $item['state'] ];
		}
		$this->counts = $counts;
	}

	/**
	 * The batch this plan undoes.
	 */
	public function batch_id(): int {
		return $this->batch_id;
	}

	/**
	 * Items in the order the undo writes them: the object journalled last first.
	 *
	 * @return list<array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * The same items with the conflicted ones first, for the preview (§9.4),
	 * otherwise in plan order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function conflicts_first(): array {
		$rank = array_flip( [ self::CONFLICTED, self::REVERTIBLE, self::SKIPPED ] );
		$keys = array_keys( $this->items );
		usort( $keys, fn( int $a, int $b ): int => [ $rank[ $this->items[ $a ]['state'] ], $a ] <=> [ $rank[ $this->items[ $b ]['state'] ], $b ] );
		return array_map( fn( int $key ): array => $this->items[ $key ], $keys );
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
	 * The hash binding the plan to its batch, its contents and the settings.
	 */
	public function hash(): string {
		return $this->hash;
	}

	/**
	 * The plan as the REST layer answers it.
	 *
	 * @return array{batch_id: int, items: list<array<string, mixed>>, counts: array{revertible: int, conflicted: int, skipped: int}, hash: string}
	 */
	public function to_array(): array {
		return [
			'batch_id' => $this->batch_id,
			'items'    => $this->items,
			'counts'   => $this->counts,
			'hash'     => $this->hash,
		];
	}
}
