<?php
/**
 * Post-commit scheduling reconciliation never creates a new batch.
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

/** A lost response or scheduling gap recovers the accepted identity. */
final class BatchRecovery {
	/**
	 * Call only after the acceptance transaction has committed.
	 *
	 * @param array $result Durable outcome.
	 * @return array Same batch, never another request.
	 */
	public static function finish( array $result ): array {
		$id     = $result['batch_id'];
		$status = ( new BatchRepository() )->status( $id );
		if ( in_array( $status, [ 'queued','running' ], true ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
				throw new \RuntimeException( 'Scheduler is unavailable; resolve the same request.' );}
			if ( ! as_has_scheduled_action( ChunkRunner::HOOK, [ $id ], Registry::ACTION_GROUP ) ) {
				$scheduled = as_enqueue_async_action( ChunkRunner::HOOK, [ $id ], Registry::ACTION_GROUP, true );
				if ( ! $scheduled && ! as_has_scheduled_action( ChunkRunner::HOOK, [ $id ], Registry::ACTION_GROUP ) ) {
					throw new \RuntimeException( 'Scheduling was interrupted; resolve the same request.' );}
			}
		}
		$result['status'] = $status ?? $result['status'];
		return $result;
	}
	/** Missing retained result is never permission to create work again. */
	public static function gone(): \WP_Error {
		return Refusal::create( 'bulkcat_request_gone', __( 'The original change is no longer in History. This request cannot be applied again.', 'bulk-catalogue-manager-for-woocommerce' ), 410 );
	}
}
