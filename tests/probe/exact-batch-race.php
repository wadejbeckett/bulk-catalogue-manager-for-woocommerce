<?php
/**
 * T83 cross-process races on the owned disposable fixture only, driven by
 * two overlapping WP-CLI processes (`$phase` set before the require):
 *
 * - apply: the same Apply grant submitted twice at once. The holder pauses
 *   inside its acceptance transaction, after claiming the receipt, until the
 *   peer is waiting; the peer's claim must wait on the unique receipt key and
 *   then answer the holder's batch. One header, one set of items, one chunk.
 * - undo: two different undo grants of the same batch at once. The holder
 *   pauses after locking and planning; the peer waits on the batch lock and
 *   is then refused (the batch is being undone). One undo batch.
 *
 * Phases: prepare_apply | hold_apply | peer_apply | verify_apply and
 * prepare_undo | hold_undo | peer_undo | verify_undo.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native concurrency fixture.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
global $wpdb;
wp_set_current_user( 1 );
$key     = 'bulkcat_probe_exact_batch_race';
$service = new RequestService();
$batches = Registry::plugin()->table( Schema::BATCHES );
$drain   = static function ( int $batch_id ): string {
	$repository = new BatchRepository();
	for ( $round = 0; $round < 40; $round++ ) {
		$status = (string) $repository->status( $batch_id );
		if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			return $status;
		}
		foreach ( as_get_scheduled_actions(
			[
				'hook'   => ChunkRunner::HOOK,
				'args'   => [ $batch_id ],
				'group'  => Registry::ACTION_GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
		) as $action_id ) {
			ActionScheduler::runner()->process_action( (int) $action_id, 'exact-batch race' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
// Pause the holder at the first statement a matcher picks, until the peer is waiting.
$barrier      = static function ( callable $matches ) use ( $wpdb ): Closure {
	$held = false;
	return static function ( string $sql ) use ( $matches, $wpdb, &$held ): string {
		if ( $held || ! $matches( $sql ) ) {
			return $sql;
		}
		$held = true;
		$wpdb->get_var( "SELECT GET_LOCK('bulkcat_t83_holder',0)" );
		$until = microtime( true ) + 30;
		while ( ! $wpdb->get_var( "SELECT IS_USED_LOCK('bulkcat_t83_peer')" ) ) {
			if ( microtime( true ) > $until ) {
				throw new RuntimeException( 'Peer barrier timed out.' );
			}
			usleep( 100000 );
		}
		usleep( 2000000 );
		return $sql;
	};
};
$await_holder = static function () use ( $wpdb ): void {
	$until = microtime( true ) + 30;
	while ( ! $wpdb->get_var( "SELECT IS_USED_LOCK('bulkcat_t83_holder')" ) ) {
		if ( microtime( true ) > $until ) {
			throw new RuntimeException( 'Holder barrier timed out.' );
		}
		usleep( 100000 );
	}
	$wpdb->get_var( "SELECT GET_LOCK('bulkcat_t83_peer',0)" );
};
$release      = static function () use ( $wpdb ): void {
	$wpdb->get_var( "SELECT RELEASE_LOCK('bulkcat_t83_holder')" );
	$wpdb->get_var( "SELECT RELEASE_LOCK('bulkcat_t83_peer')" );
};
$ops          = [
	[
		'action' => 'adjust',
		'args'   => [
			'field'     => 'regular_price',
			'direction' => 'increase',
			'by'        => 'percent',
			'amount'    => '10',
		],
	],
];
// A captured product with its exact preview and Apply grant.
$prepare = static function () use ( $service, $ops ): array {
	$product = new WC_Product_Simple();
	$product->set_name( 'Exact batch race' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '100' );
	$id      = $product->save();
	$capture = $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $id ] ] ) );
	$preview = $service->preview(
		[
			'token'      => $capture['token'],
			'operations' => $ops,
		]
	);
	$grant   = $service->issue(
		'batch.apply',
		[
			'token'      => $capture['token'],
			'operations' => $preview['operations'],
			'scope_hash' => $preview['scope_hash'],
		]
	);
	return [
		'product' => $id,
		'submit'  => $grant + [
			'token'        => $capture['token'],
			'preview_hash' => $preview['hash'],
		],
	];
};

if ( str_starts_with( $phase, 'prepare_' ) ) {
	if ( false !== get_option( $key, false ) ) {
		throw new RuntimeException( 'Inspect the unfinished race first.' );
	}
	$fixture = $prepare();
	if ( 'prepare_undo' === $phase ) {
		$done = $service->apply( $fixture['submit'] );
		$drain( (int) $done['batch_id'] );
		$plan               = ( new Planner() )->plan( (int) $done['batch_id'], 1 );
		$fixture['batch']   = (int) $done['batch_id'];
		$fixture['undos']   = [];
		$fixture['undos'][] = $service->issue(
			'batch.undo',
			[
				'batch_id'  => $fixture['batch'],
				'plan_hash' => $plan->hash(),
			]
		);
		$fixture['undos'][] = $service->issue(
			'batch.undo',
			[
				'batch_id'  => $fixture['batch'],
				'plan_hash' => $plan->hash(),
			]
		);
	}
	add_option( $key, $fixture, '', false );
	WP_CLI::line( 'Prepared ' . $phase );
} elseif ( in_array( $phase, [ 'hold_apply', 'peer_apply', 'hold_undo', 'peer_undo' ], true ) ) {
	$fixture = get_option( $key );
	$holder  = str_starts_with( $phase, 'hold_' );
	if ( $holder ) {
		// Inside the acceptance transaction: after the receipt claim (Apply), or after the batch lock and plan (undo).
		$filter = $barrier( static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO `' . $batches . '`' ) );
		add_filter( 'query', $filter );
	} else {
		$await_holder();
	}
	$started = microtime( true );
	try {
		if ( str_ends_with( $phase, '_apply' ) ) {
			$result = $service->apply( $fixture['submit'] );
		} else {
			$result = $service->undo( $fixture['undos'][ $holder ? 0 : 1 ] );
		}
	} finally {
		if ( $holder ) {
			remove_filter( 'query', $filter );
		}
		$release();
	}
	update_option(
		$key . '_' . $phase,
		[
			'result'  => is_wp_error( $result ) ? [ 'error' => $result->get_error_code() ] : $result,
			'seconds' => microtime( true ) - $started,
		],
		false
	);
	WP_CLI::line( 'Finished ' . $phase );
} elseif ( str_starts_with( $phase, 'verify_' ) ) {
	$fixture = get_option( $key );
	$which   = substr( $phase, 7 );
	$held    = get_option( $key . '_hold_' . $which );
	$peer    = get_option( $key . '_peer_' . $which );
	$left    = $held['result'];
	$right   = $peer['result'];
	if ( 'apply' === $which ) {
		$uuid    = $fixture['submit']['uuid'];
		$headers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE uuid = %s', $batches, $uuid ) );
		$queued  = count(
			as_get_scheduled_actions(
				[
					'hook'   => ChunkRunner::HOOK,
					'args'   => [ (int) ( $left['batch_id'] ?? 0 ) ],
					'group'  => Registry::ACTION_GROUP,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);
		$ok      = isset( $left['batch_id'], $right['batch_id'] ) && $left['batch_id'] === $right['batch_id'] && false === $left['replayed'] && true === $right['replayed'] && 1 === $headers && 1 === $queued;
		$status  = $ok ? $drain( (int) $left['batch_id'] ) : '';
		wp_cache_flush();
		$ok = $ok && 'complete' === $status && 110.0 === (float) wc_get_product( $fixture['product'] )->get_regular_price();
	} else {
		$undo_batches = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE undo_of = %d AND source = 'undo'", $batches, $fixture['batch'] ) );
		$ok           = isset( $left['batch_id'] ) && isset( $right['error'] ) && 'bulkcat_not_reversible' === $right['error'] && 1 === $undo_batches && 'undoing' === ( new BatchRepository() )->status( $fixture['batch'] );
		$status       = $ok ? $drain( (int) $left['batch_id'] ) : '';
		wp_cache_flush();
		$ok = $ok && 'complete' === $status && 100.0 === (float) wc_get_product( $fixture['product'] )->get_regular_price();
	}
	if ( ! $ok || $peer['seconds'] < 1 ) {
		throw new RuntimeException( 'Race failed or did not overlap: ' . wp_json_encode( [ $held, $peer ] ) );
	}
	wp_delete_post( $fixture['product'], true );
	foreach ( [ '', '_hold_' . $which, '_peer_' . $which ] as $suffix ) {
		delete_option( $key . $suffix );
	}
	WP_CLI::line(
		(string) wp_json_encode(
			[
				'phase'             => $phase,
				'pass'              => true,
				'holder'            => $left,
				'peer'              => $right,
				'peer_wait_seconds' => round( $peer['seconds'], 3 ),
			]
		)
	);
} else {
	throw new RuntimeException( 'Unknown phase.' );
}
