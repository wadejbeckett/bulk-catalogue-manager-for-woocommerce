<?php
/**
 * History retention (SPEC §9.5, §11, §12.4, §15 Data lifecycle; T60): the
 * daily `bulkcat_prune_history` action.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Registry as Data;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the detail of batches older than the retention limit.
 *
 * Activation schedules it once through as_schedule_recurring_action() in the
 * plugin's group (Data\Registry::ACTION_GROUP, the full slug), guarded by
 * as_next_scheduled_action() so a reactivation does not add a second one;
 * deactivation unschedules it (Data\Cleanup, from the registry's recurring
 * actions). Each run:
 *
 * 1. does nothing when *Keep history for* is indefinitely (0);
 * 2. picks up to BATCHES_PER_RUN batches finished before the retention limit
 *    that still hold item or journal rows, never one that is queued, running
 *    or undoing whatever its age (BatchRepository::prunable());
 * 3. marks them pruned first — the note becomes the plain reason they can no
 *    longer be undone, and `pruned_gmt` is stamped (the `pruned` signal
 *    Engine\Reversibility and Undo\Planner read) — so a run cut short leaves a batch refused for undo rather than half
 *    reversible; the batch row, its status and its counters stay, so History
 *    still lists it;
 * 4. deletes their `bulkcat_changes` rows and then their `bulkcat_batch_items` rows,
 *    ROWS_PER_STATEMENT rows per DELETE and at most STATEMENTS_PER_RUN DELETEs
 *    in the run, so no run holds a lock or the request for long whatever the
 *    history's size; no row is read into memory;
 * 5. enqueues its successor with as_enqueue_async_action() (the same hook,
 *    no arguments, so deactivation unschedules it with the daily one) when
 *    the statement budget ran out or a full set of batches was picked, and
 *    stops when nothing is left.
 *
 * Uses the public `as_*` functions only (§12.4).
 */
final class Pruner {
	/**
	 * The recurring action, in Data\Registry::ACTION_GROUP and listed in its recurring actions.
	 */
	public const HOOK = 'bulkcat_prune_history';

	/**
	 * Seconds between daily runs, and in a day of retention.
	 */
	public const INTERVAL = 86400;

	/**
	 * Seconds from activation to the first run, so activation itself stays quick.
	 */
	public const FIRST_RUN_DELAY = 3600;

	/**
	 * Most rows one DELETE removes.
	 */
	public const ROWS_PER_STATEMENT = 500;

	/**
	 * Most DELETE statements one run sends.
	 */
	public const STATEMENTS_PER_RUN = 20;

	/**
	 * Most batches one run picks.
	 */
	public const BATCHES_PER_RUN = 50;

	/**
	 * Batches and items.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The journal.
	 *
	 * @var ChangeRepository
	 */
	private ChangeRepository $changes;

	/**
	 * Settings, loaded on first use.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings;

	/**
	 * Returns the current Unix time in seconds.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Rows per DELETE.
	 *
	 * @var int
	 */
	private int $rows;

	/**
	 * DELETEs per run.
	 *
	 * @var int
	 */
	private int $statements;

	/**
	 * Batches per run.
	 *
	 * @var int
	 */
	private int $per_run;

	/**
	 * Build a pruner.
	 *
	 * @param BatchRepository|null  $batches    Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes    The journal; the plugin's by default.
	 * @param Settings|null         $settings   Settings; loaded when first needed by default.
	 * @param \Closure|null         $clock      Returns the Unix time as an int; time() by default.
	 * @param int                   $rows       Rows per DELETE.
	 * @param int                   $statements DELETEs per run.
	 * @param int                   $per_run    Batches per run.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Settings $settings = null, ?\Closure $clock = null, int $rows = self::ROWS_PER_STATEMENT, int $statements = self::STATEMENTS_PER_RUN, int $per_run = self::BATCHES_PER_RUN ) {
		$this->batches    = $batches ?? new BatchRepository();
		$this->changes    = $changes ?? new ChangeRepository();
		$this->settings   = $settings;
		$this->clock      = $clock ?? static fn(): int => time();
		$this->rows       = max( 1, $rows );
		$this->statements = max( 1, $statements );
		$this->per_run    = max( 1, min( BatchRepository::MAX_CLAIM, $per_run ) );
	}

	/**
	 * Activation hook callback: schedule the daily run unless one is already
	 * scheduled. Without Action Scheduler loaded there is nothing to schedule.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false !== as_next_scheduled_action( self::HOOK, [], Data::ACTION_GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + self::FIRST_RUN_DELAY, self::INTERVAL, self::HOOK, [], Data::ACTION_GROUP );
	}

	/**
	 * `bulkcat_prune_history`: one bounded run.
	 */
	public static function handle(): void {
		( new self() )->run();
	}

	/**
	 * One bounded run.
	 *
	 * @return string What happened: `kept` (history is kept indefinitely),
	 *                `done` (nothing is left to prune) or `continued`
	 *                (a successor is enqueued).
	 * @throws \RuntimeException When a statement fails; Action Scheduler logs it and tomorrow's run carries on.
	 */
	public function run(): string {
		$settings = $this->settings ??= Settings::load();
		if ( $settings->keeps_history_indefinitely() ) {
			return 'kept';
		}
		$days = $settings->history_days();
		$now  = (int) ( $this->clock )();
		$ids  = $this->batches->prunable( gmdate( 'Y-m-d H:i:s', $now - $days * self::INTERVAL ), $this->per_run );
		if ( [] === $ids ) {
			return 'done';
		}
		$this->batches->mark_pruned( $ids, self::note( $days ), gmdate( 'Y-m-d H:i:s', $now ) );

		$sent  = 0;
		$more  = count( $ids ) >= $this->per_run;
		$steps = [
			fn( int $limit ): int => $this->changes->delete_for_batches( $ids, $limit ),
			fn( int $limit ): int => $this->batches->delete_items( $ids, $limit ),
		];
		foreach ( $steps as $delete ) {
			do {
				if ( $sent >= $this->statements ) {
					$more = true;
					break 2;
				}
				$deleted = $delete( $this->rows );
				++$sent;
			} while ( $deleted >= $this->rows );
		}

		if ( $more && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [], Data::ACTION_GROUP );
			return 'continued';
		}
		return 'done';
	}

	/**
	 * The plain reason a pruned batch can no longer be undone (§4.8).
	 *
	 * @param int $days The retention limit in days.
	 */
	public static function note( int $days ): string {
		return sprintf(
			/* translators: %d: number of days history is kept for. */
			_n(
				'This change is more than %d day old, so its detailed record was removed as the history setting asks and it can no longer be undone.',
				'This change is more than %d days old, so its detailed record was removed as the history setting asks and it can no longer be undone.',
				$days,
				'bulk-catalogue-manager-for-woocommerce'
			),
			$days
		);
	}
}
