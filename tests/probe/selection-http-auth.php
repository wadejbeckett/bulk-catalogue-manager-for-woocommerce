<?php
/**
 * Ephemeral cookie/nonce material for a localhost-only HTTP probe.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
use BulkCatalogueManager\Requests\RequestService;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
$key = 'bulkcat_probe_http_session';
if ( 'prepare' === $phase ) {
	if ( false !== get_option( $key, false ) ) {
		throw new RuntimeException( 'Inspect previous HTTP fixture session.' ); }
	$expiry  = time() + 600;
	$session = WP_Session_Tokens::get_instance( 1 )->create( $expiry );
	add_option( $key, $session, '', false );
	$cookie                      = wp_generate_auth_cookie( 1, $expiry, 'logged_in', $session );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	wp_set_current_user( 1 );
	$service = new RequestService();
	$base    = $service->capture( $service->issue( 'selection.capture', [ 'ids' => [] ] ) );
	$grant   = $service->issue(
		'selection.compose',
		[
			'token'      => $base['token'],
			'remove_ids' => [],
		]
	);
	unset( $grant['operation'], $grant['accept_until'] );
	// Consumed in memory by the local runner; never persisted or logged.
	WP_CLI::line(
		wp_json_encode(
			[
				'cookie'  => LOGGED_IN_COOKIE . '=' . $cookie,
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'token'   => $base['token'],
				'compose' => $grant + [ 'token' => $base['token'] ],
			]
		)
	);
} elseif ( 'cleanup' === $phase ) {
	$session = get_option( $key );
	if ( is_string( $session ) ) {
		WP_Session_Tokens::get_instance( 1 )->destroy( $session ); }
	delete_option( $key );
} else {
	throw new RuntimeException( 'Unknown phase.' ); }
