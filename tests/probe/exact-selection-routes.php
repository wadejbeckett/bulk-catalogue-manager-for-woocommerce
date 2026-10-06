<?php
/**
 * REST permissions, upgrade preservation and installation identity on a local fixture.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.PHP.YodaConditions -- Native probe compares measured values directly.
// phpcs:disable WordPress.DB -- This guarded native probe measures stored rows and tests temporary schema faults.
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Data\Cleanup;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Requests\RequestService;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
global $wpdb;
$checks = [];
$check  = static function ( bool $ok, string $name ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $name );
	} $checks[] = $name;
};
$call   = static function ( string $route, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/bulkcat/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_query_params( [ 'rest_route' => '/bulkcat/v1/' . $route ] );
	$request->set_body( wp_json_encode( $data ) );
	return rest_do_request( $request );
};
$intent = [
	'operation' => 'selection.capture',
	'intent'    => [ 'ids' => [] ],
];
wp_set_current_user( 0 );
$check( 401 === $call( 'requests', $intent )->get_status(), 'Anonymous issuance refused.' );
$user           = wp_insert_user(
	[
		'user_login' => 'probe-' . bin2hex( random_bytes( 6 ) ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	]
);
$sentinel_batch = 0;
try {
	wp_set_current_user( $user );
	$check( 403 === $call( 'requests', $intent )->get_status(), 'Subscriber issuance refused.' );
	wp_set_current_user( 1 );
	$issued = $call( 'requests', $intent );
	$check( 200 === $issued->get_status(), 'Administrator issues a capture grant over registered REST route.' );
	$grant = array_intersect_key( $issued->get_data(), array_flip( [ 'uuid', 'request_proof', 'intent' ] ) );
	$first = $call( 'selection', $grant );
	$check( 200 === $first->get_status() && 0 === $first->get_data()['count'], 'Registered selection route accepts an exact empty snapshot.' );
	$resolved = $call( 'requests/resolve', $grant );
	$check( 200 === $resolved->get_status() && $resolved->get_data()['token'] === $first->get_data()['token'], 'Resolver returns the same snapshot through REST.' );
	$check( 400 === $call( 'selection', $grant + [ 'ids' => [ 1 ] ] )->get_status(), 'Mixed legacy and durable input refused.' );
	( new WP_User( $user ) )->set_role( 'shop_manager' );
	wp_set_current_user( $user );
	$check( 200 === $call( 'requests', $intent )->get_status() && 403 === $call( 'requests/resolve', $grant )->get_status(), 'Shop managers can issue their own grants but cannot resolve another owner.' );
	wp_set_current_user( 1 );
	$epoch          = FoundationStorage::epoch();
	$sentinel_batch = ( new \BulkCatalogueManager\Data\BatchRepository() )->create( wp_generate_uuid4(), 1, 'bulk', [], [ 'ids' => [ 1 ] ], 1 );
	$wpdb->insert(
		Registry::plugin()->table( Schema::ITEMS ),
		[
			'batch_id'    => $sentinel_batch,
			'object_id'   => 1,
			'object_type' => 'product',
			'state'       => 'done',
		]
	);
	( new \BulkCatalogueManager\Data\ChangeRepository() )->append( $sentinel_batch, 1, 'product', 'regular_price', '1.00', '2.00', gmdate( 'Y-m-d H:i:s' ), 'applied' );
	( new \BulkCatalogueManager\Data\BatchRepository() )->transition( $sentinel_batch, 'queued', 'complete' );
	$legacy = [];
	foreach ( [ Schema::BATCHES, Schema::ITEMS, Schema::CHANGES ] as $name ) {
		$legacy[ $name ] = $wpdb->get_row( $wpdb->prepare( 'CHECKSUM TABLE %i', Registry::plugin()->table( $name ) ), ARRAY_A )['Checksum'];
	}
	update_option( Migrator::VERSION_OPTION, '6', false );
	$check( ( new Migrator( Registry::plugin() ) )->run(), 'Schema 6 to 7 migration succeeds.' );
	foreach ( $legacy as $name => $checksum ) {
		$after = $wpdb->get_row( $wpdb->prepare( 'CHECKSUM TABLE %i', Registry::plugin()->table( $name ) ), ARRAY_A )['Checksum'];
		$check( $checksum === $after, 'Upgrade preserves existing ' . $name . ' rows.' );
	}
	$check( $epoch === FoundationStorage::epoch() && ( new Migrator( Registry::plugin() ) )->run(), 'Repeat migration preserves installation epoch.' );
	$check( false === ( new Cleanup( Registry::plugin() ) )->uninstall( new Settings( [ 'remove_data_on_delete' => false ] ) ) && $epoch === FoundationStorage::epoch() && 200 === $call( 'requests/resolve', $grant )->get_status(), 'Retain-data uninstall preserves identity, receipt and exact set.' );
	$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, FoundationStorage::EPOCH_OPTION ) );
	$check( in_array( $autoload, [ 'no', 'off', 'auto-off' ], true ), 'Installation identity is not autoloaded.' );
	delete_option( FoundationStorage::EPOCH_OPTION );
	$check( FoundationStorage::initialize() && $epoch !== FoundationStorage::epoch(), 'Reinitializing a removed epoch creates a different installation identity.' );
	try {
		$check( 403 === $call( 'requests/resolve', $grant )->get_status(), 'A different installation epoch rejects an old valid grant.' ); } finally {
		update_option( FoundationStorage::EPOCH_OPTION, $epoch, false ); }
		$table = Registry::plugin()->table( Schema::SELECTIONS );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
		try {
			$check( 503 === $call( 'requests', $intent )->get_status(), 'Nontransactional storage refuses grants.' ); } finally {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) ); }
} finally {
	if ( $sentinel_batch > 0 ) {
		foreach ( [ Schema::CHANGES, Schema::ITEMS, Schema::BATCHES ] as $name ) {
			$wpdb->delete( Registry::plugin()->table( $name ), [ 'batch_id' => $sentinel_batch ] ); }
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_set_current_user( 1 );
	wp_delete_user( $user );
	WP_CLI::line( wp_json_encode( $checks, JSON_PRETTY_PRINT ) );
}
