<?php
/**
 * What an undo batch's end does to the batch it undoes (SPEC §4.6, §9.4;
 * T56). BatchFactory::create_undo() moves the batch undone to `undoing`
 * before the undo's first chunk; when the undo batch finishes:
 *
 * - complete or partial, having put something back: the batch undone moves
 *   `undoing` -> `undone` (Reversibility and the planner then read it as
 *   already undone; a partial undo left some objects alone, and says so in
 *   its own report);
 * - failed, cancelled, finished having put nothing back (every item
 *   changed again before the undo reached it, Main Build's F2), or having
 *   put an object back only in part (some of its values written, another
 *   skipped or failed; ENGINE-1): it moves
 *   back from `undoing` to the status it had before, kept in the undo
 *   batch's selection snapshot, so the batch stays undoable — Revert anyway
 *   included (§12.6).
 *
 * Each move is conditional on `undoing` through BatchRepository::transition(),
 * which keeps the batch's own finished time. Any other batch is left alone.
 *
 * The undo batch's final status and this move are two statements: a run
 * that dies between them leaves the batch undone `undoing` with no undo
 * left to move it on (SILENT-FAILURES-4). repair(), called as the batch is
 * read, settles it from its latest undo once none is queued or running.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Settles the batch an undo batch undid.
 */
final class Original {
	/**
	 * Undo statuses that count as the batch undone.
	 */
	public const UNDONE_BY = [ 'complete', 'partial' ];

	/**
	 * Statuses a batch undone may return to.
	 */
	private const RESTORABLE = [ 'complete', 'partial', 'failed', 'cancelled' ];

	/**
	 * Move the batch an undo batch undid on, once the undo batch has reached a final status.
	 *
	 * @param BatchRepository      $batches Batches.
	 * @param array<string, mixed> $undo    The undo batch's typed row (BatchRepository::find()); its selection holds the status before.
	 * @param string               $status  The undo batch's final status: complete, partial, failed or cancelled.
	 * @param int|null             $applied The undo batch's applied journal rows, when known; 0 is an undo that put nothing back.
	 * @param bool                 $whole   False when the undo put an object back only in part.
	 * @return bool Whether the batch undone moved.
	 */
	public static function settle( BatchRepository $batches, array $undo, string $status, ?int $applied = null, bool $whole = true ): bool {
		$original = (int) ( $undo['undo_of'] ?? 0 );
		if ( 'undo' !== ( $undo['source'] ?? '' ) || $original < 1 ) {
			return false;
		}
		if ( in_array( $status, self::UNDONE_BY, true ) && 0 !== $applied && $whole ) {
			return $batches->transition( $original, 'undoing', 'undone' );
		}
		$previous = (string) ( $undo['selection']['previous_status'] ?? 'complete' );
		$previous = in_array( $previous, self::RESTORABLE, true ) && in_array( $previous, Schema::BATCH_STATUSES, true ) ? $previous : 'complete';
		return $batches->transition( $original, 'undoing', $previous );
	}

	/**
	 * Settle a batch left `undoing` after its undo finished (SILENT-FAILURES-4):
	 * when none of its undo batches is queued or running, move it on as its
	 * latest undo's final status says, as settle() would have.
	 *
	 * @param BatchRepository  $batches  Batches.
	 * @param ChangeRepository $changes  The journal, for the undo's applied rows.
	 * @param int              $batch_id The batch undone.
	 * @return bool Whether the batch moved.
	 */
	public static function repair( BatchRepository $batches, ChangeRepository $changes, int $batch_id ): bool {
		$undos = $batches->undos_of( $batch_id );
		if ( [] === $undos ) {
			return false;
		}
		foreach ( $undos as $undo ) {
			if ( in_array( $undo['status'], [ 'queued', 'running' ], true ) ) {
				return false;
			}
		}
		$latest = $batches->find( $undos[0]['batch_id'] );
		if ( null === $latest || ! in_array( $latest['status'], self::RESTORABLE, true ) ) {
			return false;
		}
		return self::settle( $batches, $latest, $latest['status'], $changes->count_for_batch( $latest['batch_id'], 'applied' ) );
	}
}
