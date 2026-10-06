<?php
/**
 * External action contract (T109; docs/EXTENSIONS.md §14, §15.2 E15) at
 * scale, on a disposable stack seeded with `tests/probe/seed.sh 10000` and the
 * test provider installed as a must-use plugin: a 10,000-object preview of
 * an external action, a 1,000-object Apply through the plugin's own queue
 * (at least 300 products a minute, SPEC §12.5), the time `GET /fields` takes
 * with one provider and without, and the cost of the test provider's state
 * token over the scale catalogue (at most 1.5 s, §14). It prints the figures
 * as JSON and exits 1 on a missed budget; the Apply is undone at the end.
 *
 *     BULKCAT_PROBE_SITEURL=http://localhost:8904 wp eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-scale.php";'
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native fixture reads.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- A CLI probe's own variables.

use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\Extension\Registrar;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() || ! class_exists( SampleProvider::class ) ) {
	fwrite( STDERR, "Needs a disposable fixture (BULKCAT_PROBE_SITEURL) and the test provider's must-use plugin.\n" );
	exit( 1 );
}
global $wpdb;
$figures  = [];
$fail     = [];
$budget   = static function ( bool $ok, string $label ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail[] = $label;
	}
};
$provider = static function ( bool $on ): void {
	remove_all_actions( 'bulkcat_register_providers' );
	if ( $on ) {
		add_action(
			'bulkcat_register_providers',
			static function ( Registrar $registrar ): void {
				$registrar->register( new SampleProvider() );
			}
		);
	}
	$class = new ReflectionClass( Actions::class );
	$class->setStaticPropertyValue( 'instance', null );
	$class->setStaticPropertyValue( 'fired', false );
	$GLOBALS['wp_rest_server'] = null;
	Actions::instance();
};
$median   = static function ( array $values ): float {
	sort( $values );
	return (float) $values[ intdiv( count( $values ), 2 ) ];
};
wp_set_current_user( 1 );
$service = new RequestService();
$add     = [
	[
		'action' => 'x_sample_add',
		'args'   => [
			'field'  => 'x_sample_labels',
			'labels' => [ 'red' ],
		],
	],
];

// /fields with and without one provider: the median of ten dispatches each.
foreach ( [
	'fields_ms_without_provider' => false,
	'fields_ms_with_provider'    => true,
] as $name => $on ) {
	$provider( $on );
	$times = [];
	for ( $i = 0; $i < 10; $i++ ) {
		$start    = microtime( true );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/bulkcat/v1/fields' ) );
		$body     = (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) );
		$times[]  = ( microtime( true ) - $start ) * 1000;
	}
	$figures[ $name ]            = round( $median( $times ), 1 );
	$figures[ $name . '_first' ] = round( $times[0], 1 );
	$figures[ $name . '_bytes' ] = strlen( $body );
	$budget( 200 === $response->get_status(), "/fields answers ($name)." );
	$budget( $on === str_contains( $body, '"provider":"sample"' ), "/fields carries the provider's entries only when it is registered ($name)." );
}
$budget( $figures['fields_ms_with_provider'] < 400, '/fields with one provider stays within the grid-page budget of 400 ms.' );

// The scale catalogue's products.
$scale                     = get_term_by( 'slug', 'bulkcat-scale', 'product_cat' );
$ids                       = $scale ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE t.term_id = %d AND p.post_type = 'product' AND p.post_status = 'publish' ORDER BY p.ID LIMIT 10000", $scale->term_id ) ) ) : [];
$figures['scale_products'] = count( $ids );
if ( count( $ids ) < 10000 ) {
	fwrite( STDERR, "Seed the scale catalogue first: tests/probe/seed.sh 10000.\n" );
	exit( 1 );
}

// The state token over the scale catalogue.
$registered = Actions::instance()->providers()->get( 'sample' );
$start      = microtime( true );
Binding::of( $registered, $add, $ids );
$figures['state_token_ms_10000'] = round( ( microtime( true ) - $start ) * 1000, 1 );
$budget( $figures['state_token_ms_10000'] < 1500, 'The test provider\'s state token stays within 1.5 s.' );

// A 10,000-object preview of an external action, over a captured search.
$capture = $service->capture( $service->issue( 'selection.capture', [ 'query' => [ 'search' => 'Scale Product' ] ] ) );
$budget( is_array( $capture ) && $capture['count'] >= 10000, 'The scale search captures 10,000 products.' );
wp_cache_flush();
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$memory                            = memory_get_usage();
$queries                           = $wpdb->num_queries;
$start                             = microtime( true );
$preview                           = $service->preview(
	[
		'token'      => $capture['token'],
		'operations' => $add,
	]
);
$figures['preview_seconds_10000']  = round( microtime( true ) - $start, 2 );
$figures['preview_added_mb_10000'] = round( ( memory_get_peak_usage() - $memory ) / 1048576, 1 );
$figures['preview_queries_10000']  = $wpdb->num_queries - $queries;
$budget( is_array( $preview ) && 10000 === $preview['totals']['computed'] && false === $preview['totals']['estimated'], 'The preview computes all 10,000 objects.' );
$budget( $figures['preview_added_mb_10000'] < 96, 'The preview stays within 96 MB.' );

// The same capture previewed with a native change, for comparison (no budget).
wp_cache_flush();
$queries                                 = $wpdb->num_queries;
$start                                   = microtime( true );
$native                                  = $service->preview(
	[
		'token'      => $capture['token'],
		'operations' => [
			[
				'action' => 'adjust',
				'args'   => [
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'percent',
					'amount'    => '10',
				],
			],
		],
	]
);
$figures['native_preview_seconds_10000'] = round( microtime( true ) - $start, 2 );
$figures['native_preview_queries_10000'] = $wpdb->num_queries - $queries;
$budget( is_array( $native ) && 10000 === $native['totals']['computed'], 'The native comparison preview computes all 10,000 objects.' );

// A 1,000-object Apply through the plugin's own queue.
$apply_ids = array_slice( $ids, 0, 1000 );
$small     = $service->preview(
	[
		'ids'        => $apply_ids,
		'operations' => $add,
	]
);
$grant     = $service->issue(
	'batch.apply',
	[
		'ids'        => $apply_ids,
		'operations' => $small['operations'],
		'scope_hash' => $small['scope_hash'],
	]
);
$result    = $service->apply( $grant + [ 'preview_hash' => $small['hash'] ] );
$batch     = (int) $result['batch_id'];
$chunks    = [];
$start     = microtime( true );
wp_set_current_user( 0 );
for ( $round = 0; $round < 200; $round++ ) {
	$status = ( new BulkCatalogueManager\Data\BatchRepository() )->status( $batch );
	if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
		break;
	}
	foreach ( as_get_scheduled_actions(
		[
			'hook'     => ChunkRunner::HOOK,
			'args'     => [ $batch ],
			'group'    => Registry::ACTION_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		],
		'ids'
	) as $action ) {
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		ActionScheduler::runner()->process_action( (int) $action, 'provider-scale probe' );
		$chunks[] = round( ( memory_get_peak_usage() - $before ) / 1048576, 1 );
	}
	wp_cache_flush();
}
wp_set_current_user( 1 );
$seconds                              = microtime( true ) - $start;
$figures['apply_status_1000']         = $status;
$figures['apply_seconds_1000']        = round( $seconds, 1 );
$figures['apply_products_per_minute'] = (int) round( 1000 / max( 0.001, $seconds ) * 60 );
$figures['apply_chunks']              = count( $chunks );
$figures['apply_chunk_added_mb_max']  = [] === $chunks ? 0 : max( $chunks );
$budget( 'complete' === $status, 'The 1,000-object Apply completes.' );
$budget( $figures['apply_products_per_minute'] >= 300, 'At least 300 products a minute.' );
$budget( $figures['apply_chunk_added_mb_max'] < 96, 'Each chunk stays within 96 MB.' );

// Put the scale catalogue back.
$plan = ( new Planner() )->plan( $batch, 1 );
$undo = $service->undo(
	$service->issue(
		'batch.undo',
		[
			'batch_id'  => $batch,
			'plan_hash' => $plan->hash(),
			'force'     => false,
		]
	)
);
wp_set_current_user( 0 );
for ( $round = 0; $round < 200; $round++ ) {
	if ( ! in_array( ( new BulkCatalogueManager\Data\BatchRepository() )->status( (int) $undo['batch_id'] ), [ 'queued', 'running' ], true ) ) {
		break;
	}
	foreach ( as_get_scheduled_actions(
		[
			'hook'     => ChunkRunner::HOOK,
			'args'     => [ (int) $undo['batch_id'] ],
			'group'    => Registry::ACTION_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		],
		'ids'
	) as $action ) {
		ActionScheduler::runner()->process_action( (int) $action, 'provider-scale probe' );
	}
	wp_cache_flush();
}
wp_set_current_user( 1 );
$figures['undo_status'] = ( new BulkCatalogueManager\Data\BatchRepository() )->status( (int) $undo['batch_id'] );
$budget( 'complete' === $figures['undo_status'], 'The undo of the 1,000-object Apply completes.' );

WP_CLI::line(
	wp_json_encode(
		[
			'figures' => $figures,
			'missed'  => $fail,
		],
		JSON_PRETTY_PRINT
	)
);
if ( [] !== $fail ) {
	exit( 1 );
}
