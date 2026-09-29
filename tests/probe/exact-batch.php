<?php
/**
 * T83 native journey on the owned disposable fixture only: an exact preview
 * that writes nothing, durable Apply under a server grant, the plugin's own
 * Action Scheduler queue, journal and value readback, and a durable undo —
 * for a captured snapshot whose change reaches variations and for an
 * explicit-ID selection assigning a category. Scope drift (a new variation
 * after preview) must require a new preview, and a retry of the accepted
 * request after more than 60 seconds, and after the selection and the grant
 * have expired, must answer the same batch with one relative change.
 *
 *     ../evidence-20260925/wp-local.sh eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/exact-batch.php";'
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native fixture checksums and readback.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
global $wpdb;
$checks = [];
$check  = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	$checks[] = $label;
};
// An answer that must not be a refusal; a refusal is printed before the check fails.
$accepted = static function ( mixed $value, string $label ) use ( $check ): array {
	if ( is_wp_error( $value ) ) {
		fwrite( STDERR, $label . ' refused: ' . $value->get_error_code() . ' ' . $value->get_error_message() . ' ' . wp_json_encode( $value->get_error_data() ) . "\n" );
	}
	$check( is_array( $value ), $label );
	return $value;
};
$refused  = static function ( mixed $value, string $code, string $label ) use ( $check ): void {
	if ( ! is_wp_error( $value ) || $code !== $value->get_error_code() ) {
		fwrite( STDERR, $label . ' answered: ' . wp_json_encode( is_wp_error( $value ) ? [ $value->get_error_code(), $value->get_error_data() ] : $value ) . "\n" );
	}
	$check( is_wp_error( $value ) && $code === $value->get_error_code(), $label );
};
// Aggregate product rows, metadata and term relationships of the probe's objects.
$checksum = static function ( array $ids ) use ( $wpdb ): string {
	$in = implode( ',', array_map( 'intval', $ids ) );
	return md5(
		(string) wp_json_encode(
			[
				$wpdb->get_results( "SELECT ID, post_status, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($in) ORDER BY ID", ARRAY_A ),
				$wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) ORDER BY meta_id", ARRAY_A ),
				$wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id IN ($in) ORDER BY object_id, term_taxonomy_id", ARRAY_A ),
			]
		)
	);
};
// This batch's pending chunk actions in the plugin's Action Scheduler group.
$pending = static function ( int $batch_id ): array {
	return as_get_scheduled_actions(
		[
			'hook'     => ChunkRunner::HOOK,
			'args'     => [ $batch_id ],
			'group'    => Registry::ACTION_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		],
		'ids'
	);
};
$queued  = static fn( int $batch_id ): int => count( $pending( $batch_id ) );
// Run this batch's chunks through Action Scheduler until it finishes; only its own actions.
$drain = static function ( int $batch_id ) use ( $pending ): string {
	$batches = new BatchRepository();
	for ( $round = 0; $round < 40; $round++ ) {
		$status = (string) $batches->status( $batch_id );
		if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			return $status;
		}
		$actions = $pending( $batch_id );
		if ( [] === $actions ) {
			throw new RuntimeException( "Batch $batch_id is $status with nothing queued." );
		}
		foreach ( $actions as $action_id ) {
			ActionScheduler::runner()->process_action( (int) $action_id, 'exact-batch probe' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
$price = static function ( int $id ): float {
	wp_cache_flush();
	return (float) wc_get_product( $id )->get_regular_price();
};

wp_set_current_user( 1 );
$now     = time();
$service = new RequestService(
	static function () use ( &$now ): int {
		return $now;
	}
);
$ids     = [];
$term    = 0;
try {
	// Fixture: a simple product at 100 and a variable product whose two variations cost 50 and 60.
	$simple = new WC_Product_Simple();
	$simple->set_name( 'Exact batch probe simple' );
	$simple->set_status( 'publish' );
	$simple->set_regular_price( '100' );
	$ids[]    = $simple->save();
	$variable = new WC_Product_Variable();
	$variable->set_name( 'Exact batch probe variable' );
	$variable->set_status( 'publish' );
	$ids[]      = $variable->save();
	$variations = [];
	foreach ( [ '50', '60' ] as $amount ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $ids[1] );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( $amount );
		$variations[] = $variation->save();
	}
	$ids = [ ...$ids, ...$variations ];

	// Snapshot selection of the two products; the change reaches the variations.
	$capture = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $ids[0], $ids[1] ] ] ) ), 'Capture the two products.' );
	$ops     = [
		[
			'action' => 'adjust',
			'args'   => [
				'field'               => 'regular_price',
				'direction'           => 'increase',
				'by'                  => 'percent',
				'amount'              => '10',
				'apply_to_variations' => true,
			],
		],
	];
	$before  = $checksum( $ids );
	$preview = $accepted(
		$service->preview(
			[
				'token'      => $capture['token'],
				'operations' => $ops,
			]
		),
		'Exact preview returns a scope.'
	);
	$check( $before === $checksum( $ids ), 'Preview wrote nothing to the probe products.' );
	$check( 3 === $preview['totals']['matched'] && 2 === $preview['selection']['variations_added'] && 1 === $preview['selection']['parents_left_out'], 'Preview covers the simple product and both variations, leaving the variable parent out.' );
	$check( is_string( $preview['scope_hash'] ) && 64 === strlen( $preview['scope_hash'] ), 'Preview answers an exact scope hash.' );

	// Drift: a third variation after the preview invalidates its scope.
	$late = new WC_Product_Variation();
	$late->set_parent_id( $ids[1] );
	$late->set_status( 'publish' );
	$late->set_regular_price( '70' );
	$ids[] = $late->save();
	$stale = $accepted(
		$service->issue(
			'batch.apply',
			[
				'token'      => $capture['token'],
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		),
		'Apply grant issued for the first preview.'
	);
	$refused(
		$service->apply(
			$stale + [
				'token'        => $capture['token'],
				'preview_hash' => $preview['hash'],
			]
		),
		'bulkcat_preview_stale',
		'A variation added after the preview requires a new preview.'
	);
	$check( null === ( new BatchRepository() )->find( 0 ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE uuid = %s', $wpdb->prefix . 'bulkcat_batches', $stale['uuid'] ) ), 'The refused Apply left no batch.' );

	$preview = $accepted(
		$service->preview(
			[
				'token'      => $capture['token'],
				'operations' => $ops,
			]
		),
		'Preview again.'
	);
	$check( 4 === $preview['totals']['matched'], 'The new preview includes the third variation.' );
	$grant  = $accepted(
		$service->issue(
			'batch.apply',
			[
				'token'      => $capture['token'],
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		),
		'Apply grant issued.'
	);
	$submit = $grant + [
		'token'        => $capture['token'],
		'preview_hash' => $preview['hash'],
	];
	$result = $accepted( $service->apply( $submit ), 'Apply is accepted.' );
	$check( 4 === $result['total_items'] && 'queued' === $result['status'] && false === $result['replayed'], 'Apply persists exactly the four checked items, queued.' );
	$batch_id = (int) $result['batch_id'];
	$check( 1 === $queued( $batch_id ), 'One chunk is queued for the batch.' );
	$receipt = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $wpdb->prefix . 'bulkcat_requests', $grant['uuid'] ), ARRAY_A );
	$check( is_array( $receipt ) && 'batch' === $receipt['result_kind'] && (string) $batch_id === $receipt['result_id'] && null === $receipt['retain_until'], 'The receipt names the batch and is kept with its header.' );

	$now   += 121;
	$replay = $accepted( $service->apply( $submit ), 'A retry after more than 60 seconds resolves.' );
	$check( $batch_id === $replay['batch_id'] && true === $replay['replayed'], 'The retry answers the same batch.' );
	$check( 1 === $queued( $batch_id ), 'The retry queued nothing more.' );

	$check( 'complete' === $drain( $batch_id ), 'The plugin queue completes the batch.' );
	$read = array_map( $price, [ $ids[0], $ids[2], $ids[3], $ids[4] ] );
	if ( [ 110.0, 55.0, 66.0, 77.0 ] !== $read ) {
		fwrite( STDERR, 'Read back: ' . wp_json_encode( $read ) . "\n" );
	}
	$check( [ 110.0, 55.0, 66.0, 77.0 ] === $read, 'Values read back increased by 10 per cent once.' );
	$check( 4 === ( new ChangeRepository() )->count_for_batch( $batch_id, 'applied' ), 'The journal holds one applied row per object.' );

	$now   += 8 * DAY_IN_SECONDS;
	$replay = $accepted( $service->apply( $submit ), 'A retry after the selection and the grant expired still resolves.' );
	$check( $batch_id === $replay['batch_id'] && 'complete' === $replay['status'] && true === $replay['replayed'], 'The late retry answers the finished batch without running it again.' );
	$check( 110.0 === $price( $ids[0] ), 'The late retry applied nothing again.' );
	$service->prune_selections();
	$replay = $accepted( $service->apply( $submit ), 'A retry after the selection payload was pruned still resolves.' );
	$check( $batch_id === $replay['batch_id'] && true === $replay['replayed'] && null === ( new BulkCatalogueManager\Selection\SnapshotRepository() )->read( substr( $capture['token'], 3, 32 ), 1 ), 'The receipt answers before any selection check, with the payload gone.' );
	$now = time();

	// Durable undo of the finished batch.
	$plan = ( new Planner() )->plan( $batch_id, 1 );
	$check( ! is_wp_error( $plan ), 'The batch can be undone.' );
	$undo_grant = $accepted(
		$service->issue(
			'batch.undo',
			[
				'batch_id'  => $batch_id,
				'plan_hash' => $plan->hash(),
				'force'     => false,
			]
		),
		'Undo grant issued.'
	);
	$undo       = $accepted( $service->undo( $undo_grant ), 'Undo is accepted.' );
	$check( 'undoing' === ( new BatchRepository() )->status( $batch_id ) && 4 === $undo['total_items'], 'The original is undoing and the undo holds four items.' );
	$again = $accepted( $service->undo( $undo_grant ), 'An undo retry resolves.' );
	$check( $undo['batch_id'] === $again['batch_id'] && true === $again['replayed'], 'The undo retry answers the same undo batch.' );
	$check( 'complete' === $drain( (int) $undo['batch_id'] ), 'The plugin queue completes the undo.' );
	$check( [ 100.0, 50.0, 60.0, 70.0 ] === array_map( $price, [ $ids[0], $ids[2], $ids[3], $ids[4] ] ), 'Undo restored every value.' );
	$check( 'undone' === ( new BatchRepository() )->status( $batch_id ), 'The original reads undone.' );

	// Explicit-ID selection assigning a category, then its undo.
	$created = wp_insert_term( 'Exact batch probe category ' . wp_generate_password( 6, false ), 'product_cat' );
	$check( is_array( $created ), 'Probe category created.' );
	$term     = (int) $created['term_id'];
	$category = [
		[
			'action' => 'add_terms',
			'args'   => [
				'field' => 'category_ids',
				'terms' => [ $term ],
			],
		],
	];
	$before   = $checksum( $ids );
	$explicit = $accepted(
		$service->preview(
			[
				'ids'        => [ $ids[0] ],
				'operations' => $category,
			]
		),
		'Explicit-ID preview.'
	);
	$check( $before === $checksum( $ids ) && 1 === $explicit['totals']['would_change'], 'Explicit-ID preview writes nothing and finds one change.' );
	$id_grant  = $accepted(
		$service->issue(
			'batch.apply',
			[
				'ids'        => [ $ids[0] ],
				'operations' => $explicit['operations'],
				'scope_hash' => $explicit['scope_hash'],
			]
		),
		'Explicit-ID Apply grant issued.'
	);
	$id_result = $accepted( $service->apply( $id_grant + [ 'preview_hash' => $explicit['hash'] ] ), 'Explicit-ID Apply is accepted.' );
	$check( 'complete' === $drain( (int) $id_result['batch_id'] ), 'The explicit-ID batch completes.' );
	wp_cache_flush();
	$check( in_array( $term, wc_get_product( $ids[0] )->get_category_ids(), true ), 'The category reads back on the product.' );
	$plan = ( new Planner() )->plan( (int) $id_result['batch_id'], 1 );
	$undo = $accepted(
		$service->undo(
			$accepted(
				$service->issue(
					'batch.undo',
					[
						'batch_id'  => (int) $id_result['batch_id'],
						'plan_hash' => $plan->hash(),
						'force'     => false,
					]
				),
				'Explicit-ID undo grant issued.'
			)
		),
		'Explicit-ID undo is accepted.'
	);
	$check( 'complete' === $drain( (int) $undo['batch_id'] ), 'The explicit-ID undo completes.' );
	wp_cache_flush();
	$check( ! in_array( $term, wc_get_product( $ids[0] )->get_category_ids(), true ), 'Undo removed the category again.' );

	echo wp_json_encode(
		[
			'checks'  => $checks,
			'batches' => [ $batch_id, (int) $id_result['batch_id'] ],
		],
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term > 0 ) {
		wp_delete_term( $term, 'product_cat' );
	}
}
