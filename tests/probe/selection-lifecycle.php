<?php
/**
 * T82 behavior on the owned disposable fixture only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled native retention/concurrency fixture.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
use BulkCatalogueManager\Requests\RequestService;
require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	exit( 1 ); }
global $wpdb;
$checks = [];
$check  = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	} $checks[] = $label;
};
$check( method_exists( RequestService::class, 'handle' ), 'Selection lifecycle service exists.' );
wp_set_current_user( 1 );
$now     = time();
$service = new RequestService(
	static function () use ( &$now ): int {
		return $now;
	}
);
$ids     = [];
try {
	foreach ( [ 'A', 'B', 'C' ] as $letter ) {
		$ids[] = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Lifecycle ' . $letter,
			]
		); }
	$base     = $service->capture( $service->issue( 'selection.capture', [ 'ids' => array_slice( $ids, 0, 2 ) ] ) );
	$raw      = [
		'token'   => $base['token'],
		'add_ids' => [ $ids[1], $ids[2] ],
	];
	$grant    = $service->issue( 'selection.compose', $raw );
	$composed = $service->compose( $grant + [ 'token' => $base['token'] ] );
	$check( is_array( $composed ) && 3 === $composed['count'], 'Union deduplicates B and preserves A/B/C.' );
	$page = $service->handle(
		'inspect',
		[
			'token'     => $composed['token'],
			'page'      => 2,
			'per_page'  => 1,
			'probe_ids' => [ $ids[0], $ids[2], 99999999 ],
		]
	);
	$check( is_array( $page ) && 3 === $page['count'] && $page['items'][0]['id'] === $ids[1] && [ $ids[0], $ids[2] ] === $page['selected_ids'], 'Inspection is paged and visible membership remains exact.' );
	wp_delete_post( $ids[0], true );
	$page = $service->handle( 'inspect', [ 'token' => $composed['token'] ] );
	$check( 3 === $page['count'] && 'unavailable' === $page['items'][0]['state'] && ! isset( $page['items'][0]['name'] ), 'Deleted member retains a removable slot without protected metadata.' );
	$remove  = $service->issue(
		'selection.compose',
		[
			'token'      => $composed['token'],
			'remove_ids' => [ $ids[0] ],
		]
	);
	$removed = $service->compose( $remove + [ 'token' => $composed['token'] ] );
	$check( is_array( $removed ) && 2 === $removed['count'], 'Unavailable off-page identity can be removed.' );
	$now    += 30 * MINUTE_IN_SECONDS;
	$renewed = $service->handle( 'renew', [ 'token' => $base['token'] ] );
	$check( is_array( $renewed ) && 2 === $renewed['count'] && $renewed['requires_preview'] && 2 === $renewed['authorization_epoch'], 'Thirty-minute suspension reauthorizes the same A/B including missing A, never C.' );
	$again = $service->handle( 'renew', [ 'token' => $base['token'] ] );
	$check( $again['token'] === $renewed['token'] && $again['requires_preview'], 'Old-epoch retry converges and still requires preview.' );
	$stale = $service->handle( 'inspect', [ 'token' => $base['token'] ] );
	$check( is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'], 'Old authorization cannot inspect or authorize new work.' );
	$check( $composed['token'] === $service->compose( $grant + [ 'token' => $base['token'] ] )['token'], 'Composition replay resolves before expired base authorization.' );
	$live = $service->handle( 'renew', [ 'token' => $renewed['token'] ] );
	$check( $live['token'] === $renewed['token'] && ! $live['requires_preview'], 'Active keepalive preserves authorization and preview epoch.' );
	wp_set_current_user( 0 );
	$denied = $service->handle( 'renew', [ 'token' => $live['token'] ] );
	$check( is_wp_error( $denied ) && 401 === $denied->get_error_data()['status'], 'Lost login refuses without erasing membership.' );
	wp_set_current_user( 1 );
	$now      += 12 * HOUR_IN_SECONDS;
	$overnight = $service->handle( 'renew', [ 'token' => $live['token'] ] );
	$check( 2 === $overnight['count'] && 3 === $overnight['authorization_epoch'] && $overnight['requires_preview'], 'Overnight suspension recovers exact membership after login restoration.' );
	$released = $service->handle( 'release', [ 'tokens' => [ $overnight['token'] ] ] );
	$now     += 60;
	$repeat   = $service->handle( 'release', [ 'tokens' => [ $overnight['token'] ] ] );
	$check( $released === $repeat, 'Repeated release never extends its first deadline.' );
	$kept = $service->handle( 'renew', [ 'token' => $overnight['token'] ] );
	$now += 2 * HOUR_IN_SECONDS;
	$service->prune_selections();
	$check( is_array( $service->handle( 'renew', [ 'token' => $kept['token'] ] ) ), 'Keepalive before the release deadline prevents cleanup.' );
	$now    += 8 * DAY_IN_SECONDS;
	$expired = $service->handle( 'renew', [ 'token' => $kept['token'] ] );
	$check( is_wp_error( $expired ) && 410 === $expired->get_error_data()['status'], 'Retention expiry refuses even before physical cleanup.' );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true ); }
	WP_CLI::line( wp_json_encode( $checks, JSON_PRETTY_PRINT ) );
}
