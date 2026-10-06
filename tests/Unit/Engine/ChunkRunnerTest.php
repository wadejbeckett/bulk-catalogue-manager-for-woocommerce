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

require_once __DIR__ . '/RunnerStore.php';

final class ChunkRunnerTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	/**
	 * The fake clock: every reading advances it by $step.
	 */
	private float $now = 1790000000.0;

	private float $step = 1.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		$this->now                                = 1790000000.0;
		$this->step                               = 1.0;
		$GLOBALS['bulkcat_test']['user_caps']     = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['scheduled']     = [];
		$GLOBALS['bulkcat_test']['term_counting'] = [];
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [], 'The runner wrote meta.' );
	}

	/**
	 * A runner over the fake clock, the default settings (50 per chunk) and a 30-second limit.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 */
	private function runner( array $settings = [], int $limit = 30, ?\Closure $lease_clock = null ): ChunkRunner {
		return new ChunkRunner( null, new Registry( new Fields() ), null, new Settings( $settings ), fn(): float => $this->now += $this->step, $limit, $lease_clock );
	}

	/**
	 * A product whose save() also runs a callback, as a slow save hook would:
	 * after the save, or before it with `$before`.
	 */
	private static function hooked( int $id, \Closure $on_save, bool $before = false ): SavedProduct {
		$stored                                     = new SavedProduct(
			'simple',
			[
				'id'            => $id,
				'regular_price' => '10',
			]
		);
		$GLOBALS['bulkcat_test']['products'][ $id ] = new class( $stored, $on_save, $before ) {
			public function __construct( private SavedProduct $stored, private \Closure $on_save, private bool $before ) {}

			public function is_type( string $type ): bool {
				return $this->stored->is_type( $type );
			}

			public function save(): int {
				if ( $this->before ) {
					( $this->on_save )();
				}
				$id = $this->stored->save();
				if ( ! $this->before ) {
					( $this->on_save )();
				}
				return $id;
			}

			public function __call( string $method, array $args ): mixed {
				return $this->stored->$method( ...$args );
			}
		};
		return $stored;
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
			$GLOBALS['bulkcat_test']['products'][ $id ] = new SavedProduct(
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
		return (string) $GLOBALS['bulkcat_test']['products'][ $id ]->props['regular_price'];
	}

	/**
	 * The recorded scheduling calls of one kind: enqueue, single or unschedule.
	 *
	 * @return list<array>
	 */
	private static function scheduled( string $kind ): array {
		return array_values( array_filter( $GLOBALS['bulkcat_test']['scheduled'], static fn( array $call ): bool => $kind === $call[0] ) );
	}

	/**
	 * The claim statements run so far.
	 *
	 * @return list<string>
	 */
	private static function claims(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE `wp_bulkcat_batch_items` SET claim_token = \'' ) ) );
	}

	public function test_creating_a_batch_enqueues_its_first_chunk_through_the_public_api(): void {
		$hooks = Plugin::instance()->hooks();
		$this->assertContains( [ BatchFactory::CREATED, [ ChunkRunner::class, 'enqueue' ], 10, 1 ], $hooks );
		$this->assertContains( [ 'bulkcat_run_chunk', [ ChunkRunner::class, 'handle' ], 10, 2 ], $hooks );

		add_action( BatchFactory::CREATED, [ ChunkRunner::class, 'enqueue' ], 10, 1 );
		do_action( 'bulkcat_batch_created', 42 );

		$this->assertSame( [ [ 'enqueue', 'bulkcat_run_chunk', [ 42 ], 'bulk-catalogue-manager-for-woocommerce', null ] ], $GLOBALS['bulkcat_test']['scheduled'], 'One async action in the plugin group, and nothing else scheduled.' );

		// T83: the listener and a durable request's reconciliation may both reach one batch; it is queued once.
		do_action( 'bulkcat_batch_created', 42 );
		$this->assertTrue( ChunkRunner::ensure_queued( 42 ) );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['scheduled'], 'A batch already queued is not queued again.' );
		$this->assertTrue( ChunkRunner::ensure_queued( 43 ) );
		$this->assertCount( 2, $GLOBALS['bulkcat_test']['scheduled'], 'Another batch is queued on its own.' );
	}

	public function test_a_batch_is_still_queued_when_the_scheduling_lock_cannot_be_taken(): void {
		// Review finding: scheduling must never stop for want of the lock.
		foreach ( [
			'refused' => null,
			'timeout' => '0',
		] as $case => $answer ) {
			bulkcat_test_reset();
			$batch = 'refused' === $case ? 51 : 52;
			$GLOBALS['wpdb']->responses[ "SELECT GET_LOCK('bulkcat_schedule_" . md5( 'wp_bulkcat_batches|' . $batch ) . "', 10)" ] = $answer;
			$this->assertTrue( ChunkRunner::ensure_queued( $batch ), $case );
			$this->assertSame( [ [ 'enqueue', 'bulkcat_run_chunk', [ $batch ], 'bulk-catalogue-manager-for-woocommerce', null ] ], $GLOBALS['bulkcat_test']['scheduled'], "Queued unlocked when the lock is $case." );
			$this->assertSame( [], preg_grep( '/^SELECT RELEASE_LOCK/', $GLOBALS['wpdb']->queries ), 'A lock not taken is not released.' );
		}
	}

	public function test_a_batch_waiting_on_its_retry_is_not_queued_early(): void {
		// A chunk that stopped early backs off; resolving its request must not cut the delay short.
		as_schedule_single_action( time() + ChunkRunner::RETRY_DELAY, 'bulkcat_run_chunk', [ 44, ChunkRunner::RETRY ], 'bulk-catalogue-manager-for-woocommerce' );
		$this->assertTrue( ChunkRunner::ensure_queued( 44 ) );
		$this->assertSame( [], self::scheduled( 'enqueue' ), 'Nothing is queued before the retry.' );
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
		$this->assertSame( [ [ 'enqueue', 'bulkcat_run_chunk', [ self::BATCH ], 'bulk-catalogue-manager-for-woocommerce', null ] ], self::scheduled( 'enqueue' ), 'The successor is enqueued while items remain.' );
		$this->assertSame( [ [ 'unschedule', 'bulkcat_run_chunk', [ self::BATCH, 'retry' ], 'bulk-catalogue-manager-for-woocommerce', null ] ], self::scheduled( 'unschedule' ), 'A chunk that ends normally drops its dead-chunk retry.' );
		$this->assertSame( (int) $this->now - 1 + BatchRepository::LEASE_SECONDS + ChunkRunner::RETRY_DELAY, self::scheduled( 'single' )[0][4], 'The dead-chunk retry is set for just after the lease goes stale.' );

		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( [ 'done' => 60 ], array_count_values( $store->states() ) );
		$this->assertCount( 1, self::scheduled( 'enqueue' ), 'No successor once nothing is pending.' );
		$batch = $store->batch();
		$this->assertSame( [ 'complete', 60, 60, 0 ], [ $batch['status'], $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $batch['finished_gmt'] ) );
		$this->assertCount( 60, $store->journal(), 'One journal row per object and touched field.' );
	}

	public function test_each_chunk_releases_the_runtime_cache_once_its_items_are_written(): void {
		// T76: a chained batch under `wp action-scheduler run` kept every chunk's objects cached.
		$ids      = self::products( 60 );
		$store    = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$released = static fn(): int => count( array_filter( $GLOBALS['bulkcat_test']['calls'] ?? [], static fn( array $call ): bool => 'wp_cache_flush_runtime' === $call[0] ) );

		$this->runner()->run( self::BATCH );
		$this->assertSame( 1, $released() );
		$this->assertSame( '11.00', self::price( 1050 ), 'Released after the chunk\'s writes.' );
		$this->runner()->run( self::BATCH );
		$this->assertSame( 2, $released() );
		$this->assertSame( 'complete', $store->batch()['status'] );
		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 2, $released(), 'A run that writes nothing releases nothing.' );
	}

	public function test_each_chunk_forgets_the_per_object_hook_names_its_writes_made_wordpress_count(): void {
		// Ticket 11 item 2: WooCommerce clears transients named after each product it saves, and
		// WordPress counts every hook name for the life of the process; one WP-CLI queue run kept them all.
		$saved                 = [ $GLOBALS['wp_actions'] ?? null, $GLOBALS['wp_filters'] ?? null ];
		$GLOBALS['wp_actions'] = [
			'init'                  => 1,
			'delete_transient_wc_7' => 1,
		];
		$GLOBALS['wp_filters'] = [ 'the_title' => 1 ];
		try {
			$ids = self::products( 60 );
			foreach ( $ids as $id ) {
				self::hooked(
					$id,
					static function () use ( $id ): void {
						$GLOBALS['wp_actions'][ 'delete_transient_wc_var_prices_' . $id ] = 1;
						$GLOBALS['wp_filters'][ 'pre_transient_wc_related_' . $id ]       = 1;
						$GLOBALS['wp_actions']['woocommerce_update_product']              = ( $GLOBALS['wp_actions']['woocommerce_update_product'] ?? 0 ) + 1;
						// Another plugin hooked to the save counts a name of its own: it keeps its count (Main Build's review).
						$GLOBALS['wp_actions'][ 'my_sync_' . $id ] = 1;
					}
				);
			}
			$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );

			$sync   = static fn( int $count ): array => array_fill_keys( array_map( static fn( int $id ): string => 'my_sync_' . $id, array_slice( $ids, 0, $count ) ), 1 );
			$sorted = static function ( array $names ): array {
				ksort( $names );
				return $names;
			};
			$this->runner()->run( self::BATCH );
			$this->assertSame(
				$sorted(
					[
						'init'                       => 1,
						'delete_transient_wc_7'      => 1,
						'woocommerce_update_product' => 50,
					] + $sync( 50 )
				),
				$sorted( $GLOBALS['wp_actions'] ),
				'The chunk\'s 50 per-product transient names are forgotten; every other name keeps its count.'
			);
			$this->assertSame( [ 'the_title' => 1 ], $GLOBALS['wp_filters'] );
			$this->runner()->run( self::BATCH );
			$this->assertSame(
				$sorted(
					[
						'init'                       => 1,
						'delete_transient_wc_7'      => 1,
						'woocommerce_update_product' => 60,
					] + $sync( 60 )
				),
				$sorted( $GLOBALS['wp_actions'] ),
				'Flat across chunks, but for the other plugin\'s own names.'
			);
			$this->assertSame( 'complete', $store->batch()['status'] );
		} finally {
			[ $GLOBALS['wp_actions'], $GLOBALS['wp_filters'] ] = $saved;
		}
	}

	public function test_the_chunk_size_follows_the_setting_and_the_filter_within_floor_and_ceiling(): void {
		foreach ( [ [ [ 'batch_size' => 20 ], null, 20 ], [ [], 3, 10 ], [ [], 500, 100 ], [ [ 'batch_size' => 10 ], 30, 30 ] ] as [ $settings, $filtered, $expected ] ) {
			bulkcat_test_reset();
			$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
			if ( null !== $filtered ) {
				add_filter( 'bulkcat_batch_size', static fn(): int => $filtered );
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
		unset( $GLOBALS['bulkcat_test']['products'][1002] );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_post:1003'] = false;
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
			$this->assertStringNotContainsString( 'bulkcat_', $item['message'] );
		}
		$batch = $store->batch();
		$this->assertSame( [ 'partial', 3, 1, 1 ], [ $batch['status'], $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );

		// Nothing applied and a failure: failed.
		bulkcat_test_reset();
		$store = new RunnerStore( self::BATCH, [ 2001, 2002 ], $this->increase(), self::USER );
		$this->assertSame( 'failed', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 'failed', $store->batch()['status'] );
		$this->assertNotNull( $store->batch()['finished_gmt'] );
	}

	public function test_item_state_and_message_from_a_write(): void {
		$changed = Result::changed( 'regular_price', '10', '11' );
		$skip    = Result::skipped( 'sale_price', '5', 'The sale price must be below the regular price, so it was not changed.', 'bulkcat_invalid_value' );
		$again   = Result::skipped( 'regular_price', null, 'Already done.', Journal::ALREADY_DONE );
		$fail    = Result::failed( 'sku', 'A', 'A product with this SKU already exists.', 'bulkcat_invalid_value' );

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
		$this->assertSame( 'bulkcat_run_chunk', self::scheduled( 'single' )[0][1] );
		$this->assertSame( [ self::BATCH, 'retry' ], self::scheduled( 'single' )[0][2] );

		// Past the lease the claim is stale: released and retried, the second attempt.
		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 2, $store->item( 1001 )['attempts'] );
		$this->assertSame( [ '11.00', '11.00' ], [ self::price( 1001 ), self::price( 1002 ) ] );

		// Three dead chunks in a row: the next run fails the item with a plain reason.
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$store                                = new RunnerStore( self::BATCH, self::products( 1, 3001 ), $this->increase(), self::USER );
		foreach ( [ 'b', 'c', 'd' ] as $token ) {
			$this->assertCount( 1, $repo->claim_chunk( self::BATCH, 50, str_repeat( $token, 32 ), $gmt(), $gmt( -300 ) ) );
			$this->now += BatchRepository::LEASE_SECONDS + 1;
		}
		$this->assertSame( 3, $store->item( 3001 )['attempts'] );
		$this->assertSame( 'failed', $this->runner()->run( self::BATCH ) );
		$item = $store->item( 3001 );
		$this->assertSame( 'failed', $item['state'] );
		$this->assertStringContainsString( 'three tries', $item['message'] );
		$this->assertStringNotContainsString( 'bulkcat_', $item['message'] );
		$this->assertSame( '10', self::price( 3001 ), 'Given up on, never written.' );
		$batch = $store->batch();
		$this->assertSame( [ 1, 0, 1 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
	}

	/**
	 * Run one chunk as a process that a product's save kills: a save that
	 * suspends the fiber stops the run there and nothing after it in the
	 * run happens. The fiber is kept, never resumed.
	 *
	 * @param list<\Fiber> $dead Killed runs, kept alive.
	 */
	private function killed_run( array &$dead ): void {
		$fiber = new \Fiber( fn(): string => $this->runner()->run( self::BATCH ) );
		$fiber->start();
		$this->assertTrue( $fiber->isSuspended(), 'The run was killed.' );
		$dead[] = $fiber;
	}

	public function test_a_product_that_kills_its_chunk_three_times_fails_alone_and_written_products_stay_done(): void {
		// ENGINE-2: a product whose save kills the process every time, in a chunk with products before and after it.
		$ids   = self::products( 5 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		// Its save kills the run before anything is stored, so the value set in memory is lost with the process.
		$poison = null;
		$poison = self::hooked(
			1003,
			static function () use ( &$poison ): void {
				$poison->props['regular_price'] = '10';
				\Fiber::suspend();
			},
			true
		);
		$dead   = [];

		$this->killed_run( $dead );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'done',
				1003 => 'working',
				1004 => 'pending',
				1005 => 'pending',
			],
			$store->states(),
			'Each product is marked as soon as it is written, so a killed run loses no marks.'
		);
		$this->assertSame( [ 2, 2, 0 ], [ $store->batch()['done_items'], $store->batch()['changed_items'], $store->batch()['failed_items'] ] );

		// Two more killed runs past the lease: the same product kills each.
		foreach ( [ 2, 3 ] as $attempt ) {
			$this->now += BatchRepository::LEASE_SECONDS + 1;
			$this->killed_run( $dead );
			$this->assertSame( $attempt, $store->item( 1003 )['attempts'] );
		}

		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->assertSame( 'partial', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'done',
				1003 => 'failed',
				1004 => 'failed',
				1005 => 'failed',
			],
			$store->states(),
			'The written products stay done; only the product that stopped the run and those never reached are not.'
		);
		$this->assertSame( [ '11.00', '11.00', '10', '10', '10' ], [ self::price( 1001 ), self::price( 1002 ), (string) $poison->props['regular_price'], self::price( 1004 ), self::price( 1005 ) ] );
		$culprit = $store->item( 1003 )['message'];
		$this->assertStringContainsString( 'stopped', $culprit );
		$this->assertStringContainsString( 'may not have been finished', $culprit );
		$unreached = $store->item( 1004 )['message'];
		$this->assertStringContainsString( 'not reached', $unreached );
		$this->assertStringNotContainsString( 'not changed', $unreached );
		$this->assertNotSame( $culprit, $unreached );
		$batch = $store->batch();
		$this->assertSame( [ 5, 2, 3 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ], 'History counts the two written products as changed.' );

		// The history's grouped reasons name both, though the product that stopped the run holds unsettled journal rows.
		$messages = array_column( ( new \BulkCatalogueManager\Engine\Progress( new BatchRepository() ) )->summary( self::BATCH ), 'count', 'message' );
		$this->assertSame( 1, $messages[ $culprit ] ?? null );
		$this->assertSame( 2, $messages[ $unreached ] ?? null );

		// Destroying the killed runs, last first, unwinds their finally blocks, so the state they changed does not leak into other tests.
		while ( [] !== $dead ) {
			array_pop( $dead );
		}
	}

	public function test_an_exhausted_item_the_journal_holds_as_applied_is_done_not_failed(): void {
		// ENGINE-2: a product written and journalled by a run killed before its mark, on each of three tries.
		$ids   = self::products( 2 );
		$list  = $this->increase();
		$store = new RunnerStore( self::BATCH, $ids, $list, self::USER );
		$repo  = new BatchRepository();
		foreach ( [ 'a', 'b', 'c' ] as $token ) {
			$repo->claim_chunk( self::BATCH, 50, str_repeat( $token, 32 ), gmdate( 'Y-m-d H:i:s', (int) $this->now ), gmdate( 'Y-m-d H:i:s', (int) $this->now - 300 ) );
			if ( 'a' === $token ) {
				Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), new Settings( [] ) ), self::BATCH, self::USER )->write( 1001, $list );
			}
			$this->now += BatchRepository::LEASE_SECONDS + 1;
		}

		$this->assertSame( 'partial', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'failed',
			],
			$store->states()
		);
		$this->assertSame( '', $store->item( 1001 )['message'] );
		$this->assertSame( [ 2, 1, 1 ], [ $store->batch()['done_items'], $store->batch()['changed_items'], $store->batch()['failed_items'] ] );
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
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['products'][1001]->saves );
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
		$batch = $store->batch();
		$this->assertSame( [ 3, 3, 0 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ], 'SILENT-FAILURES-3: the product the killed run wrote counts as changed.' );
	}

	public function test_a_rerun_counts_as_changed_only_what_the_journal_holds_as_applied(): void {
		// SILENT-FAILURES-3 / ENGINE-8: a killed run journalled one product as applied and one as skipped.
		$ids   = self::products( 3 );
		$list  = $this->increase();
		$store = new RunnerStore( self::BATCH, $ids, $list, self::USER );
		$GLOBALS['bulkcat_test']['products'][1002]->props['status'] = 'trash';
		( new BatchRepository() )->claim_chunk( self::BATCH, 50, str_repeat( 'e', 32 ), gmdate( 'Y-m-d H:i:s', (int) $this->now ), gmdate( 'Y-m-d H:i:s', (int) $this->now - 300 ) );
		$writer = Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), new Settings( [] ) ), self::BATCH, self::USER );
		$writer->write( 1001, $list );
		$writer->write( 1002, $list );

		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->runner()->run( self::BATCH );

		$batch = $store->batch();
		$this->assertSame( [ 3, 2, 0 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ], 'The binned product, journalled as skipped, is not counted as changed.' );
	}

	public function test_an_undo_whose_writes_all_landed_in_a_killed_run_does_not_say_nothing_was_put_back(): void {
		// SILENT-FAILURES-3: every value of an undo was put back by a run killed before marking.
		$ids                       = self::products( 2 );
		$list                      = $this->increase();
		$store                     = new RunnerStore( self::BATCH, $ids, $list, self::USER );
		$store->batch['source']    = 'undo';
		$store->batch['undo_of']   = 7;
		$store->batch['selection'] = \BulkCatalogueManager\Data\ValueCodec::encode(
			[
				'kind'  => 'undo',
				'force' => true,
			]
		);
		( new BatchRepository() )->claim_chunk( self::BATCH, 50, str_repeat( 'e', 32 ), gmdate( 'Y-m-d H:i:s', (int) $this->now ), gmdate( 'Y-m-d H:i:s', (int) $this->now - 300 ) );
		$writer = Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), new Settings( [] ) ), self::BATCH, self::USER );
		foreach ( $ids as $id ) {
			$writer->write( $id, $list );
		}
		foreach ( $store->items as $item_id => $item ) {
			$store->items[ $item_id ]['operations'] = (string) wp_json_encode( $list );
		}

		$this->now += BatchRepository::LEASE_SECONDS + 1;
		$this->runner()->run( self::BATCH );

		$batch = ( new BatchRepository() )->find( self::BATCH );
		$this->assertSame( 2, $batch['changed_items'] );
		$this->assertStringNotContainsString( 'Nothing was put back', (string) \BulkCatalogueManager\Engine\Progress::undo_summary( $batch )['sentence'] );
	}

	public function test_a_member_moved_to_the_bin_after_apply_is_unavailable_and_never_written(): void {
		// Found on the external write path and true here: a product binned between Apply and its chunk
		// is unavailable at write time, as a member binned before Apply is: skipped, not written, not failed.
		$ids   = self::products( 3 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$GLOBALS['bulkcat_test']['products'][1002]->props['status'] = 'trash';

		$this->assertSame( 'partial', $this->runner()->run( self::BATCH ) );
		$this->assertSame( '10', self::price( 1002 ), 'Not written.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][1002]->saves );
		$this->assertSame( [ '11.00', '11.00' ], [ self::price( 1001 ), self::price( 1003 ) ] );
		$item = array_values( array_filter( $store->items_of( self::BATCH ), static fn( array $row ): bool => 1002 === $row['object_id'] ) )[0];
		$this->assertSame( 'skipped', $item['state'], 'Skipped, never failed.' );
		$this->assertSame( \BulkCatalogueManager\Engine\BatchFactory::unavailable_reason(), $item['message'] );
		$this->assertContains( [ 1002, 'regular_price', '10', '10', 'skipped' ], $store->journal() );
		$this->assertSame( 0, $store->batch()['failed_items'] );
	}

	public function test_a_run_writes_only_the_items_it_still_holds(): void {
		$ids   = self::products( 3 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		// While the first product's save hook runs past the lease, another run takes the other two over.
		$first = self::hooked(
			1001,
			static function () use ( $store ): void {
				foreach ( [ 1002, 1003 ] as $object_id ) {
					$item = $store->item( $object_id );
					$store->items[ $item['item_id'] ]['claim_token'] = str_repeat( 'f', 32 );
				}
			}
		);

		$this->assertSame( 'continued', $this->runner()->run( self::BATCH ) );

		$this->assertSame( [ '11.00', '10', '10' ], [ (string) $first->props['regular_price'], self::price( 1002 ), self::price( 1003 ) ], 'The items another run holds are not written twice.' );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'pending',
				1003 => 'pending',
			],
			$store->states(),
			'Nor marked: they are the other run\'s to finish.'
		);
		$this->assertSame( str_repeat( 'f', 32 ), $store->item( 1002 )['claim_token'] );
		$this->assertSame( [ [ 1001, 'regular_price', '10', '11.00', 'applied' ] ], $store->journal() );
		$this->assertSame( [ 1, 1, 0 ], [ $store->batch()['done_items'], $store->batch()['changed_items'], $store->batch()['failed_items'] ] );
	}

	public function test_a_run_whose_chunk_another_run_took_over_never_counts_twice(): void {
		// ENGINE-5: the first product's save hook outlasts the lease, and the delayed retry runs the whole chunk meanwhile.
		$ids   = self::products( 3 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$later = null;
		self::hooked(
			1001,
			function () use ( &$later ): void {
				if ( null === $later ) {
					$this->now += BatchRepository::LEASE_SECONDS + 1;
					$later      = $this->runner()->run( self::BATCH );
				}
			}
		);

		$this->runner()->run( self::BATCH );

		$this->assertSame( 'complete', $later );
		$this->assertSame( [ 'done' => 3 ], array_count_values( $store->states() ) );
		$batch = $store->batch();
		$this->assertSame( [ 3, 3, 0 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ], 'Each item is counted once, so progress never passes 100%.' );
	}

	public function test_a_second_run_waits_for_an_item_the_first_claimed_late_and_is_still_writing(): void {
		// ENGINE-5: the chunk was claimed at T; the first run reaches 1002 just inside its lease share (T+230) and is
		// still saving it when the delayed retry starts (T+361: past the chunk's lease, not past 1002's own).
		$ids    = self::products( 3 );
		$store  = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$lease  = 1000.0;
		$clock  = static function () use ( &$lease ): float {
			return $lease;
		};
		$second = null;
		self::hooked(
			1001,
			function () use ( &$lease ): void {
				$this->now += 230;
				$lease     += 230;
			}
		);
		$late = self::hooked(
			1002,
			function () use ( &$second, &$lease, $store, $clock ): void {
				if ( null === $second ) {
					$this->now += 131;
					$lease     += 131;
					$second     = [ $this->runner( [], 30, $clock )->run( self::BATCH ), $store->batch()['status'], $store->states() ];
				}
			},
			true
		);

		$this->runner( [], 30, $clock )->run( self::BATCH );

		$this->assertNotSame( 'complete', $second[0], 'The second run never finalises while the first is still writing an item.' );
		$this->assertSame( 'running', $second[1] );
		$this->assertSame( 'working', $second[2][1002], 'The item being written is left to the run writing it.' );
		$this->assertCount( 1, $late->saves, 'Written once.' );
		$this->assertSame( '11.00', (string) $late->props['regular_price'] );
		$this->assertSame( [ 'done' => 3 ], array_count_values( $store->states() ) );
		$batch = $store->batch();
		$this->assertSame( [ 3, 3, 0 ], [ $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
		$this->assertSame( 'complete', $batch['status'], 'The batch completes once the last write is finished.' );
	}

	public function test_a_run_never_marks_an_item_another_run_now_holds(): void {
		// ENGINE-5: while the first run is still saving 1001, another run takes the item over and is writing it.
		$ids   = self::products( 2 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		self::hooked(
			1001,
			static function () use ( $store ): void {
				$item = $store->item( 1001 );
				$store->items[ $item['item_id'] ]['claim_token'] = str_repeat( 'f', 32 );
			}
		);

		$this->assertSame( 'continued', $this->runner()->run( self::BATCH ), 'The item another run is writing is still open, so the batch is not finalised.' );
		$this->assertSame(
			[
				1001 => 'working',
				1002 => 'done',
			],
			$store->states(),
			'Left for the run that holds it to mark.'
		);
		$this->assertSame( 1, $store->batch()['done_items'] );

		$this->assertTrue( ( new BatchRepository() )->finish_item( self::BATCH, $store->item( 1001 )['item_id'], str_repeat( 'f', 32 ), 'done', '', true ) );
		$this->assertSame( [ 2, 2, 0 ], [ $store->batch()['done_items'], $store->batch()['changed_items'], $store->batch()['failed_items'] ] );
	}

	public function test_a_run_stops_claiming_items_past_most_of_the_lease(): void {
		self::products( 3 );
		$store = new RunnerStore( self::BATCH, [ 1001, 1002, 1003 ], $this->increase(), self::USER );
		$lease = 0.0;
		// The first product's save hooks take four and a half minutes of the five-minute lease.
		$first = self::hooked(
			1001,
			static function () use ( &$lease ): void {
				$lease += 270.0;
			}
		);

		$clock = static function () use ( &$lease ): float {
			return $lease;
		};

		$this->assertSame( 'continued', $this->runner( [], 30, $clock )->run( self::BATCH ) );

		$this->assertSame( [ '11.00', '10', '10' ], [ (string) $first->props['regular_price'], self::price( 1002 ), self::price( 1003 ) ] );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'pending',
				1003 => 'pending',
			],
			$store->states()
		);
		$this->assertNull( $store->item( 1002 )['claim_token'], 'The rest are released at once for the next run.' );
		$this->assertSame( [ 'enqueue', 'bulkcat_run_chunk', [ self::BATCH ], 'bulk-catalogue-manager-for-woocommerce', null ], self::scheduled( 'enqueue' )[0] ?? null );

		// The successor finishes them.
		$this->assertSame( 'complete', $this->runner( [], 30, $clock )->run( self::BATCH ) );
		$this->assertSame( [ '11.00', '11.00' ], [ self::price( 1002 ), self::price( 1003 ) ] );
	}

	public function test_a_stale_working_item_goes_back_to_pending_and_is_finished(): void {
		$ids   = self::products( 1 );
		$store = new RunnerStore( self::BATCH, $ids, $this->increase(), self::USER );
		$item  = $store->item( 1001 );
		// A run claimed it for itself and died mid-write.
		$store->items[ $item['item_id'] ] = [
			'state'       => 'working',
			'claim_token' => str_repeat( 'a', 32 ),
			'claimed_gmt' => gmdate( 'Y-m-d H:i:s', (int) $this->now - BatchRepository::LEASE_SECONDS - 1 ),
			'attempts'    => 1,
		] + $item;

		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( '11.00', self::price( 1001 ) );
		$this->assertSame( [ 1001 => 'done' ], $store->states() );
	}

	public function test_no_time_limit_is_taken_as_the_lease(): void {
		// WP-CLI and some cron runners have none: a chunk is bounded by its lease instead.
		$previous = (string) ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.IniSet.max_execution_time_Disallowed -- restored below.
		try {
			$this->assertSame( BatchRepository::LEASE_SECONDS, ChunkRunner::php_time_limit() );
		} finally {
			ini_set( 'max_execution_time', $previous ); // phpcs:ignore WordPress.PHP.IniSet.max_execution_time_Disallowed -- restoring the test's own change.
		}
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
	}

	public function test_term_counting_is_deferred_around_the_work_and_flushed_even_when_it_throws(): void {
		new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::USER );
		$this->runner()->run( self::BATCH );
		$this->assertSame( [ [ true, 0 ], [ false, 3 ] ], $GLOBALS['bulkcat_test']['term_counting'], 'On before the first product loads, flushed after the last.' );

		// The journal refuses its row: the chunk stops, is flushed, and the error is logged by the queue.
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps']     = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['scheduled']     = [];
		$GLOBALS['bulkcat_test']['term_counting'] = [];
		$store                                    = new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::USER );
		$GLOBALS['wpdb']->failing                 = [ 'insert' ];
		try {
			$this->runner()->run( self::BATCH );
			$this->fail( 'The journal failure was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The change could not be journalled.', $e->getMessage() );
		}
		$this->assertSame( [ [ true, 0 ], [ false, 1 ] ], $GLOBALS['bulkcat_test']['term_counting'] );
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
		$this->assertSame( [ 'unschedule', 'bulkcat_run_chunk', [ self::BATCH, 'retry' ], 'bulk-catalogue-manager-for-woocommerce', null ], $GLOBALS['bulkcat_test']['scheduled'][1] );
	}

	/**
	 * Make the reads matching a pattern fail as the database fails them: no value, and the error recorded.
	 */
	private static function failing_read( string $pattern ): void {
		global $wpdb;
		$wpdb->responses = [
			$pattern => static function () use ( $wpdb ): ?string {
				$wpdb->last_error = 'Lock wait timeout exceeded';
				return null;
			},
		] + $wpdb->responses;
	}

	public function test_a_count_that_cannot_be_read_never_finalises_the_batch(): void {
		// SILENT-FAILURES-5: the pending count fails after the first chunk while ten items are still pending.
		$store = new RunnerStore( self::BATCH, self::products( 60 ), $this->increase(), self::USER );
		self::failing_read( "/^SELECT COUNT\\(\\*\\) FROM `wp_bulkcat_batch_items` WHERE batch_id = \\d+ AND state IN \\('pending', 'working'\\)$/" );

		$error = null;
		try {
			$this->runner()->run( self::BATCH );
		} catch ( \RuntimeException $e ) {
			$error = $e;
		}
		$this->assertStringContainsString( 'could not be counted', null === $error ? 'A count that cannot be read is an error, never zero.' : $error->getMessage() );
		$this->assertSame( 'running', $store->batch()['status'], 'Not finalised with items pending.' );
		$this->assertSame( 10, array_count_values( $store->states() )['pending'] );
		$this->assertSame( [ 'single', 'bulkcat_run_chunk', [ self::BATCH, 'retry' ] ], array_slice( end( $GLOBALS['bulkcat_test']['scheduled'] ), 0, 3 ), 'A retry settles it once the database answers.' );

		// The database answers again: the retry finishes the batch.
		global $wpdb;
		$wpdb->last_error = '';
		array_shift( $wpdb->responses );
		$this->assertSame( 'complete', $this->runner()->run( self::BATCH ) );
		$this->assertSame( [ 'done' => 60 ], array_count_values( $store->states() ) );

		// A failed applied count is an error too, never a batch that put nothing back.
		$wpdb->writes = [];
		$store        = new RunnerStore( self::BATCH, self::products( 1, 2001 ), $this->increase(), self::USER );
		self::failing_read( "/^SELECT COUNT\\(\\*\\) FROM `wp_bulkcat_changes` WHERE batch_id = \\d+ AND result = 'applied'$/" );
		$error = null;
		try {
			$this->runner()->run( self::BATCH );
		} catch ( \RuntimeException $e ) {
			$error = $e;
		}
		$wpdb->last_error = '';
		$this->assertNotNull( $error );
		$this->assertSame( 'running', $store->batch()['status'] );
	}

	public function test_a_batch_that_is_not_queued_or_running_is_left_alone(): void {
		foreach ( [ 'cancelled', 'complete', 'partial', 'failed', 'undone' ] as $status ) {
			bulkcat_test_reset();
			$GLOBALS['bulkcat_test']['scheduled'] = [];
			$store                                = new RunnerStore( self::BATCH, self::products( 2 ), $this->increase(), self::USER );
			$store->batch['status']               = $status;
			$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
			$this->assertSame( [], self::claims(), "A $status batch is not claimed." );
			$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		}
		bulkcat_test_reset();
		new RunnerStore( self::BATCH, [], [], self::USER );
		$this->assertSame( 'missing', $this->runner()->run( 99 ) );
	}

	/**
	 * Run $before ahead of the store's answer to the statement a pattern matches.
	 */
	private function wrap( string $prefix, \Closure $before ): void {
		global $wpdb;
		foreach ( $wpdb->responses as $pattern => $answer ) {
			if ( str_starts_with( (string) $pattern, $prefix ) ) {
				$wpdb->responses[ $pattern ] = static function ( string $sql ) use ( $answer, $before ): mixed {
					$before();
					return $answer( $sql );
				};
				return;
			}
		}
		$this->fail( "No store answer starts $prefix." );
	}

	public function test_every_status_transition_the_engine_writes_is_enumerated(): void {
		$root  = dirname( __DIR__, 3 ) . '/src';
		$calls = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code     = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			$this->assertSame( 0, preg_match( '/->set_status\s*\(/', $code ), "$relative sets a status without transition()." );
			preg_match_all( '/->transition\(\s*\$\w+,\s*([^,]+?),\s*([^)]+?)\s*\)/', $code, $found, PREG_SET_ORDER );
			foreach ( $found as [ , $from, $to ] ) {
				$calls[] = $relative . ': ' . $from . ' -> ' . $to;
			}
		}
		sort( $calls );
		$this->assertSame(
			[
				"Categories/Deleter.php: 'running' -> \$status",
				"Categories/Writer.php: 'running' -> \$status",
				"Data/BatchRepository.php: 'running' -> \$closure['write_status']",
				"Engine/BatchFactory.php: \$previous -> 'undoing'",
				"Engine/BatchFactory.php: 'queued' -> 'running'",
				"Engine/BatchFactory.php: 'queued' -> 'running'",
				"Engine/ChunkRunner.php: 'queued' -> 'running'",
				"Engine/ChunkRunner.php: 'running' -> \$status",
				"Engine/InlineWrite.php: 'running' -> \$status",
				"Engine/Progress.php: 'queued' -> 'cancelled'",
				"Engine/Progress.php: 'running' -> 'cancelled'",
				"Requests/BatchAcceptance.php: \$previous -> 'undoing'",
				"Undo/Original.php: 'undoing' -> \$previous",
				"Undo/Original.php: 'undoing' -> 'undone'",
			],
			$calls,
			'The engine writes queued -> running -> complete, partial, failed or cancelled (with inline closure committed atomically), a category write finishes its batch of one, a category delete or merge finishes its batch, an undo moves the batch it undoes to undoing and then to undone or back, and nothing else.'
		);

		// The batch an undo starts on came from a finished status (the planner refuses any other), and goes back only to one.
		$source = (string) file_get_contents( $root . '/Engine/BatchFactory.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertStringContainsString( "\$previous = \$original['status'];", $source );

		// The one variable target is the final status, which is only ever complete, partial or failed.
		$finals = [];
		foreach ( range( 0, 3 ) as $reported ) {
			foreach ( range( 0, 3 ) as $failed ) {
				foreach ( range( 0, 2 ) as $applied ) {
					$finals[ ChunkRunner::final_status( $reported, $failed, $applied ) ] = true;
				}
			}
		}
		ksort( $finals );
		$this->assertSame( [ 'complete', 'failed', 'partial' ], array_keys( $finals ) );
	}

	public function test_a_cancellation_between_the_status_read_and_the_claim_releases_the_claim(): void {
		$store                  = new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::USER );
		$store->batch['status'] = 'running';
		// The cancellation lands as the claim is taken.
		$this->wrap(
			"/^UPDATE `wp_bulkcat_batch_items` SET claim_token = '/",
			static function () use ( $store ): void {
				$store->batch['status'] = 'cancelled';
			}
		);

		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				1001 => 'pending',
				1002 => 'pending',
				1003 => 'pending',
			],
			$store->states()
		);
		foreach ( $store->items as $item ) {
			$this->assertNull( $item['claim_token'], 'The claim is given back.' );
		}
		$this->assertSame( '10', self::price( 1001 ), 'Nothing was written.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'], 'No retry and no successor.' );
		$this->assertSame( 'cancelled', $store->batch()['status'] );
	}

	public function test_a_cancellation_during_a_chunk_keeps_its_work_and_ends_the_chain(): void {
		$store = new RunnerStore( self::BATCH, self::products( 60 ), $this->increase(), self::USER );
		// The screen cancels while the first chunk is under way: it lands as the chunk counts its first product.
		$this->wrap(
			'/^UPDATE `wp_bulkcat_batch_items` AS i INNER JOIN `wp_bulkcat_batches` AS b /',
			static function () use ( $store ): void {
				$store->sync();
				$store->batch['status'] = 'cancelled';
			}
		);

		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame(
			[
				'done'    => 50,
				'pending' => 10,
			],
			array_count_values( $store->states() ),
			'The chunk under way finishes; the rest stay pending.'
		);
		$batch = $store->batch();
		$this->assertSame( [ 'cancelled', 50, 50 ], [ $batch['status'], $batch['done_items'], $batch['changed_items'] ] );
		$this->assertSame( [], self::scheduled( 'enqueue' ), 'No successor after a cancellation.' );
		$this->assertCount( 50, $store->journal(), 'The applied part is journalled, so it can be undone.' );

		// A queued action that runs later exits without claiming.
		$claims = count( self::claims() );
		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertCount( $claims, self::claims() );
		$this->assertSame( '10', self::price( 1051 ) );
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
	public function test_a_legacy_unclosed_inline_batch_is_never_claimed_or_queued(): void {
		$store                  = new RunnerStore( self::BATCH, [ 101 ], [], self::USER );
		$store->batch['source'] = 'inline';
		$store->batch['status'] = 'running';
		$this->assertFalse( ChunkRunner::ensure_queued( self::BATCH ) );
		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE' ) ) );
	}
}
