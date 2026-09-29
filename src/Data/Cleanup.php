<?php
/**
 * Deactivation and uninstall cleanup (SPEC §11, §15 Data lifecycle).
 * Deactivation unschedules only the plugin's own recurring actions. Uninstall
 * deletes nothing unless "Remove all data when the plugin is deleted" is on;
 * then it removes every scheduled action in the plugin's group, every
 * registry table, transient, user meta, term meta, post meta and option.
 * It never changes a product's status: a product switched Off stays a draft.
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
		// The Off record goes from every product; statuses are left alone, so Off products stay drafts (SPEC §5.5).
		foreach ( $this->registry->post_meta() as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
		foreach ( $this->registry->options() as $name ) {
			delete_option( $name );
		}
		return true;
	}

	/**
	 * Run a site's cleanup on every site of a network, switched to each in
	 * turn so its own prefix, options and settings apply, or once on a
	 * single site.
	 *
	 * @param callable $cleanup fn(): void, run once per site.
	 */
	public static function on_every_site( callable $cleanup ): void {
		if ( ! is_multisite() ) {
			$cleanup();
			return;
		}
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$cleanup();
			} finally {
				restore_current_blog();
			}
		}
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
			// Uninstall removes the plugin's own tables: the site prefix plus a registry name
			// validated as bulkcat_[a-z0-9_]+, passed to prepare() as a %i identifier.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own registry tables, which no WordPress API can do, and the name is an %i identifier.
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
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $store . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must find every stored transient under a registry prefix, which no WordPress API lists, and the LIKE pattern is escaped and prepared.
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
