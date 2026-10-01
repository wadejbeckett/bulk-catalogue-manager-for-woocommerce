<?php
/**
 * Complete native publication binding at the exact-selection ceiling.
 * Evaluated by publication-scale.py on a separately owned disposable fixture.
 * Products use WooCommerce CRUD; SQL below only observes membership.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Read-only qualification evidence on the explicitly owned fixture.
// phpcs:disable WordPress.PHP.YodaConditions -- Diagnostic expectations put observations first.
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Engine\Progress;

if ( ! bulkcat_probe_fixture() ) {
	throw new RuntimeException( 'Explicit disposable fixture required.' );
}
$a = $bulkcat_scale_args;
if ( 'identity' === $a['phase'] ) {
	$root  = dirname( ( new ReflectionClass( BulkCatalogueManager\Plugin::class ) )->getFileName(), 2 );
	$files = [];
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() ); }
	}
	foreach ( [ 'bulk-catalogue-manager-for-woocommerce.php', 'assets/build/index.js', 'assets/build/index.asset.php' ] as $file ) {
		$files[ $file ] = hash_file( 'sha256', $root . '/' . $file ); }
	ksort( $files );
	bulkcat_out(
		[
			'files'    => $files,
			'versions' => [ PHP_VERSION, get_bloginfo( 'version' ), WC_VERSION ],
		]
	);
	return;
}
global $wpdb;
$own = static function ( int $id ) use ( $a ): void {
	if ( get_post_meta( $id, '_bulkcat_scale_probe', true ) !== $a['run'] ) {
		throw new RuntimeException( 'Scale object ownership mismatch.' );
	}
};
$ids = static function () use ( $a, $wpdb ): array {
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s ORDER BY post_id", '_bulkcat_scale_probe', $a['run'] ) ) );
};
if ( 'user' === $a['phase'] ) {
	$login = 'scale-' . $a['run'];
	$id    = wp_create_user( $login, wp_generate_password( 40 ), $login . '@example.test' );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	( new WP_User( $id ) )->set_role( 'administrator' );
	update_user_meta( $id, '_bulkcat_scale_probe', $a['run'] );
	bulkcat_out(
		[
			'actor'    => $id,
			'versions' => [ PHP_VERSION, get_bloginfo( 'version' ), WC_VERSION ],
		]
	);
	return;
}
if ( get_user_meta( $a['actor'], '_bulkcat_scale_probe', true ) !== $a['run'] ) {
	throw new RuntimeException( 'Scale actor ownership mismatch.' );
}
wp_set_current_user( $a['actor'] );
if ( 'seed' === $a['phase'] ) {
	$made = [];
	$make = static function ( string $key, string $kind, int $parent = 0 ) use ( $a, $own, &$made ): int {
		$sku = $a['run'] . '-' . $key;
		$id  = wc_get_product_id_by_sku( $sku );
		if ( $id ) {
			$own( $id );
			return $id;
		}
		$p = 'variable' === $kind ? new WC_Product_Variable() : ( 'variation' === $kind ? new WC_Product_Variation() : new WC_Product_Simple() );
		$p->set_name( 'Publication scale ' . $sku );
		$p->set_sku( $sku );
		$p->set_status( 'draft' );
		$p->set_date_created( strtotime( '2026-01-15 UTC' ) );
		$p->set_regular_price( '17.25' );
		if ( $parent ) {
			$p->set_parent_id( $parent );
		}
		$p->update_meta_data( '_bulkcat_scale_probe', $a['run'] );
		$id     = $p->save();
		$made[] = $id;
		return $id;
	};
	// Setup only: coalesce native transient cleanup, then flush before parent sync.
	// Product CRUD and the later publication measurements remain unchanged.
	$deferrer = wc_get_container()->get( Automattic\WooCommerce\Internal\Caches\ProductTransientsDeferrer::class );
	$deferrer->start_deferring();
	wp_defer_term_counting( true );
	$retries                 = $wpdb->reconnect_retries;
	$show_errors             = $wpdb->show_errors;
	$wpdb->reconnect_retries = 0;
	try {
		// Bound only fixture creation to one native transaction per chunk.
		// Never use this wrapper around the measured publication operations.
		wc_transaction_query( 'start', true );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Cannot begin synthetic creation chunk.' );
		}
		for ( $i = $a['first']; $i <= $a['last']; ++$i ) {
			$variable = 0 === $i % 2;
			$parent   = $make( 'p-' . $i, $variable ? 'variable' : 'simple' );
			if ( $variable ) {
				for ( $j = 1; $j <= 7; ++$j ) {
					$make( 'v-' . $i . '-' . $j, 'variation', $parent );
				}
			}
			if ( 0 === $i % 25 ) {
				wp_cache_flush_runtime();
			}
		}
		wp_defer_term_counting( false );
		$deferrer->stop_deferring();
		WC_Post_Data::do_deferred_product_sync();
		wc_transaction_query( 'commit', true );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Uncertain synthetic chunk commit; inspect UUID inventory before resuming.' );
		}
	} catch ( Throwable $error ) {
		wc_transaction_query( 'rollback', true );
		throw $error;
	} finally {
		wp_defer_term_counting( false );
		$deferrer->stop_deferring();
		$wpdb->reconnect_retries = $retries;
		$wpdb->show_errors( $show_errors );
	}
	bulkcat_out(
		[
			'made'                     => $made,
			'last'                     => $a['last'],
			'peak_mb'                  => memory_get_peak_usage() / 1048576,
			'setup_transient_deferral' => true,
			'setup_native_transaction' => true,
		]
	);
	return;
}
if ( 'cleanup' === $a['phase'] ) {
	if ( count( $a['ids'] ) > 500 ) {
		throw new RuntimeException( 'Cleanup chunk exceeds its owned bound.' );
	}
	$deferrer = wc_get_container()->get( Automattic\WooCommerce\Internal\Caches\ProductTransientsDeferrer::class );
	$deferrer->start_deferring();
	wp_defer_term_counting( true );
	$retries                 = $wpdb->reconnect_retries;
	$show_errors             = $wpdb->show_errors;
	$wpdb->reconnect_retries = 0;
	try {
		wc_transaction_query( 'start', true );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Cannot begin owned cleanup chunk.' );
		}
		foreach ( $a['ids'] as $id ) {
			if ( ! get_post( $id ) ) {
				continue;
			}
			$own( $id );
			$product = wc_get_product( $id );
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child ) {
					$own( $child );
				}
			}
			if ( ! $product->delete( true ) ) {
				throw new RuntimeException( 'Owned product cleanup failed.' );
			}
		}
		wp_defer_term_counting( false );
		$deferrer->stop_deferring();
		WC_Post_Data::do_deferred_product_sync();
		wc_transaction_query( 'commit', true );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Uncertain cleanup commit; inspect UUID inventory before resuming.' );
		}
	} catch ( Throwable $error ) {
		wc_transaction_query( 'rollback', true );
		throw $error;
	} finally {
		wp_defer_term_counting( false );
		$deferrer->stop_deferring();
		$wpdb->reconnect_retries = $retries;
		$wpdb->show_errors( $show_errors );
	}
	bulkcat_out( [ 'remaining' => count( $ids() ) ] );
	return;
}
if ( 'finish' === $a['phase'] ) {
	if ( $ids() ) {
		throw new RuntimeException( 'Owned scale products remain.' );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $a['actor'] );
	bulkcat_out(
		[
			'remaining'     => 0,
			'actor_removed' => ! get_userdata( $a['actor'] ),
		]
	);
	return;
}
if ( 'inventory' === $a['phase'] ) {
	bulkcat_out( [ 'ids' => $ids() ] );
	return;
}
$report = [
	'checks'               => [],
	'stages'               => [],
	'memory_limit'         => ini_get( 'memory_limit' ),
	'peak_reset_available' => function_exists( 'memory_reset_peak_usage' ),
];
$check  = static function ( string $name, bool $condition ) use ( &$report ): void {
	$report['checks'][ $name ] = $condition;
	if ( ! $condition ) {
		throw new RuntimeException( $name );
	}
};
$stage  = static function ( string $name, callable $fn ) use ( &$report ): mixed {
	gc_collect_cycles();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$t                         = microtime( true );
	$q                         = $GLOBALS['wpdb']->num_queries;
	$start                     = memory_get_usage();
	$value                     = $fn();
	$report['stages'][ $name ] = [
		'seconds'         => microtime( true ) - $t,
		'queries'         => $GLOBALS['wpdb']->num_queries - $q,
		'start_mb'        => $start / 1048576,
		'end_mb'          => memory_get_usage() / 1048576,
		'process_peak_mb' => memory_get_peak_usage() / 1048576,
	];
	if ( is_wp_error( $value ) ) {
		throw new RuntimeException( $name . ': ' . $value->get_error_code() );
	}
	return $value;
};
$batch  = 0;
try {
	$all = $ids();
	$check( 'owned_exactly_100000', count( $all ) === 100000 );
	$service           = new RequestService();
	$query             = [
		'mode'   => 'products_and_variations',
		'search' => $a['run'],
	];
	$capture           = $stage( 'capture', fn() => $service->capture( $service->issue( 'selection.capture', [ 'query' => $query ] ) ) );
	$report['capture'] = $capture;
	$check( 'capture_complete_mixed_membership', $capture['count'] === 100000 && $capture['by_type']['products'] === 22223 && $capture['by_type']['variations'] === 77777 );
	$body              = [
		'token'       => $capture['token'],
		'sample_size' => 25,
		'operations'  => [
			[
				'action' => 'set_status',
				'args'   => [
					'field' => 'status',
					'value' => 'publish',
				],
			],
		],
	];
	$preview           = $stage( 'preview', fn() => $service->preview( $body ) );
	$report['preview'] = $preview;
	$issue             = static fn( $pre ) => $service->issue(
		'batch.apply',
		[
			'token'      => $capture['token'],
			'operations' => $pre['operations'],
			'scope_hash' => $pre['scope_hash'],
		]
	) + [
		'token'        => $capture['token'],
		'preview_hash' => $pre['hash'],
	];
	$submit            = $stage( 'issue', fn() => $issue( $preview ) );
	$far               = end( $all );
	$check( 'far_outside_sample_and_compute_cap', ! in_array( $far, array_column( $preview['sample'], 'object_id' ), true ) && $preview['totals']['computed'] < count( $all ) );
	$own( $far );
	$p = wc_get_product( $far );
	$p->set_date_created( time() + 172800 );
	$p->save();
	$stale           = $service->apply( $submit );
	$report['stale'] = is_wp_error( $stale ) ? [
		'code' => $stale->get_error_code(),
		'data' => $stale->get_error_data(),
	] : $stale;
	$check( 'far_unsampled_date_invalidates_acceptance', is_wp_error( $stale ) && 'bulkcat_preview_stale' === $stale->get_error_code() );
	$pre2    = $stage( 'fresh_preview', fn() => $service->preview( $body ) );
	$submit2 = $stage( 'fresh_issue', fn() => $issue( $pre2 ) );
	$saves   = 0;
	add_action(
		'woocommerce_before_product_object_save',
		static function () use ( &$saves ): void {
			++$saves;
		}
	);
	$accepted           = $stage( 'accept', fn() => $service->apply( $submit2 ) );
	$batch              = (int) $accepted['batch_id'];
	$report['accepted'] = $accepted;
	$members            = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->prefix}bulkcat_batch_items WHERE batch_id=%d ORDER BY object_id", $batch ) ) );
	$check( 'all_exact_members_materialized_no_saves', $members === $all && $saves === 0 );
	$p = wc_get_product( $far );
	$p->set_date_created( strtotime( '2026-01-15 UTC' ) );
	$p->save();
	$saves            = 0;
	$replay           = $stage( 'replay', fn() => $service->apply( $submit2 ) );
	$report['replay'] = $replay;
	$check( 'receipt_first_after_changed_publication_inputs', (int) $replay['batch_id'] === $batch && ! empty( $replay['replayed'] ) && 0 === $saves );
} catch ( Throwable $error ) {
	$report['error'] = $error->getMessage();
} finally {
	if ( $batch ) {
		$report['cancel'] = ( new Progress() )->cancel( $batch );
	}
}
bulkcat_out( $report );
