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
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Makes sure the batch an accepted request committed has its chunk queued:
 * called after the acceptance transaction commits, and again on every
 * replay of the receipt, so a crash or a lost response between the commit
 * and the scheduling is repaired by resolving the same request — for that
 * same batch, never another. A queued or running batch with no pending or
 * running `bulkcat_run_chunk` action for it gets one; a finished batch is
 * never queued again.
 */
final class BatchRecovery {
	/**
	 * Reconcile the scheduling of a committed batch and report its status.
	 *
	 * @param array<string,mixed> $result The accepted or replayed outcome, with `batch_id`.
	 * @return array<string,mixed> The same outcome, with the status read now.
	 * @throws \RuntimeException When the batch needs a chunk and none could be queued; resolving the same request retries.
	 */
	public static function finish( array $result ): array {
		$batch_id = (int) $result['batch_id'];
		$status   = ( new BatchRepository() )->status( $batch_id );
		if ( in_array( $status, [ 'queued', 'running' ], true ) && ! self::scheduled( $batch_id ) ) {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				throw new \RuntimeException( 'The scheduler is unavailable; resolve the same request.' );
			}
			$queued = as_enqueue_async_action( ChunkRunner::HOOK, [ $batch_id ], Registry::ACTION_GROUP, true );
			// An older Action Scheduler compares unique actions by hook and group only: another batch's chunk would refuse this one.
			if ( 0 === (int) $queued && ! self::scheduled( $batch_id ) ) {
				as_enqueue_async_action( ChunkRunner::HOOK, [ $batch_id ], Registry::ACTION_GROUP );
			}
			if ( ! self::scheduled( $batch_id ) ) {
				throw new \RuntimeException( 'Scheduling was interrupted; resolve the same request.' );
			}
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

	/**
	 * Whether a chunk of the batch is pending or running.
	 *
	 * @param int $batch_id Batch.
	 */
	private static function scheduled( int $batch_id ): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( ChunkRunner::HOOK, [ $batch_id ], Registry::ACTION_GROUP );
	}
}
