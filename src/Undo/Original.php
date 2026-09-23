<?php
/**
 * What an undo batch's end does to the batch it undoes (SPEC §4.6, §9.4;
 * T56). BatchFactory::create_undo() moves the batch undone to `undoing`
 * before the undo's first chunk; when the undo batch finishes:
 *
 * - complete or partial: the batch undone moves `undoing` -> `undone`
 *   (Reversibility and the planner then read it as already undone; a
 *   partial undo left some objects alone, and says so in its own report);
 * - failed (nothing put back) or cancelled: it moves back from `undoing` to
 *   the status it had before, kept in the undo batch's selection snapshot,
 *   so a failed undo leaves the batch undoable (§12.6).
 *
 * Each move is conditional on `undoing` through BatchRepository::transition(),
 * which keeps the batch's own finished time. Any other batch is left alone.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

use BulkCatalogueManager\Data\BatchRepository;
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
	 * @return bool Whether the batch undone moved.
	 */
	public static function settle( BatchRepository $batches, array $undo, string $status ): bool {
		$original = (int) ( $undo['undo_of'] ?? 0 );
		if ( 'undo' !== ( $undo['source'] ?? '' ) || $original < 1 ) {
			return false;
		}
		if ( in_array( $status, self::UNDONE_BY, true ) ) {
			return $batches->transition( $original, 'undoing', 'undone' );
		}
		$previous = (string) ( $undo['selection']['previous_status'] ?? 'complete' );
		$previous = in_array( $previous, self::RESTORABLE, true ) && in_array( $previous, Schema::BATCH_STATUSES, true ) ? $previous : 'complete';
		return $batches->transition( $original, 'undoing', $previous );
	}
}
