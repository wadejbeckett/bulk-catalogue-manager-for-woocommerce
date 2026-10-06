<?php
/**
 * T83 REST journey on the owned disposable fixture only: every durable
 * Apply and undo step through rest_do_request() — grant, capture, exact
 * preview, Apply, replay, resolve, the plugin queue, undo preview, undo —
 * plus the refusals a 1.0 screen and an unauthorised user get.
 *
 *     ../evidence-20260925/wp-local.sh eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/exact-batch-routes.php";'
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
$checks = [];
$check  = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	$checks[] = $label;
};
// A JSON POST through the REST server, as the screen sends it.
$call = static function ( string $route, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/bulkcat/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $data ) );
	return rest_do_request( $request );
};
// A response that must have this status; anything else is printed first.
$expect  = static function ( WP_REST_Response $response, int $status, string $label ) use ( $check ): array {
	if ( $status !== $response->get_status() ) {
		fwrite( STDERR, $label . ' answered ' . $response->get_status() . ': ' . wp_json_encode( $response->get_data() ) . "\n" );
	}
	$check( $status === $response->get_status(), $label );
	return (array) $response->get_data();
};
$drain   = static function ( int $batch_id ): string {
	$batches = new BatchRepository();
	for ( $round = 0; $round < 40; $round++ ) {
		$status = (string) $batches->status( $batch_id );
		if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			return $status;
		}
		$actions = as_get_scheduled_actions(
			[
				'hook'     => ChunkRunner::HOOK,
				'args'     => [ $batch_id ],
				'group'    => Registry::ACTION_GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			],
			'ids'
		);
		if ( [] === $actions ) {
			throw new RuntimeException( "Batch $batch_id is $status with nothing queued." );
		}
		foreach ( $actions as $action_id ) {
			ActionScheduler::runner()->process_action( (int) $action_id, 'exact-batch-routes probe' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
$created = 0;
$count   = static function () use ( &$created ): void {
	++$created;
};

wp_set_current_user( 1 );
$ids  = [];
$user = 0;
try {
	foreach ( [ '30', '40' ] as $amount ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Exact batch routes probe ' . $amount );
		$product->set_status( 'publish' );
		$product->set_regular_price( $amount );
		$ids[] = $product->save();
	}
	$user = wp_insert_user(
		[
			'user_login' => 'exact-batch-' . bin2hex( random_bytes( 6 ) ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		]
	);
	$check( is_int( $user ), 'Probe subscriber created.' );
	$ops = [
		[
			'action' => 'set_value',
			'args'   => [
				'field' => 'regular_price',
				'value' => '55',
			],
		],
	];

	// Capture, exact preview, grant, Apply: all through the REST routes.
	$grant   = $expect(
		$call(
			'requests',
			[
				'operation' => 'selection.capture',
				'intent'    => [ 'ids' => $ids ],
			]
		),
		200,
		'Capture grant issued.'
	);
	$capture = $expect(
		$call(
			'selection',
			[
				'uuid'          => $grant['uuid'],
				'request_proof' => $grant['request_proof'],
				'intent'        => $grant['intent'],
			]
		),
		200,
		'Capture accepted.'
	);
	$preview = $expect(
		$call(
			'preview',
			[
				'token'      => $capture['token'],
				'operations' => $ops,
			]
		),
		200,
		'Snapshot preview answers.'
	);
	$check( 2 === $preview['totals']['would_change'] && is_string( $preview['scope_hash'] ), 'The preview is exact and would change both products.' );
	$apply = $expect(
		$call(
			'requests',
			[
				'operation' => 'batch.apply',
				'intent'    => [
					'token'      => $capture['token'],
					'operations' => $preview['operations'],
					'scope_hash' => $preview['scope_hash'],
				],
			]
		),
		200,
		'Apply grant issued.'
	);
	$check( isset( $apply['intent']['snapshot'] ) && ! isset( $apply['intent']['token'] ), 'The canonical intent names the snapshot, not the token.' );
	$submit  = [
		'uuid'          => $apply['uuid'],
		'request_proof' => $apply['request_proof'],
		'intent'        => $apply['intent'],
		'token'         => $capture['token'],
		'preview_hash'  => $preview['hash'],
	];
	$resolve = $expect(
		$call(
			'requests/resolve',
			[
				'operation'     => 'batch.apply',
				'uuid'          => $apply['uuid'],
				'request_proof' => $apply['request_proof'],
				'intent'        => $apply['intent'],
			]
		),
		200,
		'An unaccepted grant resolves.'
	);
	$check( [ 'status' => 'not_accepted' ] === $resolve, 'It is not accepted yet.' );
	add_action( 'bulkcat_batch_created', $count );
	$first = $expect( $call( 'batches', $submit ), 201, 'Durable Apply answers 201.' );
	$check( false === $first['replayed'] && 2 === $first['total_items'], 'A new batch of both products.' );
	$again = $expect( $call( 'batches', $submit ), 200, 'The same request again answers 200.' );
	$check( $first['batch_id'] === $again['batch_id'] && true === $again['replayed'], 'It is the same batch.' );
	remove_action( 'bulkcat_batch_created', $count );
	$check( 1 === $created, 'bulkcat_batch_created fired once, for the request that committed the batch.' );
	$resolved = $expect(
		$call(
			'requests/resolve',
			[
				'operation'     => 'batch.apply',
				'uuid'          => $apply['uuid'],
				'request_proof' => $apply['request_proof'],
				'intent'        => $apply['intent'],
			]
		),
		200,
		'The accepted grant resolves.'
	);
	$check( $first['batch_id'] === $resolved['batch_id'] && true === $resolved['replayed'], 'Resolution names the same batch without creating work.' );
	$changed           = $submit;
	$changed['intent'] = [ 'scope_hash' => str_repeat( '0', 64 ) ] + $submit['intent'];
	$conflict          = $call( 'batches', $changed );
	$check( 409 === $conflict->get_status() && 'bulkcat_request_conflict' === $conflict->get_data()['code'], 'Changed intent under the same request id conflicts.' );
	$check( 400 === $call( 'batches', [ 'ids' => [ $ids[0] ] ] + $submit )->get_status(), 'A durable body with an unknown key is refused.' );

	$check( 'complete' === $drain( (int) $first['batch_id'] ), 'The plugin queue completes the batch.' );
	wp_cache_flush();
	$check( [ 55.0, 55.0 ] === array_map( static fn( int $id ): float => (float) wc_get_product( $id )->get_regular_price(), $ids ), 'Both prices read back.' );
	$report = rest_do_request( new WP_REST_Request( 'GET', '/bulkcat/v1/batches/' . $first['batch_id'] ) )->get_data();
	$check( 'complete' === $report['status'] && 2 === $report['changed_items'] && 'reversible' === $report['reversibility']['state'], 'The report reads complete and reversible.' );

	// Undo through its preview and a durable grant.
	$plan = $expect( $call( 'batches/' . $first['batch_id'] . '/undo', [ 'preview' => true ] ), 200, 'Undo preview answers.' );
	$undo = $expect(
		$call(
			'requests',
			[
				'operation' => 'batch.undo',
				'intent'    => [
					'batch_id'  => (int) $first['batch_id'],
					'plan_hash' => $plan['plan_hash'],
				],
			]
		),
		200,
		'Undo grant issued.'
	);
	$check( false === $undo['intent']['force'], 'Force is written out in the canonical undo intent.' );
	$body   = [
		'uuid'          => $undo['uuid'],
		'request_proof' => $undo['request_proof'],
		'intent'        => $undo['intent'],
	];
	$undone = $expect( $call( 'batches/' . $first['batch_id'] . '/undo', $body ), 201, 'Durable undo answers 201.' );
	$check( 'Undo of #' . $first['batch_id'] === $undone['undo']['label'], 'The undo names the batch it undoes.' );
	$repeated = $expect( $call( 'batches/' . $first['batch_id'] . '/undo', $body ), 200, 'The same undo again answers 200.' );
	$check( $undone['batch_id'] === $repeated['batch_id'] && true === $repeated['replayed'], 'It is the same undo batch.' );
	$check( 400 === $call( 'batches/' . ( (int) $first['batch_id'] + 100000 ) . '/undo', $body )->get_status(), 'An undo intent must name the batch in its path.' );
	$check( 'complete' === $drain( (int) $undone['batch_id'] ), 'The plugin queue completes the undo.' );
	wp_cache_flush();
	$check( [ 30.0, 40.0 ] === array_map( static fn( int $id ): float => (float) wc_get_product( $id )->get_regular_price(), $ids ), 'Undo restored both prices.' );

	// Explicit ids through the routes.
	$explicit = $expect(
		$call(
			'preview',
			[
				'ids'        => [ $ids[1], $ids[0] ],
				'operations' => $ops,
			]
		),
		200,
		'Explicit-ID preview answers.'
	);
	$id_grant = $expect(
		$call(
			'requests',
			[
				'operation' => 'batch.apply',
				'intent'    => [
					'ids'        => [ $ids[1], $ids[0] ],
					'operations' => $explicit['operations'],
					'scope_hash' => $explicit['scope_hash'],
				],
			]
		),
		200,
		'Explicit-ID Apply grant issued.'
	);
	$check( [ min( $ids ), max( $ids ) ] === $id_grant['intent']['ids'], 'Explicit ids are canonical: sorted, each once.' );
	$id_batch = $expect(
		$call(
			'batches',
			[
				'uuid'          => $id_grant['uuid'],
				'request_proof' => $id_grant['request_proof'],
				'intent'        => $id_grant['intent'],
				'preview_hash'  => $explicit['hash'],
			]
		),
		201,
		'Explicit-ID durable Apply answers 201.'
	);
	$check( 'complete' === $drain( (int) $id_batch['batch_id'] ), 'The explicit-ID batch completes.' );

	// A 1.0 screen.
	$legacy = $call(
		'batches',
		[
			'uuid'         => wp_generate_uuid4(),
			'operations'   => $ops,
			'ids'          => $ids,
			'preview_hash' => str_repeat( 'a', 64 ),
		]
	);
	$check( 409 === $legacy->get_status() && 'bulkcat_legacy_request' === $legacy->get_data()['code'] && [] === $legacy->get_data()['data']['batch_ids'], 'An unknown 1.0 request id never creates a batch.' );
	$old = $call(
		'preview',
		[
			'token'      => SelectionToken::mint( Selection::of_ids( $ids ) ),
			'operations' => $ops,
		]
	);
	$check( 403 === $old->get_status() && 'bulkcat_invalid_selection' === $old->get_data()['code'], 'A 1.0 selection token is refused on preview.' );

	// Authorisation, unchanged: a visitor 401, a subscriber 403, on every route this slice touches.
	foreach ( [
		0     => 401,
		$user => 403,
	] as $who => $status ) {
		wp_set_current_user( $who );
		foreach ( [
			'requests'                                => [
				'operation' => 'batch.apply',
				'intent'    => [],
			],
			'requests/resolve'                        => [
				'operation'     => 'batch.apply',
				'uuid'          => $apply['uuid'],
				'request_proof' => $apply['request_proof'],
				'intent'        => $apply['intent'],
			],
			'preview'                                 => [
				'ids'        => $ids,
				'operations' => $ops,
			],
			'batches'                                 => $submit,
			'batches/' . $first['batch_id'] . '/undo' => $body,
		] as $route => $data ) {
			$check( $status === $call( $route, $data )->get_status(), "$route refuses user $who with $status." );
		}
	}
	wp_set_current_user( 1 );

	echo wp_json_encode(
		[
			'checks'  => $checks,
			'batches' => [ (int) $first['batch_id'], (int) $undone['batch_id'], (int) $id_batch['batch_id'] ],
		],
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	wp_set_current_user( 1 );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( is_int( $user ) && $user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );
	}
}
