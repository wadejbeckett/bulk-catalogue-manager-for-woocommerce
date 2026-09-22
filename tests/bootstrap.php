<?php
/**
 * Unit test bootstrap: no WordPress, no WooCommerce. Pure PHP plus stubs.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// The unit suite models a supported WooCommerce. DependencyTest covers an
// absent or older one, each in its own PHP process.
if ( ! defined( 'WC_VERSION' ) ) {
	define( 'WC_VERSION', '10.0' );
}

$bcm_composer = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $bcm_composer ) ) {
	require_once $bcm_composer;
} else {
	require_once dirname( __DIR__ ) . '/src/Autoloader.php';
	\BulkCatalogueManager\Autoloader::register( dirname( __DIR__ ) . '/src' );
}

// After Composer so the shim sees PHPUnit's real TestCase and defines nothing.
require_once __DIR__ . '/Support/testcase-shim.php';
require_once __DIR__ . '/Support/wpdb-stub.php';
require_once __DIR__ . '/Support/wp-stubs.php';
require_once __DIR__ . '/Support/rest-stubs.php';
require_once __DIR__ . '/Support/fake-product.php';
require_once __DIR__ . '/Support/features-util-stub.php';
bcm_test_reset();
