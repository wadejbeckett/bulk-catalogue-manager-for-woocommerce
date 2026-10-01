<?php
/**
 * Temporary Apache observer. Copy outside the webroot for one owned test command.
 * Never install as a plugin, MU plugin, route, or production prepend.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- Test-only, read-only observation; exact private command, UUID and native object ownership guards below. No authentication or request payload is recorded.
( static function (): void {
	if ( 'apache2handler' !== PHP_SAPI ) {
		return;
	}
	$config = json_decode( (string) file_get_contents( __DIR__ . '/config.json' ), true );
	$run    = $_SERVER['HTTP_X_BULKCAT_RECEIPT_RUN'] ?? '';
	$id     = $_SERVER['HTTP_X_BULKCAT_RECEIPT_TARGET'] ?? '';
	$rid    = $_SERVER['HTTP_X_BULKCAT_RECEIPT_REQUEST'] ?? '';
	$uuid   = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';
	if ( ! is_array( $config ) || time() >= $config['expires'] || ( $_SERVER['HTTP_HOST'] ?? '' ) !== $config['host'] || ! hash_equals( $config['command'], $_SERVER['HTTP_X_BULKCAT_OBSERVER'] ?? '' ) || ! preg_match( $uuid, $run ) || ! preg_match( $uuid, $rid ) || ! ctype_digit( $id ) || (int) $id < 1 || ! str_contains( rawurldecode( $_SERVER['REQUEST_URI'] ?? '' ), '/bulkcat/v1/' ) ) {
		return;
	}
	$target = (int) $id;
	$count  = 0;
	$owned  = false;
	// Coordinate only observer-file removal with this matched HTTP request.
	$lease = fopen( __DIR__ . '/active.lock', 'r' );
	if ( false === $lease || ! flock( $lease, LOCK_SH ) ) {
		throw new RuntimeException( 'Owned HTTP observer could not acquire its lifetime guard.' );
	}
	// WordPress normalizes this native preinitialized hook structure at bootstrap.
	$GLOBALS['wp_filter']['woocommerce_after_product_object_save'][ PHP_INT_MAX ][] = [
		'function'      => static function ( $product ) use ( $target, $run, &$count ): void {
			if ( $product->get_id() === $target && get_post_meta( $target, '_bulkcat_native_probe', true ) === $run ) {
				++$count;
			}
		},
		'accepted_args' => 1,
	];
	$GLOBALS['wp_filter']['init'][ PHP_INT_MAX ][]                                  = [
		'function'      => static function () use ( $target, $run, &$owned ): void {
			$owned = get_post_meta( $target, '_bulkcat_native_probe', true ) === $run;
		},
		'accepted_args' => 0,
	];
	register_shutdown_function(
		static function () use ( $run, $rid, $target, &$count, &$owned, $lease ): void {
			if ( ! $owned ) {
				fclose( $lease );
				return;
			}
			file_put_contents(
				__DIR__ . '/observations.jsonl',
				json_encode(
					[
						'run'             => $run,
						'request'         => $rid,
						'target'          => $target,
						'observer_loaded' => 1,
						'target_verified' => 1,
						'saves'           => $count,
					]
				) . "\n",
				FILE_APPEND | LOCK_EX
			);
			fclose( $lease );
		}
	);
} )();
