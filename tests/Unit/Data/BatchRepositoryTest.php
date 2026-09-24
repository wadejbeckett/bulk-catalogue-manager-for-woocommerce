<?php
/**
 * The batch repository's statements against the recording $wpdb: exact SQL,
 * prepared arguments, identifiers only from the registry (SPEC §9.2, §12.4, §13).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Data\Registry;
use PHPUnit\Framework\TestCase;

final class BatchRepositoryTest extends TestCase {
	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function db(): \BulkCatalogueManager\Tests\Support\WpdbStub {
		return $GLOBALS['wpdb'];
	}

	/**
	 * Every executed statement was the output of a prepare() whose template
	 * names its table only through %i and carries no literal prefix.
	 */
	private function assert_all_prepared(): void {
		$this->assertNotEmpty( self::db()->prepared );
		foreach ( self::db()->prepared as [ $template ] ) {
			$this->assertStringContainsString( '%i', $template );
			$this->assertStringNotContainsString( 'wp_', $template );
			$this->assertStringNotContainsString( 'bulkcat_', $template );
		}
		$this->assertCount( count( self::db()->prepared ), self::db()->queries, 'One prepare() per statement.' );
	}

	public function test_create_inserts_a_queued_batch_with_zeroed_counters(): void {
		self::db()->insert_id = 41;
		$id                   = ( new BatchRepository() )->create(
			strtoupper( self::UUID ),
			3,
			'bulk',
			[
				[
					'field' => 'regular_price',
					'op'    => 'set',
					'value' => '0.00',
				],
			],
			[ 'ids' => [ 10, 11 ] ],
			2
		);

		$this->assertSame( 42, $id );
		$this->assertCount( 1, self::db()->writes );
		[ $method, $table, $data, $format ] = self::db()->writes[0];
		$this->assertSame( [ 'insert', 'wp_bulkcat_batches' ], [ $method, $table ] );
		$this->assert_datetime( $data['created_gmt'] );
		unset( $data['created_gmt'] );
		$this->assertSame(
			[
				'uuid'        => self::UUID,
				'user_id'     => 3,
				'status'      => 'queued',
				'source'      => 'bulk',
				'operations'  => '[{"field":"regular_price","op":"set","value":"0.00"}]',
				'selection'   => '{"ids":[10,11]}',
				'total_items' => 2,
				'actions'     => '',
			],
			$data
		);
		$this->assertSame( [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ], $format );
	}

	public function test_an_undo_batch_records_what_it_undoes(): void {
		( new BatchRepository() )->create( self::UUID, 3, 'undo', [], [], 0, 9 );
		[ , , $data, $format ] = self::db()->writes[0];
		$this->assertSame( 9, $data['undo_of'] );
		$this->assertSame( '%d', end( $format ) );
		$this->assertCount( count( $data ), $format );
	}

	public function test_create_refuses_bad_input_before_writing(): void {
		$repo = new BatchRepository();
		$bad  = [
			'not a uuid'          => [ 'abc', 3, 'bulk', null ],
			'unknown source'      => [ self::UUID, 3, 'cron', null ],
			'negative user'       => [ self::UUID, -1, 'bulk', null ],
			'undo without target' => [ self::UUID, 3, 'undo', null ],
			'bulk with target'    => [ self::UUID, 3, 'bulk', 5 ],
			'zero target'         => [ self::UUID, 3, 'undo', 0 ],
		];
		foreach ( $bad as $label => [ $uuid, $user, $source, $undo_of ] ) {
			try {
				$repo->create( $uuid, $user, $source, [], [], 0, $undo_of );
				$this->fail( "$label was accepted." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( [], self::db()->writes, $label );
			}
		}
	}

	public function test_a_failed_insert_is_an_error(): void {
		self::db()->failing = [ 'insert' ];
		try {
			( new BatchRepository() )->create( self::UUID, 3, 'bulk', [], [], 0 );
			$this->fail( 'A failed insert returned an id.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The batch could not be recorded.', $e->getMessage() );
		}
	}

	public function test_find_reads_one_row_back_typed(): void {
		self::db()->responses['SELECT * FROM `wp_bulkcat_batches` WHERE batch_id = 7'] = [
			'batch_id'      => '7',
			'uuid'          => self::UUID,
			'user_id'       => '3',
			'created_gmt'   => '2026-09-23 10:00:00',
			'finished_gmt'  => null,
			'status'        => 'running',
			'source'        => 'undo',
			'undo_of'       => '5',
			'operations'    => '[{"op":"set","value":""}]',
			'selection'     => '{"ids":[1]}',
			'total_items'   => '1',
			'done_items'    => '0',
			'changed_items' => '0',
			'failed_items'  => '0',
		];
		$repo  = new BatchRepository();
		$batch = $repo->find( 7 );

		$this->assertSame( [ 'SELECT * FROM %i WHERE batch_id = %d', [ 'wp_bulkcat_batches', 7 ] ], self::db()->prepared[0] );
		$this->assertSame( 7, $batch['batch_id'] );
		$this->assertSame( 5, $batch['undo_of'] );
		$this->assertNull( $batch['finished_gmt'] );
		$this->assertSame(
			[
				[
					'op'    => 'set',
					'value' => '',
				],
			],
			$batch['operations']
		);
		$this->assertSame( [ 'ids' => [ 1 ] ], $batch['selection'] );
		$this->assertSame( 0, $batch['done_items'] );
		$this->assertSame( [ 0, '' ], [ $batch['chunk_size'], $batch['note'] ], 'A row from before schema 3 reads as no chunk size and no note.' );
		$this->assertNull( $repo->find( 8 ) );
		$this->assert_all_prepared();
	}

	public function test_set_status_stamps_the_finish_only_for_a_final_status(): void {
		$repo = new BatchRepository();
		$this->assertTrue( $repo->set_status( 4, 'running' ) );
		$this->assertTrue( $repo->set_status( 4, 'partial' ) );

		$this->assertSame( [ 'update', 'wp_bulkcat_batches', [ 'status' => 'running' ], [ '%s' ], [ 'batch_id' => 4 ], [ '%d' ] ], self::db()->writes[0] );
		[ , , $data, $format, $where ] = self::db()->writes[1];
		$this->assertSame( [ 'status', 'finished_gmt' ], array_keys( $data ) );
		$this->assert_datetime( $data['finished_gmt'] );
		$this->assertSame( [ [ '%s', '%s' ], [ 'batch_id' => 4 ] ], [ $format, $where ] );

		$this->assert_invalid( static fn() => $repo->set_status( 4, 'paused' ) );
	}

	public function test_materialise_writes_pending_items_in_chunks_of_500(): void {
		self::db()->responses['/^INSERT INTO/'] = static fn( string $sql ): int => substr_count( $sql, "'pending'" );
		$objects                                = [];
		for ( $i = 1; $i <= 1201; $i++ ) {
			$objects[] = [ $i, 0 === $i % 3 ? 'variation' : 'product' ];
		}

		$this->assertSame( 1201, ( new BatchRepository() )->materialise_items( 6, $objects ) );

		$this->assertCount( 3, self::db()->queries );
		$sizes = [];
		foreach ( self::db()->prepared as [ $template, $args ] ) {
			$sizes[] = substr_count( $template, '(%d, %d, %s, \'pending\', 0)' );
			$this->assertStringStartsWith( 'INSERT INTO %i (batch_id, object_id, object_type, state, attempts) VALUES (%d, %d, %s, \'pending\', 0), ', $template );
			$this->assertSame( 'wp_bulkcat_batch_items', $args[0] );
			$this->assertCount( 1 + 3 * end( $sizes ), $args );
		}
		$this->assertSame( [ 500, 500, 201 ], $sizes );
		$this->assertSame( [ 'wp_bulkcat_batch_items', 6, 1, 'product', 6, 2, 'product', 6, 3, 'variation' ], array_slice( self::db()->prepared[0][1], 0, 10 ) );
		$this->assertStringEndsWith( "(6, 1200, 'variation', 'pending', 0), (6, 1201, 'product', 'pending', 0)", self::db()->queries[2] );
		$this->assert_all_prepared();
	}

	public function test_materialise_refuses_a_bad_item_before_writing_anything(): void {
		$repo = new BatchRepository();
		$this->assertSame( 0, $repo->materialise_items( 6, [] ) );
		foreach ( [ [ [ 1, 'order' ] ], [ [ 0, 'product' ] ], [ [ '1', 'product' ] ], [ [ 1 ] ], [ 'x' ] ] as $objects ) {
			try {
				$repo->materialise_items( 6, array_merge( [ [ 5, 'product' ] ], $objects ) );
				$this->fail( 'Accepted ' . json_encode( $objects ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( [], self::db()->queries );
			}
		}
	}

	public function test_items_may_carry_their_own_operations_and_read_them_back(): void {
		self::db()->responses['/^INSERT INTO/'] = static fn( string $sql ): int => substr_count( $sql, "'pending'" );
		$repo                                   = new BatchRepository();
		$own                                    = [
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'regular_price',
					'value' => "10's",
				],
			],
		];
		$this->assertSame( 2, $repo->materialise_items( 6, [ [ 1, 'product', $own ], [ 2, 'variation', $own ] ] ) );
		[ $template, $args ] = self::db()->prepared[0];
		$this->assertSame( 'INSERT INTO %i (batch_id, object_id, object_type, state, attempts, operations) VALUES (%d, %d, %s, \'pending\', 0, %s), (%d, %d, %s, \'pending\', 0, %s)', $template );
		$this->assertSame( [ 'wp_bulkcat_batch_items', 6, 1, 'product', ValueCodec::encode( $own ), 6, 2, 'variation', ValueCodec::encode( $own ) ], $args );

		foreach ( [ [ [ 1, 'product', $own ], [ 2, 'product' ] ], [ [ 1, 'product' ], [ 2, 'product', $own ] ], [ [ 1, 'product', [] ] ], [ [ 1, 'product', 'x' ] ] ] as $objects ) {
			try {
				$repo->materialise_items( 6, $objects );
				$this->fail( 'Accepted ' . json_encode( $objects ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertCount( 1, self::db()->queries, 'Refused before writing.' );
			}
		}

		self::db()->responses['/^SELECT item_id, operations FROM/'] = [
			[
				'item_id'    => '11',
				'operations' => ValueCodec::encode( $own ),
			],
		];
		$this->assertSame( [ 11 => $own ], $repo->item_operations( 6, [ 11, 12, 11 ] ) );
		$this->assertSame( 'SELECT item_id, operations FROM `wp_bulkcat_batch_items` WHERE batch_id = 6 AND operations IS NOT NULL AND item_id IN (11, 12) LIMIT 2', end( self::db()->queries ) );
		$this->assertSame( [], $repo->item_operations( 6, [] ) );
		$this->assert_invalid( static fn() => $repo->item_operations( 6, [ 0 ] ) );
		$this->assert_all_prepared();
	}

	public function test_a_move_out_of_undoing_keeps_the_batches_finished_time(): void {
		$repo = new BatchRepository();
		$this->assertTrue( $repo->transition( 6, 'complete', 'undoing' ) );
		$this->assertTrue( $repo->transition( 6, 'undoing', 'undone' ) );
		$this->assertTrue( $repo->transition( 6, 'undoing', 'partial' ) );
		$this->assertSame( [ [ 'status' => 'undoing' ], [ 'status' => 'undone' ], [ 'status' => 'partial' ] ], array_column( self::db()->writes, 2 ), 'The batch finished before its undo; it keeps that time.' );
	}

	public function test_claim_selects_pending_items_under_the_attempt_limit_then_counts_the_attempt(): void {
		self::db()->responses["/^SELECT item_id, object_id, object_type, attempts FROM `wp_bulkcat_batch_items` WHERE batch_id = 6 AND state = 'pending' AND attempts < 3 ORDER BY item_id ASC LIMIT 50$/"] = [
			[
				'item_id'     => '11',
				'object_id'   => '101',
				'object_type' => 'product',
				'attempts'    => '0',
			],
			[
				'item_id'     => '12',
				'object_id'   => '102',
				'object_type' => 'variation',
				'attempts'    => '2',
			],
		];
		$claimed = ( new BatchRepository() )->claim_items( 6, 50 );

		$this->assertSame(
			[
				[
					'item_id'     => 11,
					'object_id'   => 101,
					'object_type' => 'product',
					'attempts'    => 1,
				],
				[
					'item_id'     => 12,
					'object_id'   => 102,
					'object_type' => 'variation',
					'attempts'    => 3,
				],
			],
			$claimed
		);
		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET attempts = attempts + 1 WHERE batch_id = 6 AND state = 'pending' AND item_id IN (11, 12)", self::db()->queries[1] );
		$this->assertSame( [ 'wp_bulkcat_batch_items', 6, 11, 12 ], self::db()->prepared[1][1] );
		$this->assert_all_prepared();
	}

	public function test_claim_clamps_its_limit_and_writes_nothing_when_nothing_is_pending(): void {
		$repo = new BatchRepository();
		$this->assertSame( [], $repo->claim_items( 6, 1000 ) );
		$this->assertSame( [], $repo->claim_items( 6, 0 ) );
		$this->assertStringEndsWith( 'LIMIT 100', self::db()->queries[0] );
		$this->assertStringEndsWith( 'LIMIT 1', self::db()->queries[1] );
		$this->assertCount( 2, self::db()->queries, 'No UPDATE without claimed rows.' );
	}

	public function test_mark_items_sets_a_state_on_the_named_items_of_one_batch(): void {
		self::db()->responses['/^UPDATE/'] = 2;
		$repo                              = new BatchRepository();
		$this->assertSame( 2, $repo->mark_items( 6, [ 11, 12 ], 'done' ) );
		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET state = 'done' WHERE batch_id = 6 AND item_id IN (11, 12)", self::db()->queries[0] );
		$this->assertSame( [ 'wp_bulkcat_batch_items', 'done', 6, 11, 12 ], self::db()->prepared[0][1] );
		$this->assertSame( 0, $repo->mark_items( 6, [], 'failed' ) );
		$this->assertCount( 1, self::db()->queries );
		$this->assert_all_prepared();

		foreach ( [ [ [ 1 ], 'claimed' ], [ [ '1' ], 'done' ], [ [ 0 ], 'done' ] ] as [ $ids, $state ] ) {
			try {
				$repo->mark_items( 6, $ids, $state );
				$this->fail( "Accepted $state." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertCount( 1, self::db()->queries );
			}
		}
	}

	public function test_exhausted_items_are_failed_and_counts_read_by_state(): void {
		self::db()->responses["SELECT COUNT(*) FROM `wp_bulkcat_batch_items` WHERE batch_id = 6 AND state = 'pending'"] = '17';
		$repo = new BatchRepository();
		$repo->fail_exhausted( 6 );
		$this->assertSame( 17, $repo->count_items( 6, 'pending' ) );
		$this->assertSame( 0, $repo->count_items( 6, 'done' ) );

		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET state = 'failed' WHERE batch_id = 6 AND state = 'pending' AND attempts >= 3", self::db()->queries[0] );
		$this->assertSame( [ 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state = %s', [ 'wp_bulkcat_batch_items', 6, 'pending' ] ], self::db()->prepared[1] );
		$this->assert_all_prepared();
	}

	public function test_claim_chunk_claims_with_one_locking_update_then_reads_back_by_token(): void {
		$token = str_repeat( 'ab', 16 );
		self::db()->responses['/^UPDATE `wp_bulkcat_batch_items` SET claim_token/']       = 2;
		self::db()->responses['/^SELECT item_id, object_id, object_type, attempts FROM/'] = [
			[
				'item_id'     => '11',
				'object_id'   => '501',
				'object_type' => 'product',
				'attempts'    => '1',
			],
			[
				'item_id'     => '12',
				'object_id'   => '502',
				'object_type' => 'variation',
				'attempts'    => '2',
			],
		];
		$repo  = new BatchRepository();
		$items = $repo->claim_chunk( 6, 500, $token, '2026-09-23 10:05:00', '2026-09-23 10:00:00' );

		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET claim_token = '$token', claimed_gmt = '2026-09-23 10:05:00', attempts = attempts + 1 WHERE batch_id = 6 AND state = 'pending' AND attempts < 3 AND (claimed_gmt IS NULL OR claimed_gmt < '2026-09-23 10:00:00') ORDER BY item_id ASC LIMIT 100", self::db()->queries[0], 'One UPDATE, its limit clamped to the ceiling.' );
		$this->assertSame( "SELECT item_id, object_id, object_type, attempts FROM `wp_bulkcat_batch_items` WHERE batch_id = 6 AND state = 'pending' AND claim_token = '$token' ORDER BY item_id ASC LIMIT 100", self::db()->queries[1] );
		$this->assertSame(
			[
				[
					'item_id'     => 11,
					'object_id'   => 501,
					'object_type' => 'product',
					'attempts'    => 1,
				],
				[
					'item_id'     => 12,
					'object_id'   => 502,
					'object_type' => 'variation',
					'attempts'    => 2,
				],
			],
			$items
		);
		$this->assert_all_prepared();

		self::db()->responses['/^UPDATE `wp_bulkcat_batch_items` SET claim_token/'] = 0;
		$this->assertSame( [], $repo->claim_chunk( 6, 50, $token, '2026-09-23 10:05:00', '2026-09-23 10:00:00' ) );
		$this->assertCount( 3, self::db()->queries, 'Nothing claimed, nothing read back.' );
		$this->assert_invalid( static fn() => $repo->claim_chunk( 6, 50, 'not-a-token', '2026-09-23 10:05:00', '2026-09-23 10:00:00' ) );

		self::db()->failing = [ 'query' ];
		try {
			$repo->claim_chunk( 6, 50, $token, '2026-09-23 10:05:00', '2026-09-23 10:00:00' );
			$this->fail( 'A failed claim was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_claim_chunk_by_kind_is_one_literal_statement_each(): void {
		$token = str_repeat( 'ef', 16 );
		$where = "WHERE batch_id = 6 AND state = 'pending' AND attempts < 3 AND (claimed_gmt IS NULL OR claimed_gmt < '2026-09-23 10:00:00')";
		self::db()->responses['/^UPDATE `wp_bulkcat_batch_items` SET claim_token/'] = 0;
		$repo = new BatchRepository();
		foreach (
			[
				BatchRepository::CLAIM_ANY        => '',
				BatchRepository::CLAIM_VARIATIONS => " AND object_type = 'variation'",
				BatchRepository::CLAIM_PRODUCTS   => " AND object_type <> 'variation'",
			] as $kind => $type
		) {
			self::db()->queries  = [];
			self::db()->prepared = [];
			$this->assertSame( [], $repo->claim_chunk( 6, 20, $token, '2026-09-23 10:05:00', '2026-09-23 10:00:00', $kind ) );
			$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET claim_token = '$token', claimed_gmt = '2026-09-23 10:05:00', attempts = attempts + 1 $where$type ORDER BY item_id ASC LIMIT 20", self::db()->queries[0], $kind );
		}
		$this->assert_all_prepared();
		$this->assert_invalid( static fn() => $repo->claim_chunk( 6, 20, $token, '2026-09-23 10:05:00', '2026-09-23 10:00:00', 'everything' ) );

		// Plugin Check traces a template held in a variable as unescaped (T78): the claim's templates are literals.
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Data/BatchRepository.php' );
		$this->assertSame( 0, preg_match( '/->prepare\(\s*\$sql/', $source ) );
	}

	public function test_the_runner_statements_release_mark_fail_and_count(): void {
		$token = str_repeat( 'cd', 16 );
		$repo  = new BatchRepository();
		$repo->release_claim( 6, $token );
		$repo->mark_items( 6, [ 11, 12 ], 'failed', str_repeat( 'x', 300 ) );
		$repo->fail_exhausted( 6, '2026-09-23 10:00:00', "It didn't finish." );
		$repo->count_reported( 6 );

		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET claim_token = NULL, claimed_gmt = NULL WHERE batch_id = 6 AND state = 'pending' AND claim_token = '$token'", self::db()->queries[0] );
		$this->assertSame( [ 'wp_bulkcat_batch_items', 'failed', str_repeat( 'x', 255 ), 6, 11, 12 ], self::db()->prepared[1][1], 'The message is cut to the column.' );
		$this->assertSame( "UPDATE `wp_bulkcat_batch_items` SET state = 'failed', message = 'It didn\\'t finish.' WHERE batch_id = 6 AND state = 'pending' AND attempts >= 3 AND (claimed_gmt IS NULL OR claimed_gmt < '2026-09-23 10:00:00')", self::db()->queries[2], 'Only items whose last claim is stale.' );
		$this->assertSame( "SELECT COUNT(*) FROM `wp_bulkcat_batch_items` WHERE batch_id = 6 AND state <> 'pending' AND (state <> 'done' OR message <> '')", self::db()->queries[3] );
		$this->assert_all_prepared();
	}

	public function test_transition_moves_only_from_the_expected_status_and_the_chunk_size_is_recorded(): void {
		$repo = new BatchRepository();
		$this->assertTrue( $repo->transition( 6, 'running', 'partial' ) );
		$this->assertTrue( $repo->transition( 6, 'queued', 'running' ) );
		$this->assertTrue( $repo->record_chunk_size( 6, 25, 'Why.' ) );
		$this->assertTrue( $repo->record_chunk_size( 6, 40 ) );

		[ $final, $running, $sized, $unnoted ] = self::db()->writes;
		$this->assertSame( 'partial', $final[2]['status'] );
		$this->assert_datetime( $final[2]['finished_gmt'] );
		$this->assertSame(
			[
				'batch_id' => 6,
				'status'   => 'running',
			],
			$final[4],
			'The move is conditional on the current status.'
		);
		$this->assertSame( [ 'status' => 'running' ], $running[2], 'Running is not a finish.' );
		$this->assertSame(
			[
				'chunk_size' => 25,
				'note'       => 'Why.',
			],
			$sized[2]
		);
		$this->assertSame( [ 'chunk_size' => 40 ], $unnoted[2] );

		$this->assert_invalid( static fn() => $repo->transition( 6, 'running', 'stalled' ) );
		$this->assert_invalid( static fn() => $repo->record_chunk_size( 6, 0 ) );
		$this->assert_invalid( static fn() => $repo->record_chunk_size( 6, 101 ) );
	}

	public function test_counters_are_incremented_in_one_statement(): void {
		self::db()->responses['/^UPDATE `wp_bulkcat_batches`/'] = 1;
		$repo = new BatchRepository();
		$this->assertTrue( $repo->increment_counters( 6, 50, 48, 2 ) );
		$this->assertSame( 'UPDATE `wp_bulkcat_batches` SET done_items = done_items + 50, changed_items = changed_items + 48, failed_items = failed_items + 2 WHERE batch_id = 6', self::db()->queries[0] );
		$this->assertSame( [ 'wp_bulkcat_batches', 50, 48, 2, 6 ], self::db()->prepared[0][1] );
		$this->assert_all_prepared();

		self::db()->responses['/^UPDATE `wp_bulkcat_batches`/'] = 0;
		$this->assertFalse( $repo->increment_counters( 99, 1, 0, 0 ), 'No such batch.' );

		$this->assert_invalid( static fn() => $repo->increment_counters( 6, 1, -1, 0 ) );
	}

	public function test_a_failed_statement_is_an_error(): void {
		self::db()->failing = [ 'query' ];
		$repo               = new BatchRepository();
		foreach (
			[
				static fn() => $repo->materialise_items( 6, [ [ 1, 'product' ] ] ),
				static fn() => $repo->mark_items( 6, [ 1 ], 'done' ),
				static fn() => $repo->fail_exhausted( 6 ),
				static fn() => $repo->increment_counters( 6, 1, 1, 0 ),
			] as $i => $call
		) {
			try {
				$call();
				$this->fail( "Call $i swallowed a database error." );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_the_progress_and_history_reads(): void {
		$repo                 = new BatchRepository();
		self::db()->responses = [
			'SELECT status FROM `wp_bulkcat_batches` WHERE batch_id = 7'                                                                   => 'running',
			'SELECT claimed_gmt FROM `wp_bulkcat_batch_items` WHERE batch_id = 7 AND claimed_gmt IS NOT NULL ORDER BY claimed_gmt DESC LIMIT 1' => '2026-09-23 08:05:00',
			"SELECT COUNT(*) FROM `wp_bulkcat_batches` WHERE batch_id > 0 AND status = 'partial' AND user_id = 3 AND created_gmt >= '2026-09-01 00:00:00' AND created_gmt < '2026-10-01 00:00:00'" => '41',
		];
		$this->assertSame( 'running', $repo->status( 7 ) );
		$this->assertNull( $repo->status( 8 ) );
		$this->assertSame( '2026-09-23 08:05:00', $repo->last_claimed_gmt( 7 ) );
		$this->assertNull( $repo->last_claimed_gmt( 8 ) );

		$this->assertSame( [], $repo->unjournalled_reasons( 7, 500 ) );
		$this->assertSame( "SELECT i.message AS message, COUNT(*) AS objects FROM `wp_bulkcat_batch_items` AS i WHERE i.batch_id = 7 AND i.state IN ('failed', 'skipped') AND i.message <> '' AND NOT EXISTS (SELECT c.change_id FROM `wp_bulkcat_changes` AS c WHERE c.batch_id = i.batch_id AND c.object_id = i.object_id) GROUP BY i.message ORDER BY objects DESC LIMIT 100", end( self::db()->queries ) );

		$filters = [
			'status'  => 'partial',
			'user_id' => 3,
			'after'   => '2026-09-01 00:00:00',
			'before'  => '2026-10-01 00:00:00',
		];
		$this->assertSame( 41, $repo->history_count( $filters ) );
		$this->assertSame( [], $repo->history( $filters, 3, 20 ) );
		$list = end( self::db()->queries );
		$this->assertSame( "SELECT batch_id, uuid, user_id, created_gmt, finished_gmt, status, source, undo_of, operations, total_items, previewed_items, done_items, changed_items, failed_items, chunk_size, note, actions, pruned_gmt FROM `wp_bulkcat_batches` WHERE batch_id > 0 AND status = 'partial' AND user_id = 3 AND created_gmt >= '2026-09-01 00:00:00' AND created_gmt < '2026-10-01 00:00:00' ORDER BY batch_id DESC LIMIT 20 OFFSET 40", $list );
		$this->assertStringNotContainsString( 'selection', $list, 'A list never reads the selection snapshot.' );
		$repo->history( [], 1, 500 );
		$this->assertStringEndsWith( 'WHERE batch_id > 0 ORDER BY batch_id DESC LIMIT 100 OFFSET 0', end( self::db()->queries ) );
		$this->assert_all_prepared();

		foreach ( [ [ 'orderby' => 'x' ], [ 'status' => 'lost' ], [ 'user_id' => 0 ], [ 'after' => '2026-09-01' ], [ 'before' => "1' OR 1=1" ] ] as $bad ) {
			try {
				$repo->history( $bad, 1, 10 );
				$this->fail( 'Accepted ' . wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_the_site_prefix_and_only_registry_tables_are_used(): void {
		self::db()->prefix = 'wp_3_';
		( new BatchRepository() )->increment_counters( 6, 1, 0, 0 );
		$this->assertStringStartsWith( 'UPDATE `wp_3_bulkcat_batches` SET', self::db()->queries[0] );

		$this->assert_invalid( static fn() => ( new BatchRepository( new Registry( [], [], [], [], [], [] ) ) )->count_items( 6, 'done' ) );
	}

	public function test_the_action_list_is_derived_from_the_operations_and_filtered_on(): void {
		$step = static fn( mixed $action ): array => [
			'action' => $action,
			'args'   => [],
		];
		$this->assertSame( ',adjust,round,set_value,', BatchRepository::actions( [ $step( 'adjust' ), $step( 'round' ), $step( 'adjust' ), $step( 'set_value' ) ] ), 'Distinct ids in step order.' );
		$this->assertSame( '', BatchRepository::actions( [] ) );
		$this->assertSame( ',clear,', BatchRepository::actions( [ $step( 'Bad Id' ), $step( 7 ), 'not a step', $step( 'clear' ), $step( "x'; DROP" ) ] ), 'Anything but a well-formed id is left out.' );
		$long = array_map( static fn( int $i ): array => [ 'action' => 'a' . str_repeat( 'x', 60 ) . $i ], range( 1, 5 ) );
		$this->assertTrue( strlen( BatchRepository::actions( $long ) ) <= BatchRepository::ACTIONS_LENGTH, 'Never wider than the column.' );

		( new BatchRepository() )->create( self::UUID, 3, 'bulk', [ $step( 'adjust' ), $step( 'round' ) ], [], 1 );
		$this->assertSame( ',adjust,round,', self::db()->writes[0][2]['actions'] );

		$repo = new BatchRepository();
		$repo->history_count( [ 'action' => 'set_value' ] );
		$this->assertSame( "SELECT COUNT(*) FROM `wp_bulkcat_batches` WHERE batch_id > 0 AND actions LIKE '%,set\\\\_value,%'", end( self::db()->queries ) );
		foreach ( [ 'Set', "x' OR 1=1", '%', '', 7 ] as $bad ) {
			$this->assert_invalid( static fn() => $repo->history( [ 'action' => $bad ], 1, 10 ) );
		}
	}

	public function test_undone_by_reads_a_page_of_completed_undo_batches_at_once(): void {
		self::db()->responses = [
			"SELECT batch_id, undo_of FROM `wp_bulkcat_batches` WHERE undo_of IN (4, 7) AND source = 'undo' AND status = 'complete' ORDER BY batch_id ASC" => [
				[
					'batch_id' => '8',
					'undo_of'  => '7',
				],
				[
					'batch_id' => '9',
					'undo_of'  => '7',
				],
			],
		];
		$repo                 = new BatchRepository();
		$this->assertSame( [ 7 => 9 ], $repo->undone_by( [ 4, 7, 4 ] ), 'The latest undo that completed.' );
		$this->assertSame( [], $repo->undone_by( [] ) );
		$this->assertCount( 1, self::db()->queries, 'No statement for an empty page.' );
		$this->assertNull( $repo->undo_of( 5 ) );
		$this->assert_invalid( static fn() => $repo->undone_by( [ 0 ] ) );
		$this->assert_all_prepared();
	}

	/**
	 * The call throws InvalidArgumentException. (The standalone shim has no expectException().)
	 */
	private function assert_invalid( callable $call ): void {
		try {
			$call();
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			return;
		}
		$this->fail( 'Expected InvalidArgumentException.' );
	}

	private function assert_datetime( string $value ): void {
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ), "\"$value\" is not Y-m-d H:i:s." );
	}
}
