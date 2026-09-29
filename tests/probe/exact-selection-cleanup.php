<?php
/**
 * Receipt cleanup bounds and protected batch results on a disposable database.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Guarded native fixture verifies raw durable receipt retention.
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\RequestRepository;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\SnapshotRepository;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
global $wpdb;
$old   = time() - 45 * DAY_IN_SECONDS;
$batch = wp_generate_uuid4();
$table = Registry::plugin()->table( Schema::REQUESTS );
FoundationStorage::atomic(
	static function () use ( $old, $batch, $wpdb, $table ): void {
		$repo = new RequestRepository();
		for ( $i = 0; $i < 101; ++$i ) {
			$claims = [
				'owner'        => 1,
				'uuid'         => wp_generate_uuid4(),
				'operation'    => 'selection.capture',
				'fingerprint'  => str_repeat( 'a', 64 ),
				'issued_at'    => $old,
				'accept_until' => $old + DAY_IN_SECONDS,
			];
			$repo->claim( $claims );
			$snapshot = ( new SnapshotRepository() )->create(
				[
					'products'   => [],
					'variations' => [],
				],
				1,
				$old
			);
			$repo->accepted_snapshot( $claims, $snapshot['id'] );
		}
		$wpdb->insert(
			$table,
			[
				'user_id'      => 1,
				'uuid'         => $batch,
				'operation'    => 'batch.apply',
				'fingerprint'  => str_repeat( 'b', 64 ),
				'issued_at'    => gmdate( 'Y-m-d H:i:s', $old ),
				'accept_until' => gmdate( 'Y-m-d H:i:s', $old + DAY_IN_SECONDS ),
				'state'        => 'accepted',
				'result_kind'  => 'batch',
				'result_id'    => '123',
				'retain_until' => gmdate( 'Y-m-d H:i:s', $old ),
			]
		);
	}
);
try {
	$service = new RequestService( static fn(): int => $old + 8 * DAY_IN_SECONDS );
	$counts  = [ $service->prune_selections(), $service->prune_selections(), $service->prune_requests(), $service->prune_requests() ];
	if ( [ 100, 1, 100, 1 ] !== $counts || null === ( new RequestRepository() )->read( 1, $batch ) ) {
		throw new RuntimeException( 'Cleanup crossed its bounds or deleted a batch receipt.' ); }
	WP_CLI::line(
		wp_json_encode(
			[
				'counts'                 => $counts,
				'batch_receipt_retained' => true,
			]
		)
	);
} finally {
	$wpdb->delete(
		$table,
		[
			'user_id' => 1,
			'uuid'    => $batch,
		]
	); }
