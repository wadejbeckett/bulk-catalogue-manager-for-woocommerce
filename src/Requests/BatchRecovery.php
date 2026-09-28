<?php
/**
 * Scheduling after an accepted Apply or undo commits (T83).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Makes sure the batch an accepted request committed has its chunk queued:
 * called after the acceptance transaction commits, and again on every
 * replay of the receipt, so a crash or a lost response between the commit
 * and the scheduling is repaired by resolving the same request — for that
 * same batch, never another. A queued or running batch with no pending or
 * running `bulkcat_run_chunk` action for it gets one
 * (Engine\ChunkRunner::ensure_queued(), shared with the
 * `bulkcat_batch_created` listener, so two requests answering one batch
 * queue one chunk); a finished batch is never queued again.
 */
final class BatchRecovery {
	/**
	 * Reconcile the scheduling of a committed batch and report its status.
	 *
	 * @param array<string,mixed> $result The accepted or replayed outcome, with `batch_id`.
	 * @return array<string,mixed> The same outcome, with the status read now.
	 * @throws \RuntimeException When the batch cannot be read, or needs a chunk and none could be queued; resolving the same request retries.
	 */
	public static function finish( array $result ): array {
		$batch_id = (int) $result['batch_id'];
		// A failed read throws (retry the same request); it is never taken for a batch with nothing to schedule.
		$batch  = ( new BatchRepository() )->find_strict( $batch_id );
		$status = null === $batch ? null : $batch['status'];
		if ( in_array( $status, [ 'queued', 'running' ], true ) && ! ChunkRunner::ensure_queued( $batch_id ) ) {
			throw new \RuntimeException( 'Scheduling was interrupted; resolve the same request.' );
		}
		$result['status'] = $status ?? $result['status'];
		return $result;
	}

	/**
	 * The refusal for an accepted request whose batch is no longer in the history.
	 */
	public static function gone(): \WP_Error {
		return Refusal::create( 'bulkcat_request_gone', __( 'The original change is no longer in History. This request cannot be applied again.', 'bulk-catalogue-manager-for-woocommerce' ), 410 );
	}
}
