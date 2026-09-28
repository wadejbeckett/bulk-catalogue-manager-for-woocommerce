<?php
/**
 * Capacity and bounded cleanup on a disposable native database only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
// phpcs:disable WordPress.DB -- Controlled, local-only database failure and capacity fixtures require uncached SQL and temporary schema changes.
// phpcs:disable WordPress.PHP.YodaConditions -- Probe expectations compare measured values directly.
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Requests\RequestRepository;
use BulkCatalogueManager\Selection\SnapshotRepository;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:18925' !== get_option( 'siteurl' ) ) {
	exit( 1 ); }
global $wpdb;
wp_set_current_user( 1 );
$service  = new RequestService();
$reports  = [];
$measure  = static function ( array $query, int $expected ) use ( $service, &$reports, $wpdb ): array {
	$grant    = $service->issue( 'selection.capture', [ 'query' => $query ] );
	$reads    = 0;
	$products = 0;
	$counter  = static function ( string $sql ) use ( &$reads ): string {
		if ( str_contains( $sql, 'p.ID AS id, p.post_type AS object_type' ) ) {
			++$reads;
		} return $sql;
	};
	$hydrate  = static function () use ( &$products ): void {
		++$products;
	};
	add_filter( 'query', $counter );
	add_action( 'woocommerce_product_read', $hydrate );
	memory_reset_peak_usage();
	$memory  = memory_get_usage( true );
	$started = microtime( true );
	try {
		$result = $service->capture( $grant );
	} finally {
		remove_filter( 'query', $counter );
		remove_action( 'woocommerce_product_read', $hydrate ); }
	$seconds = microtime( true ) - $started;
	if ( is_wp_error( $result ) || $expected !== $result['count'] || 1 !== $reads || 0 !== $products ) {
		throw new RuntimeException( 'Capture count/query/hydration mismatch: ' . wp_json_encode( $result ) ); }
	$id        = explode( '.', $result['token'] )[1];
	$size      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT LENGTH(members) FROM %i WHERE id = %s', Registry::plugin()->table( Schema::SELECTIONS ), $id ) );
	$reports[] = [
		'count'              => $result['count'],
		'types'              => $result['by_type'],
		'seconds'            => round( $seconds, 3 ),
		'peak_extra_bytes'   => memory_get_peak_usage( true ) - $memory,
		'membership_bytes'   => $size,
		'response_bytes'     => strlen( wp_json_encode( $result ) ),
		'membership_queries' => $reads,
		'product_hydrations' => $products,
	];
	return $grant;
};
$expected = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','future','draft','pending','private')" );
$measure( [ 'mode' => 'products_and_variations' ], $expected );
$marker  = 'CapacityProbe' . bin2hex( random_bytes( 5 ) );
$created = 0;
try {
	// The same native post shape used by the scale fixture, inserted in bounded chunks.
	for ( $start = 0; $start < 100000; $start += 1000 ) {
		$values = [];
		for ( $i = $start; $i < $start + 1000; ++$i ) {
			$values[] = $wpdb->prepare( "(%s,'product','publish','','','','','',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", $marker . $i );
		}
		$sql = "INSERT INTO {$wpdb->posts} (post_title,post_type,post_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered,post_date,post_date_gmt,post_modified,post_modified_gmt) VALUES " . implode( ',', $values );
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'Capacity fixture insert failed.' ); }
		$created += 1000;
	}
	$measure( [ 'search' => $marker ], 100000 );
	$extra    = wp_insert_post(
		[
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => $marker . 'extra',
		]
	);
	$grant    = $service->issue( 'selection.capture', [ 'query' => [ 'search' => $marker ] ] );
	$overflow = $service->capture( $grant );
	if ( ! is_wp_error( $overflow ) || 'bulkcat_selection_too_large' !== $overflow->get_error_code() || [ 'status' => 'not_accepted' ] !== $service->resolve( $grant ) ) {
		throw new RuntimeException( 'Over-cap capture was not refused atomically.' ); }
	$reports[] = [
		'count'                   => 100001,
		'refused_without_receipt' => true,
	];
	wp_delete_post( $extra, true );
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_type = %s AND post_title LIKE %s', $wpdb->posts, 'product', $wpdb->esc_like( $marker ) . '%' ) );
}
// Keep ordinary fixture records and newer captures outside this cleanup clock.
$old = time() - 30 * DAY_IN_SECONDS;
FoundationStorage::atomic(
	static function () use ( $old ): void {
		for ( $i = 0; $i < 101; ++$i ) {
			( new SnapshotRepository() )->create(
				[
					'products'   => [],
					'variations' => [],
				],
				1,
				$old
			); }
	}
);
$cleanup = new RequestService( static fn(): int => $old + 8 * DAY_IN_SECONDS );
$first   = $cleanup->prune_selections();
$second  = $cleanup->prune_selections();
if ( 100 !== $first || 1 !== $second ) {
	throw new RuntimeException( 'Cleanup was not bounded to 100 payloads.' ); }
$reports[] = [
	'cleanup_first'  => $first,
	'cleanup_second' => $second,
];
WP_CLI::line( wp_json_encode( $reports, JSON_PRETTY_PRINT ) );
