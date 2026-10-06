<?php
/**
 * Plugin Name: Sample labels (test provider)
 * Description: The neutral external action provider the core's probe and browser tests install as a must-use plugin on a throwaway site. Test code only; never shipped.
 *
 * Copy it to wp-content/mu-plugins/ on a disposable stack whose plugin
 * directory is this checkout (tests/probe/README.md). It registers only when
 * the core is active and implements contract version 1.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'BULKCAT_EXTENSION_CONTRACT' ) || ! interface_exists( \BulkCatalogueManager\Extension\Provider::class ) ) {
			return;
		}
		require_once WP_PLUGIN_DIR . '/bulk-catalogue-manager-for-woocommerce/tests/Support/Extension/load.php';
		add_action(
			'bulkcat_register_providers',
			static function ( \BulkCatalogueManager\Extension\Registrar $registrar ): void {
				if ( $registrar->supports( 1 ) ) {
					$registrar->register( new \BulkCatalogueManager\Tests\Support\Extension\SampleProvider() );
				}
			}
		);
	},
	20
);
