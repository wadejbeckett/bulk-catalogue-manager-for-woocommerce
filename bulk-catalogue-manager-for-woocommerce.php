<?php
/**
 * Plugin Name:       Bulk Catalogue Manager for WooCommerce
 * Plugin URI:        https://github.com/wadejbeckett/bulk-catalogue-manager-for-woocommerce
 * Description:       Manage many WooCommerce products at once from one simple screen. Every bulk change is previewed first, recorded and reversible.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 10.0
 * WC tested up to:   11.1
 * Author:            Wade Beckett
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       bulk-catalogue-manager-for-woocommerce
 *
 * @package BulkCatalogueManager
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'BCM_FILE', __FILE__ );
define( 'BCM_DIR', __DIR__ . '/' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Autoloader.php';
	\BulkCatalogueManager\Autoloader::register( __DIR__ . '/src' );
}

add_action(
	'plugins_loaded',
	static function (): void {
		\BulkCatalogueManager\Plugin::instance()->boot();
	}
);
