<?php
/**
 * Contract version 2 native journey (ticket 25; docs/EXTENSIONS-v2.md §10
 * V3–V6, V8), on a disposable stack only, with both neutral test providers
 * copied into wp-content/mu-plugins (tests/fixtures/mu-plugins/): the
 * version 2 "Sample picks" (5,000 recipients through a lookup, row entries)
 * and the version 1 "Sample labels".
 *
 * - /fields advertises the lookup and row entries; version 1 entries gain no key;
 * - GET /lookup pages and searches 5,000 choices, bounded, never cached,
 *   refusing a bad page and a user without the right;
 * - a row change of one product leaves a retained selection untouched;
 * - a bulk change of a retained selection, History naming the chosen items,
 *   Undo and redo through the real queue;
 * - denied, withdrawn and renamed choices refused or stale;
 * - the version 1 provider still previews, applies and reads back.
 *
 *     BULKCAT_PROBE_SITEURL=http://localhost:8940 wp eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-v2-journey.php";'
 *
 * It prints its checks as JSON, exits 1 on the first failure, and removes
 * the products, users and options it made.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- A CLI probe's own variables.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Tests\Support\Extension\PicksFaults;
use BulkCatalogueManager\Tests\Support\Extension\PicksProvider;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	fwrite( STDERR, "Not a disposable fixture: set BULKCAT_PROBE_SITEURL to this site's local URL.\n" );
	exit( 1 );
}
if ( ! class_exists( PicksProvider::class ) ) {
	fwrite( STDERR, "Copy tests/fixtures/mu-plugins/bulkcat-picks-provider.php and bulkcat-sample-provider.php into wp-content/mu-plugins first.\n" );
	exit( 1 );
}

$checks   = [];
$timings  = [];
$check    = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	$checks[] = $label;
};
$accepted = static function ( mixed $value, string $label ) use ( $check ): array {
	if ( is_wp_error( $value ) ) {
		fwrite( STDERR, $label . ' refused: ' . $value->get_error_code() . ' ' . $value->get_error_message() . ' ' . wp_json_encode( $value->get_error_data() ) . "\n" );
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
$fresh    = static function (): void {
	$class = new ReflectionClass( Actions::class );
	$class->setStaticPropertyValue( 'instance', null );
	$class->setStaticPropertyValue( 'fired', false );
	$GLOBALS['wp_rest_server'] = null;
	Actions::instance();
};
$faults   = static function ( array $faults ) use ( $fresh ): void {
	update_option( PicksFaults::OPTION, $faults, false );
	$fresh();
};
$rest     = static function ( string $method, string $route, array $params = [], int $user = 1 ): WP_REST_Response {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/bulkcat/v1' . $route );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	return rest_do_request( $request );
};
$drain    = static function ( int $batch_id ): string {
	$batches = new BatchRepository();
	wp_set_current_user( 0 );
	for ( $round = 0; $round < 40; $round++ ) {
		$status = (string) $batches->status( $batch_id );
		if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			wp_set_current_user( 1 );
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
			ActionScheduler::runner()->process_action( (int) $action_id, 'provider-v2 probe' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
$picks    = static function ( int $id ): array {
	wp_cache_delete( $id, 'post_meta' );
	$raw = get_post_meta( $id, '_sample_picks', true );
	return is_string( $raw ) && '' !== $raw ? (array) json_decode( $raw, true ) : [];
};
$step     = static fn( array $recipients, string $action = 'x_picks_add' ): array => [
	'action' => $action,
	'args'   => [
		'field'      => 'x_picks_items',
		'recipients' => $recipients,
	],
];
$service  = new RequestService();
// Preview, then Apply through a grant bound to that preview: a selection token or explicit ids.
$apply   = static function ( array $scope, array $operations, string $label ) use ( $service, $accepted ): array {
	wp_set_current_user( 1 );
	$preview = $accepted( $service->preview( $scope + [ 'operations' => $operations ] ), "$label: preview." );
	$grant   = $service->issue(
		'batch.apply',
		$scope + [
			'operations' => $preview['operations'],
			'scope_hash' => $preview['scope_hash'],
		]
	);
	$accepted( $grant, "$label: grant." );
	return [ $preview, $grant + $scope + [ 'preview_hash' => $preview['hash'] ] ];
};
$undo_of = static function ( int $of ) use ( $service, $accepted ): int {
	wp_set_current_user( 1 );
	$plan  = ( new Planner() )->plan( $of, 1 );
	$grant = $accepted(
		$service->issue(
			'batch.undo',
			[
				'batch_id'  => $of,
				'plan_hash' => $plan->hash(),
				'force'     => false,
			]
		),
		'Undo grant.'
	);
	return (int) $accepted( $service->undo( $grant ), 'Undo accepted.' )['batch_id'];
};

$ids   = [];
$users = [];
try {
	wp_set_current_user( 1 );
	$faults( [] );
	$providers = Actions::instance()->providers();
	$check( null !== $providers->get( 'picks' ) && 2 === $providers->get( 'picks' )->contract, 'V1: the version 2 provider registered (contract 2).' );
	$check( null !== $providers->get( 'sample' ) && 1 === $providers->get( 'sample' )->contract, 'V1: the version 1 provider registered beside it (contract 1).' );

	foreach ( range( 1, 5 ) as $n ) {
		$product = new WC_Product_Simple();
		$product->set_name( "Picks probe $n" );
		$product->set_sku( "bulkcat-picks-$n-" . wp_generate_password( 6, false, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$ids[ $n ] = $product->save();
	}
	wp_cache_flush();

	// ---- /fields ----
	$fields = array_column( $rest( 'GET', '/fields' )->get_data()['actions'], null, 'id' );
	$check( true === ( $fields['x_picks_add']['row'] ?? null ) && [ 'multiple' => true ] === ( $fields['x_picks_add']['args']['properties']['recipients']['lookup'] ?? null ), 'V3: /fields advertises the lookup and the row entry.' );
	$check( ! isset( $fields['x_picks_add']['args']['properties']['recipients']['choices'] ), 'V3: a lookup carries no list of choices.' );
	$check( ! array_key_exists( 'row', $fields['x_sample_add'] ) && ! array_key_exists( 'target', $fields['x_sample_add'] ), 'V1: a version 1 entry gains no key.' );

	// ---- GET /lookup over 5,000 choices ----
	$ask   = static function ( array $params, int $user = 1 ) use ( $rest, &$timings ): WP_REST_Response {
		$start     = microtime( true );
		$answer    = $rest(
			'GET',
			'/lookup',
			$params + [
				'action'   => 'x_picks_add',
				'argument' => 'recipients',
			],
			$user
		);
		$timings[] = round( ( microtime( true ) - $start ) * 1000 );
		return $answer;
	};
	$first = $ask( [] );
	$check( 200 === $first->get_status() && 20 === count( $first->get_data()['items'] ) && true === $first->get_data()['hasMore'], 'V3: page 1 holds 20 of 5,000 choices, with more to come.' );
	$check( str_contains( (string) ( $first->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), 'V3: a page is never cached.' );
	$last = $ask( [ 'page' => 250 ] )->get_data();
	$check( 'r5000' === end( $last['items'] )['id'] && false === $last['hasMore'], 'V3: page 250 ends at the 5,000th choice.' );
	$check( [] === $ask( [ 'page' => 251 ] )->get_data()['items'], 'V3: past the end is an empty page.' );
	$search = $ask( [ 'query' => 'Recipient 4321' ] )->get_data();
	$check( [ 'r4321' ] === array_column( $search['items'], 'id' ) && 'Recipient 4321' === $search['items'][0]['label'], 'V3: a search finds one choice among 5,000.' );
	$check( 400 === $ask( [ 'page' => 501 ] )->get_status() && 'bulkcat_invalid_lookup' === $ask( [ 'page' => 0 ] )->get_data()['code'], 'V3: a page outside 1–500 is refused.' );
	$check( 403 === $ask( [ 'object' => 'product:' . $ids[1] ], 0 )->get_status() || 401 === $ask( [], 0 )->get_status(), 'V3: an anonymous request is refused.' );
	$check( max( $timings ) < 2000, 'V3: every lookup answered within 2 seconds (slowest ' . max( $timings ) . ' ms).' );

	// A shop manager who may not choose restricted recipients.
	$manager = wp_insert_user(
		[
			'user_login' => 'picks-manager-' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'shop_manager',
		]
	);
	$users[] = $manager;
	$faults( [ 'restricted' => [ 'r0003' ] ] );
	$check( ! in_array( 'r0003', array_column( $ask( [], $manager )->get_data()['items'], 'id' ), true ), 'V4: a choice this user may not use is not listed for them.' );
	$check( in_array( 'r0003', array_column( $ask( [] )->get_data()['items'], 'id' ), true ), 'V4: … and is listed for an administrator.' );
	wp_set_current_user( $manager );
	$denied = $refused(
		$service->preview(
			[
				'ids'        => [ $ids[1] ],
				'operations' => [ $step( [ 'r0003' ] ) ],
			]
		),
		'bulkcat_choice_unavailable',
		'V4: a denied choice sent anyway is refused at preview.'
	);
	$check( 'recipients' === ( $denied->get_error_data()['argument'] ?? '' ), 'V4: … naming the argument.' );
	$faults( [] );

	// ---- Row change of one product; a retained selection is untouched ----
	wp_set_current_user( 1 );
	$retained = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'query' => [ 'search' => 'Picks probe' ] ] ) ), 'A retained selection of the five products.' );
	$held     = $service->handle( 'inspect', [ 'token' => $retained['token'] ] );
	$check( 5 === ( $held['count'] ?? 0 ), 'The retained selection holds five products.' );
	$check( 200 === $ask( [ 'object' => 'product:' . $ids[1] ] )->get_status(), 'V6: the row\'s own lookup is allowed for that product.' );
	[ $row_preview, $row_submit ] = $apply( [ 'ids' => [ $ids[1] ] ], [ $step( [ 'r0042', 'r0001' ] ) ], 'V6 row' );
	$check( 'Sample picks will change on 1 product.' === $row_preview['summary'], 'V6: the row change previews one product.' );
	$row = (int) $accepted( $service->apply( $row_submit ), 'V6: row Apply accepted.' )['batch_id'];
	$check( 'complete' === $drain( $row ), 'V6: the row change completed.' );
	$check( [ 'r0001', 'r0042' ] === $picks( $ids[1] ) && [] === $picks( $ids[2] ), 'V6: only that product changed, read back from storage.' );
	$check( ( $service->handle( 'inspect', [ 'token' => $retained['token'] ] )['count'] ?? 0 ) === 5, 'V6: the retained selection still holds its five products.' );

	// ---- Bulk on the retained selection ----
	[ $bulk_preview, $bulk_submit ] = $apply( [ 'token' => $retained['token'] ], [ $step( [ 'r0007' ] ) ], 'V8 bulk' );
	$bulk                           = (int) $accepted( $service->apply( $bulk_submit ), 'V8: bulk Apply accepted.' )['batch_id'];
	$check( 'complete' === $drain( $bulk ), 'V8: the bulk change completed.' );
	$check( [ 'r0001', 'r0007', 'r0042' ] === $picks( $ids[1] ) && [ 'r0007' ] === $picks( $ids[5] ), 'V8: every selected product carries the chosen recipient, others kept.' );

	// ---- History ----
	$report = $rest( 'GET', "/batches/$bulk" )->get_data();
	$check( [ 'r0007' => 'Recipient 0007' ] === ( $report['external']['choices']['x_picks_add']['recipients'] ?? null ), 'V5: History names the chosen item by its kept label.' );
	$check( ! str_contains( (string) wp_json_encode( $rest( 'GET', "/batches/$bulk/changes" )->get_data() ), '"r0007"' ), 'V5: the batch\'s changes carry text, never the raw value.' );
	$listed = array_column( $rest( 'GET', '/batches' )->get_data()['items'], null, 'batch_id' );
	$check( [ 'r0007' => 'Recipient 0007' ] === ( $listed[ $bulk ]['external']['choices']['x_picks_add']['recipients'] ?? null ), 'V5: the History list names it too.' );

	// ---- Stale and withdrawn choices ----
	[ , $stale_submit ] = $apply( [ 'ids' => [ $ids[2] ] ], [ $step( [ 'r0009' ] ) ], 'V4 stale' );
	$faults( [ 'renamed' => [ 'r0009' => 'Renamed recipient' ] ] );
	$stale = $refused( $service->apply( $stale_submit ), 'bulkcat_preview_stale', 'V4: a choice renamed since the preview makes Apply stale.' );
	$check( 'provider_changed' === ( $stale->get_error_data()['reason'] ?? '' ), 'V4: … with data.reason provider_changed.' );
	$faults( [ 'withdrawn' => [ 'r0009' ] ] );
	$refused( $service->apply( $stale_submit ), 'bulkcat_choice_unavailable', 'V4: a choice withdrawn since the preview is refused at Apply.' );
	$faults( [] );
	$check( [] === $picks( $ids[2] ) || [ 'r0007' ] === $picks( $ids[2] ), 'V4: nothing was written by a refused Apply.' );

	// ---- Undo and redo ----
	$undo = $undo_of( $bulk );
	$check( 'complete' === $drain( $undo ), 'V8: the undo completed.' );
	$check( [ 'r0001', 'r0042' ] === $picks( $ids[1] ) && [] === $picks( $ids[5] ), 'V8: undo put back each product\'s earlier recipients.' );
	$redo = $undo_of( $undo );
	$check( 'complete' === $drain( $redo ), 'V8: the redo completed.' );
	$check( [ 'r0001', 'r0007', 'r0042' ] === $picks( $ids[1] ) && [ 'r0007' ] === $picks( $ids[5] ), 'V8: redo applied the change again.' );

	// ---- The version 1 provider still works ----
	[ , $v1_submit ] = $apply(
		[ 'ids' => [ $ids[4] ] ],
		[
			[
				'action' => 'x_sample_add',
				'args'   => [
					'field'  => 'x_sample_labels',
					'labels' => [ 'red' ],
				],
			],
		],
		'V1 labels'
	);
	$v1              = (int) $accepted( $service->apply( $v1_submit ), 'V1: version 1 Apply accepted.' )['batch_id'];
	$check( 'complete' === $drain( $v1 ), 'V1: the version 1 change completed.' );
	wp_cache_delete( $ids[4], 'post_meta' );
	$check( '["red"]' === get_post_meta( $ids[4], '_sample_labels', true ), 'V1: the version 1 provider wrote and read back its value.' );
	$v1_undo = $undo_of( $v1 );
	$check( 'complete' === $drain( $v1_undo ) && '' === (string) get_post_meta( $ids[4], '_sample_labels', true ), 'V1: and its undo works as before.' );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
	WP_CLI::line( wp_json_encode( [ 'passed' => $checks ] ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $users as $user ) {
		wp_delete_user( $user );
	}
	delete_option( PicksFaults::OPTION );
	exit( 1 );
}
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $users as $user ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user );
}
delete_option( PicksFaults::OPTION );
WP_CLI::line(
	wp_json_encode(
		[
			'checks'      => $checks,
			'lookup_ms'   => $timings,
			'all_correct' => true,
		],
		JSON_PRETTY_PRINT
	)
);
