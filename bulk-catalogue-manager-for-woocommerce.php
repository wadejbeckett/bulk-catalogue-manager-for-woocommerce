<?php
/**
 * Plugin Name:       Bulk Catalogue Manager for WooCommerce
 * Plugin URI:        https://github.com/wadejbeckett/bulk-catalogue-manager-for-woocommerce
 * Description:       Manage many WooCommerce products at once from one simple screen. Every bulk change is previewed first, recorded and reversible.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.1
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

define( 'BULKCAT_FILE', __FILE__ );
define( 'BULKCAT_DIR', __DIR__ . '/' );
// The newest external action contract this plugin implements (docs/EXTENSIONS.md §12); equals Extension\Contract::VERSION.
define( 'BULKCAT_EXTENSION_CONTRACT', 1 );
// Opt-in typed declarations; runtime capabilities qualify separately.
define( 'BULKCAT_TYPED_EXTENSION_CONTRACT', 2 );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Autoloader.php';
	\BulkCatalogueManager\Autoloader::register( __DIR__ . '/src' );
}

// Registered whatever WooCommerce's state, since WordPress fires them before plugins_loaded.
register_activation_hook( __FILE__, [ \BulkCatalogueManager\Data\Migrator::class, 'activate' ] );
register_activation_hook( __FILE__, [ \BulkCatalogueManager\Engine\Pruner::class, 'schedule' ] );
register_activation_hook( __FILE__, [ \BulkCatalogueManager\Requests\Cleanup::class, 'schedule' ] );
register_deactivation_hook( __FILE__, [ \BulkCatalogueManager\Data\Cleanup::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\BulkCatalogueManager\Plugin::instance()->boot();
	}
);
