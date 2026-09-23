<?php
/**
 * The chunk runner (T34; SPEC §12.3, §12.4, §12.6): the first chunk is
 * enqueued on batch creation through the public `as_*` functions; one chunk
 * claims exactly N pending items with one UPDATE; its successor is enqueued
 * while items remain; the final status follows the mix of outcomes; a stale
 * claim is released and retried and a third one fails the item; the journal
 * guard stops a re-run applying twice; the chunk size halves after a slow
 * chunk and grows back after fast ones; term counting is deferred around the
 * work and flushed even when it throws; and nothing in src/Engine/ names a
 * WooCommerce internal batch-processing class.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class ChunkRunnerTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	/**
	 * The fake clock: every reading advances it by $step.
	 */
	private float $now = 1790000000.0;

	private float $step = 1.0;

	protected function setUp(): void {
		bcm_test_reset();
		$this->now                            = 1790000000.0;
		$this->step                           = 1.0;
		$GLOBALS['bcm_test']['user_caps']     = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bcm_test']['scheduled']     = [];
		$GLOBALS['bcm_test']['term_counting'] = [];
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bcm_test']['post_meta_updates'] ?? [], 'The runner wrote meta.' );
	}

	/**
	 * A runner over the fake clock, the default settings (50 per chunk) and a 30-second limit.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 */
	private function runner( array $settings = [], int $limit = 30 ): ChunkRunner {
		return new ChunkRunner( null, new Registry( new Fields() ), null, new Settings( $settings ), fn(): float => $this->now += $this->step, $limit );
	}

	/**
	 * A 10 per cent increase on the regular price, normalised and sent through JSON as stored.
	 */
	private function increase(): array {
		$list = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
			]
		);
		$this->assertTrue( is_array( $list ) );
		return json_decode( (string) wp_json_encode( $list ), true );
	}

	/**
	 * Products 1..$count (ids from 1001) at a regular price of 10, registered for wc_get_product().
	 *
	 * @return list<int> Their ids.
	 */
	private static function products( int $count, int $first = 1001 ): array {
		$ids = range( $first, $first + $count - 1 );
		foreach ( $ids as $id ) {
			$GLOBALS['bcm_test']['products'][ $id ] = new SavedProduct(
				'simple',
				[
					'id'            => $id,
					'regular_price' => '10',
				]
			);
		}
		return $ids;
	}

	private static function price( int $id ): string {
		return (string) $GLOBALS['bcm_test']['products'][ $id ]->props['regular_price'];
	}

	/**
	 * The recorded scheduling calls of one kind: enqueue, single or unschedule.
	 *
	 * @return list<array>
	 */
	private static function scheduled( string $kind ): array {
		return array_values( array_filter( $GLOBALS['bcm_test']['scheduled'], static fn( array $call ): bool => $kind === $call[0] ) );
	}

	/**
	 * The claim statements run so far.
	 *
	 * @return list<string>
	 */
	private static function claims(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE `wp_bcm_batch_items` SET claim_token = \'' ) ) );
	}

	public function test_creating_a_batch_enqueues_its_first_chunk_through_the_public_api(): void {
		$hooks = Plugin::instance()->hooks();
		$this->assertContains( [ BatchFactory::CREATED, [ ChunkRunner::class, 'enqueue' ], 10, 1 ], $hooks );
		$this->assertContains( [ 'bcm_run_chunk', [ ChunkRunner::class, 'handle' ], 10, 2 ], $hooks );

		add_action( BatchFactory::CREATED, [ ChunkRunner::class, 'enqueue' ], 10, 1 );
		do_action( 'bcm_batch_created', 42 );

		$this->assertSame( [ [ 'enqueue', 'bcm_run_chunk', [ 42 ], 'bcm', null ] ], $GLOBALS['bcm_test']['scheduled'], 'One async action in the bcm group, and nothing else scheduled.' );
	}

	public function test_one_chunk_claims_exactly_n_pending_items_with_one_update_and_enqueues_its_successor(): void {
		$ids   = self::products( 60 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );

		$this->assertSame( 'continued', $this->runner()->run( self::BATCH ) );

		$this->assertCount( 1, self::claims(), 'One locking UPDATE claims the chunk.' );
		$this->assertStringContainsString( 'LIMIT 50', self::claims()[0] );
		$states = array_count_values( $store->states() );
		$this->assertSame(
			[
				'done'    => 50,
				'pending' => 10,
			],
			$states
		);
		$this->assertSame( '11.00', self::price( 1001 ) );
		$this->assertSame( '11.00', self::price( 1050 ) );
		$this->assertSame( '10', self::price( 1051 ), 'The 51st product waits for the next chunk.' );
		$batch = $store->batch();
		$this->assertSame( 'running', $batch['status'], 'The first chunk marks the batch running.' );
		$this->assertSame( [ 50, 50, 0 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
		$this->assertNull( $batch['finished_gmt'] );
		$this->assertSame( [ [ 'enqueue', 'bcm_run_chunk', [ self::BATCH ], 'bcm', null ] ], self::scheduled( 'enqueue' ), 'The successor is enqueued while items remain.' );
		$this->assertSame( [ [ 'unschedule', 'bcm_run_chunk', [ self::BATCH, 'retry' ], 'bcm', null ] ], self::scheduled( 'unschedule' ), 'A chunk that ends normally drops its dead-chunk retry.' );
		$this->assertSame( (int) $this->now - 1 + BatchRepository::LEASE_SECONDS + ChunkRunner::RETRY_DELAY, self::scheduled( 'single' )[0][4], 'The dead-chunk retry is set for just after the lease goes stale.' );

		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( [ 'done' => 60 ], array_count_values( $store->states() ) );
		$this->assertCount( 1, self::scheduled( 'enqueue' ), 'No successor once nothing is pending.' );
		$batch = $store->batch();
		$this->assertSame( [ 'complete', 60, 60, 0 ], [ $batch['status'], $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $batch['finished_gmt'] ) );
		$this->assertCount( 60, $store->journal(), 'One journal row per object and touched field.' );
	}

	public function test_the_chunk_size_follows_the_setting_and_the_filter_within_floor_and_ceiling(): void {
		foreach ( [ [ [ 'batch_size' => 20 ], null, 20 ], [ [], 3, 10 ], [ [], 500, 100 ], [ [ 'batch_size' => 10 ], 30, 30 ] ] as [ $settings, $filtered, $expected ] ) {
			bcm_test_reset();
			$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
			if ( null !== $filtered ) {
				add_filter( 'bcm_batch_size', static fn(): int => $filtered );
			}
			new RunnerStore( self::BATCH, self::products( 120 ), $this->increase(), self::USER );
			$this->runner( $settings )->run( self::BATCH );
			$this->assertStringContainsString( "LIMIT $expected", self::claims()[0] );
		}
	}

	public function test_the_final_status_follows_the_outcomes(): void {
		$this->assertSame( 'complete', ChunkRunner::final_status( 0, 0, 5 ) );
		$this->assertSame( 'complete', ChunkRunner::final_status( 0, 0, 0 ), 'Everything was already at its target.' );
		$this->assertSame( 'partial', ChunkRunner::final_status( 2, 2, 5 ) );
		$this->assertSame( 'partial', ChunkRunner::final_status( 1, 0, 0 ), 'Skips and nothing failed.' );
		$this->assertSame( 'failed', ChunkRunner::final_status( 3, 3, 0 ) );
		$this->assertSame( 'failed', ChunkRunner::final_status( 4, 3, 0 ), 'Failures and skips, nothing applied.' );

		// Through a batch: a missing product and one the user may not edit.
		$ids = self::products( 3 );
		unset( $GLOBALS['bcm_test']['products'][1002] );
		$GLOBALS['bcm_test']['user_caps'][ self::USER ]['edit_post:1003'] = false;
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$this->assertSame( 'partial', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'failed',
				1003 => 'skipped',
			],
			$store->states()
		);
		$this->assertStringContainsString( 'could not be found', $store->item( 1002 )['message'] );
		$this->assertStringContainsString( 'permission', $store->item( 1003 )['message'] );
		$this->assertSame( '', $store->item( 1001 )['message'] );
		foreach ( $store->items as $item ) {
			$this->assertStringNotContainsString( 'bcm_', $item['message'] );
		}
		$batch = $store->batch();
		$this->assertSame( [ 'partial', 3, 1, 1 ], [ $batch['status'], $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );

		// Nothing applied and a failure: failed.
		bcm_test_reset();
		$store = new RunnerStore( self::BATCH, [ 2001, 2002 ], $this->increase(), self::USER );
		$this->assertSame( 'failed', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 'failed', $store->batch()['status'] );
		$this->assertNotNull( $store->batch()['finished_gmt'] );
	}

	public function test_item_state_and_message_from_a_write(): void {
		$changed = Result::changed( 'regular_price', '10', '11' );
		$skip    = Result::skipped( 'sale_price', '5', 'The sale price must be below the regular price, so it was not changed.', 'bcm_invalid_value' );
		$again   = Result::skipped( 'regular_price', null, 'Already done.', Journal::ALREADY_DONE );
		$fail    = Result::failed( 'sku', 'A', 'A product with this SKU already exists.', 'bcm_invalid_value' );

		$this->assertSame( [ 'done', '' ], ChunkRunner::item_state( new WriteOutcome( 1, [ $changed ], true ) ) );
		$this->assertSame( [ 'done', '' ], ChunkRunner::item_state( new WriteOutcome( 1, [ $again ], false ) ), 'An object a retried chunk skips is done, not skipped.' );
		$this->assertSame( [ 'skipped', $skip->reason() ], ChunkRunner::item_state( new WriteOutcome( 1, [ $skip ], false ) ) );
		$this->assertSame( [ 'done', $skip->reason() ], ChunkRunner::item_state( new WriteOutcome( 1, [ $changed, $skip ], true ) ), 'A step refused beside a saved change is reported on a done item.' );
		$this->assertSame( [ 'failed', $fail->reason() ], ChunkRunner::item_state( new WriteOutcome( 1, [ $changed, $fail ], true ) ) );
	}

	public function test_a_stale_claim_is_released_and_retried_and_the_third_fails_the_item(): void {
		$ids   = self::products( 2 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$repo  = new BatchRepository();
		$gmt   = fn( int $offset = 0 ): string => gmdate( 'Y-m-d H:i:s', (int) $this->now + $offset );

		// A chunk claims both and dies.
		$this->assertCount( 2, $repo->claim_chunk( self::BATCH, 50, str_repeat( 'a', 32 ), $gmt(), $gmt( -300 ) ) );

		// Within the lease nothing is claimable: the run waits and schedules a retry.
		$this->assertSame( 'waiting', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				1001 => 'pending',
				1002 => 'pending',
			],
			$store->states()
		);
		$this->assertSame( 'bcm_run_chunk', self::scheduled( 'single' )[0][1] );
		$this->assertSame( [ self::BATCH, 'retry' ], self::scheduled( 'single' )[0][2] );

		// Past the lease the claim is stale: released and retried, the second attempt.
		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 2, $store->item( 1001 )['attempts'] );
		$this->assertSame( [ '11.00', '11.00' ], [ self::price( 1001 ), self::price( 1002 ) ] );

		// Three dead chunks in a row: the next run fails the item with a plain reason.
		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$store                            = new RunnerStore( self::BATCH, self::products( 1, 3001 ), $this->increase(), self::USER );
		foreach ( [ 'b', 'c', 'd' ] as $token ) {
			$this->assertCount( 1, $repo->claim_chunk( self::BATCH, 50, str_repeat( $token, 32 ), $gmt(), $gmt( -300 ) ) );
			$this->now += BatchRepository::LEASE_SECONDS + 1;
		}
		$this->assertSame( 3, $store->item( 3001 )['attempts'] );
		$this->assertSame( 'failed', $this->runner()->run( self::BATCH ) );
		$item = $store->item( 3001 );
		$this->assertSame( 'failed', $item['state'] );
		$this->assertStringContainsString( 'three tries', $item['message'] );
		$this->assertStringNotContainsString( 'bcm_', $item['message'] );
		$this->assertSame( '10', self::price( 3001 ), 'Given up on, never written.' );
		$batch = $store->batch();
		$this->assertSame( [ 1, 0, 1 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
	}

	public function test_a_rerun_chunk_does_not_apply_twice_through_the_journal_guard(): void {
		$ids   = self::products( 3 );
		$list  = $this->increase();
		$store = new RunnerStore( self::BATCH, $ids, $list, self::USER );
		$repo  = new BatchRepository();

		// A chunk claims all three, writes and journals the first, and is killed.
		$repo->claim_chunk( self::BATCH, 50, str_repeat( 'e', 32 ), gmdate( 'Y-m-d H:i:s', (int) $this->now ), gmdate( 'Y-m-d H:i:s', (int) $this->now - 300 ) );
		Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), new Settings( [] ) ), self::BATCH, self::USER )->write( 1001, $list );
		$this->assertSame( '11.00', self::price( 1001 ) );

		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );

		$this->assertSame( [ '11.00', '11.00', '11.00' ], [ self::price( 1001 ), self::price( 1002 ), self::price( 1003 ) ], 'The increase applied once to each.' );
		$this->assertCount( 1, $GLOBALS['bcm_test']['products'][1001]->saves );
		$this->assertSame(
			[
				[ 1001, 'regular_price', '10', '11.00', 'applied' ],
				[ 1002, 'regular_price', '10', '11.00', 'applied' ],
				[ 1003, 'regular_price', '10', '11.00', 'applied' ],
			],
			$store->journal(),
			'One applied row per object and field.'
		);
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'done',
				1003 => 'done',
			],
			$store->states()
		);
	}

	public function test_a_slow_chunk_halves_the_size_within_the_floor_and_fast_chunks_grow_it_back(): void {
		$store = new RunnerStore( self::BATCH, self::products( 400 ), $this->increase(), self::USER );

		$this->step = 25.0; // 25 of 30 seconds: over 70 per cent.
		$this->runner()->run( self::BATCH );
		$this->assertSame( 25, $store->batch()['chunk_size'] );
		$this->assertStringContainsString( '50 products took 25 of the 30 seconds', $store->batch()['note'] );
		$this->runner()->run( self::BATCH );
		$this->assertStringContainsString( 'LIMIT 25', self::claims()[1], 'The halved size holds for the rest of the batch.' );
		$this->assertSame( 12, $store->batch()['chunk_size'] );
		$this->runner()->run( self::BATCH );
		$this->assertSame( 10, $store->batch()['chunk_size'], 'Never below the floor.' );
		$this->runner()->run( self::BATCH );
		$this->assertStringContainsString( 'LIMIT 10', self::claims()[3] );
		$this->assertSame( 10, $store->batch()['chunk_size'] );

		$this->step = 15.0; // Half the limit: neither slow nor fast.
		$this->runner()->run( self::BATCH );
		$this->assertSame( 10, $store->batch()['chunk_size'] );

		$this->step = 2.0; // Under 30 per cent: doubles, up to the configured 50.
		$this->runner()->run( self::BATCH );
		$this->assertSame( 20, $store->batch()['chunk_size'] );
		$this->assertStringContainsString( '20 products at a time again', $store->batch()['note'] );
		$this->runner()->run( self::BATCH );
		$this->assertSame( 40, $store->batch()['chunk_size'] );
		$this->runner()->run( self::BATCH );
		$this->assertSame( 50, $store->batch()['chunk_size'], 'Grows back no further than the configured size.' );
		$this->runner()->run( self::BATCH );
		$this->assertStringContainsString( 'LIMIT 50', self::claims()[8] );
		$this->assertSame( 50, $store->batch()['chunk_size'] );

		// A time limit of 0 is taken as 30 seconds.
		$this->assertSame( 30, ChunkRunner::DEFAULT_TIME_LIMIT );
	}

	public function test_term_counting_is_deferred_around_the_work_and_flushed_even_when_it_throws(): void {
		new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::USER );
		$this->runner()->run( self::BATCH );
		$this->assertSame( [ [ true, 0 ], [ false, 3 ] ], $GLOBALS['bcm_test']['term_counting'], 'On before the first product loads, flushed after the last.' );

		// The journal refuses its row: the chunk stops, is flushed, and the error is logged by the queue.
		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps']     = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bcm_test']['scheduled']     = [];
		$GLOBALS['bcm_test']['term_counting'] = [];
		$store                                = new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::USER );
		$GLOBALS['wpdb']->failing             = [ 'insert' ];
		try {
			$this->runner()->run( self::BATCH );
			$this->fail( 'The journal failure was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The change could not be journalled.', $e->getMessage() );
		}
		$this->assertSame( [ [ true, 0 ], [ false, 1 ] ], $GLOBALS['bcm_test']['term_counting'] );
		$this->assertSame(
			[
				1001 => 'failed',
				1002 => 'pending',
				1003 => 'pending',
			],
			$store->states(),
			'The item that threw is failed, not retried blindly; the rest wait.'
		);
		$this->assertNull( $store->item( 1002 )['claim_token'], 'The rest of the claim is released at once.' );
		$this->assertSame( [ 1, 0, 1 ], [ $store->batch()['done_items'], $store->batch()['changed_items'], $store->batch()['failed_items'] ] );
		$this->assertSame( [], self::scheduled( 'enqueue' ) );
		$retry = self::scheduled( 'single' );
		$this->assertCount( 2, $retry, 'The dead-chunk retry, then the retry after the error.' );
		$this->assertSame( [ 'unschedule', 'bcm_run_chunk', [ self::BATCH, 'retry' ], 'bcm', null ], $GLOBALS['bcm_test']['scheduled'][1] );
	}

	public function test_a_batch_that_is_not_queued_or_running_is_left_alone(): void {
		foreach ( [ 'cancelled', 'complete', 'partial', 'failed', 'undone' ] as $status ) {
			bcm_test_reset();
			$GLOBALS['bcm_test']['scheduled'] = [];
			$store                            = new RunnerStore( self::BATCH, self::products( 2 ), $this->increase(), self::USER );
			$store->batch['status']           = $status;
			$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
			$this->assertSame( [], self::claims(), "A $status batch is not claimed." );
			$this->assertSame( [], $GLOBALS['bcm_test']['scheduled'] );
		}
		bcm_test_reset();
		new RunnerStore( self::BATCH, [], [], self::USER );
		$this->assertSame( 'missing', $this->runner()->run( 99 ) );
	}

	public function test_no_woocommerce_internal_batch_processing_class_is_referenced(): void {
		$files = glob( dirname( __DIR__, 3 ) . '/src/Engine/*.php' );
		$this->assertNotEmpty( $files );
		$this->assertContains( dirname( __DIR__, 3 ) . '/src/Engine/ChunkRunner.php', $files );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
			$this->assertSame( 0, preg_match( '/Automattic\\\\WooCommerce\\\\Internal\\\\BatchProcessing|BatchProcessingController|BatchProcessorInterface/i', $code ), basename( $file ) . ' references WooCommerce\'s internal batch processing.' );
			$this->assertSame( 0, preg_match( '/\bActionScheduler(_[A-Za-z]+)?\s*::|new\s+\\\\?ActionScheduler/', $code ), basename( $file ) . ' reaches past the public as_* functions.' );
			$this->assertSame( 0, preg_match( '/action_scheduler_queue_runner_(batch_size|concurrent_batches)/', $code ), basename( $file ) . ' reconfigures the queue runner.' );
		}
		$runner = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ChunkRunner.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertStringContainsString( 'as_enqueue_async_action(', $runner );
		// The scan would catch a reference.
		$this->assertSame( 1, preg_match( '/Automattic\\\\WooCommerce\\\\Internal\\\\BatchProcessing/', 'use Automattic\\WooCommerce\\Internal\\BatchProcessing\\BatchProcessingController;' ) );
	}
}
