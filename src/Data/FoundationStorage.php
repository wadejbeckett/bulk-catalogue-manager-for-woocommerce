<?php
/**
 * Transaction and installation boundaries for exact selections and receipts.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Storage readiness and transactions must use current database state; WordPress has no equivalent API.

/** Shared atomicity precondition, never a product writer. */
final class FoundationStorage {
	/** Prevent accidentally committing an outer foundation transaction. */
	private static bool $active = false;
	/** Non-autoloaded install identity, removed only with opted-in data deletion. */
	public const EPOCH_OPTION = 'bulkcat_request_epoch';

	/** Install once; add_option's unique key handles concurrent migrations. */
	public static function initialize(): bool {
		if ( false === get_option( self::EPOCH_OPTION, false ) ) {
			add_option( self::EPOCH_OPTION, bin2hex( random_bytes( 32 ) ), '', false );
		}
		return self::epoch() !== '';
	}

	/** Return the existing identity, never regenerate missing/corrupt state on a read. */
	public static function epoch(): string {
		$value = get_option( self::EPOCH_OPTION, '' );
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
	}

	/** Refuse partial migration and nontransactional plugin storage. */
	public static function ready(): bool {
		global $wpdb;
		if ( Migrator::SCHEMA_VERSION !== get_option( Migrator::VERSION_OPTION, '' ) || '' === self::epoch() ) {
			return false;
		}
		$tables = array_map( [ Registry::plugin(), 'table' ], array_keys( Schema::TABLES ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s) LIMIT 5', $tables[0], $tables[1], $tables[2], $tables[3], $tables[4] ), ARRAY_A );
		return is_array( $rows ) && count( $rows ) === count( $tables ) && count( array_filter( $rows, static fn( array $row ): bool => 'INNODB' === strtoupper( (string) $row['ENGINE'] ) ) ) === count( $tables ) && self::shape_ready();
	}

	/** Verify the columns and unique identities even after a partial dbDelta run. */
	private static function shape_ready(): bool {
		global $wpdb;
		foreach ( [
			Schema::SELECTIONS => [ 'id' ],
			Schema::REQUESTS   => [ 'user_id', 'uuid' ],
		] as $name => $primary ) {
			$table   = Registry::plugin()->table( $name );
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $columns ) || '' !== $wpdb->last_error ) {
				return false;
			}
			$columns = array_column( $columns, null, 'Field' );
			foreach ( explode( "\n", Schema::TABLES[ $name ] ) as $line ) {
				if ( 1 !== preg_match( '/^([a-z_]+) (.+?) (NOT NULL|NULL)[, ]/', $line, $match ) ) {
					continue;
				}
				$column    = $columns[ $match[1] ] ?? null;
				$normalize = static fn( string $type ): string => (string) preg_replace( '/(bigint|int)\(\d+\)/', '$1', strtolower( $type ) );
				if ( null === $column || $normalize( $match[2] ) !== $normalize( $column['Type'] ) || ( 'NULL' === $match[3] ? 'YES' : 'NO' ) !== $column['Null'] ) {
					return false;
				}
			}
			$indexes = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
			if ( ! is_array( $indexes ) || '' !== $wpdb->last_error ) {
				return false;
			}
			usort( $indexes, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
			if ( array_column( $indexes, 'Column_name' ) !== $primary || array_filter( $indexes, static fn( array $index ): bool => 0 !== (int) $index['Non_unique'] || null !== $index['Sub_part'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Require participation, never silently open or commit a nested transaction. */
	public static function require_transaction(): void {
		if ( ! self::$active ) {
			throw new \RuntimeException( 'A foundation transaction is required.' ); }
	}

	/**
	 * Own one transaction; no scheduling, hooks or product writes inside it.
	 *
	 * @param callable $operation Transaction body.
	 * @return mixed Body result after commit.
	 * @throws \RuntimeException On a database failure, including ambiguous commit.
	 * @throws \Throwable Preserves the original body failure after rollback.
	 */
	public static function atomic( callable $operation ): mixed {
		global $wpdb;
		if ( self::$active ) {
			throw new \RuntimeException( 'Nested storage transactions are not supported.' );
		}
		// wpdb otherwise reconnects and replays failed statements in autocommit mode.
		// Its backward-compatible property accessor permits this request-local guard.
		$retries                 = $wpdb->reconnect_retries;
		$wpdb->reconnect_retries = 0;
		self::$active            = true;
		$connection              = TransactionAbort::connection();
		$started                 = false;
		$committing              = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Cannot start storage transaction.' );
			}
			$started = true;
			$result  = $operation();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}
			$committing = true;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Uncertain transaction outcome; resolve the same request.' );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$confirmable = $error instanceof TransactionAbort && $started && ! $committing
				&& null !== $connection && TransactionAbort::connection() === $connection
				&& 0 === $wpdb->reconnect_retries;
			if ( $error instanceof TransactionAbort ) {
				$error->acknowledge_rollback( false, null );
			}
			$rolled_back = $wpdb->query( 'ROLLBACK' );
			if ( $error instanceof TransactionAbort ) {
				$error->acknowledge_rollback(
					$confirmable && false !== $rolled_back && 0 === $wpdb->reconnect_retries
						&& TransactionAbort::connection() === $connection,
					$connection
				);
			}
			throw $error;
		} finally {
			$wpdb->reconnect_retries = $retries;
			self::$active            = false;
		}
	}
	/** Verify the additive typed identity seam without changing v1 storage checks. */
	public static function typed_ready(): bool {
		global $wpdb;
		foreach ( [ Schema::ITEMS, Schema::CHANGES ] as $name ) {
			$table  = Registry::plugin()->table( $name );
			$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'target_taxonomy' ), ARRAY_A );
			if ( ! is_array( $column ) || '' !== $wpdb->last_error || 'varchar(32)' !== strtolower( $column['Type'] ?? '' ) || 'NO' !== ( $column['Null'] ?? null ) || '' !== ( $column['Default'] ?? null ) ) {
				return false; }
			$index = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'target'", $table ), ARRAY_A );
			if ( ! is_array( $index ) || '' !== $wpdb->last_error ) {
				return false; }
			usort( $index, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
			if ( array_column( $index, 'Column_name' ) !== [ 'batch_id', 'object_type', 'target_taxonomy', 'object_id' ] || array_filter( $index, static fn( array $entry ): bool => null !== $entry['Sub_part'] ) ) {
				return false; }
		}
		return true;
	}
}
