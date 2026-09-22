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
use BulkCatalogueManager\Data\Registry;
use PHPUnit\Framework\TestCase;

final class BatchRepositoryTest extends TestCase {
	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	protected function setUp(): void {
		bcm_test_reset();
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
			$this->assertStringNotContainsString( 'bcm_', $template );
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
		$this->assertSame( [ 'insert', 'wp_bcm_batches' ], [ $method, $table ] );
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
			],
			$data
		);
		$this->assertSame( [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ], $format );
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
		self::db()->responses['SELECT * FROM `wp_bcm_batches` WHERE batch_id = 7'] = [
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

		$this->assertSame( [ 'SELECT * FROM %i WHERE batch_id = %d', [ 'wp_bcm_batches', 7 ] ], self::db()->prepared[0] );
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
		$this->assertNull( $repo->find( 8 ) );
		$this->assert_all_prepared();
	}

	public function test_set_status_stamps_the_finish_only_for_a_final_status(): void {
		$repo = new BatchRepository();
		$this->assertTrue( $repo->set_status( 4, 'running' ) );
		$this->assertTrue( $repo->set_status( 4, 'partial' ) );

		$this->assertSame( [ 'update', 'wp_bcm_batches', [ 'status' => 'running' ], [ '%s' ], [ 'batch_id' => 4 ], [ '%d' ] ], self::db()->writes[0] );
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
			$this->assertSame( 'wp_bcm_batch_items', $args[0] );
			$this->assertCount( 1 + 3 * end( $sizes ), $args );
		}
		$this->assertSame( [ 500, 500, 201 ], $sizes );
		$this->assertSame( [ 'wp_bcm_batch_items', 6, 1, 'product', 6, 2, 'product', 6, 3, 'variation' ], array_slice( self::db()->prepared[0][1], 0, 10 ) );
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

	public function test_claim_selects_pending_items_under_the_attempt_limit_then_counts_the_attempt(): void {
		self::db()->responses["/^SELECT item_id, object_id, object_type, attempts FROM `wp_bcm_batch_items` WHERE batch_id = 6 AND state = 'pending' AND attempts < 3 ORDER BY item_id ASC LIMIT 50$/"] = [
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
		$this->assertSame( "UPDATE `wp_bcm_batch_items` SET attempts = attempts + 1 WHERE batch_id = 6 AND state = 'pending' AND item_id IN (11, 12)", self::db()->queries[1] );
		$this->assertSame( [ 'wp_bcm_batch_items', 6, 11, 12 ], self::db()->prepared[1][1] );
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
		$this->assertSame( "UPDATE `wp_bcm_batch_items` SET state = 'done' WHERE batch_id = 6 AND item_id IN (11, 12)", self::db()->queries[0] );
		$this->assertSame( [ 'wp_bcm_batch_items', 'done', 6, 11, 12 ], self::db()->prepared[0][1] );
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
		self::db()->responses["SELECT COUNT(*) FROM `wp_bcm_batch_items` WHERE batch_id = 6 AND state = 'pending'"] = '17';
		$repo = new BatchRepository();
		$repo->fail_exhausted( 6 );
		$this->assertSame( 17, $repo->count_items( 6, 'pending' ) );
		$this->assertSame( 0, $repo->count_items( 6, 'done' ) );

		$this->assertSame( "UPDATE `wp_bcm_batch_items` SET state = 'failed' WHERE batch_id = 6 AND state = 'pending' AND attempts >= 3", self::db()->queries[0] );
		$this->assertSame( [ 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state = %s', [ 'wp_bcm_batch_items', 6, 'pending' ] ], self::db()->prepared[1] );
		$this->assert_all_prepared();
	}

	public function test_counters_are_incremented_in_one_statement(): void {
		self::db()->responses['/^UPDATE `wp_bcm_batches`/'] = 1;
		$repo = new BatchRepository();
		$this->assertTrue( $repo->increment_counters( 6, 50, 48, 2 ) );
		$this->assertSame( 'UPDATE `wp_bcm_batches` SET done_items = done_items + 50, changed_items = changed_items + 48, failed_items = failed_items + 2 WHERE batch_id = 6', self::db()->queries[0] );
		$this->assertSame( [ 'wp_bcm_batches', 50, 48, 2, 6 ], self::db()->prepared[0][1] );
		$this->assert_all_prepared();

		self::db()->responses['/^UPDATE `wp_bcm_batches`/'] = 0;
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

	public function test_the_site_prefix_and_only_registry_tables_are_used(): void {
		self::db()->prefix = 'wp_3_';
		( new BatchRepository() )->increment_counters( 6, 1, 0, 0 );
		$this->assertStringStartsWith( 'UPDATE `wp_3_bcm_batches` SET', self::db()->queries[0] );

		$this->assert_invalid( static fn() => ( new BatchRepository( new Registry( [], [], [], [], [], [] ) ) )->count_items( 6, 'done' ) );
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
