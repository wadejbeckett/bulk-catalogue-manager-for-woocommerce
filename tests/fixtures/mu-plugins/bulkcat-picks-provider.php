<?php
/**
 * Plugin Name: Bulk Catalogue Manager — version 2 test provider
 * Description: Registers the neutral "Sample picks" provider (contract version 2: lookups, row entries and, when the core supports them, category actions) on a disposable test site. Never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'BULKCAT_EXTENSION_CONTRACT' ) || BULKCAT_EXTENSION_CONTRACT < 2 || ! interface_exists( \BulkCatalogueManager\Extension\LookupSource::class ) ) {
			return;
		}
		require_once WP_PLUGIN_DIR . '/bulk-catalogue-manager-for-woocommerce/tests/Support/Extension/load.php';
		add_action(
			'bulkcat_register_providers',
			static function ( \BulkCatalogueManager\Extension\Registrar $registrar ): void {
				if ( $registrar->supports( 2 ) && $registrar->supports_feature( 'lookup' ) ) {
					$registrar->register(
						new \BulkCatalogueManager\Tests\Support\Extension\PicksProvider(
							null,
							null,
							$registrar->supports_feature( 'category_target' ),
							$registrar->supports_feature( 'row_entry' )
						)
					);
				}
			}
		);
	},
	20
);
