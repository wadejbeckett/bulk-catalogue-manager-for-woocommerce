<?php
/** Separate native bootstrap with Trash disabled; never alter wp-config. @package BulkCatalogueManager */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 0 !== EMPTY_TRASH_DAYS || ! in_array( wp_parse_url( site_url(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
	throw new RuntimeException( 'An explicitly local CLI bootstrap with EMPTY_TRASH_DAYS=0 is required.' );
}

$bulkcat_actor = get_user_by( 'login', 'admin' );
if ( ! $bulkcat_actor || ! in_array( 'administrator', $bulkcat_actor->roles, true ) ) {
	throw new RuntimeException( 'Synthetic fixture administrator missing.' );
}
wp_set_current_user( $bulkcat_actor->ID );
$bulkcat_run     = wp_generate_uuid4();
$bulkcat_product = new WC_Product_Simple();
$bulkcat_product->set_name( 'Disabled Trash probe ' . $bulkcat_run );
$bulkcat_product->set_status( 'draft' );
$bulkcat_product->update_meta_data( '_bulkcat_row_probe', $bulkcat_run );
$bulkcat_id  = $bulkcat_product->save();
$bulkcat_die = static function (): Closure {
	return static function ( $message, $title, $args ): never {
		throw new RuntimeException( wp_strip_all_tags( (string) $message ), (int) ( $args['response'] ?? 500 ) );
	};
};
add_filter( 'wp_die_handler', $bulkcat_die, PHP_INT_MAX );
try {
	$bulkcat_actions = \BulkCatalogueManager\Admin\ProductRowActions::for_post( get_post( $bulkcat_id ) );
	if ( null !== $bulkcat_actions['trash'] ) {
		throw new RuntimeException( 'Disabled Trash still offers a destination.' );
	}
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_POST                     = [];
	$_GET                      = [
		'post'               => (string) $bulkcat_id,
		'action'             => 'trash',
		'_wpnonce'           => wp_create_nonce( 'trash-post_' . $bulkcat_id ),
		'bulkcat_row_action' => 'trash',
		'bulkcat_row_sales'  => '0',
		'bulkcat_row_nonce'  => wp_create_nonce( 'bulkcat_row_trash_' . $bulkcat_id . '_sales_0' ),
	];
	$_REQUEST                  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Synthetic signed request consumed by the real native guard below.
	$bulkcat_status            = 0;
	try {
		\BulkCatalogueManager\Admin\ProductRowActionGuard::trash();
	} catch ( RuntimeException $bulkcat_error ) {
		$bulkcat_status = $bulkcat_error->getCode();
	}
	if ( 409 !== $bulkcat_status || 'draft' !== get_post_status( $bulkcat_id ) ) {
		throw new RuntimeException( 'Saved marked link failed closed incorrectly.' );
	}
	echo wp_json_encode(
		[
			'menu_absent'      => true,
			'guard_status'     => $bulkcat_status,
			'source_preserved' => true,
		]
	);
} finally {
	remove_filter( 'wp_die_handler', $bulkcat_die, PHP_INT_MAX );
	$bulkcat_saved = wc_get_product( $bulkcat_id );
	if ( $bulkcat_saved && $bulkcat_saved->get_meta( '_bulkcat_row_probe', true, 'edit' ) === $bulkcat_run ) {
		$bulkcat_saved->delete( true );
	}
	if ( get_post( $bulkcat_id ) ) {
		throw new RuntimeException( 'Owned disabled-Trash probe cleanup failed.' );
	}
}
