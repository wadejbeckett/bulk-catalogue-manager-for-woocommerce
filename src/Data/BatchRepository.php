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
	 * Seconds after which a claim on a still-pending item is stale and the
	 * item may be claimed again (§12.4: a chunk that died mid-way).
	 */
	public const LEASE_SECONDS = 300;

	/**
	 * claim_chunk(): any pending item.
	 */
	public const CLAIM_ANY = 'any';

	/**
	 * claim_chunk(): any pending item but a variation (products and terms).
	 */
	public const CLAIM_PRODUCTS = 'products';

	/**
	 * claim_chunk(): pending variations only.
	 */
	public const CLAIM_VARIATIONS = 'variations';

	/**
	 * Longest stored item message or batch note (the column width).
	 */
	public const MESSAGE_LENGTH = 255;

	/**
	 * The filters history() and history_count() accept.
	 */
	public const LIST_FILTERS = [ 'status', 'user_id', 'after', 'before', 'action' ];

	/**
	 * Length of the `actions` column (T54).
	 */
	public const ACTIONS_LENGTH = 255;

	/**
	 * An action id as the `actions` column and the action filter take it.
	 */
	private const ACTION_ID = '/^[a-z][a-z0-9_]{0,63}$/';

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
	 * Create a batch row with status `queued` and zeroed counters. The
	 * `actions` column (T54) is derived here from the operation list — the
	 * distinct `action` ids in order, delimited `,adjust,round,` — so every
	 * batch, whoever creates it, can be filtered by action type.
	 *
	 * @param string   $uuid        Client request id (36 characters).
	 * @param int      $user_id     Initiating user.
	 * @param string   $source      One of Schema::BATCH_SOURCES.
	 * @param array    $operations  Operation list, stored as JSON.
	 * @param array    $selection   Snapshot of the filter or id list, stored as JSON.
	 * @param int      $total_items Number of items to materialise.
	 * @param int|null $undo_of     The batch this one undoes, for source `undo`.
	 * @param int|null $previewed   The count the preview of a filter selection showed (T38), or null.
	 * @return int The new batch_id.
	 * @throws \InvalidArgumentException On an invalid argument.
	 * @throws \RuntimeException When the row is not written.
	 */
	public function create( string $uuid, int $user_id, string $source, array $operations, array $selection, int $total_items, ?int $undo_of = null, ?int $previewed = null ): int {
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
			'actions'     => self::actions( $operations ),
		];
		$format = [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];
		if ( null !== $undo_of ) {
			$data['undo_of'] = $undo_of;
			$format[]        = '%d';
		}
		if ( null !== $previewed ) {
			self::assert_ids( [ $previewed ], 'previewed count', true );
			$data['previewed_items'] = $previewed;
			$format[]                = '%d';
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
	 * @return array{batch_id: int, uuid: string, user_id: int, created_gmt: string, finished_gmt: ?string, status: string, source: string, undo_of: ?int, operations: array, selection: array, total_items: int, previewed_items: ?int, done_items: int, changed_items: int, failed_items: int, chunk_size: int, note: string, actions: list<string>, pruned_gmt: ?string}|null
	 */
	public function find( int $batch_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %d', $this->registry->table( Schema::BATCHES ), $batch_id ), ARRAY_A );
		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * A batch's status alone, or null for no such batch: the cheap read the
	 * chunk runner repeats to notice a cancellation (T35).
	 *
	 * @param int $batch_id Batch.
	 */
	public function status( int $batch_id ): ?string {
		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE batch_id = %d', $this->registry->table( Schema::BATCHES ), $batch_id ) );
		return null === $status ? null : (string) $status;
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
	 * An object may carry its own operation list as a third element (T56:
	 * each item of an undo batch puts that object's own old values back),
	 * stored as JSON in the item's `operations` column; either every object
	 * of a call carries one or none does, and an item without one takes the
	 * batch's list.
	 *
	 * @param int                                                              $batch_id Batch.
	 * @param array<int, array{0: int, 1: string, 2?: list<array<string, mixed>>}> $objects  [ object_id, object_type ] pairs, or [ object_id, object_type, operations ] triples.
	 * @return int Rows written.
	 * @throws \InvalidArgumentException On an invalid id or type.
	 * @throws \RuntimeException When a statement fails.
	 */
	public function materialise_items( int $batch_id, array $objects ): int {
		global $wpdb;
		self::assert_ids( [ $batch_id ], 'batch id' );
		$values = [];
		$own    = null;
		foreach ( $objects as $object ) {
			if ( ! is_array( $object ) || ! is_int( $object[0] ?? null ) || ! is_string( $object[1] ?? null ) ) {
				throw new \InvalidArgumentException( 'Each item is an [ object_id, object_type ] pair.' );
			}
			self::assert_ids( [ $object[0] ], 'object id' );
			self::assert_in( $object[1], Schema::OBJECT_TYPES, 'object type' );
			$has   = array_key_exists( 2, $object );
			$own ??= $has;
			if ( $own !== $has || ( $has && ( ! is_array( $object[2] ) || [] === $object[2] ) ) ) {
				throw new \InvalidArgumentException( 'Either every item carries its own non-empty operation list or none does.' );
			}
			$values[] = $has ? [ $object[0], $object[1], self::json( $object[2] ) ] : [ $object[0], $object[1] ];
		}
		$table   = $this->registry->table( Schema::ITEMS );
		$written = 0;
		foreach ( array_chunk( $values, self::INSERT_CHUNK ) as $chunk ) {
			$args = [ $table ];
			foreach ( $chunk as $pair ) {
				array_push( $args, $batch_id, ...$pair );
			}
			if ( true === $own ) {
				$rows = implode( ', ', array_fill( 0, count( $chunk ), "(%d, %d, %s, 'pending', 0, %s)" ) );
				// $rows is only a repeated placeholder group; every value is a prepared argument.
				$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (batch_id, object_id, object_type, state, attempts, operations) VALUES {$rows}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rows is only literal value tuples of placeholders; every value is an argument.
			} else {
				$rows = implode( ', ', array_fill( 0, count( $chunk ), "(%d, %d, %s, 'pending', 0)" ) );
				// $rows is only a repeated placeholder group; every value is a prepared argument.
				$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (batch_id, object_id, object_type, state, attempts) VALUES {$rows}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rows is only literal value tuples of placeholders; every value is an argument.
			}
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
	 * The chunk runner (T34) uses claim_chunk() instead: one locking UPDATE
	 * with a lease, so a live claim is never taken twice.
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
	 * @param int         $batch_id Batch.
	 * @param int[]       $item_ids Items.
	 * @param string      $state    One of Schema::ITEM_STATES.
	 * @param string|null $message  The plain reason stored with the items (cut to MESSAGE_LENGTH), or null to leave it.
	 * @return int Rows updated.
	 * @throws \InvalidArgumentException On an invalid id or state.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function mark_items( int $batch_id, array $item_ids, string $state, ?string $message = null ): int {
		global $wpdb;
		self::assert_in( $state, Schema::ITEM_STATES, 'item state' );
		if ( [] === $item_ids ) {
			return 0;
		}
		self::assert_ids( $item_ids, 'item id' );
		if ( null === $message ) {
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s WHERE batch_id = %d AND item_id IN (' . implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) ) . ')', array_merge( [ $this->registry->table( Schema::ITEMS ), $state, $batch_id ], array_values( $item_ids ) ) ) );
		} else {
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s, message = %s WHERE batch_id = %d AND item_id IN (' . implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) ) . ')', array_merge( [ $this->registry->table( Schema::ITEMS ), $state, self::cut( $message ), $batch_id ], array_values( $item_ids ) ) ) );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be updated.' );
		}
		return (int) $result;
	}

	/**
	 * Mark failed every pending item of a batch that has been claimed MAX_ATTEMPTS times.
	 * With `$stale_before`, only items whose last claim is older than that
	 * time (or that hold none), so an item a live chunk is still working on
	 * is left alone; `$message` is then stored as the plain reason.
	 *
	 * @param int         $batch_id     Batch.
	 * @param string|null $stale_before GMT `Y-m-d H:i:s`: claims before it are stale; null for any item.
	 * @param string      $message      The plain reason stored with each item given up on.
	 * @return int Items given up on.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function fail_exhausted( int $batch_id, ?string $stale_before = null, string $message = '' ): int {
		global $wpdb;
		$table = $this->registry->table( Schema::ITEMS );
		if ( null === $stale_before ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed' WHERE batch_id = %d AND state = 'pending' AND attempts >= %d", $table, $batch_id, self::MAX_ATTEMPTS ) );
		} else {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed', message = %s WHERE batch_id = %d AND state = 'pending' AND attempts >= %d AND (claimed_gmt IS NULL OR claimed_gmt < %s)", $table, self::cut( $message ), $batch_id, self::MAX_ATTEMPTS, $stale_before ) );
		}
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
	 * Claim the next chunk of a batch's pending items with ONE locking UPDATE
	 * (§12.4, T34): up to `$limit` items, oldest first, that are pending,
	 * under MAX_ATTEMPTS and either unclaimed or holding a claim older than
	 * `$stale_before` are stamped with `$token` and `$now_gmt` and have their
	 * attempts incremented, in the one statement, so the row locks InnoDB
	 * takes for the UPDATE are the claim. The claimed rows are then read back
	 * by their token. A stale claim is thereby released and retried by the
	 * next claim; an item under a live claim is not touched.
	 *
	 * @param int    $batch_id     Batch.
	 * @param int    $limit        Most items to claim, 1 to MAX_CLAIM.
	 * @param string $token        32 lower-case hex characters, unique to this claim.
	 * @param string $now_gmt      GMT `Y-m-d H:i:s` stamped on the claim.
	 * @param string $stale_before GMT `Y-m-d H:i:s`: an earlier claim is stale.
	 * @param string $kind         CLAIM_ANY (every item), CLAIM_PRODUCTS (every item but variations) or CLAIM_VARIATIONS (T71).
	 * @return array<int, array{item_id: int, object_id: int, object_type: string, attempts: int}> Claimed items, attempts after the claim.
	 * @throws \InvalidArgumentException On a malformed token or an unknown kind.
	 * @throws \RuntimeException When the claim cannot be written.
	 */
	public function claim_chunk( int $batch_id, int $limit, string $token, string $now_gmt, string $stale_before, string $kind = self::CLAIM_ANY ): array {
		global $wpdb;
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			throw new \InvalidArgumentException( 'A claim token is 32 lower-case hex characters.' );
		}
		self::assert_in( $kind, [ self::CLAIM_ANY, self::CLAIM_PRODUCTS, self::CLAIM_VARIATIONS ], 'claim kind' );
		$limit = max( 1, min( self::MAX_CLAIM, $limit ) );
		$table = $this->registry->table( Schema::ITEMS );
		$sql   = "UPDATE %i SET claim_token = %s, claimed_gmt = %s, attempts = attempts + 1 WHERE batch_id = %d AND state = 'pending' AND attempts < %d AND (claimed_gmt IS NULL OR claimed_gmt < %s)";
		$args  = [ $table, $token, $now_gmt, $batch_id, self::MAX_ATTEMPTS, $stale_before ];
		if ( self::CLAIM_ANY !== $kind ) {
			$sql   .= self::CLAIM_VARIATIONS === $kind ? ' AND object_type = %s' : ' AND object_type <> %s';
			$args[] = 'variation';
		}
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literals and placeholders; every value is an argument.
		$claimed = $wpdb->query( $wpdb->prepare( $sql . ' ORDER BY item_id ASC LIMIT %d', ...$args ) );
		if ( false === $claimed ) {
			throw new \RuntimeException( 'The batch items could not be claimed.' );
		}
		if ( 0 === (int) $claimed ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, object_id, object_type, attempts FROM %i WHERE batch_id = %d AND state = 'pending' AND claim_token = %s ORDER BY item_id ASC LIMIT %d", $table, $batch_id, $token, $limit ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => [
				'item_id'     => (int) $row['item_id'],
				'object_id'   => (int) $row['object_id'],
				'object_type' => (string) $row['object_type'],
				'attempts'    => (int) $row['attempts'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * Claim one item of a chunk for this run alone, just before writing it
	 * (§12.4): pending to working, only while the chunk's claim is still this
	 * run's. A run whose lease went stale and was taken over gets false and
	 * leaves the item to the run that holds it now.
	 *
	 * @param int    $batch_id Batch.
	 * @param int    $item_id  Item.
	 * @param string $token    The chunk's claim token.
	 * @return bool Whether this run holds the item.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function claim_item( int $batch_id, int $item_id, string $token ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s WHERE batch_id = %d AND item_id = %d AND claim_token = %s AND state = %s', $this->registry->table( Schema::ITEMS ), Schema::ITEM_WORKING, $batch_id, $item_id, $token, 'pending' ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch item could not be claimed.' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Put back to pending every working item of a batch whose claim is
	 * older than `$stale_before`: the run writing it died, so the next claim
	 * may take it over (and the journal's intent rows make that retry safe).
	 *
	 * @param int    $batch_id     Batch.
	 * @param string $stale_before GMT `Y-m-d H:i:s`: claims before it are stale.
	 * @return int Items put back.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function reset_stale_working( int $batch_id, string $stale_before ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s WHERE batch_id = %d AND state = %s AND claimed_gmt < %s', $this->registry->table( Schema::ITEMS ), 'pending', $batch_id, Schema::ITEM_WORKING, $stale_before ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be updated.' );
		}
		return (int) $result;
	}

	/**
	 * Number of a batch's items not yet finished: pending, or working under a run.
	 *
	 * @param int $batch_id Batch.
	 */
	public function count_open( int $batch_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state IN (%s, %s)', $this->registry->table( Schema::ITEMS ), $batch_id, 'pending', Schema::ITEM_WORKING ) );
	}

	/**
	 * Release a claim's still-pending items at once, keeping their attempts,
	 * so the next run may claim them without waiting for the lease to go stale.
	 *
	 * @param int    $batch_id Batch.
	 * @param string $token    The claim's token.
	 * @return int Items released.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function release_claim( int $batch_id, string $token ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET claim_token = NULL, claimed_gmt = NULL WHERE batch_id = %d AND state = 'pending' AND claim_token = %s", $this->registry->table( Schema::ITEMS ), $batch_id, $token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be released.' );
		}
		return (int) $result;
	}

	/**
	 * Number of a batch's finished items with something to report: failed,
	 * skipped, or done with a message (a step refused on an object whose other
	 * changes were applied). Zero means the batch is complete (§12.6).
	 *
	 * @param int $batch_id Batch.
	 */
	public function count_reported( int $batch_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state <> 'pending' AND (state <> 'done' OR message <> '')", $this->registry->table( Schema::ITEMS ), $batch_id ) );
	}

	/**
	 * Move a batch from one status to another only if it is still in the
	 * first, so a status set meanwhile (a cancellation, T35) is never
	 * overwritten. A final status also stamps finished_gmt, as set_status()
	 * does, except a move out of `undoing` (T56): that batch finished before
	 * its undo started, and keeps the time it finished.
	 *
	 * @param int    $batch_id Batch.
	 * @param string $from     The status the batch must be in.
	 * @param string $to       The new status.
	 * @return bool Whether the batch moved.
	 * @throws \InvalidArgumentException On an unknown status.
	 */
	public function transition( int $batch_id, string $from, string $to ): bool {
		global $wpdb;
		self::assert_in( $from, Schema::BATCH_STATUSES, 'status' );
		self::assert_in( $to, Schema::BATCH_STATUSES, 'status' );
		$data   = [ 'status' => $to ];
		$format = [ '%s' ];
		if ( 'undoing' !== $from && ! in_array( $to, [ 'queued', 'running', 'undoing' ], true ) ) {
			$data['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$format[]             = '%s';
		}
		// update() prepares the statement from the formats.
		return 1 === $wpdb->update(
			$this->registry->table( Schema::BATCHES ),
			$data,
			[
				'batch_id' => $batch_id,
				'status'   => $from,
			],
			$format,
			[ '%d', '%s' ]
		);
	}

	/**
	 * Replace a batch's note, the plain sentence its report and history row
	 * show (T38: why an inline batch was given up on).
	 *
	 * @param int    $batch_id Batch.
	 * @param string $note     The plain sentence, cut to MESSAGE_LENGTH.
	 * @return bool Whether the batch row was updated.
	 */
	public function record_note( int $batch_id, string $note ): bool {
		global $wpdb;
		// update() prepares the statement from the formats.
		return 1 === $wpdb->update( $this->registry->table( Schema::BATCHES ), [ 'note' => self::cut( $note ) ], [ 'batch_id' => $batch_id ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * Mark failed, with a plain reason, every item of a batch still pending,
	 * claimed or not (T38: an inline batch whose request died mid-write).
	 *
	 * @param int    $batch_id Batch.
	 * @param string $message  The plain reason stored with each item.
	 * @return int Items marked.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function fail_pending( int $batch_id, string $message ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed', message = %s WHERE batch_id = %d AND state = 'pending'", $this->registry->table( Schema::ITEMS ), self::cut( $message ), $batch_id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be updated.' );
		}
		return (int) $result;
	}

	/**
	 * Record the chunk size the runner uses for the rest of a batch, and why.
	 *
	 * @param int         $batch_id Batch.
	 * @param int         $size     Items per chunk, 1 to MAX_CLAIM.
	 * @param string|null $note     The plain reason (cut to MESSAGE_LENGTH), or null to leave the note.
	 * @return bool Whether the batch row was updated.
	 * @throws \InvalidArgumentException On a size out of range.
	 */
	public function record_chunk_size( int $batch_id, int $size, ?string $note = null ): bool {
		global $wpdb;
		if ( $size < 1 || $size > self::MAX_CLAIM ) {
			throw new \InvalidArgumentException( 'A chunk size is 1 to 100 items.' );
		}
		$data   = [ 'chunk_size' => $size ];
		$format = [ '%d' ];
		if ( null !== $note ) {
			$data['note'] = self::cut( $note );
			$format[]     = '%s';
		}
		// update() prepares the statement from the formats.
		return 1 === $wpdb->update( $this->registry->table( Schema::BATCHES ), $data, [ 'batch_id' => $batch_id ], $format, [ '%d' ] );
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
	 * When a chunk last claimed items of a batch (T35): the latest
	 * claimed_gmt among its items, or null when none was ever claimed. A
	 * finished item keeps the time of the claim that worked it, so this is
	 * the time the queue last ran for the batch.
	 *
	 * @param int $batch_id Batch.
	 */
	public function last_claimed_gmt( int $batch_id ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT claimed_gmt FROM %i WHERE batch_id = %d AND claimed_gmt IS NOT NULL ORDER BY claimed_gmt DESC LIMIT 1', $this->registry->table( Schema::ITEMS ), $batch_id ) );
		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * The reasons of a batch's failed and skipped items that the journal
	 * holds no row for (T36: an item given up on after three tries, a write
	 * that threw, a category item), grouped by message with the number of
	 * items carrying each, most first.
	 *
	 * @param int $batch_id Batch.
	 * @param int $limit    Most reasons, 1 to 100.
	 * @return list<array{message: string, objects: int}>
	 */
	public function unjournalled_reasons( int $batch_id, int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.message AS message, COUNT(*) AS objects FROM %i AS i WHERE i.batch_id = %d AND i.state IN ('failed', 'skipped') AND i.message <> '' AND NOT EXISTS (SELECT c.change_id FROM %i AS c WHERE c.batch_id = i.batch_id AND c.object_id = i.object_id) GROUP BY i.message ORDER BY objects DESC LIMIT %d",
				$this->registry->table( Schema::ITEMS ),
				$batch_id,
				$this->registry->table( Schema::CHANGES ),
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => [
				'message' => (string) $row['message'],
				'objects' => (int) $row['objects'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * One page of batches, newest first, for the history screen (T35; T54
	 * adds the action-type filter).
	 *
	 * @param array{status?: string, user_id?: int, after?: string, before?: string, action?: string} $filters  Status, initiating user, created at or after / before a GMT `Y-m-d H:i:s` time, and an action id the operations use.
	 * @param int                                                                                     $page     Page, from 1.
	 * @param int                                                                                     $per_page Rows per page, 1 to 100.
	 * @return list<array<string, mixed>> Typed rows as find() returns them, with an empty selection.
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	public function history( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		[ $where, $args ] = $this->conditions( $filters );
		$per_page         = max( 1, min( self::MAX_CLAIM, $per_page ) );
		$args[]           = $per_page;
		$args[]           = ( max( 1, $page ) - 1 ) * $per_page;
		// Every column but the selection snapshot, which can hold 100,000 ids and is not shown in a list.
		// $where holds only fixed column conditions with placeholders; every value is a prepared argument.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, uuid, user_id, created_gmt, finished_gmt, status, source, undo_of, operations, total_items, previewed_items, done_items, changed_items, failed_items, chunk_size, note, actions, pruned_gmt FROM %i WHERE {$where} ORDER BY batch_id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literal conditions and placeholders from conditions(); every value is an argument.
		return array_map( [ self::class, 'typed' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Number of batches history() pages through.
	 *
	 * @param array{status?: string, user_id?: int, after?: string, before?: string, action?: string} $filters As history().
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	public function history_count( array $filters ): int {
		global $wpdb;
		[ $where, $args ] = $this->conditions( $filters );
		// $where holds only fixed column conditions with placeholders; every value is a prepared argument.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literal conditions and placeholders from conditions(); every value is an argument.
	}

	/**
	 * The undo batch that completed undoing a batch, or null (T54, §4.6:
	 * "already undone"). A redo is an undo of the undo batch, so it names the
	 * undo batch, never the original.
	 *
	 * @param int $batch_id Batch.
	 */
	public function undo_of( int $batch_id ): ?int {
		return $this->undone_by( [ $batch_id ] )[ $batch_id ] ?? null;
	}

	/**
	 * For a page of batches in one read, the latest undo batch that
	 * completed undoing each: batch id => undo batch id, batches without one
	 * left out.
	 *
	 * @param list<int> $batch_ids Batches.
	 * @return array<int, int>
	 * @throws \InvalidArgumentException On an id that is not a positive integer.
	 */
	public function undone_by( array $batch_ids ): array {
		global $wpdb;
		$batch_ids = array_values( array_unique( $batch_ids ) );
		if ( [] === $batch_ids ) {
			return [];
		}
		self::assert_ids( $batch_ids, 'batch id' );
		$in   = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );
		$args = array_merge( [ $this->registry->table( Schema::BATCHES ) ], $batch_ids );
		// $in holds only %d placeholders, one per id; every id is a prepared argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, undo_of FROM %i WHERE undo_of IN ({$in}) AND source = 'undo' AND status = 'complete' ORDER BY batch_id ASC", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders; every id is an argument.
		$undone = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$undone[ (int) $row['undo_of'] ] = (int) $row['batch_id'];
		}
		return $undone;
	}

	/**
	 * The WHERE clause and its arguments (the table first) for history() and history_count().
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<mixed>}
	 * @throws \InvalidArgumentException On an unknown filter or value.
	 */
	private function conditions( array $filters ): array {
		global $wpdb;
		$unknown = array_diff( array_keys( $filters ), self::LIST_FILTERS );
		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown batch filter "%s".', (string) reset( $unknown ) ) ) );
		}
		$where = [ 'batch_id > 0' ];
		$args  = [ $this->registry->table( Schema::BATCHES ) ];
		if ( isset( $filters['status'] ) ) {
			self::assert_in( (string) $filters['status'], Schema::BATCH_STATUSES, 'status' );
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( isset( $filters['user_id'] ) ) {
			self::assert_ids( [ $filters['user_id'] ], 'user id' );
			$where[] = 'user_id = %d';
			$args[]  = $filters['user_id'];
		}
		if ( isset( $filters['action'] ) ) {
			if ( ! is_string( $filters['action'] ) || 1 !== preg_match( self::ACTION_ID, $filters['action'] ) ) {
				throw new \InvalidArgumentException( 'An action filter is an action id.' );
			}
			$where[] = 'actions LIKE %s';
			$args[]  = '%,' . $wpdb->esc_like( $filters['action'] ) . ',%';
		}
		foreach ( [
			'after'  => 'created_gmt >= %s',
			'before' => 'created_gmt < %s',
		] as $key => $condition ) {
			if ( isset( $filters[ $key ] ) ) {
				if ( ! is_string( $filters[ $key ] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $filters[ $key ] ) ) {
					throw new \InvalidArgumentException( 'A date filter is a GMT Y-m-d H:i:s time.' );
				}
				$where[] = $condition;
				$args[]  = $filters[ $key ];
			}
		}
		return [ implode( ' AND ', $where ), $args ];
	}

	/**
	 * A batch row typed, operations and selection decoded (an absent selection as empty).
	 *
	 * @param array $row Row from the database.
	 * @return array{batch_id: int, uuid: string, user_id: int, created_gmt: string, finished_gmt: ?string, status: string, source: string, undo_of: ?int, operations: array, selection: array, total_items: int, previewed_items: ?int, done_items: int, changed_items: int, failed_items: int, chunk_size: int, note: string, actions: list<string>, pruned_gmt: ?string}
	 */
	private static function typed( array $row ): array {
		return [
			'batch_id'        => (int) $row['batch_id'],
			'uuid'            => (string) $row['uuid'],
			'user_id'         => (int) $row['user_id'],
			'created_gmt'     => (string) $row['created_gmt'],
			'finished_gmt'    => null === ( $row['finished_gmt'] ?? null ) ? null : (string) $row['finished_gmt'],
			'status'          => (string) $row['status'],
			'source'          => (string) $row['source'],
			'undo_of'         => null === ( $row['undo_of'] ?? null ) ? null : (int) $row['undo_of'],
			'operations'      => (array) ValueCodec::decode( (string) $row['operations'] ),
			'selection'       => isset( $row['selection'] ) ? (array) ValueCodec::decode( (string) $row['selection'] ) : [],
			'total_items'     => (int) $row['total_items'],
			'previewed_items' => null === ( $row['previewed_items'] ?? null ) ? null : (int) $row['previewed_items'],
			'done_items'      => (int) $row['done_items'],
			'changed_items'   => (int) $row['changed_items'],
			'failed_items'    => (int) $row['failed_items'],
			'chunk_size'      => (int) ( $row['chunk_size'] ?? 0 ),
			'note'            => (string) ( $row['note'] ?? '' ),
			'actions'         => array_values( array_filter( explode( ',', (string) ( $row['actions'] ?? '' ) ), static fn( string $id ): bool => '' !== $id ) ),
			'pruned_gmt'      => null === ( $row['pruned_gmt'] ?? null ) ? null : (string) $row['pruned_gmt'],
		];
	}

	/**
	 * The `actions` column for an operation list: its distinct action ids in
	 * order, with a comma at each end (`,adjust,round,`), or '' when it names
	 * none. An entry without a well-formed id is left out, and an id that
	 * would overflow the column is dropped (every version 1 action id
	 * together takes well under a third of it).
	 *
	 * @param array $operations Operation list, `[ { action, args } ]`.
	 */
	public static function actions( array $operations ): string {
		$ids = [];
		foreach ( $operations as $operation ) {
			$id = is_array( $operation ) ? ( $operation['action'] ?? null ) : null;
			if ( is_string( $id ) && 1 === preg_match( self::ACTION_ID, $id ) && ! in_array( $id, $ids, true ) && strlen( ',' . implode( ',', [ ...$ids, $id ] ) . ',' ) <= self::ACTIONS_LENGTH ) {
				$ids[] = $id;
			}
		}
		return [] === $ids ? '' : ',' . implode( ',', $ids ) . ',';
	}

	/**
	 * A message cut to the column width.
	 *
	 * @param string $message Message.
	 */
	private static function cut( string $message ): string {
		return mb_substr( $message, 0, self::MESSAGE_LENGTH );
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

	/**
	 * Batches due for pruning (T60, SPEC §9.5): finished before a moment, in
	 * any status but queued, running or undoing (never touched whatever their
	 * age), and still holding item or journal rows, lowest id first. A batch
	 * whose rows are all gone is not returned again.
	 *
	 * @param string $finished_before GMT `Y-m-d H:i:s`; only batches finished earlier.
	 * @param int    $limit           Most batches, 1 to MAX_CLAIM.
	 * @return list<int>
	 * @throws \InvalidArgumentException On a malformed time.
	 */
	public function prunable( string $finished_before, int $limit ): array {
		global $wpdb;
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $finished_before ) ) {
			throw new \InvalidArgumentException( 'A prune limit is a GMT Y-m-d H:i:s time.' );
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT b.batch_id AS batch_id FROM %i AS b WHERE b.finished_gmt IS NOT NULL AND b.finished_gmt < %s AND b.status NOT IN ('queued', 'running', 'undoing') AND (EXISTS (SELECT i.item_id FROM %i AS i WHERE i.batch_id = b.batch_id) OR EXISTS (SELECT c.change_id FROM %i AS c WHERE c.batch_id = b.batch_id)) ORDER BY b.batch_id ASC LIMIT %d",
				$this->registry->table( Schema::BATCHES ),
				$finished_before,
				$this->registry->table( Schema::ITEMS ),
				$this->registry->table( Schema::CHANGES ),
				max( 1, min( self::MAX_CLAIM, $limit ) )
			)
		);
		return array_map( 'intval', is_array( $ids ) ? array_values( $ids ) : [] );
	}

	/**
	 * Mark batches pruned (T60): their note becomes the plain reason they can
	 * no longer be undone and `pruned_gmt` (schema version 5) is stamped once
	 * (a later mark keeps the first time); Reversibility and the undo planner
	 * read it as the `pruned` signal. A queued, running or undoing batch is
	 * never marked. Counters, status and every other column are left alone.
	 *
	 * @param list<int> $batch_ids Batches.
	 * @param string    $note      The plain reason, cut to MESSAGE_LENGTH.
	 * @param string    $now_gmt   GMT `Y-m-d H:i:s` for `pruned_gmt`.
	 * @return int Batch rows updated.
	 * @throws \InvalidArgumentException On an invalid id.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function mark_pruned( array $batch_ids, string $note, string $now_gmt ): int {
		global $wpdb;
		if ( [] === $batch_ids ) {
			return 0;
		}
		self::assert_ids( $batch_ids, 'batch id' );
		$table  = $this->registry->table( Schema::BATCHES );
		$in     = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET note = %s, pruned_gmt = COALESCE(pruned_gmt, %s) WHERE status NOT IN ('queued', 'running', 'undoing') AND batch_id IN ({$in})", array_merge( [ $table, self::cut( $note ), $now_gmt ], array_values( $batch_ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders.
		if ( false === $result ) {
			throw new \RuntimeException( 'The pruned batches could not be marked.' );
		}
		return (int) $result;
	}

	/**
	 * Delete up to a number of item rows of some batches, lowest item id
	 * first (T60: one bounded pruning statement).
	 *
	 * @param list<int> $batch_ids Batches.
	 * @param int       $limit     Most rows, at least 1.
	 * @return int Rows deleted.
	 * @throws \InvalidArgumentException On an invalid id.
	 * @throws \RuntimeException When the statement fails.
	 */
	public function delete_items( array $batch_ids, int $limit ): int {
		global $wpdb;
		if ( [] === $batch_ids ) {
			return 0;
		}
		self::assert_ids( $batch_ids, 'batch id' );
		$in     = implode( ', ', array_fill( 0, count( $batch_ids ), '%d' ) );
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE batch_id IN ({$in}) ORDER BY item_id ASC LIMIT %d", array_merge( [ $this->registry->table( Schema::ITEMS ) ], array_values( $batch_ids ), [ max( 1, $limit ) ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders.
		if ( false === $result ) {
			throw new \RuntimeException( 'The batch items could not be pruned.' );
		}
		return (int) $result;
	}


	/**
	 * The undo batches recorded against a batch (T55), newest first, as
	 * `{ batch_id, status }`, so the planner can refuse one already undone.
	 *
	 * @param int $batch_id The batch undone.
	 * @return list<array{batch_id: int, status: string}>
	 */
	public function undos_of( int $batch_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT batch_id, status FROM %i WHERE undo_of = %d ORDER BY batch_id DESC LIMIT %d', $this->registry->table( Schema::BATCHES ), $batch_id, self::MAX_CLAIM ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => [
				'batch_id' => (int) $row['batch_id'],
				'status'   => (string) $row['status'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * The operation lists some items of a batch carry of their own (T56: an
	 * undo batch's items), decoded, by item id; items without one are left
	 * out, and take the batch's list.
	 *
	 * @param int       $batch_id Batch.
	 * @param list<int> $item_ids Items, at most MAX_CLAIM.
	 * @return array<int, list<array<string, mixed>>>
	 * @throws \InvalidArgumentException On an invalid id.
	 */
	public function item_operations( int $batch_id, array $item_ids ): array {
		global $wpdb;
		if ( [] === $item_ids ) {
			return [];
		}
		self::assert_ids( $item_ids, 'item id' );
		$item_ids = array_slice( array_values( array_unique( $item_ids ) ), 0, self::MAX_CLAIM );
		$in       = implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, operations FROM %i WHERE batch_id = %d AND operations IS NOT NULL AND item_id IN ({$in}) LIMIT %d", array_merge( [ $this->registry->table( Schema::ITEMS ), $batch_id ], $item_ids, [ count( $item_ids ) ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders.
		$found    = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$operations = ValueCodec::decode( (string) $row['operations'] );
			if ( is_array( $operations ) && [] !== $operations ) {
				$found[ (int) $row['item_id'] ] = array_values( $operations );
			}
		}
		return $found;
	}
}
