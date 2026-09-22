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
	 * @param string $modified_gmt The object's post_modified_gmt at write time, `Y-m-d H:i:s`.
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
		if ( ! self::is_datetime( $modified_gmt ) ) {
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
