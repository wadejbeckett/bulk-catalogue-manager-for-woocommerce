<?php
/**
 * Native cross-process lease/cleanup races on the owned fixture.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled native concurrency fixture.
// phpcs:disable WordPress.PHP.YodaConditions -- Direct native assertions.
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\SnapshotRepository;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Data\FoundationStorage;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
global $wpdb;
wp_set_current_user( 1 );
$key = 'bulkcat_probe_lifecycle_race';
if ( in_array( $phase, [ 'prepare', 'prepare_compose' ], true ) ) {
	if ( false !== get_option( $key, false ) ) {
		throw new RuntimeException( 'Inspect unfinished lifecycle race.' ); }
	$now                             = time();
	$row                             = ( new SnapshotRepository() )->create(
		[
			'products'   => [ 99999999 ],
			'variations' => [],
		],
		1,
		$now
	);
	$row['authorization_expires_at'] = gmdate( 'Y-m-d H:i:s', $now + ( 'prepare_compose' === $phase ? 60 : -1 ) );
	$row['retained_until']           = gmdate( 'Y-m-d H:i:s', $now + 60 );
	( new SnapshotRepository() )->retain( $row );
	$compose_grant = ( new RequestService() )->issue(
		'selection.compose',
		[
			'token'      => SnapshotToken::issue( $row ),
			'remove_ids' => [],
		]
	);
	add_option(
		$key,
		[
			'token' => SnapshotToken::issue( $row ),
			'now'   => $now,
			'id'    => $row['id'],
			'grant' => $compose_grant,
		],
		'',
		false
	);
	WP_CLI::line( 'Prepared owned race.' );
} elseif ( in_array( $phase, [ 'renew_hold', 'prune_hold', 'renew', 'prune', 'compose_hold', 'compose' ], true ) ) {
	$fixture  = get_option( $key );
	$is_prune = str_starts_with( $phase, 'prune' );
	$service  = new RequestService( static fn(): int => $fixture['now'] + ( $is_prune ? 120 : 0 ) );
	$hold     = static function ( string $sql ) use ( $phase, $wpdb, $fixture ): string {
		$match = 'compose_hold' === $phase ? str_starts_with( $sql, 'INSERT INTO `wp_bulkcat_selections`' ) : ( 'renew_hold' === $phase ? str_starts_with( $sql, 'UPDATE `wp_bulkcat_selections`' ) : str_starts_with( $sql, 'DELETE FROM `wp_bulkcat_selections`' ) );
		if ( $match && ( 'compose_hold' === $phase || str_contains( $sql, $fixture['id'] ) ) ) {
			$wpdb->get_var( "SELECT GET_LOCK('bulkcat_t82_holder',0)" );
			$until = microtime( true ) + 30;
			while ( ! $wpdb->get_var( "SELECT IS_USED_LOCK('bulkcat_t82_peer')" ) ) {
				if ( microtime( true ) > $until ) {
					throw new RuntimeException( 'Peer barrier timed out.' ); }
				usleep( 100000 );
			}
			usleep( 2000000 );
		}
		return $sql;
	};
	if ( str_ends_with( $phase, '_hold' ) ) {
		add_filter( 'query', $hold ); }
	if ( ! str_ends_with( $phase, '_hold' ) ) {
		$until = microtime( true ) + 30;
		while ( ! $wpdb->get_var( "SELECT IS_USED_LOCK('bulkcat_t82_holder')" ) ) {
			if ( microtime( true ) > $until ) {
				throw new RuntimeException( 'Holder barrier timed out.' ); }
			usleep( 100000 );
		}
		$wpdb->get_var( "SELECT GET_LOCK('bulkcat_t82_peer',0)" );
	}
	$started = microtime( true );
	try {
		$result = $is_prune ? $service->prune_selections() : ( str_starts_with( $phase, 'compose' ) ? $service->compose( $fixture['grant'] + [ 'token' => $fixture['token'] ] ) : $service->handle( 'renew', [ 'token' => $fixture['token'] ] ) ); } finally {
		remove_filter( 'query', $hold );
		$wpdb->get_var( "SELECT RELEASE_LOCK('bulkcat_t82_holder')" );
		$wpdb->get_var( "SELECT RELEASE_LOCK('bulkcat_t82_peer')" ); }
		update_option(
			$key . '_' . $phase,
			[
				'result'  => $result,
				'seconds' => microtime( true ) - $started,
			],
			false
		);
	WP_CLI::line( 'Finished ' . $phase );
} elseif ( str_starts_with( $phase, 'verify_' ) ) {
	$fixture = get_option( $key );
	$held    = get_option( $key . ( 'verify_compose' === $phase ? '_compose_hold' : ( 'verify_prune' === $phase ? '_prune_hold' : '_renew_hold' ) ) );
	$peer    = get_option( $key . ( 'verify_compose' === $phase ? '_compose' : ( 'verify_keep' === $phase ? '_prune' : '_renew' ) ) );
	$left    = $held['result'];
	$right   = $peer['result'];
	if ( 'verify_compose' === $phase ) {
		$ok = is_array( $left ) && is_array( $right ) && $left['token'] === $right['token'] && 1 === $right['count'] && $left['replayed'] !== $right['replayed'];
	} elseif ( 'verify_dual' === $phase ) {
		$ok = is_array( $left ) && is_array( $right ) && $left['token'] === $right['token'] && 2 === $right['authorization_epoch'] && $left['requires_preview'] && $right['requires_preview'];
	} elseif ( 'verify_keep' === $phase ) {
		$ok = is_array( $left ) && null !== ( new SnapshotRepository() )->read( $fixture['id'], 1 );
	} else {
		$ok = is_wp_error( $right ) && 410 === $right->get_error_data()['status'] && null === ( new SnapshotRepository() )->read( $fixture['id'], 1 );
	}
	if ( ! $ok || $peer['seconds'] < 1 ) {
		throw new RuntimeException( 'Race failed or did not overlap: ' . $phase ); }
	foreach ( [ '', '_renew_hold', '_prune_hold', '_renew', '_prune', '_compose_hold', '_compose' ] as $suffix ) {
		delete_option( $key . $suffix ); }
	WP_CLI::line(
		wp_json_encode(
			[
				'phase'             => $phase,
				'pass'              => true,
				'peer_wait_seconds' => $peer['seconds'],
			]
		)
	);
} else {
	throw new RuntimeException( 'Unknown phase.' ); }
