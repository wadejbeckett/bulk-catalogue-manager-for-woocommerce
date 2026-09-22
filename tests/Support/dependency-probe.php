<?php
/**
 * Loads the plugin in a fresh PHP process under one WooCommerce scenario and
 * prints, as JSON, the hooks registered, the admin notice output, the
 * compatibility declarations and the settings registered on init. Used by DependencyTest.
 *
 *     php tests/Support/dependency-probe.php <WC_VERSION|none> <stub|nostub>
 *
 * "stub" loads the recording FeaturesUtil; "nostub" leaves the class absent.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

define( 'ABSPATH', '/' );

$wc_version = $argv[1] ?? 'none';
$features   = $argv[2] ?? 'stub';

require __DIR__ . '/wp-stubs.php';
if ( 'none' !== $wc_version ) {
	define( 'WC_VERSION', $wc_version );
}
if ( 'stub' === $features ) {
	require __DIR__ . '/features-util-stub.php';
}
require dirname( __DIR__, 2 ) . '/bulk-catalogue-manager-for-woocommerce.php';

do_action( 'plugins_loaded' );

$hooks = [];
foreach ( $GLOBALS['bcm_test']['filters'] as $hook => $entries ) {
	$hooks[ $hook ] = count( $entries );
}
ksort( $hooks );

$GLOBALS['bcm_test']['caps']['activate_plugins'] = true;
ob_start();
do_action( 'admin_notices' );
$notice = (string) ob_get_clean();

do_action( 'before_woocommerce_init' );
do_action( 'init' );

echo json_encode(
	[
		'hooks'    => $hooks,
		'notice'   => $notice,
		'features' => $GLOBALS['bcm_test']['features'],
		'gettext'  => $GLOBALS['bcm_test']['gettext'],
		'settings' => array_keys( $GLOBALS['bcm_test']['settings'] ),
	]
);
