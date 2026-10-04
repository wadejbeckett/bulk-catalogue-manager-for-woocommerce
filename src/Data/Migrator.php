<?php
/**
 * Schema migrator (SPEC §9.2, §15 Data lifecycle). On activation, and on the
 * first admin request after an update, compares `bulkcat_db_version` with the
 * code's schema version and, when they differ, feeds every registry table to
 * dbDelta(). The new version is stored only once every table exists, so a
 * failed run is retried on the next request.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
final class Migrator {
	/**
	 * The schema version this code expects. Bump it whenever a registry table
	 * changes, so existing installs re-run dbDelta(). 1: no tables (T5);
	 * 2: the three §9.2 tables (T6); 3: the chunk runner's claim lease, item
	 * message, chunk size and note (T34); 4: the batch's previewed count (T38); 5: the batch's action list and
	 * pruned time (T54); 6: the item's own operations (T56); 7: immutable selections and durable request receipts (T81);
	 * 8: the batch's provider manifest, `extensions` (T107).
	 */
	public const SCHEMA_VERSION = '9';

	/**
	 * The option holding the schema version the database was last migrated to.
	 */
	public const VERSION_OPTION = 'bulkcat_db_version';

	/**
	 * What to create.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Build a migrator over a registry.
	 *
	 * @param Registry $registry Tables to create.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Activation hook callback. WordPress passes whether the activation is
	 * network-wide; it is ignored, so the tables are created for the site
	 * being activated.
	 */
	public static function activate(): void {
		( new self( Registry::plugin() ) )->run();
	}

	/**
	 * Whether the stored schema version equals the code's.
	 */
	public function is_current(): bool {
		return self::SCHEMA_VERSION === get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Bring the tables up to the code's schema version. Does nothing when the
	 * stored version is already current.
	 *
	 * @return bool Whether the database is now at the code's schema version.
	 */
	public function run(): bool {
		if ( $this->is_current() ) {
			return true;
		}
		$tables = $this->registry->tables();
		if ( [] !== $tables ) {
			global $wpdb;
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$collate    = $wpdb->get_charset_collate();
			$statements = [];
			foreach ( $tables as $name => $body ) {
				$engine       = in_array( $name, [ Schema::SELECTIONS, Schema::REQUESTS ], true ) ? ' ENGINE=InnoDB' : '';
				$statements[] = "CREATE TABLE {$wpdb->prefix}{$name} (\n" . trim( $body ) . "\n) {$collate}{$engine};";
			}
			dbDelta( $statements );
			foreach ( array_keys( $tables ) as $name ) {
				if ( ! self::table_exists( $wpdb->prefix . $name ) ) {
					return false;
				}
			}
		}
		if ( array_key_exists( Schema::REQUESTS, $tables ) && ! FoundationStorage::initialize() ) {
			return false;
		}
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
		return true;
	}

	/**
	 * Whether a table exists in the site's database.
	 *
	 * @param string $table Full table name, prefix included.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// Schema check after dbDelta(); nothing to cache. The table name is a prepared value.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Whether the plugin's own table exists must be read live from the database, which no WordPress API answers, and the pattern is escaped and prepared.
		return $table === $found;
	}
}
