<?php
/**
 * Ticket 11 item 5: a cleanup backlog for the native probes. Makes $stale
 * selections through the request service with its clock nine days back, so
 * every payload's seven-day retention and its receipt's eight days have
 * passed: the plugin's bounded cleanup (100 a run, oldest first) then has
 * $stale payloads and $stale receipts waiting, older than anything a probe
 * makes. On a disposable fixture only (fixture.php). Prints the backlog.
 *
 *     wp eval '$stale = 500; require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/stale-selections.php";'
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native fixture counts.

use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\RequestService;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
global $wpdb;
$stale = max( 1, (int) ( $stale ?? 500 ) );
wp_set_current_user( 1 );
$then    = time() - 9 * DAY_IN_SECONDS;
$service = new RequestService( static fn(): int => $then );
$product = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID LIMIT 1" );
if ( $product < 1 ) {
	throw new RuntimeException( 'Seed the fixture first: no published product.' );
}
for ( $i = 0; $i < $stale; ++$i ) {
	$capture = $service->capture( $service->issue( 'selection.capture', [ 'ids' => [ $product ] ] ) );
	if ( is_wp_error( $capture ) ) {
		throw new RuntimeException( 'Stale capture refused: ' . $capture->get_error_code() );
	}
}
$now = gmdate( 'Y-m-d H:i:s' );
WP_CLI::line(
	(string) wp_json_encode(
		[
			'made'             => $stale,
			'payloads_waiting' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE retained_until <= %s', Registry::plugin()->table( Schema::SELECTIONS ), $now ) ),
			'receipts_waiting' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (result_kind = 'snapshot' OR (result_kind = 'batch' AND state = 'gone')) AND retain_until <= %s", Registry::plugin()->table( Schema::REQUESTS ), $now ) ),
		]
	)
);
