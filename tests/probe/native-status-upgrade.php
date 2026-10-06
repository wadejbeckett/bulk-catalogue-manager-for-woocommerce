<?php
/**
 * Native archive upgrade observations; evaluated by the guarded Python driver.
 * No helper is installed in the site. Products belong to one recorded UUID.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Read-only evidence from the owned synthetic fixture.
// phpcs:disable WordPress.PHP.YodaConditions -- Observed values precede expectations in diagnostic checks.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Requests\RequestService;

$a = $bulkcat_upgrade_args;
if ( ! bulkcat_probe_fixture() ) {
	throw new RuntimeException( 'Explicit local fixture required.' );
}
global $wpdb;
$actor = (int) ( $a['actor'] ?? 0 );
if ( $actor ) {
	if ( get_user_meta( $actor, '_bulkcat_native_probe', true ) !== $a['run'] ) {
		throw new RuntimeException( 'Owned actor required.' );
	}
	wp_set_current_user( $actor );
}
$service = new RequestService();
$ok      = static function ( mixed $value ): mixed {
	if ( is_wp_error( $value ) ) {
		throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() );
	}
	return $value;
};
$request = static function ( string $method, string $route, array $data = [] ): array {
	$r = new WP_REST_Request( $method, '/bulkcat/v1/' . $route );
	foreach ( $data as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$reply = rest_do_request( $r );
	return [
		'status' => $reply->get_status(),
		'data'   => $reply->get_data(),
	];
};
$counts  = [];
$ids     = array_map( 'intval', $a['ids'] ?? [] );
foreach ( $ids as $id ) {
	bulkcat_native_owned( $id, $a['run'] );
	$counts[ $id ] = [
		'crud' => 0,
		'post' => 0,
	];
}
add_action(
	'woocommerce_before_product_object_save',
	static function ( $p ) use ( &$counts ): void {
		$id = $p->get_id();
		if ( isset( $counts[ $id ] ) ) {
			++$counts[ $id ]['crud'];
		}
	}
);
add_action(
	'pre_post_update',
	static function ( $id ) use ( &$counts ): void {
		if ( isset( $counts[ $id ] ) ) {
			++$counts[ $id ]['post'];
		}
	}
);
$snapshot = static function ( int $batch ) use ( $wpdb, $ids, $a, $actor ): array {
	$header = ( new BatchRepository() )->find( $batch );
	return [
		'header'  => $header,
		'items'   => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id=%d ORDER BY item_id', $wpdb->prefix . 'bulkcat_batch_items', $batch ), ARRAY_A ),
		'changes' => ( new ChangeRepository() )->for_objects( $batch, $ids ),
		'objects' => array_map( static fn( $id ) => bulkcat_native_read( $id, $a['run'], $actor ), $ids ),
		'receipt' => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE result_kind=%s AND result_id=%s', $wpdb->prefix . 'bulkcat_requests', 'batch', (string) $batch ), ARRAY_A ),
	];
};
switch ( $a['phase'] ) {
	case 'identity':
		$root  = dirname( ( new ReflectionClass( BulkCatalogueManager\Plugin::class ) )->getFileName(), 2 );
		$files = [];
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$files[ substr( $f->getPathname(), strlen( $root ) + 1 ) ] = hash_file( 'sha256', $f->getPathname() );
			}
		}
		ksort( $files );
		bulkcat_out(
			[
				'files'    => $files,
				'baseline' => bulkcat_native_baseline(),
				'wp'       => get_bloginfo( 'version' ),
				'wc'       => WC_VERSION,
				'php'      => PHP_VERSION,
				'plugins'  => get_option( 'active_plugins' ),
				'mu'       => array_keys( get_mu_plugins() ),
				'native'   => class_exists( BulkCatalogueManager\Publishing\StatusTransition::class ),
			]
		);
		break;
	case 'create':
		$p = new WC_Product_Simple();
		$p->set_name( 'Native upgrade ' . $a['run'] . ' ' . $a['name'] );
		$p->set_status( $a['status'] );
		$p->set_regular_price( '17.25' );
		$p->set_weight( '1.25' );
		$p->set_date_created( strtotime( '2026-01-15 12:00:00 UTC' ) );
		$p->update_meta_data( '_bulkcat_native_probe', $a['run'] );
		$id = $p->save();
		bulkcat_out(
			[
				'id'     => $id,
				'before' => bulkcat_native_read( $id, $a['run'], $actor ),
			]
		);
		break;
	case 'grant':
		$pre   = $ok(
			$service->preview(
				[
					'ids'        => $ids,
					'operations' => [
						[
							'action' => 'set_value',
							'args'   => [
								'field'               => 'status',
								'value'               => $a['target'],
								'apply_to_variations' => false,
							],
						],
					],
				]
			)
		);
		$grant = $ok(
			$service->issue(
				'batch.apply',
				[
					'ids'        => $ids,
					'operations' => $pre['operations'],
					'scope_hash' => $pre['scope_hash'],
				]
			)
		);
		bulkcat_out(
			[
				'preview' => $pre,
				'request' => $grant + [ 'preview_hash' => $pre['hash'] ],
				'counts'  => $counts,
			]
		);
		break;
	case 'accept':
		$result = $service->apply( $a['request'] );
		bulkcat_out(
			is_wp_error( $result ) ? [
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'counts'  => $counts,
			] : [
				'result'   => $result,
				'snapshot' => $snapshot( $result['batch_id'] ),
				'counts'   => $counts,
			]
		);
		break;
	case 'worker':
	case 'crash':
		$batch = (int) $a['batch'];
		$owned = $snapshot( $batch );
		if ( (int) $owned['header']['user_id'] !== $actor || array_diff( array_column( $owned['items'], 'object_id' ), $ids ) ) {
			throw new RuntimeException( 'Batch ownership mismatch.' );
		}
		if ( 'crash' === $a['phase'] ) {
			add_action(
				'woocommerce_after_product_object_save',
				static function ( $p ) use ( $ids, &$counts ): void {
					if ( in_array( $p->get_id(), $ids, true ) ) {
						bulkcat_out(
							[
								'interrupted_after_save' => $p->get_id(),
								'counts'                 => $counts,
							]
						);
						exit( 0 ); // Process death before Writer settlement/ChunkRunner catch, not a fabricated journal row.
					}
				},
				999
			);
		}
		wp_set_current_user( 0 );
		// Advancing only the runner's injected lease clock avoids editing persisted claims.
		$clock  = (float) ( time() + (int) ( $a['lease_advance'] ?? 0 ) );
		$result = ( new ChunkRunner( clock: static fn(): float => $clock ) )->run( $batch );
		bulkcat_out(
			[
				'result'   => $result,
				'snapshot' => $snapshot( $batch ),
				'counts'   => $counts,
			]
		);
		break;
	case 'snapshot':
		bulkcat_out( $snapshot( (int) $a['batch'] ) );
		break;
	case 'route':
		bulkcat_out( $request( $a['method'], $a['route'], $a['data'] ?? [] ) + [ 'counts' => $counts ] );
		break;
	case 'undo':
		$batch  = (int) $a['batch'];
		$plan   = $ok( ( new BulkCatalogueManager\Undo\Planner() )->plan( $batch, $actor ) );
		$grant  = $ok(
			$service->issue(
				'batch.undo',
				[
					'batch_id'  => $batch,
					'plan_hash' => $plan->hash(),
					'force'     => false,
				]
			)
		);
		$result = $ok( $service->undo( $grant ) );
		bulkcat_out(
			[
				'result'  => $result,
				'plan'    => $plan->to_array(),
				'request' => $grant,
			]
		);
		break;
	case 'revoke':
		( new WP_User( $actor ) )->add_cap( 'edit_products', false );
		bulkcat_out(
			array_map(
				static fn( $id ) => [
					'id'      => $id,
					'mapped'  => map_meta_cap( 'edit_post', $actor, $id ),
					'allowed' => user_can( $actor, 'edit_post', $id ),
				],
				$ids
			)
		);
		break;
	case 'restore-edit':
		( new WP_User( $actor ) )->add_cap( 'edit_products', true );
		bulkcat_out( true );
		break;
	default:
		throw new RuntimeException( 'Unknown upgrade phase.' );
}
