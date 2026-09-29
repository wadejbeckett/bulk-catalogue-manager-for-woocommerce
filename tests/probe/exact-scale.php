<?php
/**
 * T85 scale probe: the whole catalogue (every product and variation) as one
 * exact selection, through the durable service — capture, exact preview,
 * Apply acceptance (every item materialised in one transaction) — with the
 * time and the memory each stage adds, on a disposable scale site only.
 * The accepted batch is cancelled before any chunk runs, so no product is
 * written (checked), unless BULKCAT_SCALE_KEEP=1 leaves it queued for a
 * timed queue run.
 *
 *     ../evidence-20260925/wp-local.sh eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/exact-scale.php";'
 *
 * Environment: BULKCAT_SCALE_QUERY — a JSON capture query (default
 * {"mode":"products_and_variations"}); BULKCAT_SCALE_KEEP=1.
 *
 * Memory is reported as what a stage adds to the process's memory when it
 * starts (WP-CLI and WooCommerce's commands hold far more than a web request
 * starts with), and as that plus a light REST request's peak, which is what
 * a web request needs; the web limit on the test site is WP_MEMORY_LIMIT.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Local-only measurement on a disposable scale site.
// phpcs:disable WordPress.PHP.YodaConditions -- Probe expectations compare measured values directly.
use BulkCatalogueManager\Requests\RequestService;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
global $wpdb;
wp_set_current_user( 1 );
$service = new RequestService();
$query   = json_decode( false !== getenv( 'BULKCAT_SCALE_QUERY' ) ? (string) getenv( 'BULKCAT_SCALE_QUERY' ) : '{"mode":"products_and_variations"}', true );
$keep    = '1' === getenv( 'BULKCAT_SCALE_KEEP' );
$report  = [
	'query'          => $query,
	'php_memory_web' => WP_MEMORY_LIMIT,
	'php_memory_max' => WP_MAX_MEMORY_LIMIT,
	'mysql'          => $wpdb->get_var( 'SELECT VERSION()' ),
	'load'           => sys_getloadavg(),
];
$stage   = static function ( string $name, callable $work ) use ( &$report ) {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$start           = memory_get_usage();
	$queries         = $GLOBALS['wpdb']->num_queries;
	$began           = microtime( true );
	$result          = $work();
	$report[ $name ] = [
		'seconds'  => round( microtime( true ) - $began, 3 ),
		'added_mb' => round( ( memory_get_peak_usage() - $start ) / 1048576, 2 ),
		'queries'  => $GLOBALS['wpdb']->num_queries - $queries,
	];
	if ( is_wp_error( $result ) ) {
		$report[ $name ]['error'] = $result->get_error_code() . ': ' . $result->get_error_message();
		WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
		exit( 1 );
	}
	return $result;
};
$light   = rest_do_request( new WP_REST_Request( 'GET', '/bulkcat/v1/fields' ) );
unset( $light );
$ops                                 = [
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
$capture                             = $stage( 'capture', fn() => $service->capture( $service->issue( 'selection.capture', [ 'query' => $query ] ) ) );
$report['capture']['count']          = $capture['count'];
$report['capture']['by_type']        = $capture['by_type'];
$preview                             = $stage(
	'preview',
	fn() => $service->preview(
		[
			'token'      => $capture['token'],
			'operations' => $ops,
		]
	)
);
$report['preview']['matched']        = $preview['totals']['matched'];
$report['preview']['computed']       = $preview['totals']['computed'];
$report['preview']['would_change']   = $preview['totals']['would_change'];
$report['preview']['response_bytes'] = strlen( (string) wp_json_encode( $preview ) );
$grant                               = $stage(
	'issue',
	fn() => $service->issue(
		'batch.apply',
		[
			'token'      => $capture['token'],
			'operations' => $preview['operations'],
			'scope_hash' => $preview['scope_hash'],
		]
	)
);
$submit                              = $grant + [
	'token'        => $capture['token'],
	'preview_hash' => $preview['hash'],
];
$report['issue']['request_bytes']    = strlen( (string) wp_json_encode( array_intersect_key( $submit, array_flip( [ 'uuid', 'request_proof', 'intent', 'token', 'preview_hash' ] ) ) ) );
$before                              = (string) $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$batch                               = $stage( 'accept', fn() => $service->apply( $submit ) );
$id                                  = (int) $batch['batch_id'];
$report['accept']['batch_id']        = $id;
$report['accept']['total_items']     = $batch['total_items'];
$report['accept']['items_rows']      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_batch_items WHERE batch_id = %d", $id ) );
$report['accept']['chunks']          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = 'bulkcat_run_chunk' AND status = 'pending' AND args LIKE %s", '%' . $id . '%' ) );
$replay                              = $stage( 'replay', fn() => $service->apply( $submit ) );
$report['replay']['same_batch']      = (int) $replay['batch_id'] === $id && ! empty( $replay['replayed'] );
if ( ! $keep ) {
	$cancel              = rest_do_request( new WP_REST_Request( 'POST', "/bulkcat/v1/batches/{$id}/cancel" ) );
	$report['cancelled'] = [
		'status'           => $cancel->get_status(),
		'batch'            => $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bulkcat_batches WHERE batch_id = %d", $id ) ),
		'products_written' => $before !== (string) $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ),
	];
}
WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
