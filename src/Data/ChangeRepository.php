<?php
/**
 * The only code that reads or writes `{prefix}bcm_changes`, the journal of
 * SPEC §9.2 and §9.3: one row per touched field per object, holding the value
 * before and after the write, JSON encoded by ValueCodec so type and null
 * survive. Every statement is prepared; the table name comes from the
 * registry with `$wpdb->prefix` and reaches SQL as a `%i` identifier.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own journal table has no WordPress API, and undo must read exactly what was written.

/**
 * The change journal.
 */
final class ChangeRepository {
	/**
	 * Most rows one read returns.
	 */
	public const MAX_PAGE = 500;

	/**
	 * Length of the message column.
	 */
	public const MESSAGE_LENGTH = 255;

	/**
	 * Length of the prop column.
	 */
	public const PROP_LENGTH = 64;

	/**
	 * The modified time of an object whose time is not known (it could not
	 * be loaded), in WordPress's own zero-date form.
	 */
	public const UNKNOWN_TIME = '0000-00-00 00:00:00';

	/**
	 * The filters audit() and audit_count() accept (T54).
	 */
	public const AUDIT_FILTERS = [ 'object_id', 'prop', 'user_id', 'after', 'before' ];

	/**
	 * Where the table name comes from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Build a repository.
	 *
	 * @param Registry|null $registry Registry holding the table; the plugin's own by default.
	 */
	public function __construct( ?Registry $registry = null ) {
		$this->registry = $registry ?? Registry::plugin();
	}

	/**
	 * Append one journal row.
	 *
	 * @param int    $batch_id     Batch.
	 * @param int    $object_id    Product, variation or term id.
	 * @param string $object_type  One of Schema::OBJECT_TYPES.
	 * @param string $prop         Field key from the field registry.
	 * @param mixed  $old_value    Value before the write.
	 * @param mixed  $new_value    Value after the write (or the intended value, for a skipped or failed row).
	 * @param string $modified_gmt The object's post_modified_gmt at write time, `Y-m-d H:i:s`, or UNKNOWN_TIME.
	 * @param string $result       One of Schema::CHANGE_RESULTS.
	 * @param string $message      Reason for a skip or failure; cut to MESSAGE_LENGTH characters.
	 * @return int The new change_id.
	 * @throws \InvalidArgumentException On an invalid argument.
	 * @throws \RuntimeException When the row is not written.
	 */
	public function append( int $batch_id, int $object_id, string $object_type, string $prop, mixed $old_value, mixed $new_value, string $modified_gmt, string $result, string $message = '' ): int {
		global $wpdb;
		if ( $batch_id < 1 || $object_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid batch or object id.' );
		}
		if ( ! in_array( $object_type, Schema::OBJECT_TYPES, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown object type "%s".', $object_type ) ) );
		}
		// A field-registry key: lower case, starting with a letter, so a raw `_meta` key is refused.
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,' . ( self::PROP_LENGTH - 1 ) . '}$/', $prop ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Invalid field key "%s".', $prop ) ) );
		}
		if ( ! in_array( $result, Schema::CHANGE_RESULTS, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown result "%s".', $result ) ) );
		}
		if ( self::UNKNOWN_TIME !== $modified_gmt && ! self::is_datetime( $modified_gmt ) ) {
			throw new \InvalidArgumentException( 'The modified time must be Y-m-d H:i:s.' );
		}
		$data = [
			'batch_id'     => $batch_id,
			'object_id'    => $object_id,
			'object_type'  => $object_type,
			'prop'         => $prop,
			'old_value'    => ValueCodec::encode( $old_value ),
			'new_value'    => ValueCodec::encode( $new_value ),
			'modified_gmt' => $modified_gmt,
			'applied_gmt'  => gmdate( 'Y-m-d H:i:s' ),
			'result'       => $result,
			'message'      => mb_substr( $message, 0, self::MESSAGE_LENGTH ),
		];
		// insert() prepares the statement from the formats.
		if ( false === $wpdb->insert( $this->registry->table( Schema::CHANGES ), $data, [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] ) ) {
			throw new \RuntimeException( 'The change could not be journalled.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a batch's journal back in the order it was written, a page at a
	 * time: pass the last change_id seen as $after to continue.
	 *
	 * @param int  $batch_id     Batch.
	 * @param int  $after        Return only rows with a greater change_id.
	 * @param int  $limit        Most rows, 1 to MAX_PAGE.
	 * @param bool $newest_first Read from the newest row down (undo's order); $after is then an upper bound, 0 meaning none.
	 * @return array<int, array{change_id: int, batch_id: int, object_id: int, object_type: string, prop: string, old_value: mixed, new_value: mixed, modified_gmt: string, applied_gmt: string, result: string, message: string}>
	 */
	public function for_batch( int $batch_id, int $after = 0, int $limit = 100, bool $newest_first = false ): array {
		global $wpdb;
		$limit = max( 1, min( self::MAX_PAGE, $limit ) );
		$table = $this->registry->table( Schema::CHANGES );
		if ( $newest_first ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %d AND change_id < %d ORDER BY change_id DESC LIMIT %d', $table, $batch_id, $after > 0 ? $after : PHP_INT_MAX, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %d AND change_id > %d ORDER BY change_id ASC LIMIT %d', $table, $batch_id, max( 0, $after ), $limit ), ARRAY_A );
		}
		return array_map( [ self::class, 'row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Whether a batch has journalled anything for an object, so a retried
	 * chunk skips it rather than applying twice (§12.3).
	 *
	 * @param int $batch_id  Batch.
	 * @param int $object_id Object.
	 */
	public function has_object( int $batch_id, int $object_id ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT change_id FROM %i WHERE batch_id = %d AND object_id = %d LIMIT 1', $this->registry->table( Schema::CHANGES ), $batch_id, $object_id ) );
	}

	/**
	 * Number of a batch's journal rows, optionally with one result.
	 *
	 * @param int         $batch_id Batch.
	 * @param string|null $result   One of Schema::CHANGE_RESULTS, or null for all.
	 * @throws \InvalidArgumentException On an unknown result.
	 */
	public function count_for_batch( int $batch_id, ?string $result = null ): int {
		global $wpdb;
		$table = $this->registry->table( Schema::CHANGES );
		if ( null === $result ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_id = %d', $table, $batch_id ) );
		}
		if ( ! in_array( $result, Schema::CHANGE_RESULTS, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown result "%s".', $result ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND result = %s', $table, $batch_id, $result ) );
	}

	/**
	 * One page of a batch's journal in the order it was written, for the
	 * changes feed (T36): optionally only rows with some results.
	 *
	 * @param int               $batch_id Batch.
	 * @param int               $page     Page, from 1.
	 * @param int               $per_page Rows per page, 1 to MAX_PAGE.
	 * @param list<string>|null $results  Schema::CHANGE_RESULTS to keep, or null for all.
	 * @return array<int, array{change_id: int, batch_id: int, object_id: int, object_type: string, prop: string, old_value: mixed, new_value: mixed, modified_gmt: string, applied_gmt: string, result: string, message: string}>
	 * @throws \InvalidArgumentException On an unknown result.
	 */
	public function page( int $batch_id, int $page, int $per_page, ?array $results = null ): array {
		global $wpdb;
		$per_page         = max( 1, min( self::MAX_PAGE, $per_page ) );
		[ $where, $args ] = $this->feed_conditions( $batch_id, $results );
		$args[]           = $per_page;
		$args[]           = ( max( 1, $page ) - 1 ) * $per_page;
		// $where holds only fixed conditions with placeholders; every value is a prepared argument.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT change_id, batch_id, object_id, object_type, prop, old_value, new_value, modified_gmt, applied_gmt, result, message FROM %i WHERE {$where} ORDER BY change_id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array_map( [ self::class, 'row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Number of rows page() pages through.
	 *
	 * @param int               $batch_id Batch.
	 * @param list<string>|null $results  As page().
	 * @throws \InvalidArgumentException On an unknown result.
	 */
	public function page_count( int $batch_id, ?array $results = null ): int {
		global $wpdb;
		[ $where, $args ] = $this->feed_conditions( $batch_id, $results );
		// $where holds only fixed conditions with placeholders; every value is a prepared argument.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * The reasons of a batch's failed and skipped rows grouped by message,
	 * with the number of objects carrying each, most first (T36's summary).
	 *
	 * @param int $batch_id Batch.
	 * @param int $limit    Most reasons, 1 to 100.
	 * @return list<array{message: string, objects: int}>
	 */
	public function reasons( int $batch_id, int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT message, COUNT(DISTINCT object_id) AS objects FROM %i WHERE batch_id = %d AND result IN ('failed', 'skipped') AND message <> '' GROUP BY message ORDER BY objects DESC LIMIT %d", $this->registry->table( Schema::CHANGES ), $batch_id, max( 1, min( 100, $limit ) ) ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => [
				'message' => (string) $row['message'],
				'objects' => (int) $row['objects'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * For a page of batches in one grouped read, how many `applied` journal
	 * rows each has (T54's reversibility state): batch id => count, batches
	 * with none left out.
	 *
	 * @param list<int> $batch_ids Batches.
	 * @return array<int, int>
	 * @throws \InvalidArgumentException On an id that is not a positive integer.
	 */
	public function applied_counts( array $batch_ids ): array {
		global $wpdb;
		$batch_ids = array_values( array_unique( $batch_ids ) );
		if ( [] === $batch_ids ) {
			return [];
		}
		foreach ( $batch_ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new \InvalidArgumentException( 'Invalid batch id.' );
			}
		}
		$in   = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );
		$args = array_merge( [ $this->registry->table( Schema::CHANGES ) ], $batch_ids );
		// $in holds only %d placeholders, one per id; every id is a prepared argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, COUNT(*) AS applied FROM %i WHERE batch_id IN ({$in}) AND result = 'applied' GROUP BY batch_id", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (int) $row['batch_id'] ] = (int) $row['applied'];
		}
		return $counts;
	}

	/**
	 * One page of the audit log (T54, §4.6): journal rows across every
	 * batch, newest first, each with its batch's user, filtered by object,
	 * field, the batch's user and the time the row was written.
	 *
	 * @param array{object_id?: int, prop?: string, user_id?: int, after?: string, before?: string} $filters  Filters; times are GMT `Y-m-d H:i:s`, `after` inclusive, `before` exclusive.
	 * @param int                                                                                  $page     Page, from 1.
	 * @param int                                                                                  $per_page Rows per page, 1 to MAX_PAGE.
	 * @return array<int, array{change_id: int, batch_id: int, object_id: int, object_type: string, prop: string, old_value: mixed, new_value: mixed, modified_gmt: string, applied_gmt: string, result: string, message: string, user_id: int}>
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	public function audit( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$per_page         = max( 1, min( self::MAX_PAGE, $per_page ) );
		[ $where, $args ] = $this->audit_conditions( $filters );
		$args[]           = $per_page;
		$args[]           = ( max( 1, $page ) - 1 ) * $per_page;
		// $where holds only fixed conditions with placeholders; every value is a prepared argument.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.change_id, c.batch_id, c.object_id, c.object_type, c.prop, c.old_value, c.new_value, c.modified_gmt, c.applied_gmt, c.result, c.message, b.user_id FROM %i AS c INNER JOIN %i AS b ON b.batch_id = c.batch_id WHERE {$where} ORDER BY c.change_id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array_map( static fn( array $row ): array => self::row( $row ) + [ 'user_id' => (int) $row['user_id'] ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Number of rows audit() pages through.
	 *
	 * @param array<string, mixed> $filters As audit().
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	public function audit_count( array $filters ): int {
		global $wpdb;
		[ $where, $args ] = $this->audit_conditions( $filters );
		// $where holds only fixed conditions with placeholders; every value is a prepared argument.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i AS c INNER JOIN %i AS b ON b.batch_id = c.batch_id WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * The WHERE clause and its arguments (the two tables first) for the audit log.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<mixed>}
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	private function audit_conditions( array $filters ): array {
		$unknown = array_diff( array_keys( $filters ), self::AUDIT_FILTERS );
		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown audit filter "%s".', (string) reset( $unknown ) ) ) );
		}
		$where = [ 'c.change_id > 0' ];
		$args  = [ $this->registry->table( Schema::CHANGES ), $this->registry->table( Schema::BATCHES ) ];
		foreach ( [
			'object_id' => 'c.object_id = %d',
			'user_id'   => 'b.user_id = %d',
		] as $key => $condition ) {
			if ( isset( $filters[ $key ] ) ) {
				if ( ! is_int( $filters[ $key ] ) || $filters[ $key ] < 1 ) {
					throw new \InvalidArgumentException( esc_html( sprintf( 'The %s filter is a positive integer.', $key ) ) );
				}
				$where[] = $condition;
				$args[]  = $filters[ $key ];
			}
		}
		if ( isset( $filters['prop'] ) ) {
			if ( ! is_string( $filters['prop'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,' . ( self::PROP_LENGTH - 1 ) . '}$/', $filters['prop'] ) ) {
				throw new \InvalidArgumentException( 'The field filter is a field key.' );
			}
			$where[] = 'c.prop = %s';
			$args[]  = $filters['prop'];
		}
		foreach ( [
			'after'  => 'c.applied_gmt >= %s',
			'before' => 'c.applied_gmt < %s',
		] as $key => $condition ) {
			if ( isset( $filters[ $key ] ) ) {
				if ( ! is_string( $filters[ $key ] ) || ! self::is_datetime( $filters[ $key ] ) ) {
					throw new \InvalidArgumentException( 'A date filter is a GMT Y-m-d H:i:s time.' );
				}
				$where[] = $condition;
				$args[]  = $filters[ $key ];
			}
		}
		return [ implode( ' AND ', $where ), $args ];
	}

	/**
	 * The WHERE clause and its arguments (the table first) for the feed.
	 *
	 * @param int               $batch_id Batch.
	 * @param list<string>|null $results  Results to keep, or null for all.
	 * @return array{0: string, 1: list<mixed>}
	 * @throws \InvalidArgumentException On an unknown or empty result list.
	 */
	private function feed_conditions( int $batch_id, ?array $results ): array {
		$where = 'batch_id = %d';
		$args  = [ $this->registry->table( Schema::CHANGES ), $batch_id ];
		if ( null !== $results ) {
			$results = array_values( array_unique( $results ) );
			if ( [] === $results || [] !== array_diff( $results, Schema::CHANGE_RESULTS ) ) {
				throw new \InvalidArgumentException( 'Results are a non-empty list of applied, skipped and failed.' );
			}
			$where .= ' AND result IN (' . implode( ', ', array_fill( 0, count( $results ), '%s' ) ) . ')';
			$args   = array_merge( $args, $results );
		}
		return [ $where, $args ];
	}

	/**
	 * Type a stored row and decode its values.
	 *
	 * @param array $row Row from the database.
	 * @return array{change_id: int, batch_id: int, object_id: int, object_type: string, prop: string, old_value: mixed, new_value: mixed, modified_gmt: string, applied_gmt: string, result: string, message: string}
	 */
	private static function row( array $row ): array {
		return [
			'change_id'    => (int) $row['change_id'],
			'batch_id'     => (int) $row['batch_id'],
			'object_id'    => (int) $row['object_id'],
			'object_type'  => (string) $row['object_type'],
			'prop'         => (string) $row['prop'],
			'old_value'    => ValueCodec::decode( (string) $row['old_value'] ),
			'new_value'    => ValueCodec::decode( (string) $row['new_value'] ),
			'modified_gmt' => (string) $row['modified_gmt'],
			'applied_gmt'  => (string) $row['applied_gmt'],
			'result'       => (string) $row['result'],
			'message'      => (string) $row['message'],
		];
	}

	/**
	 * Whether a string is a `Y-m-d H:i:s` time.
	 *
	 * @param string $value Candidate.
	 */
	private static function is_datetime( string $value ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $value;
	}
}
