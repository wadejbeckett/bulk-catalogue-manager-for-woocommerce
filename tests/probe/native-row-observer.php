<?php
/** Local UUID-owned native hook observer; never shipped. @package BulkCatalogueManager */
declare( strict_types = 1 );

if ( ! in_array( wp_parse_url( site_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
	return;
}

/** Hooks record only products belonging to the explicitly armed fixture run. */
$bulkcat_row_observe = static function ( string $event, WC_Product $product, int $source = 0 ): void {
	$run = $product->get_meta( '_bulkcat_row_probe', true, 'edit' );
	if ( ! is_string( $run ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) || ! get_option( 'bulkcat_row_probe_armed_' . $run ) ) {
		return;
	}
	$key   = 'bulkcat_row_probe_log_' . $run;
	$log   = get_option( $key, [] );
	$log[] = [
		'event'  => $event,
		'id'     => $product->get_id(),
		'source' => $source,
	];
	update_option( $key, $log, false );
};

add_action(
	'woocommerce_product_duplicate_before_save',
	static function ( WC_Product $copy, WC_Product $source ) use ( $bulkcat_row_observe ): void {
		$bulkcat_row_observe( 'duplicate_before_save', $copy, $source->get_id() );
	},
	10,
	2
);
add_action(
	'woocommerce_product_duplicate',
	static function ( WC_Product $copy, WC_Product $source ) use ( $bulkcat_row_observe ): void {
		$bulkcat_row_observe( 'duplicate_complete', $copy, $source->get_id() );
	},
	10,
	2
);
add_filter(
	'woocommerce_duplicate_product_exclude_meta',
	static fn( array $excluded, array $keys ): array => in_array( '_bulkcat_row_probe', $keys, true ) ? array_merge( $excluded, [ '_bulkcat_row_probe_excluded' ] ) : $excluded,
	10,
	2
);
add_filter(
	'woocommerce_duplicate_product_exclude_children',
	static fn( bool $excluded, WC_Product $product ): bool => $excluded || (bool) $product->get_meta( '_bulkcat_row_probe_no_children', true, 'edit' ),
	10,
	2
);
add_filter(
	'pre_trash_post',
	static function ( $trash, WP_Post $post ) use ( $bulkcat_row_observe ) {
		if ( get_post_meta( $post->ID, '_bulkcat_row_probe_refuse_trash', true ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$bulkcat_row_observe( 'trash_refused', $product );
			}
			return false;
		}
		return $trash;
	},
	10,
	2
);

// A capability filter applies only to an owned synthetic actor of an armed run.
add_filter(
	'woocommerce_duplicate_product_capability',
	static function ( string $capability ): string {
		$user = get_current_user_id();
		$run  = get_user_meta( $user, '_bulkcat_row_probe', true );
		if ( ! is_string( $run ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) || ! get_option( 'bulkcat_row_probe_armed_' . $run ) ) {
			return $capability;
		}
		return 'manage_options' === get_user_meta( $user, '_bulkcat_row_probe_duplicate_cap', true ) ? 'manage_options' : $capability;
	}
);
