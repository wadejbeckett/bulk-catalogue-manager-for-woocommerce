<?php
/**
 * T83 faults and lifecycle cases on the owned disposable fixture only, on
 * native transactional storage: rollback at every write of an acceptance,
 * a lost COMMIT acknowledgement, a scheduling gap and a refused unique
 * enqueue, a terminal batch never queued again, expiry before and during
 * acceptance, changed intent under one request id, a missing header's
 * tombstone and its pruning, a failed header read that must not tombstone,
 * an object that changes kind, unavailable members, a same-count
 * substitution, one materialisation, the authorization epoch, history
 * pruning, and 1.0 jobs and request ids.
 *
 *     wp eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/exact-batch-faults.php";'
 *
 * Every case prints the refusal or answer it did not expect before failing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native faults, checksums and readback.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
global $wpdb;
$tables   = [
	'batches'  => Registry::plugin()->table( Schema::BATCHES ),
	'items'    => Registry::plugin()->table( Schema::ITEMS ),
	'requests' => Registry::plugin()->table( Schema::REQUESTS ),
];
$checks   = [];
$check    = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	$checks[] = $label;
};
$accepted = static function ( mixed $value, string $label ) use ( $check ): array {
	if ( is_wp_error( $value ) ) {
		fwrite( STDERR, $label . ' refused: ' . $value->get_error_code() . ' ' . $value->get_error_message() . "\n" );
	}
	$check( is_array( $value ), $label );
	return $value;
};
$refused  = static function ( mixed $value, string $code, string $label ) use ( $check ): WP_Error {
	if ( ! is_wp_error( $value ) || $code !== $value->get_error_code() ) {
		fwrite( STDERR, $label . ' answered: ' . wp_json_encode( is_wp_error( $value ) ? [ $value->get_error_code(), $value->get_error_data() ] : $value ) . "\n" );
	}
	$check( is_wp_error( $value ) && $code === $value->get_error_code(), $label );
	return $value;
};
$headers  = static fn( string $uuid ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE uuid = %s', $tables['batches'], $uuid ) );
$receipt  = static fn( string $uuid ): ?array => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $tables['requests'], $uuid ), ARRAY_A );
$items    = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['items'] ) );
$pending  = static fn( int $batch_id ): array => as_get_scheduled_actions(
	[
		'hook'     => ChunkRunner::HOOK,
		'args'     => [ $batch_id ],
		'group'    => Registry::ACTION_GROUP,
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 10,
	],
	'ids'
);
// Replace the statements a matcher picks with an invalid one while $body runs.
$fault = static function ( callable $matches, callable $body ) use ( $wpdb ): mixed {
	$filter     = static fn( string $sql ): string => $matches( $sql ) ? 'INVALID T83 FAULT' : $sql;
	$suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $filter );
	try {
		return $body();
	} finally {
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( $suppressed );
	}
};
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
			ActionScheduler::runner()->process_action( (int) $action_id, 'exact-batch-faults probe' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
$price = static function ( int $id ): float {
	wp_cache_flush();
	return (float) get_post_meta( $id, '_regular_price', true );
};
$call  = static function ( string $route, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/bulkcat/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $data ) );
	return rest_do_request( $request );
};

wp_set_current_user( 1 );
$now      = time();
$late     = false;
$service  = new RequestService(
	static function () use ( &$now, &$late ): int {
		return $late ? $now + 2 * DAY_IN_SECONDS : $now;
	}
);
$made     = [];
$simple   = static function ( string $amount ) use ( &$made ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Exact batch faults ' . $amount );
	$product->set_status( 'publish' );
	$product->set_regular_price( $amount );
	$made[] = $product->save();
	return end( $made );
};
$variable = static function ( array $amounts ) use ( &$made ): array {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'Exact batch faults variable' );
	$parent->set_status( 'publish' );
	$made[]   = $parent->save();
	$children = [];
	foreach ( $amounts as $amount ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( $amount );
		$children[] = $variation->save();
		$made[]     = end( $children );
	}
	return [ $parent->get_id(), $children ];
};
$set      = static fn( string $value ): array => [
	[
		'action' => 'set_value',
		'args'   => [
			'field' => 'regular_price',
			'value' => $value,
		],
	],
];
$increase = [
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
// Capture (or name ids), preview and issue: the durable Apply's submission.
$prepare = static function ( array $ids, array $ops, bool $snapshot = true ) use ( $service, $accepted ): array {
	$selection = [ 'ids' => $ids ];
	if ( $snapshot ) {
		$capture   = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'ids' => $ids ] ) ), 'Capture.' );
		$selection = [ 'token' => $capture['token'] ];
	}
	$preview = $accepted( $service->preview( $selection + [ 'operations' => $ops ] ), 'Preview.' );
	$grant   = $accepted(
		$service->issue(
			'batch.apply',
			$selection + [
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		),
		'Apply grant.'
	);
	$submit  = $grant + [ 'preview_hash' => $preview['hash'] ] + array_intersect_key( $selection, [ 'token' => 0 ] );
	return [
		'selection' => $selection,
		'preview'   => $preview,
		'grant'     => $grant,
		'submit'    => $submit,
	];
};

$restore = [];
try {
	// A. Rollback at every write of an acceptance: nothing is left, and the same grant then succeeds once.
	$a     = $simple( '10' );
	$case  = $prepare( [ $a ], $set( '11' ) );
	$uuid  = $case['grant']['uuid'];
	$count = $items();
	foreach ( [
		'receipt claim'  => 'INSERT INTO `' . $tables['requests'] . '`',
		'batch header'   => 'INSERT INTO `' . $tables['batches'] . '`',
		'batch items'    => 'INSERT INTO `' . $tables['items'] . '`',
		'receipt result' => 'UPDATE `' . $tables['requests'] . "` SET result_kind = 'batch'",
	] as $write => $prefix ) {
		$failed = $fault( static fn( string $sql ): bool => str_starts_with( $sql, $prefix ), static fn(): mixed => $service->apply( $case['submit'] ) );
		$refused( $failed, 'bulkcat_request_storage', "A failed $write refuses as retryable." );
		$check( 0 === $headers( $uuid ) && null === $receipt( $uuid ) && $count === $items(), "A failed $write leaves no header, items or receipt." );
	}
	$first = $accepted( $service->apply( $case['submit'] ), 'The same grant succeeds after the rollbacks.' );
	$check( 1 === $headers( $uuid ) && false === $first['replayed'] && 1 === count( $pending( $first['batch_id'] ) ), 'Exactly one header and one queued chunk.' );
	$check( 'complete' === $drain( $first['batch_id'] ) && 11.0 === $price( $a ), 'It runs once.' );

	// B. COMMIT reaches MySQL but its answer is lost: the retry resolves that batch and schedules it.
	$b          = $simple( '20' );
	$case       = $prepare( [ $b ], $set( '21' ) );
	$uuid       = $case['grant']['uuid'];
	$lost       = static function ( string $sql ) use ( &$lost, $wpdb ): string {
		if ( 'COMMIT' !== $sql ) {
			return $sql;
		}
		remove_filter( 'query', $lost );
		$wpdb->query( 'COMMIT' );
		return 'INVALID COMMIT ACKNOWLEDGEMENT';
	};
	$suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $lost );
	try {
		$failed = $service->apply( $case['submit'] );
	} finally {
		remove_filter( 'query', $lost );
		$wpdb->suppress_errors( $suppressed );
	}
	$refused( $failed, 'bulkcat_request_storage', 'A lost COMMIT answer reports uncertainty.' );
	$row = $receipt( $uuid );
	$check( 1 === $headers( $uuid ) && is_array( $row ) && 'batch' === $row['result_kind'], 'The batch and its receipt did commit.' );
	$check( [] === $pending( (int) $row['result_id'] ), 'Nothing was scheduled before the answer was lost.' );
	$again = $accepted( $service->apply( $case['submit'] ), 'The retry resolves.' );
	$check( (int) $row['result_id'] === $again['batch_id'] && true === $again['replayed'] && 1 === count( $pending( $again['batch_id'] ) ) && 1 === $headers( $uuid ), 'The retry answers the committed batch and schedules it once.' );

	// C. A scheduling gap, and a unique enqueue refused as an older Action Scheduler would.
	as_unschedule_all_actions( ChunkRunner::HOOK, [ $again['batch_id'] ], Registry::ACTION_GROUP );
	$refuse_unique = static fn( mixed $pre, string $hook, array $args, string $group, int $priority = 10, bool $unique = false ): mixed => $unique ? 0 : $pre;
	add_filter( 'pre_as_enqueue_async_action', $refuse_unique, 10, 6 );
	try {
		$gap = $accepted( $service->apply( $case['submit'] ), 'A retry after a scheduling gap resolves.' );
	} finally {
		remove_filter( 'pre_as_enqueue_async_action', $refuse_unique, 10 );
	}
	$check( $again['batch_id'] === $gap['batch_id'] && 1 === count( $pending( $gap['batch_id'] ) ), 'The same batch is scheduled again, once, despite the refused unique enqueue.' );
	$check( 'complete' === $drain( $gap['batch_id'] ) && 21.0 === $price( $b ), 'It runs once.' );

	// D. A finished batch is never queued again by a replay.
	$final = $accepted( $service->apply( $case['submit'] ), 'A replay of a finished batch.' );
	$check( 'complete' === $final['status'] && [] === $pending( $final['batch_id'] ), 'A finished batch is not queued again.' );

	// E. Expiry before acceptance, and while accepting.
	$c    = $simple( '30' );
	$case = $prepare( [ $c ], $set( '31' ) );
	$uuid = $case['grant']['uuid'];
	$now += DAY_IN_SECONDS + 1;
	$refused( $service->apply( $case['submit'] ), 'bulkcat_request_expired', 'A grant past its deadline is not accepted.' );
	$now -= DAY_IN_SECONDS + 1;
	$check( 0 === $headers( $uuid ), 'Nothing was recorded.' );
	$flip = static function ( string $sql ) use ( &$late, $tables ): string {
		if ( str_starts_with( $sql, 'UPDATE `' . $tables['requests'] . "` SET result_kind = 'batch'" ) ) {
			$late = true;
		}
		return $sql;
	};
	add_filter( 'query', $flip );
	try {
		$expired = $service->apply( $case['submit'] );
	} finally {
		remove_filter( 'query', $flip );
		$late = false;
	}
	$refused( $expired, 'bulkcat_request_expired', 'A deadline passing during acceptance refuses at the last check.' );
	$check( 0 === $headers( $uuid ) && null === $receipt( $uuid ), 'The expired acceptance rolled back.' );
	$check( 30.0 === $price( $c ), 'No product was touched.' );

	// F. Changed intent under one request id conflicts, before and after acceptance.
	$changed                         = $case['submit'];
	$changed['intent']['scope_hash'] = str_repeat( '0', 64 );
	$refused( $service->apply( $changed ), 'bulkcat_request_conflict', 'A changed scope under the same id conflicts before acceptance.' );
	$case = $prepare( [ $c ], $set( '31' ) );
	$uuid = $case['grant']['uuid'];
	$accepted( $service->apply( $case['submit'] ), 'Apply accepted.' );
	$variants = [
		'operations' => [ 'operations' => $case['grant']['intent']['operations'] ] + $case['submit']['intent'],
		'selection'  => [ 'snapshot' => str_repeat( 'a', 32 ) ] + $case['submit']['intent'],
	];
	$variants['operations']['operations'][0]['args']['value'] = '99';
	foreach ( $variants as $what => $intent ) {
		$refused( $service->apply( [ 'intent' => $intent ] + $case['submit'] ), 'bulkcat_request_conflict', "Changed $what under an accepted id conflicts." );
	}
	$malformed = $refused( $service->apply( [ 'intent' => [ 'source' => 'undo' ] + $case['submit']['intent'] ] + $case['submit'] ), 'bulkcat_invalid_value', 'A changed source is not an Apply intent.' );
	$check( str_contains( $malformed->get_error_message(), 'This change could not be read' ), 'A malformed change is refused in the words of a change, not of a selection.' );
	$refused( $service->undo( $case['submit'] ), 'bulkcat_invalid_value', 'An Apply intent is not an undo intent.' );
	$first_plan = ( new Planner() )->plan( $first['batch_id'], 1 );
	$undo_grant = $accepted(
		$service->issue(
			'batch.undo',
			[
				'batch_id'  => $first['batch_id'],
				'plan_hash' => $first_plan->hash(),
			]
		),
		'An undo grant.'
	);
	$refused(
		$service->apply(
			array_intersect_key(
				$undo_grant,
				[
					'uuid'          => 0,
					'request_proof' => 0,
				]
			) + $case['submit']
		),
		'bulkcat_request_conflict',
		'An undo grant presented as an Apply conflicts.'
	);
	$refused( $service->undo( [ 'intent' => [ 'force' => true ] + $undo_grant['intent'] ] + $undo_grant ), 'bulkcat_request_conflict', 'A changed undo intent under its grant conflicts.' );
	$check( 1 === $headers( $uuid ), 'Still one batch.' );
	$drain( $accepted( $service->apply( $case['submit'] ), 'Replay.' )['batch_id'] );

	// G. A header read that fails must not tombstone the receipt.
	$row      = $receipt( $uuid );
	$select   = 'SELECT * FROM `' . $tables['batches'] . '` WHERE batch_id = ' . (int) $row['result_id'];
	$unstable = $fault( static fn( string $sql ): bool => $select === $sql, static fn(): mixed => $service->apply( $case['submit'] ) );
	$refused( $unstable, 'bulkcat_request_storage', 'A failed header read is a retryable refusal.' );
	$check( 'accepted' === $receipt( $uuid )['state'], 'The receipt is not tombstoned by a failed read.' );
	$check( true === $accepted( $service->apply( $case['submit'] ), 'The next retry resolves.' )['replayed'], 'The next retry answers the batch.' );

	// H. A header that is really gone leaves a tombstone, kept eight days from issue, then pruned; nothing is created again.
	$gone_id = (int) $receipt( $uuid )['result_id'];
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE batch_id = %d', $tables['items'], $gone_id ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE batch_id = %d', $tables['batches'], $gone_id ) );
	$refused( $service->apply( $case['submit'] ), 'bulkcat_request_gone', 'A removed header answers gone.' );
	$tomb = $receipt( $uuid );
	$check( 'gone' === $tomb['state'] && gmdate( 'Y-m-d H:i:s', $case['grant']['accept_until'] - DAY_IN_SECONDS + 8 * DAY_IN_SECONDS ) === $tomb['retain_until'], 'Its tombstone lasts eight days from issue.' );
	$refused( $service->apply( $case['submit'] ), 'bulkcat_request_gone', 'Gone again, never re-executed.' );
	$now += 9 * DAY_IN_SECONDS;
	// Drained, not called once: a backlog of older receipts must not hide this one (ticket 11).
	bulkcat_probe_drain( [ $service, 'prune_requests' ] );
	$check( null === $receipt( $uuid ), 'The tombstone is pruned after its deadline.' );
	$refused( $service->apply( $case['submit'] ), 'bulkcat_request_expired', 'After pruning the signed deadline still refuses.' );
	$now -= 9 * DAY_IN_SECONDS;
	$check( 0 === $headers( $uuid ), 'No batch was created again.' );

	// I. An object that changes kind is never written as the kind it was chosen as.
	[ $parent ] = $variable( [ '5' ] );
	$d          = $simple( '40' );
	$case       = $prepare( [ $d ], $set( '41' ) );
	$result     = $accepted( $service->apply( $case['submit'] ), 'Apply of a product accepted.' );
	$wpdb->update(
		$wpdb->posts,
		[
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
		],
		[ 'ID' => $d ]
	);
	$restore[ $d ] = true;
	clean_post_cache( $d );
	wp_cache_flush();
	$check( 'failed' === $drain( $result['batch_id'] ), 'The batch fails rather than write it.' );
	$message = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM %i WHERE batch_id = %d', $tables['items'], $result['batch_id'] ) );
	$check( str_contains( $message, 'changed between a product and a variation' ) && 40.0 === $price( $d ), 'Its item says why and its price is unchanged.' );
	$capture = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $c ] ] ) ), 'Capture.' );
	$wpdb->update(
		$wpdb->posts,
		[
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
		],
		[ 'ID' => $c ]
	);
	$restore[ $c ] = true;
	clean_post_cache( $c );
	$preview = $accepted(
		$service->preview(
			[
				'token'      => $capture['token'],
				'operations' => $set( '1' ),
			]
		),
		'Preview after the kind changed.'
	);
	$check( [ $c ] === $preview['unreadable'] && 0 === $preview['totals']['would_change'] && 1 === $preview['unavailable_count'], 'The preview shows it unavailable, never computed.' );
	foreach ( array_keys( $restore ) as $id ) {
		$wpdb->update(
			$wpdb->posts,
			[
				'post_type'   => 'product',
				'post_parent' => 0,
			],
			[ 'ID' => $id ]
		);
		clean_post_cache( $id );
	}
	$restore = [];

	// J. An unavailable member stays in scope and makes the outcome partial, never complete.
	$e       = $simple( '50' );
	$f       = $simple( '60' );
	$capture = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $e, $f ] ] ) ), 'Capture two.' );
	wp_delete_post( $f, true );
	$preview = $accepted(
		$service->preview(
			[
				'token'      => $capture['token'],
				'operations' => $set( '51' ),
			]
		),
		'Preview with one member gone.'
	);
	$check( 2 === $preview['totals']['matched'] && 1 === $preview['unavailable_count'] && [ $f ] === $preview['unreadable'], 'The missing member stays in the scope.' );
	$grant  = $accepted(
		$service->issue(
			'batch.apply',
			[
				'token'      => $capture['token'],
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		),
		'Grant.'
	);
	$result = $accepted(
		$service->apply(
			$grant + [
				'token'        => $capture['token'],
				'preview_hash' => $preview['hash'],
			]
		),
		'Apply.'
	);
	$check( 2 === $result['total_items'], 'Both members are items.' );
	$status = $drain( $result['batch_id'] );
	$report = ( new BatchRepository() )->find( $result['batch_id'] );
	$check( 'partial' === $status && 1 === $report['failed_items'] && 1 === $report['changed_items'] && 51.0 === $price( $e ), 'The outcome is partial: one changed, one failed.' );

	// K. A same-count substitution of variations invalidates the preview.
	[ $parent, $children ] = $variable( [ '7', '8' ] );
	$case                  = $prepare( [ $parent ], $increase );
	$check( 2 === $case['preview']['totals']['matched'], 'The preview reaches two variations.' );
	wp_delete_post( $children[1], true );
	$variable_again = new WC_Product_Variation();
	$variable_again->set_parent_id( $parent );
	$variable_again->set_status( 'publish' );
	$variable_again->set_regular_price( '9' );
	$made[] = $variable_again->save();
	$refused( $service->apply( $case['submit'] ), 'bulkcat_preview_stale', 'A same-count substitution requires a new preview.' );
	$check( 0 === $headers( $case['grant']['uuid'] ), 'Nothing was recorded.' );

	// L. Apply materialises the checked scope once: two catalogue reads, none after the header.
	[ $parent ] = $variable( [ '3', '4' ] );
	$g          = $simple( '70' );
	$case       = $prepare( [ $g, $parent ], $increase );
	$log        = [];
	$record     = static function ( string $sql ) use ( &$log ): string {
		$log[] = $sql;
		return $sql;
	};
	add_filter( 'query', $record );
	try {
		$result = $accepted( $service->apply( $case['submit'] ), 'Apply with a query log.' );
	} finally {
		remove_filter( 'query', $record );
	}
	$posts  = array_keys( array_filter( $log, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, '`' . $wpdb->posts . '`' ) ) );
	$header = array_keys( array_filter( $log, static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO `' . $tables['batches'] . '`' ) ) );
	$check( 2 === count( $posts ) && 1 === count( $header ) && max( $posts ) < $header[0], 'Two catalogue reads (members, variations), both before the header; none after.' );
	$check( 3 === $result['total_items'], 'The batch holds exactly the checked scope.' );
	$drain( $result['batch_id'] );

	// M. The preview is bound to the authorization epoch; a live renewal keeps it.
	$h    = $simple( '80' );
	$case = $prepare( [ $h ], $set( '81' ) );
	$now += 5 * MINUTE_IN_SECONDS;
	$live = $accepted( $service->handle( 'renew', [ 'token' => $case['selection']['token'] ] ), 'Live renewal.' );
	$check( $case['selection']['token'] === $live['token'] && false === $live['requires_preview'], 'A live renewal keeps the token.' );
	$drain( $accepted( $service->apply( $case['submit'] ), 'The preview still applies after a live renewal.' )['batch_id'] );
	$i    = $simple( '90' );
	$case = $prepare( [ $i ], $set( '91' ) );
	$now += 16 * MINUTE_IN_SECONDS;
	$refused( $service->apply( $case['submit'] ), 'bulkcat_selection_renew_required', 'An expired lease must be renewed.' );
	$renewed = $accepted( $service->handle( 'renew', [ 'token' => $case['selection']['token'] ] ), 'Renewal after expiry.' );
	$check( true === $renewed['requires_preview'] && 2 === $renewed['authorization_epoch'] && $renewed['token'] !== $case['selection']['token'], 'A new epoch and token.' );
	$refused( $service->apply( [ 'token' => $renewed['token'] ] + $case['submit'] ), 'bulkcat_preview_stale', 'The old preview is bound to the old epoch.' );
	$again = $accepted(
		$service->preview(
			[
				'token'      => $renewed['token'],
				'operations' => $set( '91' ),
			]
		),
		'Preview under the new epoch.'
	);
	$check( $case['preview']['scope_hash'] === $again['scope_hash'], 'The exact scope is unchanged.' );
	$result = $accepted(
		$service->apply(
			[
				'token'        => $renewed['token'],
				'preview_hash' => $again['hash'],
			] + $case['submit']
		),
		'The same grant applies with the new preview.'
	);
	$check( 'complete' === $drain( $result['batch_id'] ) && 91.0 === $price( $i ), 'It runs once.' );
	$now = time();

	// N. History detail pruning keeps the header and the receipt answers it.
	$j    = $simple( '100' );
	$case = $prepare( [ $j ], $set( '101' ) );
	$id   = $accepted( $service->apply( $case['submit'] ), 'Apply.' )['batch_id'];
	$drain( $id );
	$repository = new BatchRepository();
	$repository->mark_pruned( [ $id ], 'Pruned by the exact-batch faults probe.', gmdate( 'Y-m-d H:i:s' ) );
	$repository->delete_items( [ $id ], 1000 );
	( new ChangeRepository() )->delete_for_batches( [ $id ], 1000 );
	$pruned = $accepted( $service->apply( $case['submit'] ), 'A retry after pruning resolves.' );
	$check( $id === $pruned['batch_id'] && true === $pruned['replayed'] && 'complete' === $pruned['status'] && 1 === $headers( $case['grant']['uuid'] ), 'It answers the kept header.' );
	$plan = ( new Planner() )->plan( $id, 1 );
	$check( is_wp_error( $plan ) && 'pruned' === $plan->get_error_data()['reason'], 'Undo is no longer available, as the history says.' );

	// O. 1.0 jobs and request ids.
	$k   = $simple( '110' );
	$old = wp_generate_uuid4();
	$job = ( new BatchFactory() )->create( $old, 1, $set( '111' ), Selection::of_ids( [ $k ] ) );
	$check( is_array( $job ) && 1 === count( $pending( $job['batch_id'] ) ), 'A 1.0 job is queued through bulkcat_batch_created.' );
	$check( 'complete' === $drain( $job['batch_id'] ) && 111.0 === $price( $k ), 'Old queued work still runs.' );
	$replay = $call(
		'batches',
		[
			'uuid'       => $old,
			'operations' => $set( '111' ),
			'ids'        => [ $k ],
		]
	);
	$check( 200 === $replay->get_status() && $job['batch_id'] === $replay->get_data()['batch_id'] && true === $replay->get_data()['replayed'], 'Its 1.0 request id answers its batch.' );
	$shared    = wp_generate_uuid4();
	$pair      = [
		$repository->create( $shared, 1, 'bulk', $set( '1' ), [ 'kind' => 'ids' ], 1 ),
		$repository->create( $shared, 1, 'bulk', $set( '1' ), [ 'kind' => 'ids' ], 1 ),
	];
	$ambiguous = $call(
		'batches',
		[
			'uuid'       => $shared,
			'operations' => $set( '1' ),
			'ids'        => [ $k ],
		]
	);
	$check( 409 === $ambiguous->get_status() && 'bulkcat_legacy_request' === $ambiguous->get_data()['code'] && $pair === $ambiguous->get_data()['data']['batch_ids'] && 2 === $headers( $shared ), 'Two old headers under one id are ambiguous; no third.' );
	foreach ( $pair as $orphan ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE batch_id = %d', $tables['batches'], $orphan ) );
	}
	$plan = ( new Planner() )->plan( $job['batch_id'], 1 );
	$undo = $accepted(
		$service->undo(
			$accepted(
				$service->issue(
					'batch.undo',
					[
						'batch_id'  => $job['batch_id'],
						'plan_hash' => $plan->hash(),
					]
				),
				'Undo grant for the 1.0 batch.'
			)
		),
		'The 1.0 batch is undone durably.'
	);
	$check( 'complete' === $drain( $undo['batch_id'] ) && 110.0 === $price( $k ) && 'undone' === ( new BatchRepository() )->status( $job['batch_id'] ), 'Its history still undoes it.' );

	// P. Members in the bin at preview are recorded failed and never written, even when restored before the run.
	$kept     = $simple( '120' );
	$restored = $simple( '130' );
	$binned   = $simple( '140' );
	$capture  = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $kept, $restored, $binned ] ] ) ), 'Capture three.' );
	wp_trash_post( $restored );
	wp_trash_post( $binned );
	$preview = $accepted(
		$service->preview(
			[
				'token'      => $capture['token'],
				'operations' => $set( '1' ),
			]
		),
		'Preview with two members in the bin.'
	);
	$check( [ $restored, $binned ] === $preview['unreadable'] && 1 === $preview['totals']['would_change'], 'The preview says the two in the bin cannot be changed.' );
	$grant  = $accepted(
		$service->issue(
			'batch.apply',
			[
				'token'      => $capture['token'],
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		),
		'Grant.'
	);
	$result = $accepted(
		$service->apply(
			$grant + [
				'token'        => $capture['token'],
				'preview_hash' => $preview['hash'],
			]
		),
		'Apply.'
	);
	$states = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, state, message FROM %i WHERE batch_id = %d ORDER BY item_id', $tables['items'], $result['batch_id'] ), ARRAY_A );
	$check( [ 'pending', 'failed', 'failed' ] === array_column( $states, 'state' ) && BatchFactory::unavailable_reason() === $states[1]['message'], 'The two are recorded failed, with the plain reason, at acceptance.' );
	wp_untrash_post( $restored );
	wp_cache_flush();
	$status = $drain( $result['batch_id'] );
	$report = ( new BatchRepository() )->find( $result['batch_id'] );
	$check( 'partial' === $status && 1 === $report['changed_items'] && 2 === $report['failed_items'] && 3 === $report['done_items'], 'The outcome is partial: one changed, two failed.' );
	$check( 1.0 === $price( $kept ) && 130.0 === $price( $restored ) && 140.0 === $price( $binned ), 'Neither the restored member nor the one still in the bin was written.' );

	// Q. A failing bulkcat_batch_created listener that runs before the plugin's own cannot hold the batch back: it is scheduled first.
	$l       = $simple( '150' );
	$case    = $prepare( [ $l ], $set( '151' ) );
	$failing = static function (): void {
		throw new RuntimeException( 'A listener failed.' );
	};
	add_action( 'bulkcat_batch_created', $failing, 1 );
	try {
		$refused( $service->apply( $case['submit'] ), 'bulkcat_request_storage', 'A failing listener makes the answer uncertain.' );
	} finally {
		remove_action( 'bulkcat_batch_created', $failing, 1 );
	}
	$id = (int) $receipt( $case['grant']['uuid'] )['result_id'];
	$check( $id > 0 && 1 === count( $pending( $id ) ), 'The batch was accepted and scheduled before the listener ran.' );
	$again = $accepted( $service->apply( $case['submit'] ), 'The retry resolves.' );
	$check( $id === $again['batch_id'] && true === $again['replayed'] && 1 === count( $pending( $id ) ), 'The retry answers it, still queued once.' );
	$check( 'complete' === $drain( $id ) && 151.0 === $price( $l ), 'It runs once.' );

	// R. A product moved to the bin after Apply, or after an undo was confirmed, is unavailable when its chunk
	// reaches it: skipped with the plain reason, never written, never failed.
	$m      = $simple( '160' );
	$binned = $simple( '170' );
	$case   = $prepare( [ $m, $binned ], $set( '161' ) );
	$id     = $accepted( $service->apply( $case['submit'] ), 'Apply two.' )['batch_id'];
	wp_trash_post( $binned );
	$status = $drain( $id );
	$report = ( new BatchRepository() )->find( $id );
	$item   = $wpdb->get_row( $wpdb->prepare( 'SELECT state, message FROM %i WHERE batch_id = %d AND object_id = %d', $tables['items'], $id, $binned ), ARRAY_A );
	$check( 'partial' === $status && 1 === $report['changed_items'] && 0 === $report['failed_items'] && 2 === $report['done_items'], 'Apply: one changed, none failed.' );
	$check( 'skipped' === $item['state'] && BatchFactory::unavailable_reason() === $item['message'], 'Apply: the binned product is skipped as unavailable.' );
	$check( 161.0 === $price( $m ) && 170.0 === $price( $binned ) && 'trash' === get_post_status( $binned ), 'Apply: the binned product was not written.' );
	$n     = $simple( '180' );
	$later = $simple( '190' );
	$case  = $prepare( [ $n, $later ], $set( '181' ) );
	$id    = $accepted( $service->apply( $case['submit'] ), 'Apply two to undo.' )['batch_id'];
	$check( 'complete' === $drain( $id ) && 181.0 === $price( $n ) && 181.0 === $price( $later ), 'Both changed.' );
	$plan = ( new Planner() )->plan( $id, 1 );
	$undo = $accepted(
		$service->undo(
			$accepted(
				$service->issue(
					'batch.undo',
					[
						'batch_id'  => $id,
						'plan_hash' => $plan->hash(),
					]
				),
				'Undo grant.'
			)
		),
		'Undo accepted.'
	);
	wp_trash_post( $later );
	$status = $drain( $undo['batch_id'] );
	$item   = $wpdb->get_row( $wpdb->prepare( 'SELECT state, message FROM %i WHERE batch_id = %d AND object_id = %d', $tables['items'], $undo['batch_id'], $later ), ARRAY_A );
	$check( 'partial' === $status && 'skipped' === $item['state'] && BatchFactory::unavailable_reason() === $item['message'], 'Undo: the binned product is skipped as unavailable.' );
	$check( 180.0 === $price( $n ) && 181.0 === $price( $later ), 'Undo: the rest was put back; the binned product was not written.' );

	echo wp_json_encode( [ 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
} finally {
	foreach ( array_keys( $restore ) as $id ) {
		$wpdb->update(
			$wpdb->posts,
			[
				'post_type'   => 'product',
				'post_parent' => 0,
			],
			[ 'ID' => $id ]
		);
		clean_post_cache( $id );
	}
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( $id, true );
	}
}
