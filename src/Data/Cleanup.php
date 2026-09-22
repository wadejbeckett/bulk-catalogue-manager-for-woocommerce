<?php
/**
 * Deactivation and uninstall cleanup (SPEC §11, §15 Data lifecycle).
 * Deactivation unschedules only the plugin's own recurring actions. Uninstall
 * deletes nothing unless "Remove all data when the plugin is deleted" is on;
 * then it removes every scheduled action in the plugin's group, every
 * registry table, transient, user meta, term meta and option.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Removes what the registry lists.
 */
final class Cleanup {
	/**
	 * What to remove.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Build over a registry.
	 *
	 * @param Registry $registry What the plugin stores.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Deactivation hook callback. WordPress passes whether the deactivation is
	 * network-wide; it is ignored.
	 */
	public static function deactivate(): void {
		( new self( Registry::plugin() ) )->unschedule_recurring();
	}

	/**
	 * Unschedule the plugin's recurring actions, and nothing else. Without
	 * Action Scheduler loaded there is nothing to unschedule.
	 */
	public function unschedule_recurring(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( $this->registry->recurring_actions() as $hook ) {
			as_unschedule_all_actions( $hook, [], Registry::ACTION_GROUP );
		}
	}

	/**
	 * Remove all the plugin's data if the setting asks for it. Options go last,
	 * so an interrupted run leaves the setting on and can be repeated.
	 *
	 * @param Settings $settings The stored settings.
	 * @return bool Whether anything was removed.
	 */
	public function uninstall( Settings $settings ): bool {
		if ( ! $settings->remove_data_on_delete() ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Registry::ACTION_GROUP );
		}
		$this->drop_tables();
		$this->delete_transients();
		foreach ( $this->registry->user_meta() as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		foreach ( $this->registry->term_meta() as $key ) {
			delete_metadata( 'term', 0, $key, '', true );
		}
		foreach ( $this->registry->options() as $name ) {
			delete_option( $name );
		}
		return true;
	}

	/**
	 * Drop every registry table.
	 */
	private function drop_tables(): void {
		global $wpdb;
		foreach ( array_keys( $this->registry->tables() ) as $name ) {
			if ( ! Registry::valid_name( $name ) ) {
				continue;
			}
			$table = $wpdb->prefix . $name;
			// Uninstall removes the plugin's own tables. The identifier is the site prefix plus a registry
			// name validated as bcm_[a-z0-9_]+, and identifiers cannot be passed through prepare().
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Delete every transient whose name starts with a registry prefix. WordPress
	 * has no call to list transients, so names are read from the options table;
	 * with a persistent object cache they live in the cache instead and expire there.
	 */
	private function delete_transients(): void {
		global $wpdb;
		foreach ( $this->registry->transient_prefixes() as $prefix ) {
			foreach ( [ '_transient_', '_site_transient_' ] as $store ) {
				// Uninstall only; the names are listed once to be deleted, so nothing is cached.
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $store . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( (array) $names as $option_name ) {
					$transient = substr( (string) $option_name, strlen( $store ) );
					if ( '_site_transient_' === $store ) {
						delete_site_transient( $transient );
					} else {
						delete_transient( $transient );
					}
				}
			}
		}
	}
}
