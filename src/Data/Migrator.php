<?php
/**
 * Schema migrator (SPEC §9.2, §15 Data lifecycle). On activation, and on the
 * first admin request after an update, compares `bulkcat_db_version` with the
 * code's schema version and, when they differ, feeds every registry table to
 * dbDelta(). The new version is stored only once every table exists with
 * every column and index its body names (ENGINE-9), so a failed run is
 * retried on the next request.
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
	 * 8: the batch's provider manifest, `extensions` (T107); 9: every table
	 * InnoDB, as storage readiness requires (FoundationStorage::ready()), with
	 * existing plugin tables on another engine converted.
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
				$statements[] = "CREATE TABLE {$wpdb->prefix}{$name} (\n" . trim( $body ) . "\n) {$collate} ENGINE=InnoDB;";
			}
			dbDelta( $statements );
			foreach ( $tables as $name => $body ) {
				if ( ! self::table_exists( $wpdb->prefix . $name ) || ! self::ensure_innodb( $name ) || ! self::shape_landed( $name, $body ) ) {
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
	 * Convert one of the plugin's own tables to InnoDB when it was created on
	 * another engine (a server whose default engine is MyISAM ignored or
	 * predates the ENGINE clause). dbDelta() never changes a table's engine.
	 *
	 * @param string $name Unprefixed registry table name.
	 * @return bool False only when a conversion was needed and failed.
	 */
	private static function ensure_innodb( string $name ): bool {
		global $wpdb;
		if ( ! Registry::valid_name( $name ) ) {
			return true;
		}
		$table = $wpdb->prefix . $name;
		// The engine must be read live after dbDelta(); no WordPress API reports it.
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A live schema read of the plugin's own table.
		if ( ! is_string( $engine ) || 'INNODB' === strtoupper( $engine ) ) {
			return true;
		}
		// The plugin's own registry table (validated bulkcat_ name plus the site prefix), as a %i identifier.
		return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Converts only the plugin's own table to the engine its transactions need.
	}

	/**
	 * Whether every column and index a table's body names is on the table
	 * (ENGINE-9): dbDelta() reports nothing when an ALTER it ran failed
	 * (permissions, a lock timeout), so the version is stored only once
	 * each one is read back, and a run that missed one is retried.
	 *
	 * @param string $name Unprefixed registry table name.
	 * @param string $body Its CREATE TABLE body.
	 */
	private static function shape_landed( string $name, string $body ): bool {
		global $wpdb;
		$columns = [];
		$indexes = [];
		foreach ( explode( "\n", $body ) as $line ) {
			$line = trim( $line );
			if ( 1 === preg_match( '/^PRIMARY KEY/i', $line ) ) {
				$indexes[] = 'primary';
			} elseif ( 1 === preg_match( '/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+(\w+)/i', $line, $match ) ) {
				$indexes[] = strtolower( $match[1] );
			} elseif ( 1 === preg_match( '/^(\w+)\s/', $line, $match ) && ! in_array( strtoupper( $match[1] ), [ 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT', 'FOREIGN' ], true ) ) {
				$columns[] = strtolower( $match[1] );
			}
		}
		$table = $wpdb->prefix . $name;
		// Live schema reads of the plugin's own table after dbDelta(); nothing to cache.
		$have_columns = array_map( 'strtolower', array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Whether dbDelta()'s changes landed must be read live.
		$have_indexes = array_map( 'strtolower', array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above; column 2 is Key_name.
		return [] === array_diff( $columns, $have_columns ) && [] === array_diff( $indexes, $have_indexes );
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
		// A server with lower_case_table_names=1 answers an upper-case prefix in lower case: still the same table.
		return is_string( $found ) && 0 === strcasecmp( $table, $found );
	}
}
