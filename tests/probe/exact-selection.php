<?php
/**
 * Native foundation proof. Run through wp eval require on the owned fixture.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled, local-only database failure and capacity fixtures require uncached SQL and temporary schema changes.
// phpcs:disable WordPress.PHP.YodaConditions -- Probe expectations compare measured values directly.
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\RequestService;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 );
}
global $wpdb;
$checks = [];
$check  = static function ( bool $ok, string $name ) use ( &$checks ): void {
	$checks[] = [
		'name'   => $name,
		'passed' => $ok,
	];
	if ( ! $ok ) {
		throw new RuntimeException( $name );
	}
};
$check( ( new Migrator( Registry::plugin() ) )->run(), 'Native migration succeeds.' );
wp_set_current_user( 1 );
$now      = time();
$clock    = static function () use ( &$now ): int {
	return $now;
};
$service  = new RequestService( $clock );
$payloads = Registry::plugin()->table( Schema::SELECTIONS );
$receipts = Registry::plugin()->table( Schema::REQUESTS );
$created  = [];
$marker   = 'FrozenProbe' . bin2hex( random_bytes( 5 ) );
$count    = static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
try {
	foreach ( [ 'A', 'B' ] as $letter ) {
		$created[] = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $marker . $letter,
			]
		);
	}
	$before = [ $count( $payloads ), $count( $receipts ) ];
	$grant  = $service->issue( 'selection.capture', [ 'query' => [ 'search' => $marker ] ] );
	$check( is_array( $grant ) && $before === [ $count( $payloads ), $count( $receipts ) ], 'Issuance writes neither membership nor receipt.' );
	$check( [ 'status' => 'not_accepted' ] === $service->resolve( $grant ), 'Resolve alone does not accept an unused grant.' );
	$first = $service->capture( $grant );
	$check( is_array( $first ) && 2 === $first['count'], 'Capture exactly A and B.' );
	$created[] = wp_insert_post(
		[
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => $marker . 'C',
		]
	);
	$again     = $service->capture( $grant );
	$check( is_array( $again ) && $first['token'] === $again['token'] && 2 === $again['count'] && true === $again['replayed'], 'Lost response returns original set without newly matching C.' );
	$changed           = $grant;
	$changed['intent'] = [
		'version' => 1,
		'ids'     => [ $created[2] ],
	];
	$refused           = $service->capture( $changed );
	$check( is_wp_error( $refused ) && 'bulkcat_request_conflict' === $refused->get_error_code(), 'Changed intent conflicts.' );
	$now  += 2 * DAY_IN_SECONDS;
	$again = $service->capture( $grant );
	$check( is_array( $again ) && $first['token'] === $again['token'] && $again['requires_preview'] && $first['retained_until'] === $again['retained_until'], 'Receipt outlives first acceptance and authorization, without renewal.' );
	$now -= 2 * DAY_IN_SECONDS;

	// Break capture between its receipt claim and result insert: neither may commit.
	$rollback   = $service->issue( 'selection.capture', [ 'ids' => [ $created[0] ] ] );
	$before     = [ $count( $payloads ), $count( $receipts ) ];
	$fault      = static fn( string $sql ): string => str_starts_with( $sql, 'INSERT INTO `' . $payloads . '`' ) ? 'INVALID CAPTURE FAULT' : $sql;
	$suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $fault );
	try {
		$failed = $service->capture( $rollback );
	} finally {
		remove_filter( 'query', $fault );
		$wpdb->suppress_errors( $suppressed ); }
	$check( is_wp_error( $failed ) && 'bulkcat_request_storage' === $failed->get_error_code() && $before === [ $count( $payloads ), $count( $receipts ) ], 'Failed result insert rolls back both tables.' );
	$check( is_array( $service->capture( $rollback ) ), 'Same UUID succeeds after a rolled-back failure.' );

	// Simulate COMMIT reaching MySQL, but its acknowledgement being lost.
	$uncertain  = $service->issue( 'selection.capture', [ 'ids' => [ $created[1] ] ] );
	$fault      = static function ( string $sql ) use ( &$fault, $wpdb ): string {
		if ( 'COMMIT' !== $sql ) {
			return $sql; }
		remove_filter( 'query', $fault );
		$wpdb->query( 'COMMIT' );
		return 'INVALID COMMIT ACKNOWLEDGEMENT';
	};
	$suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $fault );
	try {
		$failed = $service->capture( $uncertain );
	} finally {
		remove_filter( 'query', $fault );
		$wpdb->suppress_errors( $suppressed ); }
	$check( is_wp_error( $failed ) && 'bulkcat_request_storage' === $failed->get_error_code(), 'Ambiguous commit reports uncertainty.' );
	$again = $service->capture( $uncertain );
	$check( is_array( $again ) && $again['replayed'] && 1 === $again['count'], 'Retry resolves the single committed uncertain result.' );

	// A damaged schema must fail before a grant is issued.
	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY', $receipts ) );
	try {
		$bad = $service->issue( 'selection.capture', [ 'ids' => [] ] );
		$check( is_wp_error( $bad ) && 'bulkcat_request_storage' === $bad->get_error_code(), 'Missing owner/UUID uniqueness fails closed.' );
	} finally {
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD PRIMARY KEY (user_id,uuid)', $receipts ) ); }
	$now += 7 * DAY_IN_SECONDS + 1;
	$service->prune_selections();
	$gone = $service->capture( $grant );
	$check( is_wp_error( $gone ) && 'bulkcat_selection_expired' === $gone->get_error_code() && $count( $receipts ) >= 3, 'Payload pruning leaves durable gone receipts.' );
	$now += DAY_IN_SECONDS;
	$service->prune_requests();
	$expired = $service->capture( $grant );
	$check( is_wp_error( $expired ) && 'bulkcat_request_expired' === $expired->get_error_code(), 'After receipt pruning the signed deadline still forbids recapture.' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true ); }
	WP_CLI::line( (string) wp_json_encode( [ 'checks' => $checks ], JSON_PRETTY_PRINT ) );
}
