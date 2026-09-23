<?php
/**
 * Runs when the plugin is deleted. With "Remove all data when the plugin is
 * deleted" off, the default, nothing is removed, so history and undo records
 * survive a reinstall. With it on, every table, option, user meta, term meta,
 * transient and scheduled action in the registry is removed (SPEC §11, §15),
 * on every site of a network.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Uninstall loads no plugin file, so src/ is loaded here.
require_once __DIR__ . '/src/Autoloader.php';
\BulkCatalogueManager\Autoloader::register( __DIR__ . '/src' );

// On a network every site holds its own tables, settings and transients, so each is cleaned under its own setting.
\BulkCatalogueManager\Data\Cleanup::on_every_site(
	static function (): void {
		( new \BulkCatalogueManager\Data\Cleanup( \BulkCatalogueManager\Data\Registry::plugin() ) )->uninstall( \BulkCatalogueManager\Settings\Settings::load() );
	}
);
