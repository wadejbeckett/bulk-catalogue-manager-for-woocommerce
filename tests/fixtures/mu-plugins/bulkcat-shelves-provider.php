<?php
/**
 * Plugin Name: Bulk Catalogue Manager — category test provider
 * Description: Registers the neutral "Sample shelves" provider (contract version 2, category actions only: Add and Remove shelves on product categories) on a disposable test site. Never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'BULKCAT_EXTENSION_CONTRACT' ) || BULKCAT_EXTENSION_CONTRACT < 2 || ! interface_exists( \BulkCatalogueManager\Extension\CategoryProvider::class ) ) {
			return;
		}
		require_once WP_PLUGIN_DIR . '/bulk-catalogue-manager-for-woocommerce/tests/Support/Extension/load.php';
		add_action(
			'bulkcat_register_providers',
			static function ( \BulkCatalogueManager\Extension\Registrar $registrar ): void {
				if ( $registrar->supports( 2 ) && $registrar->supports_feature( 'category_target' ) ) {
					$registrar->register( new \BulkCatalogueManager\Tests\Support\Extension\ShelvesProvider() );
				}
			}
		);
	},
	20
);
