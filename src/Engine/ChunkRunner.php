<?php
/**
 * The chunk runner (SPEC §12.3, §12.4, §12.6; T34): works a batch's pending
 * items in chunks, one Action Scheduler action per chunk, as a chain rather
 * than a fan-out, through Action Scheduler's public `as_*` functions only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Requests\InlineReceipt;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Registry as Data;
use BulkCatalogueManager\External\Blocked;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Undo\Original;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use BulkCatalogueManager\Categories\TermUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one chunk of a batch per scheduled action.
 *
 * `bulkcat_batch_created` (BatchFactory) enqueues the first `bulkcat_run_chunk`
 * action in the plugin's group (Data\Registry::ACTION_GROUP, the full slug)
 * with as_enqueue_async_action(). Each run:
 *
 * 1. exits without claiming unless the batch is queued or running (a
 *    cancelled or finished batch, T35), and moves queued to running on the
 *    first chunk; a cancellation that lands between that read and the claim
 *    is seen straight after the claim, which is then released untouched;
 * 2. puts back to pending every item a dead run left working
 *    (BatchRepository::reset_stale_working()), then fails, with a plain
 *    reason, every pending item already claimed MAX_ATTEMPTS times whose
 *    last claim has gone stale (the chunk that held it died three times),
 *    counting it done and failed;
 * 3. claims the next chunk of pending items with one locking UPDATE
 *    (BatchRepository::claim_chunk()): unclaimed items, or items whose claim
 *    is older than LEASE_SECONDS — a claim left by a chunk that died is so
 *    released and retried — sized by the batch's current chunk size. A
 *    chunk holds one kind of item (T71, §12.4): products (and category
 *    items) while any is left to claim, up to the product size, and only
 *    then variations, up to the variation size — so a mixed batch counts
 *    its variations against the variation size and every chunk costs
 *    about the same time. When no product is left the product claim finds
 *    nothing and a second UPDATE claims variations;
 * 4. schedules a retry for just after the lease would go stale, so a chunk
 *    killed mid-way (a fatal error, the PHP time limit) is picked up again
 *    without anyone watching; a chunk that ends normally unschedules it;
 * 5. inside wp_defer_term_counting( true ), flushed by passing false at the
 *    end even when the work throws, runs Writer::for_batch()->run() over the
 *    chunk: each object is written through the one write path, which checks
 *    the Validator and journals from inside (T32, T33). Just before each
 *    write the run claims that item for itself alone
 *    (BatchRepository::claim_item(): pending to working, only while the
 *    chunk's claim token is still its own), so a run whose lease went stale
 *    and was taken over skips the items the newer run now holds rather than
 *    writing them a second time; and past LEASE_SHARE (80 per cent) of the
 *    lease it claims no further item, releasing the rest to its successor.
 *    The journal's own guard makes a chunk idempotent: an object the batch
 *    already journalled is skipped, and one left with intent rows is
 *    finished from the recorded values, so a retried percentage increase
 *    applies once;
 * 6. marks each item done, skipped or failed with its plain message and adds
 *    to the batch counters in one statement (done_items counts every
 *    finished item whatever its outcome, changed_items the saved ones,
 *    failed_items the failed ones);
 *    an item may carry its own operation list (BatchRepository::
 *    item_operations(), one read per chunk) which the writer applies in
 *    place of the batch's: every item of an undo batch (T56; an undo item
 *    without one fails with a plain reason), and a variation "Also apply to
 *    variations" added to a bulk batch whose other steps do not reach it (T69);
 *    an undo item is checked again just before it is written (T111):
 *    the writer, once it has loaded the object (after its retry guard),
 *    plans it again from the undone batch's journal (Writer::guarded(),
 *    Undo\Planner::checker(): every field the batch changed, status and its
 *    Off record included, the rows read once a chunk), and one changed since
 *    the undo was previewed and confirmed is left alone whole, skipped with
 *    the plan's own "changed since" sentence (the one RestoreStatus's guard
 *    gives too), unless the undo was Revert anyway;
 * 7. adapts the chunk size: a chunk that used more than 70 per cent of the
 *    PHP time limit halves it for the rest of the batch, never below 10, and
 *    records why in the batch's note; one that used under 30 per cent grows
 *    it back, doubling, up to the size the settings and the `bulkcat_batch_size`
 *    filter give (itself within 10 and 100). The filter gets the batch id and
 *    the kind, `product` or `variation`: products start from *Products per
 *    background batch* (50), variations from *Variations per background
 *    batch* (100). The batch's `chunk_size` records the product size; the
 *    variation size follows it in proportion (configured variation size ×
 *    chunk_size ÷ configured product size, within 10 and 100), so a halving
 *    after a slow chunk of either kind halves both. The time limit is
 *    `max_execution_time`, with 0 (no limit, as under WP-CLI and some cron
 *    runners) taken as the lease, LEASE_SECONDS;
 * 8. enqueues its successor with as_enqueue_async_action() while pending
 *    or working items remain, or finalises the batch: complete when nothing is reported,
 *    failed when items failed and the journal holds no applied change, and
 *    partial otherwise (any failure or skip); finished_gmt is stamped. An
 *    undo batch then settles the batch it undid (Undo\Original: undone
 *    after complete or partial, back to its old status after failed). A
 *    batch cancelled while the chunk ran gets neither: its applied items
 *    stay applied and journalled, the rest stay pending, and the status
 *    stays cancelled (the move is from running only in any case).
 *
 * The only status moves the engine writes are queued to running, running to
 * complete, partial or failed here, and queued or running to cancelled in
 * Progress::cancel(); ChunkRunnerTest enumerates every transition() call.
 *
 * An exception out of a write (the journal refusing a row, T33) stops the
 * chunk: that item is marked failed and not retried, the rest of the claim is
 * released at once, the counters are kept, a retry is scheduled a minute
 * later and the exception is rethrown so Action Scheduler logs it.
 *
 * The queue runner's own batch size and concurrency options are never
 * touched; chunk size is the only knob (§12.4).
 *
 * External changes (T107, docs/EXTENSIONS.md §11): before claiming, a run of
 * a batch with a provider manifest checks each provider it names against the
 * providers registered now (External\Manifest::blocked()). When one is
 * missing, unavailable, of an unsupported contract or unable to read a
 * recorded format, the run claims nothing: every still-pending item is
 * skipped with a plain reason and counted, the batch's note says why
 * (External\Blocked: "Stopped because <add-on> is not active. …" when it is
 * not registered, "Stopped because <add-on> cannot run. … <its reason>" when
 * it is registered but cannot run), and the batch ends through the
 * final-status rule, never complete. Nothing resumes it. Otherwise each
 * chunk of an external change primes its objects once before they are
 * written.
 */
final class ChunkRunner {
	/**
	 * The scheduled action each chunk runs as, in Data\Registry::ACTION_GROUP.
	 */
	public const HOOK = 'bulkcat_run_chunk';

	/**
	 * The second argument of a delayed retry, so a finishing chunk unschedules only that.
	 */
	public const RETRY = 'retry';

	/**
	 * The filter over the configured chunk size.
	 */
	public const SIZE_FILTER = 'bulkcat_batch_size';

	/**
	 * The `bulkcat_batch_size` kind for products (and category items).
	 */
	public const PRODUCT = 'product';

	/**
	 * The `bulkcat_batch_size` kind for variations.
	 */
	public const VARIATION = 'variation';

	/**
	 * Fewest items per chunk.
	 */
	public const FLOOR = 10;

	/**
	 * Most items per chunk.
	 */
	public const CEILING = BatchRepository::MAX_CLAIM;

	/**
	 * Above this share of the time limit a chunk halves the size.
	 */
	public const SLOW = 0.7;

	/**
	 * Below this share of the time limit a chunk grows the size back.
	 */
	public const FAST = 0.3;

	/**
	 * Past this share of the lease a run claims no further item of its
	 * chunk and hands the rest to its successor, so it never works an item
	 * after its claim could have been taken over.
	 */
	public const LEASE_SHARE = 0.8;

	/**
	 * Seconds past the lease before the dead-chunk retry runs, and before a
	 * retry after an error.
	 */
	public const RETRY_DELAY = 60;

	/**
	 * Batch statuses a run works on.
	 */
	private const ACTIVE = [ 'queued', 'running' ];

	/**
	 * Batches and items.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * Operations and, through them, the field registry.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * The journal table.
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
	 * Returns the current Unix time in seconds, with a fraction.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Seconds a request may run, or null to read PHP's.
	 *
	 * @var int|null
	 */
	private ?int $time_limit;

	/**
	 * Returns the Unix time the lease is measured by, with a fraction.
	 *
	 * @var \Closure
	 */
	private \Closure $lease_clock;

	/**
	 * Build a runner.
	 *
	 * @param BatchRepository|null  $batches    Batches; the plugin's by default.
	 * @param Actions|null          $actions    Operation registry; the shared one by default.
	 * @param ChangeRepository|null $changes    The journal table; the plugin's by default.
	 * @param Settings|null         $settings   Settings; loaded when first needed by default.
	 * @param \Closure|null         $clock      Returns the Unix time as a float; microtime( true ) by default.
	 * @param int|null              $time_limit Seconds a request may run; PHP's max_execution_time by default.
	 * @param \Closure|null         $lease_clock Returns the Unix time the lease is measured by; microtime( true ) by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?Actions $actions = null, ?ChangeRepository $changes = null, ?Settings $settings = null, ?\Closure $clock = null, ?int $time_limit = null, ?\Closure $lease_clock = null ) {
		$this->batches     = $batches ?? new BatchRepository();
		$this->actions     = $actions ?? Actions::instance();
		$this->changes     = $changes ?? new ChangeRepository();
		$this->settings    = $settings;
		$this->clock       = $clock ?? static fn(): float => microtime( true );
		$this->time_limit  = $time_limit;
		$this->lease_clock = $lease_clock ?? static fn(): float => microtime( true );
	}

	/**
	 * `bulkcat_batch_created`: enqueue the batch's first chunk.
	 *
	 * @param mixed $batch_id The new batch.
	 */
	public static function enqueue( mixed $batch_id ): void {
		self::ensure_queued( (int) $batch_id );
	}

	/**
	 * Queue a batch's first chunk unless one is already pending or running
	 * (T83). The `bulkcat_batch_created` listener and a durable request's
	 * scheduling reconciliation (Requests\BatchRecovery) both come here, and
	 * may do so at once from two requests answering the same batch, so the
	 * check and the enqueue run under BatchRepository::with_schedule_lock()
	 * (Action Scheduler's unique enqueue alone lets two concurrent inserts
	 * through); if that lock cannot be taken they still run, unlocked. A
	 * pending retry of the batch counts as queued. An Action Scheduler whose
	 * uniqueness ignores arguments refuses the unique enqueue while another
	 * batch's chunk is queued; then a plain enqueue follows.
	 *
	 * @param int $batch_id The batch.
	 * @return bool Whether a chunk of the batch is now pending or running.
	 */
	public static function ensure_queued( int $batch_id ): bool {
		if ( $batch_id < 1 || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$batch = ( new BatchRepository() )->find_strict( $batch_id );
		if ( null !== $batch && InlineReceipt::is_inline( $batch ) ) {
			return false; }
		// Checked and enqueued under the batch's scheduling lock: two requests answering one batch queue one chunk.
		$queued = ( new BatchRepository() )->with_schedule_lock(
			$batch_id,
			static function () use ( $batch_id ): bool {
				if ( self::queued( $batch_id ) ) {
					return true;
				}
				$action = as_enqueue_async_action( self::HOOK, [ $batch_id ], Data::ACTION_GROUP, true );
				if ( 0 === (int) $action && ! self::queued( $batch_id ) ) {
					as_enqueue_async_action( self::HOOK, [ $batch_id ], Data::ACTION_GROUP );
				}
				return self::queued( $batch_id );
			}
		);
		return true === $queued;
	}

	/**
	 * Queue again every batch left queued or running with no pending chunk:
	 * deactivation removes a batch's pending chunk (Data\Cleanup::deactivate())
	 * and leaves its status and items as they were, so on reactivation the
	 * batch carries on from its next pending item. Runs on activation and
	 * where the admin repairs the recurring clean-ups (Plugin::migrate()).
	 * A batch whose chunk is already pending or running is left alone.
	 */
	public static function resume_interrupted(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		foreach ( ( new BatchRepository() )->active_ids() as $batch_id ) {
			try {
				self::ensure_queued( $batch_id );
			} catch ( \RuntimeException $error ) {
				// A batch that cannot be read now is retried at the next repair.
				unset( $error );
			}
		}
	}

	/**
	 * Whether a chunk of the batch is pending or running, or its delayed
	 * retry is waiting (a chunk that stopped early backs off RETRY_DELAY
	 * seconds; resolving the request must not cut that short).
	 *
	 * @param int $batch_id The batch.
	 */
	private static function queued( int $batch_id ): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& ( as_has_scheduled_action( self::HOOK, [ $batch_id ], Data::ACTION_GROUP ) || as_has_scheduled_action( self::HOOK, [ $batch_id, self::RETRY ], Data::ACTION_GROUP ) );
	}

	/**
	 * `bulkcat_run_chunk`: run one chunk of a batch.
	 *
	 * @param mixed $batch_id The batch.
	 * @param mixed $kind     RETRY for a delayed retry; ignored otherwise.
	 */
	public static function handle( mixed $batch_id, mixed $kind = '' ): void {
		unset( $kind );
		( new self() )->run( (int) $batch_id );
	}

	/**
	 * Run one chunk of a batch.
	 *
	 * @param int $batch_id The batch.
	 * @return string What happened: `missing`, `stopped` (not queued or
	 *                running, or cancelled during the run), `continued` (successor enqueued), `waiting`
	 *                (items are under a live claim; a retry is scheduled) or
	 *                the final status.
	 * @throws \Throwable An exception out of a write, after the chunk's bookkeeping.
	 */
	public function run( int $batch_id ): string {
		// What WordPress counts before this chunk: its per-object hook names are forgotten at the end (ticket 11).
		$mark  = Memory::mark();
		$batch = $this->batches->find( $batch_id );
		if ( null === $batch || ! in_array( $batch['status'], self::ACTIVE, true ) ) {
			return null === $batch ? 'missing' : 'stopped';
		}
		if ( InlineReceipt::is_inline( $batch ) ) {
			return 'stopped'; }
		if ( 'queued' === $batch['status'] && ! $this->batches->transition( $batch_id, 'queued', 'running' ) ) {
			$batch = $this->batches->find( $batch_id );
			if ( null === $batch || 'running' !== $batch['status'] ) {
				return 'stopped';
			}
		}

		$start = ( $this->clock )();
		$now   = (int) $start;
		$stale = gmdate( 'Y-m-d H:i:s', $now - BatchRepository::LEASE_SECONDS );

		// An item claimed three times is settled from the journal and its state (ENGINE-2): written, the item that stopped the run, or never reached.
		$exhausted = $this->batches->give_up_exhausted( $batch_id, $stale, self::stopped_reason(), self::unreached_reason() );
		if ( $exhausted['done'] + $exhausted['failed'] > 0 ) {
			$this->batches->increment_counters( $batch_id, $exhausted['done'] + $exhausted['failed'], $exhausted['done'], $exhausted['failed'] );
		}
		// An item a dead run was writing goes back to pending, so its stale claim can be taken over.
		$this->batches->reset_stale_working( $batch_id, $stale );

		$manifest = $batch['extensions'] ?? null;
		$blocked  = is_array( $manifest ) ? Manifest::blocked( $manifest, $this->actions->providers() ) : null;
		if ( null !== $blocked ) {
			return $this->stop( $batch, $blocked );
		}

		$configured = $this->configured_size( $batch_id, self::PRODUCT );
		$size       = $batch['chunk_size'] > 0 ? min( self::clamp( $batch['chunk_size'] ), $configured ) : $configured;
		$token      = bin2hex( random_bytes( 16 ) );
		$items      = $this->batches->claim_chunk( $batch_id, $size, $token, gmdate( 'Y-m-d H:i:s', $now ), $stale, BatchRepository::CLAIM_PRODUCTS );
		$kind       = self::PRODUCT;
		if ( [] === $items ) {
			$kind  = self::VARIATION;
			$items = $this->batches->claim_chunk( $batch_id, self::variation_size( $size, $configured, $this->configured_size( $batch_id, self::VARIATION ) ), $token, gmdate( 'Y-m-d H:i:s', $now ), $stale, BatchRepository::CLAIM_VARIATIONS );
		}
		if ( [] === $items ) {
			return $this->settle( $batch, false );
		}
		// A cancellation that landed between the status read and the claim (T35): give the items back untouched.
		if ( 'running' !== $this->batches->status( $batch_id ) ) {
			$this->batches->release_claim( $batch_id, $token );
			return 'stopped';
		}

		self::schedule_retry( $now + BatchRepository::LEASE_SECONDS + self::RETRY_DELAY, $batch_id );
		[ $error, $stopped ] = $this->work( $batch, $items, $token, $now );

		if ( null !== $error || $stopped ) {
			$this->batches->release_claim( $batch_id, $token );
		}
		$this->adapt( $batch_id, $size, $configured, ( $this->clock )() - $start, count( $items ), $kind );
		self::unschedule_retry( $batch_id );
		// What the chunk loaded, and the hook names it made WordPress count, are not needed again; a long runner would keep them all (T76, ticket 11).
		Memory::release( $mark );

		if ( null !== $error ) {
			self::schedule_retry( (int) ( $this->clock )() + self::RETRY_DELAY, $batch_id );
			throw $error;
		}
		return $this->settle( $batch, true );
	}

	/**
	 * Stop a batch whose provider cannot work on it now (T107): skip what is
	 * pending, say so in the note, and finish it.
	 *
	 * @param array<string, mixed> $batch   The batch row.
	 * @param Blocked              $blocked The provider and why it cannot work.
	 */
	private function stop( array $batch, Blocked $blocked ): string {
		$batch_id = (int) $batch['batch_id'];
		$skipped  = $this->batches->skip_pending( $batch_id, $blocked->skipped_sentence() );
		if ( $skipped > 0 ) {
			$this->batches->increment_counters( $batch_id, $skipped, 0, 0 );
		}
		$now = $this->batches->find( $batch_id );
		$this->batches->record_note( $batch_id, $blocked->stopped_note( (int) ( $now['changed_items'] ?? 0 ) ) );
		return $this->settle( $batch, false );
	}

	/**
	 * Write the claimed items through one writer run, term counting deferred.
	 * Each item is claimed for this run alone just before it is written
	 * (BatchRepository::claim_item()): an item whose chunk claim another run
	 * has taken over is skipped, neither written nor marked. Each item is
	 * marked and counted as soon as it is written (BatchRepository::finish_item(),
	 * ENGINE-2), so a run killed part way loses nothing it finished. Past
	 * LEASE_SHARE of the lease no further item is claimed; the rest are left
	 * pending for the successor.
	 *
	 * @param array                                                                     $batch The batch row.
	 * @param array<int, array{item_id: int, object_id: int, object_type: string, attempts: int}> $items Claimed items.
	 * @param string                                                                    $token The chunk's claim token.
	 * @param int                                                                       $claimed_at Unix time the chunk was claimed; each item's own lease is stamped from it plus the lease clock's elapsed time (ENGINE-5).
	 * @return array{0: ?\Throwable, 1: bool} The exception that stopped the chunk, whether the lease ran short.
	 */
	private function work( array $batch, array $items, string $token, int $claimed_at ): array {
		$writer     = Writer::for_batch( $this->actions, Journal::from_settings( $this->actions->fields(), $this->settings(), $this->changes ), $batch['batch_id'], $batch['user_id'] );
		$operations = $batch['operations'];
		$undo       = 'undo' === $batch['source'];
		$own        = $this->batches->item_operations( $batch['batch_id'], array_values( array_map( static fn( array $item ): int => $item['item_id'], $items ) ) );
		$writer->guarded( $undo ? $this->undo_guard( $batch, $items ) : null );
		$terms    = $undo ? $this->term_guard( $batch, $items ) : null;
		$error    = null;
		$stopped  = false;
		$written  = [];
		$batches  = $this->batches;
		$changes  = $this->changes;
		$batch_id = $batch['batch_id'];
		$lease    = $this->lease_clock;
		$began    = $lease();
		$started  = $claimed_at;
		$deadline = $began + self::LEASE_SHARE * BatchRepository::LEASE_SECONDS;

		// An external change's objects are primed once per chunk (T107).
		$writer->prime_external( $operations, array_values( array_map( static fn( array $item ): int => (int) $item['object_id'], $items ) ) );
		wp_defer_term_counting( true );
		try {
			$writer->run(
				static function ( Writer $writer ) use ( $items, $operations, $undo, $own, $batches, $changes, $batch_id, $token, $lease, $began, $started, $deadline, $terms, &$error, &$stopped, &$written ): void {
					$finish = static function ( int $item_id, array $mark, bool $changed ) use ( $batches, $batch_id, $token ): void {
						$batches->finish_item( $batch_id, $item_id, $token, $mark[0], $mark[1], $changed );
					};
					foreach ( $items as $item ) {
						if ( $lease() > $deadline ) {
							$stopped = true;
							return;
						}
						// The item's own lease starts as it is taken up (ENGINE-5), so a later run waits for this write.
						if ( ! $batches->claim_item( $batch_id, $item['item_id'], $token, gmdate( 'Y-m-d H:i:s', $started + (int) floor( $lease() - $began ) ) ) ) {
							continue; // Another run took the chunk over when this one's lease went stale.
						}
						if ( 'term' === $item['object_type'] ) {
							if ( $undo && isset( $own[ $item['item_id'] ] ) ) {
								// Ticket 133 bug 1: a category's undo, checked again now unless Revert anyway.
								[ $state, $message, $changed ] = TermUndo::write( $changes, $batch_id, $item['object_id'], $own[ $item['item_id'] ], null === $terms ? null : $terms( $item['object_id'] ) );
								$finish( $item['item_id'], [ $state, $message ], $changed );
								continue;
							}
							$finish( $item['item_id'], [ 'failed', __( 'Category changes cannot run in the background yet, so this change was not made.', 'bulk-catalogue-manager-for-woocommerce' ) ], false );
							continue;
						}
						if ( $undo && ! isset( $own[ $item['item_id'] ] ) ) {
							$finish( $item['item_id'], [ 'failed', self::no_operations_reason() ], false );
							continue;
						}
						try {
							// The item's kind is checked: an object that became the other kind is never written as it was chosen (T83).
							$outcome = $writer->write( $item['object_id'], $own[ $item['item_id'] ] ?? $operations, $item['object_type'] );
						} catch ( \Throwable $e ) {
							$error = $e;
							$finish( $item['item_id'], [ 'failed', self::error_reason() ], false );
							return;
						}
						// An object a killed run wrote is skipped as already done, but still counts as changed (SILENT-FAILURES-3).
						$finish( $item['item_id'], self::item_state( $outcome ), $outcome->saved() || ( self::done_before( $outcome ) && $changes->has_applied( $batch_id, $item['object_id'] ) ) );
						if ( $outcome->saved() && 'variation' === $item['object_type'] ) {
							$written[ $item['item_id'] ] = $item['object_id'];
						}
					}
				}
			);
		} catch ( \Throwable $e ) {
			// A parent's resynchronisation at the end of the run; the items are already written.
			$error ??= $e;
			$this->resync( $batch_id, $written );
		} finally {
			wp_defer_term_counting( false );
		}
		return [ $error, $stopped ];
	}

	/**
	 * Resynchronise the parents of the variations a run saved, each on its
	 * own, after the run's end-of-run resynchronisation threw
	 * (SILENT-FAILURES-6): one parent that throws there stops the rest, so
	 * every parent is tried again here, and the variations of a parent that
	 * still cannot be refreshed are reported, so the batch never ends
	 * complete with its price range or stock left stale.
	 *
	 * @param int             $batch_id The batch.
	 * @param array<int, int> $written  Item id => variation id, for the variations the run saved.
	 */
	private function resync( int $batch_id, array $written ): void {
		$parents = [];
		foreach ( $written as $item_id => $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$parent    = is_object( $variation ) ? (int) $variation->get_parent_id( 'edit' ) : 0;
			if ( $parent > 0 ) {
				$parents[ $parent ][] = (int) $item_id;
			}
		}
		foreach ( $parents as $parent => $item_ids ) {
			try {
				\WC_Product_Variable::sync( $parent );
			} catch ( \Throwable $e ) {
				$this->batches->mark_items( $batch_id, $item_ids, 'done', self::stale_parent_reason() );
			}
		}
	}

	/**
	 * The check an undo chunk's items get as the writer loads each (T111,
	 * SPEC §9.4; Writer::guarded()): the undone batch's journal rows for the
	 * chunk's objects are read once, and each object the writer has just
	 * loaded is planned again as it stands (Undo\Planner::checker()). The
	 * closure answers the plan's "changed since" sentence for one changed
	 * since the undo was previewed and confirmed — the sentence RestoreStatus
	 * gives for a status changed since, so one rule reads the same — or null
	 * to write it. Revert anyway checks nothing here: it puts values back
	 * regardless (RestoreStatus still guards the status it planned).
	 *
	 * @param array                                                                     $batch The undo batch.
	 * @param array<int, array{item_id: int, object_id: int, object_type: string, attempts: int}> $items The chunk's items.
	 * @return \Closure( int, object ): ?string|null Null when nothing is checked.
	 */
	private function undo_guard( array $batch, array $items ): ?\Closure {
		$undo_of = (int) ( $batch['undo_of'] ?? 0 );
		if ( $undo_of < 1 || true === ( $batch['selection']['force'] ?? false ) ) {
			return null;
		}
		$ids = [];
		foreach ( $items as $item ) {
			if ( 'term' !== $item['object_type'] ) {
				$ids[] = $item['object_id'];
			}
		}
		if ( [] === $ids ) {
			return null;
		}
		$now = ( new Planner( $this->batches, $this->changes, $this->actions, $this->settings() ) )->checker( $undo_of, $ids, (int) $batch['user_id'] );
		// An object whose rows in the batch undone are gone is written as confirmed: the pruner never clears an undoing batch.
		return static function ( int $object_id, object $product ) use ( $now ): ?string {
			$item = $now( $object_id, $product );
			return null !== $item && Plan::CONFLICTED === $item['state'] ? (string) $item['reason'] : null;
		};
	}

	/**
	 * The check an undo chunk's categories get just before each is written
	 * (ticket 133 bug 1): the plan item of each as it stands then, or null
	 * when nothing is checked (Revert anyway, or no category in the chunk).
	 *
	 * @param array                                                                     $batch The undo batch.
	 * @param array<int, array{item_id: int, object_id: int, object_type: string, attempts: int}> $items The chunk's items.
	 * @return \Closure( int ): ?array<string, mixed>|null
	 */
	private function term_guard( array $batch, array $items ): ?\Closure {
		$undo_of = (int) ( $batch['undo_of'] ?? 0 );
		$ids     = [];
		foreach ( $items as $item ) {
			if ( 'term' === $item['object_type'] ) {
				$ids[] = $item['object_id'];
			}
		}
		if ( $undo_of < 1 || [] === $ids || true === ( $batch['selection']['force'] ?? false ) ) {
			return null;
		}
		$now = ( new Planner( $this->batches, $this->changes, $this->actions, $this->settings() ) )->checker( $undo_of, $ids, (int) $batch['user_id'] );
		return static fn( int $object_id ): ?array => $now( $object_id );
	}

	/**
	 * After a chunk: enqueue the successor while items are pending, or finalise.
	 *
	 * @param array<string, mixed> $batch   The batch row, as read at the start of the run.
	 * @param bool                 $claimed Whether this run claimed anything (else pending items are under a live claim).
	 */
	private function settle( array $batch, bool $claimed ): string {
		$batch_id = $batch['batch_id'];
		// Cancelled while the chunk ran (T35): the chunk's work stands, nothing follows it.
		if ( 'running' !== $this->batches->status( $batch_id ) ) {
			return 'stopped';
		}
		// A count the database fails to give is never taken as 0 (SILENT-FAILURES-5): a retry settles the batch once it answers.
		try {
			$open = $this->batches->count_open( $batch_id, true );
			if ( 0 === $open ) {
				$applied  = $this->changes->count_for_batch( $batch_id, 'applied', true );
				$reported = $this->batches->count_reported( $batch_id, true );
				$failed   = $this->batches->count_items( $batch_id, 'failed', true );
			}
		} catch ( \RuntimeException $e ) {
			self::schedule_retry( (int) ( $this->clock )() + self::RETRY_DELAY, $batch_id );
			throw $e;
		}
		if ( $open > 0 ) {
			if ( $claimed ) {
				as_enqueue_async_action( self::HOOK, [ $batch_id ], Data::ACTION_GROUP );
				return 'continued';
			}
			self::schedule_retry( (int) ( $this->clock )() + BatchRepository::LEASE_SECONDS, $batch_id );
			return 'waiting';
		}
		$status = self::final_status( $reported, $failed, $applied );
		if ( $this->batches->transition( $batch_id, 'running', $status ) ) {
			// An undo that put an object back only in part has not undone the batch (ENGINE-1).
			$whole = 'undo' !== ( $batch['source'] ?? '' ) || 0 === $this->changes->count_written_in_part( $batch_id );
			Original::settle( $this->batches, $batch, $status, $applied, $whole );
		}
		return $status;
	}

	/**
	 * The final status: complete with nothing to report, failed when items
	 * failed and nothing was applied, partial otherwise.
	 *
	 * @param int $reported Items failed, skipped or done with a message.
	 * @param int $failed   Items failed.
	 * @param int $applied  The batch's applied journal rows.
	 */
	public static function final_status( int $reported, int $failed, int $applied ): string {
		if ( 0 === $reported ) {
			return 'complete';
		}
		return $failed > 0 && 0 === $applied ? 'failed' : 'partial';
	}

	/**
	 * An item's state and message from its write.
	 *
	 * @param WriteOutcome $outcome The write.
	 * @return array{0: string, 1: string}
	 */
	public static function item_state( WriteOutcome $outcome ): array {
		$failed  = array_map( static fn( Result $r ): string => $r->reason(), $outcome->failed() );
		$skipped = array_map(
			static fn( Result $r ): string => $r->reason(),
			array_filter( $outcome->results(), static fn( Result $r ): bool => Result::SKIPPED === $r->state() && Journal::ALREADY_DONE !== $r->code() )
		);
		if ( [] !== $failed ) {
			return [ 'failed', self::join( $failed ) ];
		}
		if ( [] !== $skipped ) {
			return [ $outcome->saved() ? 'done' : 'skipped', self::join( $skipped ) ];
		}
		return [ 'done', '' ];
	}

	/**
	 * Whether a write found its object already done by an earlier run of the batch.
	 *
	 * @param WriteOutcome $outcome The write.
	 */
	private static function done_before( WriteOutcome $outcome ): bool {
		foreach ( $outcome->results() as $result ) {
			if ( Journal::ALREADY_DONE === $result->code() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Halve the chunk size after a slow chunk, or grow it back after a fast one.
	 *
	 * @param int   $batch_id   The batch.
	 * @param int   $size       This chunk's size.
	 * @param int   $configured The configured size, the most it grows back to.
	 * @param float  $elapsed    Seconds the chunk took.
	 * @param int    $count      Items it claimed.
	 * @param string $kind       What it claimed: PRODUCT or VARIATION.
	 */
	private function adapt( int $batch_id, int $size, int $configured, float $elapsed, int $count, string $kind = self::PRODUCT ): void {
		$limit = $this->time_limit ?? self::php_time_limit();
		$share = $elapsed / max( 1, $limit );
		if ( $share > self::SLOW && $size > self::FLOOR ) {
			$next = max( self::FLOOR, intdiv( $size, 2 ) );
			if ( self::VARIATION === $kind ) {
				$this->batches->record_chunk_size(
					$batch_id,
					$next,
					sprintf(
						/* translators: 1: number of variations in the chunk, 2: seconds it took, 3: seconds allowed, 4: new number of products per chunk, 5: new number of variations per chunk. */
						__( 'The last group of %1$d variations took %2$d of the %3$d seconds the server allows, so this change now works on %4$d products or %5$d variations at a time.', 'bulk-catalogue-manager-for-woocommerce' ),
						$count,
						(int) round( $elapsed ),
						$limit,
						$next,
						self::variation_size( $next, $configured, $this->configured_size( $batch_id, self::VARIATION ) )
					)
				);
				return;
			}
			$note = sprintf(
				/* translators: 1: number of products in the chunk, 2: seconds it took, 3: seconds allowed, 4: new number of products per chunk. */
				__( 'The last group of %1$d products took %2$d of the %3$d seconds the server allows, so this change now works on %4$d products at a time.', 'bulk-catalogue-manager-for-woocommerce' ),
				$count,
				(int) round( $elapsed ),
				$limit,
				$next
			);
			$this->batches->record_chunk_size( $batch_id, $next, $note );
		} elseif ( $share < self::FAST && $size < $configured ) {
			$next = min( $configured, $size * 2 );
			$note = sprintf(
				/* translators: %d: new number of products per chunk. */
				_n( 'Groups of products were finishing well within the time the server allows, so this change now works on %d product at a time again.', 'Groups of products were finishing well within the time the server allows, so this change now works on %d products at a time again.', $next, 'bulk-catalogue-manager-for-woocommerce' ),
				$next
			);
			$this->batches->record_chunk_size( $batch_id, $next, $note );
		}
	}

	/**
	 * The configured chunk size for a kind of item: *Products per background
	 * batch* or *Variations per background batch* through the
	 * `bulkcat_batch_size` filter, within the floor and ceiling.
	 *
	 * @param int    $batch_id The batch, passed to the filter.
	 * @param string $kind     PRODUCT or VARIATION, passed to the filter.
	 */
	private function configured_size( int $batch_id, string $kind ): int {
		$setting = self::VARIATION === $kind ? $this->settings()->variation_batch_size() : $this->settings()->batch_size();
		/**
		 * Filters how many items one background chunk works on.
		 *
		 * @param int    $size     The setting for the kind, 10 to 100; the result is held within 10 and 100.
		 * @param int    $batch_id The batch.
		 * @param string $kind     `product` (products and categories) or `variation`.
		 */
		return self::clamp( (int) apply_filters( 'bulkcat_batch_size', $setting, $batch_id, $kind ) );
	}

	/**
	 * The variation chunk size for the batch's current product size: the
	 * configured variation size in the same proportion, within the floor
	 * and ceiling, so halving one halves the other.
	 *
	 * @param int $size       The product size in use.
	 * @param int $configured The configured product size.
	 * @param int $variations The configured variation size.
	 */
	public static function variation_size( int $size, int $configured, int $variations ): int {
		return self::clamp( intdiv( $variations * $size, max( 1, $configured ) ) );
	}

	/**
	 * A size within the floor and ceiling.
	 *
	 * @param int $size Size.
	 */
	private static function clamp( int $size ): int {
		return max( self::FLOOR, min( self::CEILING, $size ) );
	}

	/**
	 * PHP's time limit in seconds. None (0, as under WP-CLI and some cron
	 * runners) is taken as the lease: a chunk is then bounded by its claim,
	 * not by PHP.
	 */
	public static function php_time_limit(): int {
		$limit = (int) ini_get( 'max_execution_time' );
		return $limit > 0 ? $limit : BatchRepository::LEASE_SECONDS;
	}

	/**
	 * The settings, loaded once.
	 */
	private function settings(): Settings {
		$this->settings ??= Settings::load();
		return $this->settings;
	}

	/**
	 * Distinct reasons as one message.
	 *
	 * @param list<string> $reasons Reasons.
	 */
	private static function join( array $reasons ): string {
		return implode( ' ', array_values( array_unique( array_filter( $reasons, static fn( string $r ): bool => '' !== $r ) ) ) );
	}

	/**
	 * Schedule a delayed retry of a batch.
	 *
	 * @param int $timestamp When.
	 * @param int $batch_id  The batch.
	 */
	private static function schedule_retry( int $timestamp, int $batch_id ): void {
		as_schedule_single_action( $timestamp, self::HOOK, [ $batch_id, self::RETRY ], Data::ACTION_GROUP );
	}

	/**
	 * Unschedule the pending delayed retry of a batch.
	 *
	 * @param int $batch_id The batch.
	 */
	private static function unschedule_retry( int $batch_id ): void {
		as_unschedule_action( self::HOOK, [ $batch_id, self::RETRY ], Data::ACTION_GROUP );
	}

	/**
	 * The reason an item is given up on when its own write stopped the run on each of MAX_ATTEMPTS tries (ENGINE-2).
	 */
	private static function stopped_reason(): string {
		return __( 'The background run stopped while changing this product on each of three tries, so it may not have been finished. Open the product to check it.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The reason an item is given up on when MAX_ATTEMPTS runs stopped before reaching it (ENGINE-2).
	 */
	private static function unreached_reason(): string {
		return __( 'This product was not reached, because the background run stopped before getting to it on each of three tries. Nothing was done to it, so try the change again for it.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The reason given on a saved variation whose variable product could not be refreshed (SILENT-FAILURES-6).
	 */
	private static function stale_parent_reason(): string {
		return __( 'This variation was changed, but its variable product\'s price range and stock could not be refreshed. Open the variable product and update it to refresh them.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The reason an undo item fails when it holds no operations of its own.
	 */
	private static function no_operations_reason(): string {
		return __( 'The record of what to put back on this product is missing, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The reason an item fails when its write threw.
	 */
	private static function error_reason(): string {
		return __( 'Something went wrong while changing this product, so it may not have been finished. Open the product to check it.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
