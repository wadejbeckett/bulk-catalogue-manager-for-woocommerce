<?php
/**
 * Two-process capture race on the owned fixture, invoked with a local phase.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled, local-only database failure and capacity fixtures require uncached SQL and temporary schema changes.
// phpcs:disable WordPress.PHP.YodaConditions -- Probe expectations compare measured values directly.
use BulkCatalogueManager\Requests\RequestService;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:18925' !== get_option( 'siteurl' ) ) {
	exit( 1 ); }
wp_set_current_user( 1 );
$service = new RequestService();
if ( 'prepare' === $phase ) {
	if ( false !== get_option( 'bulkcat_probe_race', false ) ) {
		throw new RuntimeException( 'Previous race needs inspection.' ); }
	add_option( 'bulkcat_probe_race', $service->issue( 'selection.capture', [ 'ids' => [] ] ), '', false );
} elseif ( in_array( $phase, [ 'a', 'b' ], true ) ) {
	$hold = static function ( string $sql ): string {
		if ( str_contains( $sql, 'INSERT INTO `wp_bulkcat_selections`' ) ) {
			usleep( 2000000 ); }
		return $sql;
	};
	add_filter( 'query', $hold );
	try {
		$result = $service->capture( get_option( 'bulkcat_probe_race' ) );
	} finally {
		remove_filter( 'query', $hold ); }
	update_option( 'bulkcat_probe_race_' . $phase, $result, false );
	WP_CLI::line( wp_json_encode( $result ) );
} elseif ( 'verify' === $phase ) {
	$a  = get_option( 'bulkcat_probe_race_a' );
	$b  = get_option( 'bulkcat_probe_race_b' );
	$ok = is_array( $a ) && is_array( $b ) && $a['token'] === $b['token'] && $a['replayed'] !== $b['replayed'] && 0 === $a['count'] && 0 === $b['count'];
	foreach ( [ 'bulkcat_probe_race', 'bulkcat_probe_race_a', 'bulkcat_probe_race_b' ] as $key ) {
		delete_option( $key ); }
	if ( ! $ok ) {
		throw new RuntimeException( 'Concurrent retries did not converge.' ); }
	WP_CLI::line( 'PASS: two concurrent processes returned the same empty snapshot; one accepted, one replayed.' );
} else {
	throw new RuntimeException( 'Unknown probe phase.' ); }
