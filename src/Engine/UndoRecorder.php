<?php
/**
 * Records an undo batch while its plan is walked (SPEC §9.4; T111, ticket
 * 11 item 1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * The undo batch of a plan, recorded item by item as Undo\Planner::walk()
 * hands them over, so the plan is never held whole. add() takes each item:
 * one the undo puts back — revertible, or conflicted when **Revert anyway**
 * was chosen — becomes a pending item carrying its own operations, written
 * BatchRepository::INSERT_CHUNK at a time, the batch header (source `undo`,
 * `undo_of` the batch undone) created with the first; one left alone for a
 * reason other than "nothing applied" adds to the grouped reasons. finish()
 * writes the rest and completes the header with what only the whole plan
 * knows — the operation outline (each action and field once, in plan
 * order), the counts, the hash, how many were left alone, the grouped
 * reasons — or refuses 409 `bulkcat_nothing_to_undo` when nothing would be
 * put back, having written nothing. The items and the completed header are
 * the ones the whole plan held in memory gave (T56).
 *
 * It never moves the batch undone to `undoing` or schedules work: the
 * caller does, in the same transaction for a durable undo
 * (Requests\BatchAcceptance), or through BatchFactory::create_undo(),
 * which discards what was recorded when it refuses.
 */
final class UndoRecorder {
	/**
	 * The undo batch, once its header is written.
	 *
	 * @var int|null
	 */
	private ?int $batch_id = null;

	/**
	 * Items not written yet: [ object_id, object_type, operations ].
	 *
	 * @var list<array{0: int, 1: string, 2: list<array<string, mixed>>}>
	 */
	private array $pending = [];

	/**
	 * Items the undo puts back, written or pending.
	 *
	 * @var int
	 */
	private int $taken = 0;

	/**
	 * The operation outline: each action and field once, in plan order.
	 *
	 * @var array<string, array{action: string, args: array{field: string}}>
	 */
	private array $outline = [];

	/**
	 * Reasons an item could not be put back => how many.
	 *
	 * @var array<string, int>
	 */
	private array $reasons = [];

	/**
	 * Build a recorder.
	 *
	 * @param BatchRepository $batches  Batches and items.
	 * @param string          $uuid     The undo request's id (a UUID).
	 * @param int             $user_id  The person undoing.
	 * @param int             $undo_of  The batch undone.
	 * @param string          $previous That batch's status now, which it returns to if the undo fails.
	 * @param bool            $force    Whether conflicted objects are put back too (Revert anyway).
	 */
	public function __construct( private readonly BatchRepository $batches, private readonly string $uuid, private readonly int $user_id, private readonly int $undo_of, private readonly string $previous, private readonly bool $force ) {}

	/**
	 * Take one plan item, in plan order.
	 *
	 * @param array<string, mixed> $item Plan item.
	 * @throws \RuntimeException When items cannot be recorded.
	 */
	public function add( array $item ): void {
		$take = Plan::REVERTIBLE === $item['state'] || ( $this->force && Plan::CONFLICTED === $item['state'] );
		if ( Plan::SKIPPED === $item['state'] && Planner::NOT_APPLIED !== $item['code'] ) {
			$this->reasons[ $item['reason'] ] = ( $this->reasons[ $item['reason'] ] ?? 0 ) + 1;
		}
		if ( ! $take || [] === $item['operations'] ) {
			return;
		}
		$this->pending[] = [ $item['object_id'], $item['object_type'], $item['operations'] ];
		++$this->taken;
		foreach ( $item['operations'] as $operation ) {
			$this->outline[ $operation['action'] . '|' . $operation['args']['field'] ] ??= [
				'action' => $operation['action'],
				'args'   => [ 'field' => $operation['args']['field'] ],
			];
		}
		if ( BatchRepository::INSERT_CHUNK <= count( $this->pending ) ) {
			$this->flush();
		}
	}

	/**
	 * Write what is left and complete the header, or refuse an undo that
	 * would put nothing back.
	 *
	 * @param array{revertible: int, conflicted: int, skipped: int} $counts The plan's items by state.
	 * @param string                                                 $hash   The plan's hash.
	 * @return array{batch_id: int, status: string, total_items: int, replayed: bool}|\WP_Error
	 * @throws \RuntimeException When items or the header cannot be recorded.
	 */
	public function finish( array $counts, string $hash ): array|\WP_Error {
		if ( 0 === $this->taken ) {
			return Refusal::create(
				BatchFactory::NOTHING_TO_UNDO,
				$counts[ Plan::CONFLICTED ] > 0 && ! $this->force
					? __( 'Everything this batch changed has been changed again since, so nothing was undone. Choose Revert anyway to put the old values back regardless.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'Nothing this batch changed can be put back now, so nothing was undone. The preview lists why for each product.', 'bulk-catalogue-manager-for-woocommerce' ),
				409,
				[ 'counts' => $counts ]
			);
		}
		$this->flush();
		$reasons = $this->reasons;
		arsort( $reasons );
		$grouped = [];
		foreach ( array_slice( $reasons, 0, BatchFactory::MAX_UNDO_REASONS, true ) as $message => $count ) {
			$grouped[] = [
				'message' => (string) $message,
				'count'   => $count,
			];
		}
		$this->batches->complete_header(
			(int) $this->batch_id,
			array_values( $this->outline ),
			[
				'kind'            => 'undo',
				'undo_of'         => $this->undo_of,
				'previous_status' => $this->previous,
				'force'           => $this->force,
				'plan_hash'       => $hash,
				'counts'          => $counts,
				'left_alone'      => $this->force ? 0 : $counts[ Plan::CONFLICTED ],
				'not_undone'      => array_sum( $this->reasons ),
				'reasons'         => $grouped,
				'materialised'    => $this->taken,
			],
			$this->taken
		);
		return [
			'batch_id'    => (int) $this->batch_id,
			'status'      => 'queued',
			'total_items' => $this->taken,
			'replayed'    => false,
		];
	}

	/**
	 * The undo batch, once recorded, or null.
	 */
	public function batch_id(): ?int {
		return $this->batch_id;
	}

	/**
	 * The status the batch undone had when the undo was recorded.
	 */
	public function previous(): string {
		return $this->previous;
	}

	/**
	 * Remove what was recorded, outside a transaction (BatchFactory::create_undo()).
	 */
	public function discard(): void {
		if ( null !== $this->batch_id ) {
			$this->batches->discard( $this->batch_id );
			$this->batch_id = null;
		}
		$this->pending = [];
	}

	/**
	 * Write the pending items, creating the header first.
	 *
	 * @throws \RuntimeException When the items are not all recorded.
	 */
	private function flush(): void {
		if ( [] === $this->pending ) {
			return;
		}
		// The header first: its outline, counts and item count are completed by finish().
		$this->batch_id ??= $this->batches->create(
			$this->uuid,
			$this->user_id,
			'undo',
			[],
			[
				'kind'    => 'undo',
				'undo_of' => $this->undo_of,
			],
			0,
			$this->undo_of
		);
		if ( count( $this->pending ) !== $this->batches->materialise_items( $this->batch_id, $this->pending ) ) {
			throw new \RuntimeException( 'Incomplete materialization.' );
		}
		$this->pending = [];
	}
}
