<?php
/**
 * T60, history retention (SPEC §9.5, §11, §12.4): the daily
 * `bulkcat_prune_history` action is scheduled once on activation and unscheduled
 * on deactivation; each run deletes the item and journal rows of batches
 * finished before the retention limit in bounded statements, re-enqueues
 * itself while work remains, never touches a queued, running or undoing
 * batch, and leaves the batch row with its counters, marked pruned. Keeping
 * history indefinitely prunes nothing.
 *
 * The tables live in memory. The repositories' real prepared statements run
 * against them: the due-batch SELECT through the SQL evaluator, and each
 * DELETE and the marking UPDATE by evaluating their WHERE clause there too,
 * so the conditions are tested, not their text.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\Cleanup;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Engine\Pruner;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase {
	/**
	 * The fixed "now" of every run: 23 September 2026, 12:00 GMT.
	 */
	private const NOW = 1790164800;

	private const DAY = 86400;

	/**
	 * Tables by full name => rows keyed by primary key.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $tables = [];

	private int $next_item = 0;

	private int $next_change = 0;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['scheduled'] = [];
		$this->tables                         = [
			'wp_bulkcat_batches'     => [],
			'wp_bulkcat_batch_items' => [],
			'wp_bulkcat_changes'     => [],
		];
		$this->next_item                      = 0;
		$this->next_change                    = 0;

		global $wpdb;
		$wpdb->responses = [
			'/^SELECT b\.batch_id AS batch_id FROM `wp_bulkcat_batches` AS b /' => fn( string $sql ): array => array_column( $this->db()->rows( $sql ), 'batch_id' ),
			'/^UPDATE `wp_bulkcat_batches` SET note = /' => fn( string $sql ): int => $this->mark( $sql ),
			'/^DELETE FROM `(wp_bulkcat_changes|wp_bulkcat_batch_items)` WHERE /' => fn( string $sql ): int => $this->delete( $sql ),
		];
	}

	/*
	 * The in-memory tables.
	 */

	private function db(): SqlEvaluator {
		return new SqlEvaluator( array_map( 'array_values', $this->tables ) );
	}

	/**
	 * Seed a batch finished `$days` ago with items and journal rows.
	 */
	private function batch( int $id, float $days, string $status = 'complete', int $items = 2, int $changes = 3 ): void {
		$this->tables['wp_bulkcat_batches'][ $id ] = [
			'batch_id'        => $id,
			'uuid'            => sprintf( '00000000-0000-4000-8000-%012d', $id ),
			'user_id'         => 3,
			'created_gmt'     => gmdate( 'Y-m-d H:i:s', (int) ( self::NOW - $days * self::DAY ) - 60 ),
			'finished_gmt'    => gmdate( 'Y-m-d H:i:s', (int) ( self::NOW - $days * self::DAY ) ),
			'status'          => $status,
			'source'          => 'bulk',
			'undo_of'         => null,
			'operations'      => '[]',
			'selection'       => '{"ids":[]}',
			'total_items'     => $items,
			'previewed_items' => null,
			'done_items'      => $items,
			'changed_items'   => $items - 1,
			'failed_items'    => 1,
			'chunk_size'      => 50,
			'note'            => '',
			'actions'         => '[]',
			'pruned_gmt'      => null,
		];
		for ( $i = 1; $i <= $items; $i++ ) {
			$item_id = ++$this->next_item;
			$this->tables['wp_bulkcat_batch_items'][ $item_id ] = [
				'item_id'     => $item_id,
				'batch_id'    => $id,
				'object_id'   => 1000 + $i,
				'object_type' => 'product',
				'state'       => 'done',
				'attempts'    => 1,
				'claim_token' => null,
				'claimed_gmt' => null,
				'message'     => '',
			];
		}
		for ( $i = 1; $i <= $changes; $i++ ) {
			$change_id                                        = ++$this->next_change;
			$this->tables['wp_bulkcat_changes'][ $change_id ] = [
				'change_id'    => $change_id,
				'batch_id'     => $id,
				'object_id'    => 1000 + $i,
				'object_type'  => 'product',
				'prop'         => 'regular_price',
				'old_value'    => '"10.00"',
				'new_value'    => '"11.00"',
				'modified_gmt' => '2020-01-01 00:00:00',
				'applied_gmt'  => '2020-01-01 00:00:00',
				'result'       => 'applied',
				'message'      => '',
			];
		}
	}

	/**
	 * Answer a DELETE … WHERE … ORDER BY key ASC LIMIT n by selecting the
	 * same rows through the evaluator and removing them.
	 */
	private function delete( string $sql ): int {
		$this->assertSame( 1, preg_match( '/^DELETE FROM `(\w+)` WHERE (.+) ORDER BY (\w+) ASC LIMIT (\d+)$/s', $sql, $m ), "Unexpected DELETE: $sql" );
		[ , $table, $where, $key, $limit ] = $m;
		$ids                               = array_column( $this->db()->rows( "SELECT $key FROM `$table` WHERE $where ORDER BY $key ASC LIMIT $limit" ), $key );
		foreach ( $ids as $id ) {
			unset( $this->tables[ $table ][ (int) $id ] );
		}
		return count( $ids );
	}

	/**
	 * Answer the marking UPDATE through the evaluator for its WHERE clause.
	 */
	private function mark( string $sql ): int {
		$this->assertSame( 1, preg_match( "/^UPDATE `wp_bulkcat_batches` SET note = '((?:[^'\\\\]|\\\\.)*)', pruned_gmt = COALESCE\\(pruned_gmt, '([^']*)'\\) WHERE (.+)$/s", $sql, $m ), "Unexpected UPDATE: $sql" );
		$stamp = $m[2];
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stamp ), 'pruned_gmt is always stamped (schema version 5).' );
		$ids = array_column( $this->db()->rows( 'SELECT batch_id FROM `wp_bulkcat_batches` WHERE ' . $m[3] ), 'batch_id' );
		foreach ( $ids as $id ) {
			$row                 = &$this->tables['wp_bulkcat_batches'][ (int) $id ];
			$row['note']         = stripslashes( $m[1] );
			$row['pruned_gmt'] ??= $stamp;
			unset( $row );
		}
		return count( $ids );
	}

	private function pruner( int $days = 90, int $rows = Pruner::ROWS_PER_STATEMENT, int $statements = Pruner::STATEMENTS_PER_RUN, int $per_run = Pruner::BATCHES_PER_RUN ): Pruner {
		return new Pruner( null, null, new Settings( [ 'history_days' => $days ] ), static fn(): int => self::NOW, $rows, $statements, $per_run );
	}

	/**
	 * Batch ids still holding journal rows.
	 *
	 * @return list<int>
	 */
	private function journalled(): array {
		$ids = array_values( array_unique( array_column( $this->tables['wp_bulkcat_changes'], 'batch_id' ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Batch ids still holding item rows.
	 *
	 * @return list<int>
	 */
	private function itemised(): array {
		$ids = array_values( array_unique( array_column( $this->tables['wp_bulkcat_batch_items'], 'batch_id' ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private static function deletes(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'DELETE ' ) ) );
	}

	/**
	 * @return list<array>
	 */
	private static function scheduled( string $kind ): array {
		return array_values( array_filter( $GLOBALS['bulkcat_test']['scheduled'], static fn( array $entry ): bool => $kind === $entry[0] ) );
	}

	/*
	 * Scheduling.
	 */

	public function test_the_prune_action_is_the_registered_recurring_action_and_is_hooked(): void {
		$this->assertSame( 'bulkcat_prune_history', Pruner::HOOK );
		$this->assertContains( Pruner::HOOK, Registry::plugin()->recurring_actions() );
		$this->assertContains( [ 'bulkcat_prune_history', [ Pruner::class, 'handle' ], 10, 0 ], Plugin::instance()->hooks() );
	}

	public function test_activation_schedules_the_daily_prune_once_in_the_bulkcat_group(): void {
		$before = time();
		Pruner::schedule();

		$recurring = self::scheduled( 'recurring' );
		$this->assertCount( 1, $recurring );
		[ , $hook, $args, $group, $timestamp, $interval ] = $recurring[0];
		$this->assertSame( [ 'bulkcat_prune_history', [], 'bulk-catalogue-manager-for-woocommerce', 86400 ], [ $hook, $args, $group, $interval ] );
		$this->assertTrue( $timestamp >= $before + Pruner::FIRST_RUN_DELAY && $timestamp <= time() + Pruner::FIRST_RUN_DELAY, 'The first run is an hour after activation.' );
		$this->assertSame( $recurring, $GLOBALS['bulkcat_test']['scheduled'], 'Nothing else is scheduled.' );
	}

	public function test_reactivation_does_not_schedule_a_second_prune(): void {
		Pruner::schedule();
		Pruner::schedule();
		Pruner::schedule();

		$this->assertCount( 1, self::scheduled( 'recurring' ) );
	}

	public function test_deactivation_unschedules_the_prune_and_reactivation_schedules_it_again(): void {
		Pruner::schedule();
		$this->assertNotSame( false, as_next_scheduled_action( Pruner::HOOK, [], 'bulk-catalogue-manager-for-woocommerce' ) );

		Cleanup::deactivate();

		$this->assertContains( [ 'bulkcat_prune_history', [], 'bulk-catalogue-manager-for-woocommerce' ], $GLOBALS['bulkcat_test']['unscheduled'] );
		$this->assertFalse( as_next_scheduled_action( Pruner::HOOK, [], 'bulk-catalogue-manager-for-woocommerce' ) );

		Pruner::schedule();
		$this->assertCount( 2, self::scheduled( 'recurring' ), 'Reactivation after a deactivation schedules it again.' );
		Pruner::schedule();
		$this->assertCount( 2, self::scheduled( 'recurring' ) );
	}

	/*
	 * Retention.
	 */

	public function test_the_retention_limit_is_the_setting_in_days(): void {
		$this->batch( 1, 89 );
		$this->batch( 2, 91 );
		$this->batch( 3, 90 - 1 / 24 );
		$this->batch( 4, 90 + 1 / 24 );

		$this->assertSame( 'done', $this->pruner( 90 )->run() );

		$this->assertSame( [ 1, 3 ], $this->journalled(), 'Batches finished within 90 days keep their journal.' );
		$this->assertSame( [ 1, 3 ], $this->itemised() );
		$this->assertSame( '', $this->tables['wp_bulkcat_batches'][1]['note'] );
		$this->assertSame( Pruner::note( 90 ), $this->tables['wp_bulkcat_batches'][2]['note'] );
	}

	public function test_a_shorter_retention_prunes_more(): void {
		$this->batch( 1, 6 );
		$this->batch( 2, 8 );

		$this->pruner( 7 )->run();

		$this->assertSame( [ 1 ], $this->journalled() );
	}

	public function test_active_batches_are_never_touched_whatever_their_age(): void {
		$this->batch( 1, 800, 'queued' );
		$this->batch( 2, 800, 'running' );
		$this->batch( 3, 800, 'undoing' );
		$this->batch( 4, 800, 'complete' );
		$this->batch( 5, 800, 'partial' );
		$this->batch( 6, 800, 'failed' );
		$this->batch( 7, 800, 'cancelled' );
		$this->batch( 8, 800, 'undone' );
		$this->batch( 10, 800, 'running' );
		$this->tables['wp_bulkcat_batches'][10]['finished_gmt'] = null;
		$before = $this->tables['wp_bulkcat_batches'];

		$this->pruner( 90 )->run();

		$this->assertSame( [ 1, 2, 3, 10 ], $this->journalled(), 'Queued, running and undoing batches keep every journal row.' );
		$this->assertSame( [ 1, 2, 3, 10 ], $this->itemised() );
		foreach ( [ 1, 2, 3, 10 ] as $id ) {
			$this->assertSame( $before[ $id ], $this->tables['wp_bulkcat_batches'][ $id ], "Batch $id is not marked." );
		}
		foreach ( [ 4, 5, 6, 7, 8 ] as $id ) {
			$this->assertSame( Pruner::note( 90 ), $this->tables['wp_bulkcat_batches'][ $id ]['note'], "Batch $id is marked." );
		}
	}

	public function test_indefinitely_runs_and_prunes_nothing(): void {
		$this->batch( 1, 800 );
		$this->batch( 2, 3000 );

		$this->assertSame( 'kept', $this->pruner( 0 )->run() );

		global $wpdb;
		$this->assertSame( [], $wpdb->queries, 'No statement is sent.' );
		$this->assertSame( [ 1, 2 ], $this->journalled() );
		$this->assertSame( [ 1, 2 ], $this->itemised() );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
	}

	public function test_indefinitely_is_read_from_the_stored_setting(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'history_days' => 0 ];
		$this->batch( 1, 800 );

		( new Pruner( null, null, null, static fn(): int => self::NOW ) )->run();
		Pruner::handle();

		$this->assertSame( [ 1 ], $this->journalled() );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}

	/*
	 * The batch row.
	 */

	public function test_the_batch_row_survives_with_its_counters_and_the_pruned_note(): void {
		$this->batch( 1, 100, 'partial', 4, 6 );
		$before = $this->tables['wp_bulkcat_batches'][1];

		$this->pruner( 90 )->run();

		$after = $this->tables['wp_bulkcat_batches'][1];
		$this->assertSame( Pruner::note( 90 ), $after['note'] );
		$this->assertStringContainsString( 'can no longer be undone', $after['note'] );
		$this->assertStringNotContainsString( 'bulkcat_', $after['note'] );
		$this->assertLessThan( 256, mb_strlen( $after['note'] ) );
		$this->assertSame( '2026-09-23 12:00:00', $after['pruned_gmt'] );
		unset( $before['note'], $after['note'], $before['pruned_gmt'], $after['pruned_gmt'] );
		$this->assertSame( $before, $after, 'Status, counters and times are unchanged; only the note and pruned_gmt are written.' );
	}

	public function test_pruned_gmt_is_stamped_once(): void {
		$this->batch( 1, 100, 'complete', 3, 3 );

		$this->pruner( 90, 2, 1 )->run();
		$this->assertSame( '2026-09-23 12:00:00', $this->tables['wp_bulkcat_batches'][1]['pruned_gmt'] );

		$later = new Pruner( null, null, new Settings( [ 'history_days' => 90 ] ), static fn(): int => self::NOW + 60, 2, 1 );
		$later->run();
		$this->assertSame( '2026-09-23 12:00:00', $this->tables['wp_bulkcat_batches'][1]['pruned_gmt'], 'A later run keeps the first time.' );
	}

	/*
	 * Bounded chunks.
	 */

	public function test_the_default_bounds(): void {
		$this->assertSame( 500, Pruner::ROWS_PER_STATEMENT );
		$this->assertSame( 20, Pruner::STATEMENTS_PER_RUN );
		$this->assertSame( 50, Pruner::BATCHES_PER_RUN );
	}

	public function test_a_run_is_bounded_and_re_enqueues_itself_until_nothing_is_left(): void {
		$this->batch( 1, 100, 'complete', 7, 23 );
		$this->batch( 2, 10, 'complete', 2, 2 );
		$pruner = $this->pruner( 90, 5, 3 );

		$this->assertSame( 'continued', $pruner->run() );
		$this->assertCount( 3, self::deletes(), 'Three statements at most.' );
		foreach ( self::deletes() as $sql ) {
			$this->assertStringEndsWith( 'LIMIT 5', $sql );
		}
		$this->assertCount( 23 - 15, array_filter( $this->tables['wp_bulkcat_changes'], static fn( array $row ): bool => 1 === $row['batch_id'] ) );
		$this->assertSame( [ [ 'enqueue', 'bulkcat_prune_history', [], 'bulk-catalogue-manager-for-woocommerce', null ] ], $GLOBALS['bulkcat_test']['scheduled'], 'Its successor is one async action in the plugin group.' );

		$runs = 1;
		while ( 'continued' === $pruner->run() ) {
			++$runs;
			$this->assertLessThan( 20, $runs );
		}
		++$runs;

		$this->assertSame( [ 2 ], $this->journalled() );
		$this->assertSame( [ 2 ], $this->itemised() );
		// 23 changes: 5 + 5 + 5 | 5 + 3 (short: changes done), 5 | 2 (short: items done): three runs.
		$this->assertSame( 3, $runs );
		$this->assertCount( 2, $GLOBALS['bulkcat_test']['scheduled'], 'The last run enqueues nothing.' );
		$this->assertSame( 'done', $pruner->run(), 'A run with nothing due stops.' );
		$this->assertCount( 2, $GLOBALS['bulkcat_test']['scheduled'] );
	}

	public function test_a_full_set_of_batches_re_enqueues_for_the_next_set(): void {
		foreach ( range( 1, 5 ) as $id ) {
			$this->batch( $id, 200, 'complete', 1, 1 );
		}

		$this->assertSame( 'continued', $this->pruner( 90, 500, 20, 2 )->run() );
		$this->assertSame( [ 3, 4, 5 ], $this->journalled(), 'The lowest ids go first.' );
		$this->assertSame( 'continued', $this->pruner( 90, 500, 20, 2 )->run() );
		$this->assertSame( 'done', $this->pruner( 90, 500, 20, 2 )->run() );
		$this->assertSame( [], $this->journalled() );
		$this->assertSame( [], $this->itemised() );
		$this->assertCount( 2, self::scheduled( 'enqueue' ) );
	}

	public function test_nothing_due_sends_no_write(): void {
		$this->batch( 1, 10 );

		$this->assertSame( 'done', $this->pruner( 90 )->run() );

		$this->assertSame( [], self::deletes() );
		$this->assertSame( [], $GLOBALS['wpdb']->writes );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries, 'One SELECT and nothing else.' );
	}

	public function test_a_failing_delete_throws_for_action_scheduler_to_log(): void {
		$this->batch( 1, 100 );
		global $wpdb;
		$wpdb->responses['/^DELETE FROM `(wp_bulkcat_changes|wp_bulkcat_batch_items)` WHERE /'] = false;

		$thrown = null;
		try {
			$this->pruner( 90 )->run();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$this->assertNotNull( $thrown );
		$this->assertSame( Pruner::note( 90 ), $this->tables['wp_bulkcat_batches'][1]['note'], 'The batch was marked before any row went.' );
	}

	/*
	 * Integration over a seeded history.
	 */

	public function test_seeded_batches_of_10_100_and_800_days_at_retention_90(): void {
		$this->batch( 1, 10, 'complete', 3, 5 );
		$this->batch( 2, 100, 'partial', 4, 6 );
		$this->batch( 3, 800, 'complete', 2, 2 );
		$this->batch( 4, 800, 'running', 2, 2 );
		$this->batch( 5, 100, 'undone', 1, 1 );
		$kept_changes = array_keys( array_filter( $this->tables['wp_bulkcat_changes'], static fn( array $row ): bool => in_array( $row['batch_id'], [ 1, 4 ], true ) ) );
		$kept_items   = array_keys( array_filter( $this->tables['wp_bulkcat_batch_items'], static fn( array $row ): bool => in_array( $row['batch_id'], [ 1, 4 ], true ) ) );

		$this->assertSame( 'done', $this->pruner( 90 )->run() );

		$this->assertSame( $kept_changes, array_keys( $this->tables['wp_bulkcat_changes'] ), 'Exactly the 10-day and the running batch keep their journal rows.' );
		$this->assertSame( $kept_items, array_keys( $this->tables['wp_bulkcat_batch_items'] ) );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_keys( $this->tables['wp_bulkcat_batches'] ), 'Every batch row stays, so History lists it.' );
		$this->assertSame( [ '', Pruner::note( 90 ), Pruner::note( 90 ), '', Pruner::note( 90 ) ], array_column( $this->tables['wp_bulkcat_batches'], 'note' ) );
		$this->assertSame( [ 3, 4, 2, 2, 1 ], array_column( $this->tables['wp_bulkcat_batches'], 'total_items' ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'], 'Everything fitted in one run.' );
	}
}
