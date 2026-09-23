<?php
/**
 * The only code that reads or writes `{prefix}bcm_batches` and
 * `{prefix}bcm_batch_items` (SPEC §9.2, §12.4). Every statement is prepared;
 * table names come from the registry with `$wpdb->prefix` and reach SQL as
 * `%i` identifiers, and values as `%d` or `%s`. Direct database access is
 * deliberate: these are the plugin's own tables, which WordPress has no API
 * for, and their rows change on every chunk, so nothing is cached.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables have no WordPress API, and batch progress must never be read stale.

/**
 * Batches and their materialised items.
 */
final class BatchRepository {
	/**
	 * Most items written by one INSERT when materialising a selection.
	 */
	public const INSERT_CHUNK = 500;

	/**
	 * Most items one claim returns (the §12.4 chunk ceiling).
	 */
	public const MAX_CLAIM = 100;

	/**
	 * Claims after which a still-pending item is given up on (§12.4).
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Where table names come from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Build a repository.
	 *
	 * @param Registry|null $registry Registry holding the tables; the plugin's own by default.
	 */
	public function __construct( ?Registry $registry = null ) {
		$this->registry = $registry ?? Registry::plugin();
	}

	/**
	 * Create a batch row with status `queued` and zeroed counters.
	 *
	 * @param string   $uuid        Client request id (36 characters).
	 * @param int      $user_id     Initiating user.
	 * @param string   $source      One of Schema::BATCH_SOURCES.
	 * @param array    $operations  Operation list, stored as JSON.
	 * @param array    $selection   Snapshot of the filter or id list, stored as JSON.
	 * @param int      $total_items Number of items to materialise.
	 * @param int|null $undo_of     The batch this one undoes, for source `undo`.
	 * @return int The new batch_id.
	 * @throws \InvalidArgumentException On an invalid argument.
	 * @throws \RuntimeException When the row is not written.
	 */
	public function create( string $uuid, int $user_id, string $source, array $operations, array $selection, int $total_items, ?int $undo_of = null ): int {
		global $wpdb;
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			throw new \InvalidArgumentException( 'The request id must be a UUID.' );
		}
		self::assert_in( $source, Schema::BATCH_SOURCES, 'source' );
		self::assert_ids( [ $user_id ], 'user id', true );
		self::assert_ids( [ $total_items ], 'item count', true );
		if ( null !== $undo_of ) {
			self::assert_ids( [ $undo_of ], 'batch id' );
		}
		if ( ( 'undo' === $source ) !== ( null !== $undo_of ) ) {
			throw new \InvalidArgumentException( 'An undo batch, and only an undo batch, names the batch it undoes.' );
		}
		$data   = [
			'uuid'        => strtolower( $uuid ),
			'user_id'     => $user_id,
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'status'      => 'queued',
			'source'      => $source,
			'operations'  => self::json( $operations ),
			'selection'   => self::json( $selection ),
			'total_items' => $total_items,
		];
		$format = [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ];
		if ( null !== $undo_of ) {
			$data['undo_of'] = $undo_of;
			$format[]        = '%d';
		}
		// insert() prepares the statement from $format.
		if ( false === $wpdb->insert( $this->registry->table( Schema::BATCHES ), $data, $format ) ) {
			throw new \RuntimeException( 'The batch could not be recorded.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a batch back, typed, with operations and selection decoded.
	 *
	 * @param int $batch_id Batch.
	 * @return array{batch_id: int, uuid: string, user_id: int, created_gmt: string, finished_gmt: ?string, status: string, source: string, undo_of: ?int, operations: array, selection: array, total_items: int, done_items: int, changed_items: int, failed_items: int}|null
	 */
	public function find( int $batch_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %d', $this->registry->table( Schema::BATCHES ), $batch_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'batch_id'      => (int) $row['batch_id'],
			'uuid'          => (string) $row['uuid'],
			'user_id'       => (int) $row['user_id'],
			'created_gmt'   => (string) $row['created_gmt'],
			'finished_gmt'  => null === $row['finished_gmt'] ? null : (string) $row['finished_gmt'],
			'status'        => (string) $row['status'],
			'source'        => (string) $row['source'],
			'undo_of'       => null === $row['undo_of'] ? null : (int) $row['undo_of'],
			'operations'    => (array) ValueCodec::decode( (string) $row['operations'] ),
			'selection'     => (array) ValueCodec::decode( (string) $row['selection'] ),
			'total_items'   => (int) $row['total_items'],
			'done_items'    => (int) $row['done_items'],
			'changed_items' => (int) $row['changed_items'],
			'failed_items'  => (int) $row['failed_items'],
		];
	}

	/**
	 * Set a batch's status. A status other than queued, running or undoing
	 * also stamps finished_gmt.
	 *
	 * @param int    $batch_id Batch.
	 * @param string $status   One of Schema::BATCH_STATUSES.
	 * @return bool Whether a row was updated.
	 */
	public function set_status( int $batch_id, string $status ): bool {
		global $wpdb;
		self::assert_in( $status, Schema::BATCH_STATUSES, 'status' );
		$data   = [ 'status' => $status ];
		$format = [ '%s' ];
		if ( ! in_array( $status, [ 'queued', 'running', 'undoing' ], true ) ) {
			$data['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$format[]             = '%s';
		}
		// update() prepares the statement from the formats.
		return 1 === $wpdb->update( $this->registry->table( Schema::BATCHES ), $data, [ 'batch_id' => $batch_id ], $format, [ '%d' ] );
	}

	/**
	 * Materialise a selection into pending items, INSERT_CHUNK rows per statement.
	 *
	 * @param int                                   $batch_id Batch.
	 * @param array<int, array{0: int, 1: string}> $objects  [ object_id, object_type ] pairs.
	 * @return int Rows written.
	 * @throws \InvalidArgumentException On an invalid id or type.
	 * @throws \RuntimeException When a statement fails.
	 */
	public function materialise_items( int $batch_id, array $objects ): int {
		global $wpdb;
		self::assert_ids( [ $batch_id ], 'batch id' );
		$values = [];
		foreach ( $objects as $object ) {
			if ( ! is_array( $object ) || ! is_int( $object[0] ?? null ) || ! is_string( $object[1] ?? null ) ) {
				throw new \InvalidArgumentException( 'Each item is an [ object_id, object_type ] pair.' );
			}
			self::assert_ids( [ $object[0] ], 'object id' );
			self::assert_in( $object[1], Schema::OBJECT_TYPES, 'object type' );
			$values[] = [ $object[0], $object[1] ];
		}
		$table   = $this->registry->table( Schema::ITEMS );
		$written = 0;
		foreach ( array_chunk( $values, self::INSERT_CHUNK ) as $chunk ) {
			$args = [ $table ];
			foreach ( $chunk as $pair ) {
				array_push( $args, $batch_id, $pair[0], $pair[1] );
			}
			$rows = implode( ', ', array_fill( 0, count( $chunk ), "(%d, %d, %s, 'pending', 0)" ) );
			// $rows is only a repeated placeholder group; every value is a prepared argument.
			$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (batch_id, object_id, object_type, state, attempts) VALUES {$rows}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $result ) {
				throw new \RuntimeException( 'The batch items could not be recorded.' );
			}
			$written += (int) $result;
		}
		return $written;
	}

	/**
	 * Claim the next pending items of a batch, oldest first: each claimed
	 * item's attempts is incremented. An item stays pending until it is
	 * marked, so a claim abandoned by a chunk that died is simply claimed
	 * again by the next run, up to MAX_ATTEMPTS times; see fail_exhausted().
	 * Action Scheduler runs one chunk at a time, so two claims do not race.
	 *
	 * @param int $batch_id Batch.
	 * @param int $limit    Most items to claim, 1 to MAX_CLAIM.
	 * @return array<int, array{item_id: int, object_id: int, object_type: string, attempts: int}> Claimed items, attempts after the claim.
	 * @throws \RuntimeException When the claim cannot be written.
	 */
	public function claim_items( int $batch_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( self::MAX_CLAIM, $limit ) );
		$table = $this->registry->table( Schema::ITEMS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, object_id, object_type, attempts FROM %i WHERE batch_id = %d AND state = 'pending' AND attempts < %d ORDER BY item_id ASC LIMIT %d",
				$table,
				$batch_id,
				self::MAX_ATTEMPTS,
				$limit
			),
			ARRAY_A
		);
		if ( [] === $rows ) {
			return [];
		}
		$ids     = array_map( static fn( array $row ): int => (int) $row['item_id'], $rows );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts = attempts + 1 WHERE batch_id = %d AND state = 'pending' AND item_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $table, $batch_id ], $ids ) ) );
		if ( false === $claimed ) {
			throw new \RuntimeException( 'The batch items could not be claimed.' );
		}
		return array_map(
			static fn( array $row ): array => [
				'item_id'     => (int) $row['item_id'],
				'object_id'   => (int) $row['object_id'],
				'object_type' => (string) $row['object_type'],
				'attempts'    => (int) $row['attempts'] + 1,
			],
			$rows
		);
	}

	/**
	 * Mark items of a batch with a state.
	 *
	 * @param int   $batch_id Batch.
	 * @param int[] $item_ids Items.
	 * @param string $state   One of Schema::ITEM_STATES.
	 * @return int Rows updated.
	 * @throws \InvalidArgumentException On an invalid id or state.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function mark_items( int $batch_id, array $item_ids, string $state ): int {
		global $wpdb;
		self::assert_in( $state, Schema::ITEM_STATES, 'item state' );
		if ( [] === $item_ids ) {
			return 0;
		}
		self::assert_ids( $item_ids, 'item id' );
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s WHERE batch_id = %d AND item_id IN (' . implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) ) . ')', array_merge( [ $this->registry->table( Schema::ITEMS ), $state, $batch_id ], array_values( $item_ids ) ) ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be updated.' );
		}
		return (int) $result;
	}

	/**
	 * Mark failed every pending item of a batch that has been claimed MAX_ATTEMPTS times.
	 *
	 * @param int $batch_id Batch.
	 * @return int Items given up on.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function fail_exhausted( int $batch_id ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed' WHERE batch_id = %d AND state = 'pending' AND attempts >= %d", $this->registry->table( Schema::ITEMS ), $batch_id, self::MAX_ATTEMPTS ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be updated.' );
		}
		return (int) $result;
	}

	/**
	 * Number of a batch's items in a state.
	 *
	 * @param int    $batch_id Batch.
	 * @param string $state    One of Schema::ITEM_STATES.
	 */
	public function count_items( int $batch_id, string $state ): int {
		global $wpdb;
		self::assert_in( $state, Schema::ITEM_STATES, 'item state' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state = %s', $this->registry->table( Schema::ITEMS ), $batch_id, $state ) );
	}

	/**
	 * Add to a batch's progress counters in one statement, so concurrent
	 * readers never see a partial update.
	 *
	 * @param int $batch_id Batch.
	 * @param int $done     Items finished, whatever their outcome.
	 * @param int $changed  Items changed.
	 * @param int $failed   Items failed.
	 * @return bool Whether the batch row was updated.
	 * @throws \InvalidArgumentException On a negative increment.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function increment_counters( int $batch_id, int $done, int $changed, int $failed ): bool {
		global $wpdb;
		self::assert_ids( [ $done, $changed, $failed ], 'counter increment', true );
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET done_items = done_items + %d, changed_items = changed_items + %d, failed_items = failed_items + %d WHERE batch_id = %d', $this->registry->table( Schema::BATCHES ), $done, $changed, $failed, $batch_id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch progress could not be updated.' );
		}
		return 1 === (int) $result;
	}

	/**
	 * The earliest batch before `$before_id` that the same user created with
	 * the same request id since a moment, or null (T31: two submissions of
	 * one request that raced past the replay check; the earlier one wins).
	 *
	 * @param string $uuid      Client request id.
	 * @param int    $user_id   Initiating user.
	 * @param int    $before_id Only batches with a lower id.
	 * @param string $since_gmt Only batches created at or after this GMT time, `Y-m-d H:i:s`.
	 */
	public function earlier_request( string $uuid, int $user_id, int $before_id, string $since_gmt ): ?int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT batch_id FROM %i WHERE uuid = %s AND user_id = %d AND batch_id < %d AND created_gmt >= %s ORDER BY batch_id ASC LIMIT 1', $this->registry->table( Schema::BATCHES ), strtolower( $uuid ), $user_id, $before_id, $since_gmt ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Remove a batch and its items. Only for undoing a creation that did not
	 * complete (T31), before any work was queued for it; a batch that has
	 * run is kept for its history.
	 *
	 * @param int $batch_id Batch.
	 * @throws \InvalidArgumentException On an invalid id.
	 * @throws \RuntimeException When a statement fails.
	 */
	public function discard( int $batch_id ): void {
		global $wpdb;
		self::assert_ids( [ $batch_id ], 'batch id' );
		foreach ( [ Schema::ITEMS, Schema::BATCHES ] as $table ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE batch_id = %d', $this->registry->table( $table ), $batch_id ) ) ) {
				throw new \RuntimeException( 'The unfinished batch could not be removed.' );
			}
		}
	}

	/**
	 * Encode a structure for a LONGTEXT column.
	 *
	 * @param array $value Value.
	 */
	private static function json( array $value ): string {
		return ValueCodec::encode( $value );
	}

	/**
	 * Refuse a value outside its allow-list.
	 *
	 * @param string   $value   Value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $what    Name for the message.
	 * @throws \InvalidArgumentException When the value is not allowed.
	 */
	private static function assert_in( string $value, array $allowed, string $what ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown %s "%s".', $what, $value ) ) );
		}
	}

	/**
	 * Refuse anything but positive integers (or zero, when allowed).
	 *
	 * @param array  $ids        Values.
	 * @param string $what       Name for the message.
	 * @param bool   $allow_zero Whether zero is allowed.
	 * @throws \InvalidArgumentException When a value is not allowed.
	 */
	private static function assert_ids( array $ids, string $what, bool $allow_zero = false ): void {
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < ( $allow_zero ? 0 : 1 ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Invalid %s.', $what ) ) );
			}
		}
	}
}
