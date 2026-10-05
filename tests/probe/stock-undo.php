<?php
/**
 * Apply then Undo on real WooCommerce, compared byte for byte (hotfix 1.3.1):
 * Manage stock off and on (stock quantity, backorders, low stock amount and
 * stock status, which WooCommerce changes on save), a bulk Stock status on
 * managed stock, and a regular price change that clears the sale price.
 *
 * Run on a disposable fixture only (fixture.php), from the repository root:
 *
 *     BULKCAT_PROBE_SITEURL=<site url> wp eval 'require WP_PLUGIN_DIR .
 *         "/bulk-catalogue-manager-for-woocommerce/tests/probe/stock-undo.php";'
 *
 * It creates its own products (SKU prefix `bulkcat-su-`), drives preview,
 * Apply and Undo through the plugin's REST routes as an administrator, runs
 * each batch's chunks in this process, and prints one PASS or FAIL line per
 * check, then deletes its products.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

if ( ! bulkcat_probe_fixture() ) {
	throw new RuntimeException( 'The stock undo probe runs only on a disposable fixture.' );
}

$bulkcat_su_admin = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
wp_set_current_user( (int) $bulkcat_su_admin[0] );
$GLOBALS['bulkcat_su_failed'] = 0;

/**
 * One REST call as the current user.
 *
 * @param string $method Method.
 * @param string $route  Route below /bulkcat/v1.
 * @param array  $body   JSON body.
 */
function bulkcat_su_rest( string $method, string $route, array $body = [] ): array {
	$request = new WP_REST_Request( $method, '/bulkcat/v1' . $route );
	if ( [] !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}
	$response = rest_do_request( $request );
	return [ $response->get_status(), (array) rest_get_server()->response_to_data( $response, false ) ];
}

/**
 * Run a batch's chunks until it leaves queued or running.
 *
 * @param int $batch_id Batch.
 */
function bulkcat_su_drain( int $batch_id ): array {
	for ( $i = 0; $i < 30; ++$i ) {
		\BulkCatalogueManager\Engine\ChunkRunner::handle( $batch_id );
		[ , $report ] = bulkcat_su_rest( 'GET', '/batches/' . $batch_id );
		if ( ! in_array( $report['status'] ?? '', [ 'queued', 'running' ], true ) ) {
			return $report;
		}
	}
	return $report;
}

/**
 * Preview, then Apply under a grant, then run.
 *
 * @param list<int> $ids        Objects.
 * @param array     $operations Steps.
 * @return array{preview: array, batch: int, report: array}
 */
function bulkcat_su_apply( array $ids, array $operations ): array {
	[ $status, $preview ] = bulkcat_su_rest(
		'POST',
		'/preview',
		[
			'ids'         => $ids,
			'operations'  => $operations,
			'sample_size' => 'all',
		]
	);
	if ( 200 !== $status ) {
		throw new RuntimeException( 'preview ' . $status . ' ' . wp_json_encode( $preview ) );
	}
	[ , $grant ]       = bulkcat_su_rest(
		'POST',
		'/requests',
		[
			'operation' => 'batch.apply',
			'intent'    => [
				'ids'        => $ids,
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			],
		]
	);
	[ $status, $made ] = bulkcat_su_rest(
		'POST',
		'/batches',
		[
			'uuid'          => $grant['uuid'],
			'request_proof' => $grant['request_proof'],
			'intent'        => $grant['intent'],
			'preview_hash'  => $preview['hash'],
		]
	);
	if ( 201 !== $status ) {
		throw new RuntimeException( 'apply ' . $status . ' ' . wp_json_encode( $made ) );
	}
	return [
		'preview' => $preview,
		'batch'   => (int) $made['batch_id'],
		'report'  => bulkcat_su_drain( (int) $made['batch_id'] ),
	];
}

/**
 * Preview the undo of a batch, apply it under a grant and run it.
 *
 * @param int $batch_id The batch undone.
 * @return array{preview: array, batch: int, report: array, original: string}
 */
function bulkcat_su_undo( int $batch_id ): array {
	$route             = '/batches/' . $batch_id . '/undo';
	[ , $preview ]     = bulkcat_su_rest( 'POST', $route, [ 'preview' => true ] );
	[ , $grant ]       = bulkcat_su_rest(
		'POST',
		'/requests',
		[
			'operation' => 'batch.undo',
			'intent'    => [
				'batch_id'  => $batch_id,
				'plan_hash' => $preview['plan_hash'] ?? null,
				'force'     => false,
			],
		]
	);
	[ $status, $made ] = bulkcat_su_rest(
		'POST',
		$route,
		[
			'uuid'          => $grant['uuid'],
			'request_proof' => $grant['request_proof'],
			'intent'        => $grant['intent'],
		]
	);
	if ( 201 !== $status ) {
		throw new RuntimeException( 'undo ' . $status . ' ' . wp_json_encode( $made ) );
	}
	$report         = bulkcat_su_drain( (int) $made['batch_id'] );
	[ , $original ] = bulkcat_su_rest( 'GET', '/batches/' . $batch_id );
	return [
		'preview'  => $preview,
		'batch'    => (int) $made['batch_id'],
		'report'   => $report,
		'original' => (string) ( $original['status'] ?? '' ),
	];
}

/**
 * The stored stock and price state of objects, raw from the database: each
 * meta row (absent, NULL or its exact string) and the lookup table row.
 *
 * @param list<int> $ids Objects.
 */
function bulkcat_su_raw( array $ids ): array {
	global $wpdb;
	$keys = [ '_manage_stock', '_stock', '_backorders', '_low_stock_amount', '_stock_status', '_regular_price', '_sale_price', '_price' ];
	$out  = [];
	foreach ( $ids as $id ) {
		$row = [];
		foreach ( $keys as $key ) {
			$values      = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw readback of a disposable fixture, never a runtime query.
			$row[ $key ] = [] === $values ? '(absent)' : array_map( static fn( $v ) => null === $v ? '(NULL)' : $v, $values );
		}
		$row['lookup'] = $wpdb->get_row( $wpdb->prepare( "SELECT stock_quantity, stock_status, min_price, max_price, onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw readback of a disposable fixture, never a runtime query.
		$out[ $id ]    = $row;
	}
	return $out;
}

/**
 * Report one check.
 *
 * @param string $name   Check.
 * @param bool   $ok     Passed.
 * @param mixed  $detail What to show on failure.
 */
function bulkcat_su_check( string $name, bool $ok, $detail = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['bulkcat_su_failed'];
	}
	WP_CLI::line( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : ' ' . wp_json_encode( $detail ) ) );
}

/**
 * The differences between two raw snapshots.
 *
 * @param array $before Before.
 * @param array $after  After.
 */
function bulkcat_su_diff( array $before, array $after ): array {
	$diff = [];
	foreach ( $before as $id => $row ) {
		foreach ( $row as $key => $value ) {
			if ( ( $after[ $id ][ $key ] ?? null ) !== $value ) {
				$diff[ $id ][ $key ] = [ $value, $after[ $id ][ $key ] ?? null ];
			}
		}
	}
	return $diff;
}

/**
 * A simple product.
 *
 * @param string $sku   SKU.
 * @param array  $props Setters to call.
 */
function bulkcat_su_simple( string $sku, array $props ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $sku );
	$product->set_sku( 'bulkcat-su-' . $sku );
	$product->set_regular_price( '100.00' );
	foreach ( $props as $setter => $value ) {
		$product->{'set_' . $setter}( $value );
	}
	return $product->save();
}

/**
 * A variable product with one variation per props list; returns [ parent, variation ids ].
 *
 * @param string $sku   SKU.
 * @param array  $kids  Setters to call on each variation.
 */
function bulkcat_su_variable( string $sku, array $kids ): array {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( [ 'S', 'M', 'L' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent = new WC_Product_Variable();
	$parent->set_name( $sku );
	$parent->set_sku( 'bulkcat-su-' . $sku );
	$parent->set_attributes( [ $attribute ] );
	$parent_id = $parent->save();
	$ids       = [];
	foreach ( array_values( $kids ) as $index => $props ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => [ 'S', 'M', 'L' ][ $index ] ] );
		$variation->set_regular_price( '100.00' );
		foreach ( $props as $setter => $value ) {
			$variation->{'set_' . $setter}( $value );
		}
		$ids[] = $variation->save();
	}
	WC_Product_Variable::sync( $parent_id );
	return [ $parent_id, $ids ];
}

/**
 * Apply one change, undo it, and check every stored value is back.
 *
 * @param string    $name       Scenario.
 * @param list<int> $ids        Objects chosen.
 * @param list<int> $watched    Objects compared.
 * @param array     $operations Steps.
 * @param callable  $applied    fn( array $apply, array $raw ): void, checks after Apply.
 */
function bulkcat_su_round_trip( string $name, array $ids, array $watched, array $operations, callable $applied ): void {
	$before = bulkcat_su_raw( $watched );
	$apply  = bulkcat_su_apply( $ids, $operations );
	$after  = bulkcat_su_raw( $watched );
	$applied( $apply, $after, $before );
	$undo     = bulkcat_su_undo( $apply['batch'] );
	$restored = bulkcat_su_raw( $watched );
	$diff     = bulkcat_su_diff( $before, $restored );
	$same     = 0 === count( $diff );
	$undone   = 'complete' === ( $undo['report']['status'] ?? '' ) && 'undone' === $undo['original'];
	bulkcat_su_check( "$name: undo restores every stored value byte for byte", $same, $diff );
	bulkcat_su_check( "$name: undo finishes complete and the batch reads undone", $undone, [ $undo['report']['status'] ?? null, $undo['original'], $undo['report']['message'] ?? null ] );
}

$bulkcat_su_made = [];
try {
	// 1. Manage stock: No on managed stock.
	$managed_a                 = bulkcat_su_simple(
		'off-a',
		[
			'manage_stock'     => true,
			'stock_quantity'   => 7,
			'backorders'       => 'notify',
			'low_stock_amount' => 3,
		]
	);
	$managed_b                 = bulkcat_su_simple(
		'off-b',
		[
			'manage_stock'   => true,
			'stock_quantity' => 0,
			'backorders'     => 'yes',
		]
	);
	[ $parent_off, $kids_off ] = bulkcat_su_variable(
		'off-v',
		[
			[
				'manage_stock'     => true,
				'stock_quantity'   => 5,
				'backorders'       => 'notify',
				'low_stock_amount' => 2,
			],
			[
				'manage_stock'   => true,
				'stock_quantity' => 11,
			],
		]
	);
	$bulkcat_su_made           = array_merge( $bulkcat_su_made, [ $managed_a, $managed_b, $parent_off ], $kids_off );
	$off_ids                   = array_merge( [ $managed_a, $managed_b ], $kids_off );
	bulkcat_su_round_trip(
		'Manage stock off',
		$off_ids,
		array_merge( $off_ids, [ $parent_off ] ),
		[
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'manage_stock',
					'value' => false,
				],
			],
		],
		static function ( array $apply, array $raw ) use ( $managed_a ): void {
			$warnings = $apply['preview']['warnings'] ?? [];
			bulkcat_su_check( 'Manage stock off: preview warns that stock quantities are cleared', ( $warnings['stock_cleared'] ?? 0 ) === 4, $warnings );
			bulkcat_su_check( 'Manage stock off: WooCommerce cleared the stock quantity', '(NULL)' === ( $raw[ $managed_a ]['_stock'][0] ?? '' ) || '' === ( $raw[ $managed_a ]['_stock'][0] ?? 'x' ), $raw[ $managed_a ] );
		}
	);

	// 2. Manage stock: Yes on unmanaged stock.
	$plain_in                = bulkcat_su_simple( 'on-in', [ 'stock_status' => 'instock' ] );
	$plain_back              = bulkcat_su_simple( 'on-back', [ 'stock_status' => 'onbackorder' ] );
	[ $parent_on, $kids_on ] = bulkcat_su_variable( 'on-v', [ [ 'stock_status' => 'instock' ], [ 'stock_status' => 'outofstock' ] ] );
	$bulkcat_su_made         = array_merge( $bulkcat_su_made, [ $plain_in, $plain_back, $parent_on ], $kids_on );
	$on_ids                  = array_merge( [ $plain_in, $plain_back ], $kids_on );
	bulkcat_su_round_trip(
		'Manage stock on',
		$on_ids,
		array_merge( $on_ids, [ $parent_on ] ),
		[
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'manage_stock',
					'value' => true,
				],
			],
		],
		static function ( array $apply, array $raw ) use ( $plain_in ): void {
			bulkcat_su_check( 'Manage stock on: WooCommerce marked the in-stock product out of stock', [ 'outofstock' ] === $raw[ $plain_in ]['_stock_status'], $raw[ $plain_in ] );
		}
	);

	// 3. Stock status on managed stock: skipped, never reported changed.
	$counted         = bulkcat_su_simple(
		'status-managed',
		[
			'manage_stock'   => true,
			'stock_quantity' => 12,
		]
	);
	$loose           = bulkcat_su_simple( 'status-loose', [ 'stock_status' => 'instock' ] );
	$bulkcat_su_made = array_merge( $bulkcat_su_made, [ $counted, $loose ] );
	$apply           = bulkcat_su_apply(
		[ $counted, $loose ],
		[
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'stock_status',
					'value' => 'outofstock',
				],
			],
		]
	);
	$raw             = bulkcat_su_raw( [ $counted, $loose ] );
	bulkcat_su_check( 'Stock status on managed stock: preview counts only the unmanaged product as a change', 1 === (int) ( $apply['preview']['totals']['would_change'] ?? -1 ), $apply['preview']['totals'] ?? null );
	bulkcat_su_check( 'Stock status on managed stock: one product changed, stored as reported', 1 === (int) ( $apply['report']['changed_items'] ?? -1 ) && [ 'instock' ] === $raw[ $counted ]['_stock_status'] && [ 'outofstock' ] === $raw[ $loose ]['_stock_status'], [ $apply['report']['changed_items'] ?? null, $raw ] );

	// 4. Regular price below the sale price, clearing the sale price; and copy sale to regular.
	$on_sale                       = bulkcat_su_simple( 'price-set', [ 'sale_price' => '80.00' ] );
	[ $parent_price, $kids_price ] = bulkcat_su_variable( 'price-v', [ [ 'sale_price' => '80.00' ] ] );
	$copied                        = bulkcat_su_simple( 'price-copy', [ 'sale_price' => '80.00' ] );
	$bulkcat_su_made               = array_merge( $bulkcat_su_made, [ $on_sale, $parent_price, $copied ], $kids_price );
	bulkcat_su_round_trip(
		'Set regular price clearing the sale price',
		array_merge( [ $on_sale ], $kids_price ),
		array_merge( [ $on_sale, $parent_price ], $kids_price ),
		[
			[
				'action' => 'set_value',
				'args'   => [
					'field'              => 'regular_price',
					'value'              => '60',
					'clear_invalid_sale' => true,
				],
			],
		],
		static function ( array $apply, array $raw ) use ( $on_sale ): void {
			unset( $apply );
			bulkcat_su_check( 'Set regular price clearing the sale price: sale price cleared', in_array( $raw[ $on_sale ]['_sale_price'], [ [ '' ], '(absent)' ], true ) && [ '60.00' ] === $raw[ $on_sale ]['_regular_price'], $raw[ $on_sale ] );
		}
	);
	bulkcat_su_round_trip(
		'Copy sale price to regular clearing the sale price',
		[ $copied ],
		[ $copied ],
		[
			[
				'action' => 'copy_price',
				'args'   => [
					'field'              => 'regular_price',
					'clear_invalid_sale' => true,
				],
			],
		],
		static function ( array $apply, array $raw ) use ( $copied ): void {
			unset( $apply );
			bulkcat_su_check( 'Copy sale price to regular: regular 80, sale cleared', [ '80.00' ] === $raw[ $copied ]['_regular_price'] && in_array( $raw[ $copied ]['_sale_price'], [ [ '' ], '(absent)' ], true ), $raw[ $copied ] );
		}
	);
} finally {
	foreach ( array_reverse( $bulkcat_su_made ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product && str_starts_with( (string) get_post_meta( (int) ( $product->get_parent_id() > 0 ? $product->get_parent_id() : $id ), '_sku', true ), 'bulkcat-su-' ) ) {
			$product->delete( true );
		}
	}
}
WP_CLI::line( 0 === $GLOBALS['bulkcat_su_failed'] ? 'ALL PASS' : $GLOBALS['bulkcat_su_failed'] . ' FAILED' );
