<?php
/**
 * Native REST and per-object visibility verification on the owned fixture.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled native fixtures only.
// phpcs:disable WordPress.PHP.YodaConditions -- Direct native assertions.
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\SnapshotRepository;
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
$call   = static function ( string $route, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/bulkcat/v1/' . $route );
	$request->set_query_params( [ 'rest_route' => '/bulkcat/v1/' . $route ] );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $data ) );
	return rest_do_request( $request );
};
wp_set_current_user( 1 );
$service = new RequestService();
$ids     = [];
$user    = wp_insert_user(
	[
		'user_login' => 'lifecycle-' . bin2hex( random_bytes( 6 ) ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	]
);
try {
	$ids[] = wp_insert_post(
		[
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Protected parent label',
		]
	);
	$ids[] = wp_insert_post(
		[
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $ids[0],
			'post_title'  => 'Protected option label',
		]
	);
	$base  = $service->capture( $service->issue( 'selection.capture', [ 'ids' => $ids ] ) );

	$product_reads = 0;
	$count_product = static function () use ( &$product_reads ): void {
		++$product_reads;
	};
	add_action( 'woocommerce_product_read', $count_product );
	try {
		$visible = $call( 'selection/inspect', [ 'token' => $base['token'] ] )->get_data(); } finally {
		remove_action( 'woocommerce_product_read', $count_product ); }
		$check( 0 === $product_reads && 'Protected parent label' === $visible['items'][0]['name'] && 'Protected parent label' === $visible['items'][1]['parent_name'], 'Readable product/variation metadata uses lean hydration without WC_Product reads.' );
		$grant = $service->issue(
			'selection.compose',
			[
				'token'      => $base['token'],
				'remove_ids' => [ $ids[0] ],
			]
		);
	unset( $grant['operation'], $grant['accept_until'] );
	$requests = [
		'inspect' => [ 'token' => $base['token'] ],
		'renew'   => [ 'token' => $base['token'] ],
		'release' => [ 'tokens' => [ $base['token'] ] ],
		'compose' => $grant + [ 'token' => $base['token'] ],
	];
	foreach ( [
		0     => 401,
		$user => 403,
	] as $who => $status ) {
		wp_set_current_user( $who );
		foreach ( $requests as $op => $data ) {
			$check( $status === $call( 'selection/' . $op, $data )->get_status(), $op . ' refuses unauthorised user ' . $who ); }
	}
	( new WP_User( $user ) )->set_role( 'shop_manager' );
	wp_set_current_user( 0 );
	wp_set_current_user( $user );
	foreach ( $requests as $op => $data ) {
		$check( 403 === $call( 'selection/' . $op, $data )->get_status(), $op . ' refuses another owner.' ); }
	$own = $service->capture( $service->issue( 'selection.capture', [ 'ids' => [] ] ) );
	$check( is_array( $own ) && 200 === $call( 'selection/inspect', [ 'token' => $own['token'] ] )->get_status(), 'Shop manager can inspect own selection.' );
	( new WP_User( $user ) )->set_role( 'subscriber' );
	wp_set_current_user( 0 );
	wp_set_current_user( $user );
	$check( 403 === $call( 'selection/renew', [ 'token' => $own['token'] ] )->get_status(), 'Revoked global capability refuses renewal.' );
	( new WP_User( $user ) )->set_role( 'shop_manager' );
	wp_set_current_user( 0 );
	wp_set_current_user( $user );
	$check( 200 === $call( 'selection/renew', [ 'token' => $own['token'] ] )->get_status(), 'Restored capability recovers retained work.' );
	wp_set_current_user( 1 );
	$deny = static function ( array $caps, string $cap, int $who, array $args ) use ( $ids ): array {
		return 'read_post' === $cap && (int) ( $args[0] ?? 0 ) === $ids[0] ? [ 'do_not_allow' ] : $caps;
	};
	add_filter( 'map_meta_cap', $deny, 10, 4 );
	try {
		$data = $call( 'selection/inspect', [ 'token' => $base['token'] ] )->get_data();
		$check( 2 === $data['count'] && [ 'unavailable', 'unavailable' ] === array_column( $data['items'], 'state' ) && ! str_contains( wp_json_encode( $data ), 'Protected' ) && ! str_contains( wp_json_encode( $data ), 'parent_name' ), 'Revoked parent read permission hides both parent and option metadata without shrinking count.' );
	} finally {
		remove_filter( 'map_meta_cap', $deny, 10 ); }
	$composed = $call( 'selection/compose', $requests['compose'] );
	$check( 200 === $composed->get_status() && 1 === $composed->get_data()['count'], 'Native composition route subtracts a product, preserving its independently selected variation.' );
	$resolved = $call( 'requests/resolve', $grant + [ 'operation' => 'selection.compose' ] );
	$check( 200 === $resolved->get_status() && $resolved->get_data()['token'] === $composed->get_data()['token'], 'Compose resolver works without selection authorization credentials.' );
	$changed                         = $grant;
	$changed['intent']['remove_ids'] = [];
	$check( 409 === $call( 'selection/compose', $changed + [ 'token' => $base['token'] ] )->get_status(), 'Changed semantic input under an accepted grant conflicts.' );
	foreach ( [ [ 'page' => 0 ], [ 'per_page' => 101 ], [ 'probe_ids' => range( 1, 101 ) ], [ 'bogus' => true ] ] as $bad ) {
		$check( 400 === $call( 'selection/inspect', [ 'token' => $base['token'] ] + $bad )->get_status(), 'Inspection rejects invalid input ' . implode( ',', array_keys( $bad ) ) );
	}
	$check( 400 === $call( 'selection/release', [ 'tokens' => array_fill( 0, 5, $base['token'] ) ] )->get_status(), 'Release is bounded to four handles.' );
	$invalid = $call( 'selection/renew', [ 'token' => $base['token'] . 'x' ] );
	$check( 403 === $invalid->get_status() && 'bulkcat_invalid_selection' === $invalid->get_data()['code'] && ! str_contains( $invalid->get_data()['message'], 'bulkcat_' ), 'Tampered token refuses with the uniform selection code and plain message.' );
	$check( 400 === $call( 'selection/compose', $requests['compose'] + [ 'remove_ids' => [] ] )->get_status(), 'Compose rejects top-level mutations beside canonical intent.' );
	$cap       = ( new SnapshotRepository() )->create(
		[
			'products'   => range( 1000000, 1099999 ),
			'variations' => [],
		],
		1,
		time()
	);
	$cap_token = \BulkCatalogueManager\Selection\SnapshotToken::issue( $cap );
	$union     = $service->issue(
		'selection.compose',
		[
			'token'     => $cap_token,
			'add_token' => $cap_token,
		]
	);
	$start     = microtime( true );
	$full      = $service->compose(
		$union + [
			'token'     => $cap_token,
			'add_token' => $cap_token,
		]
	);
	$check( is_array( $full ) && 100000 === $full['count'], 'A 100,000-member overlapping union deduplicates before cap.' );
	WP_CLI::line(
		wp_json_encode(
			[
				'capacity_seconds'        => microtime( true ) - $start,
				'capacity_response_bytes' => strlen( wp_json_encode( $full ) ),
			]
		)
	);
	$post_reads  = 0;
	$count_reads = static function ( string $sql ) use ( &$post_reads ): string {
		if ( str_starts_with( $sql, 'SELECT' ) && preg_match( '/FROM\\s+`?wp_posts`?\\s/i', $sql ) ) {
			++$post_reads;
		} return $sql;
	};
	add_filter( 'query', $count_reads );
	try {
		$page = $service->handle(
			'inspect',
			[
				'token'    => $full['token'],
				'page'     => 1000,
				'per_page' => 100,
			]
		); } finally {
		remove_filter( 'query', $count_reads ); }
		$check( $post_reads <= 2, 'Unavailable inspection page uses bounded bulk reads, not one query per missing identity.' );
		$check( count( $page['items'] ) === 100 && 1099900 === $page['items'][0]['id'] && 100000 === $page['count'], 'Last page of unavailable 100k set preserves slots and bounded response.' );
		$overflow = $service->issue(
			'selection.compose',
			[
				'token'   => $cap_token,
				'add_ids' => [ $ids[0] ],
			]
		);
	$too_big      = $service->compose( $overflow + [ 'token' => $cap_token ] );
	$check( is_wp_error( $too_big ) && 'bulkcat_selection_too_large' === $too_big->get_error_code() && null === ( new \BulkCatalogueManager\Requests\RequestRepository() )->read( 1, $overflow['uuid'] ), 'Overflow rolls back the receipt and leaves original intact.' );
	$subtract = $service->issue(
		'selection.compose',
		[
			'token'        => $cap_token,
			'remove_token' => $cap_token,
		]
	);
	$empty    = $service->compose(
		$subtract + [
			'token'        => $cap_token,
			'remove_token' => $cap_token,
		]
	);
	$check( is_array( $empty ) && 0 === $empty['count'], 'Subtracting the exact set creates a valid empty successor.' );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user );
	WP_CLI::line( wp_json_encode( $checks, JSON_PRETTY_PRINT ) );
}
