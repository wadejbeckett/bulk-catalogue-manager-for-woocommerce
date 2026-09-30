<?php
/**
 * Whether a batch can still be undone (SPEC §4.6, §9.4, §9.5, §12.6; T54):
 * the state the history list and the batch read show beside Undo, derived
 * from the batch row, its journal and the retention setting — never stored.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\External\Blocked;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The derived reversibility state of a batch.
 *
 * `{ state, conflicts, reason }`, checked in this order:
 * - `in_progress`: the batch is queued, running or undoing; the screen shows
 *   a progress row in place of Undo. No reason, no conflicts.
 * - `not_reversible` with a reason:
 *   - `pruned`: retention pruning removed its journal (`pruned_gmt` set;
 *     the pruner sets it, T60). The batch stays listed;
 *   - `already_undone`: its status is `undone`, or an undo batch naming it
 *     in `undo_of` has completed or finished partial (BatchRepository::
 *     undone_by()). A redo is
 *     an undo of that undo batch, so the undo batch itself stays reversible;
 *   - `outside_retention`: it was created more than *Keep history for* days
 *     ago (Settings::history_days(); never when kept indefinitely);
 *   - `nothing_applied`: it finished with no `applied` journal row — every
 *     row was already at its target, skipped or failed, or it was
 *     cancelled before its first chunk — so there is nothing to put back;
 *   - `provider_missing` (T108): its provider manifest names an add-on
 *     that is not registered now (not active); `provider_unavailable`: one
 *     that is registered but not available or of an unsupported contract,
 *     whose own reason the answer carries as `provider_reason`. Recomputed
 *     on every read, so it clears when the add-on can run again; both carry
 *     the add-on's label as `provider` (External\Blocked).
 * - `completed_with_errors`: it ended partial or failed with applied rows;
 *   the applied part can be undone on its own (§12.6).
 * - `partially_reversible`: some applied rows were changed again since
 *   (`conflicts` > 0), so undo would leave those alone unless forced.
 * - `reversible` otherwise (a cancelled batch with applied rows included).
 *
 * Conflicts are T55's undo planner's to count. They come through the
 * injectable `$conflicts` callable, `fn( array $batch ): int`, called only
 * for a batch that is not in progress and not refused; it defaults to 0
 * here, and Progress (so the single batch read) passes
 * Undo\Planner::counter(), the plan's conflicted items. A count costs one
 * plan — the batch's journal and a read of each object it touched — so it
 * is made only for one batch at a time (of()).
 *
 * for_page() answers a whole page of the history list from the cheap
 * signals alone: two grouped reads (the completed undo batches and the
 * applied counts) and no plan, so the list costs the same however many
 * rows it shows and reads no product (T56). Its rows carry `conflicts:
 * null` — not counted — and so read `reversible` or
 * `completed_with_errors` where the single read may say
 * `partially_reversible`; the screen asks the single read before Undo.
 */
final class Reversibility {
	public const REVERSIBLE            = 'reversible';
	public const PARTIALLY_REVERSIBLE  = 'partially_reversible';
	public const IN_PROGRESS           = 'in_progress';
	public const COMPLETED_WITH_ERRORS = 'completed_with_errors';
	public const NOT_REVERSIBLE        = 'not_reversible';

	/**
	 * Every state, for the route schema.
	 */
	public const STATES = [ self::REVERSIBLE, self::PARTIALLY_REVERSIBLE, self::IN_PROGRESS, self::COMPLETED_WITH_ERRORS, self::NOT_REVERSIBLE ];

	public const OUTSIDE_RETENTION = 'outside_retention';
	public const ALREADY_UNDONE    = 'already_undone';
	public const PRUNED            = 'pruned';
	public const NOTHING_APPLIED   = 'nothing_applied';

	/**
	 * The add-on that made the change is registered but cannot run now (T108).
	 */
	public const PROVIDER_UNAVAILABLE = Blocked::UNAVAILABLE;

	/**
	 * The add-on that made the change is not registered now (not active).
	 */
	public const PROVIDER_MISSING = Blocked::MISSING;

	/**
	 * Every reason a batch is not reversible, for the route schema.
	 */
	public const REASONS = [ self::OUTSIDE_RETENTION, self::ALREADY_UNDONE, self::PRUNED, self::NOTHING_APPLIED, self::PROVIDER_MISSING, self::PROVIDER_UNAVAILABLE ];

	/**
	 * Statuses in which a batch is still working.
	 */
	public const ACTIVE = [ 'queued', 'running', 'undoing' ];

	/**
	 * Statuses that end with errors.
	 */
	public const WITH_ERRORS = [ 'partial', 'failed' ];

	/**
	 * Seconds in a day, for the retention window.
	 */
	private const DAY = 86400;

	/**
	 * Batches and undo batches.
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
	 * Settings, or null to read them when a state is asked for.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings;

	/**
	 * Returns the Unix time.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Counts a finished batch's conflicts: `fn( array $batch ): int`.
	 *
	 * @var \Closure
	 */
	private \Closure $conflicts;

	/**
	 * The providers registered now, or null to read the shared registry's when asked (T108).
	 *
	 * @var Providers|null
	 */
	private ?Providers $providers;

	/**
	 * Build over the repositories.
	 *
	 * @param BatchRepository|null  $batches   Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes   The journal; the plugin's by default.
	 * @param Settings|null         $settings  Settings; read when needed by default.
	 * @param \Closure|null         $clock     Returns the Unix time; time() by default.
	 * @param \Closure|null         $conflicts Counts a batch's conflicts (T55); none by default.
	 * @param Providers|null        $providers The providers registered now; the shared registry's by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $conflicts = null, ?Providers $providers = null ) {
		$this->providers = $providers;
		$this->batches   = $batches ?? new BatchRepository();
		$this->changes   = $changes ?? new ChangeRepository();
		$this->settings  = $settings;
		$this->clock     = $clock ?? static fn(): int => time();
		$this->conflicts = $conflicts ?? static fn(): int => 0;
	}

	/**
	 * The state of one batch, its conflicts counted.
	 *
	 * @param array<string, mixed> $batch Typed batch row (BatchRepository::find()).
	 * @return array{state: string, conflicts: int, reason: ?string}
	 */
	public function of( array $batch ): array {
		return $this->states( [ $batch ], true )[ $batch['batch_id'] ];
	}

	/**
	 * The states of a page of batches, by batch id, in two grouped reads,
	 * conflicts not counted (`null`).
	 *
	 * @param list<array<string, mixed>> $batches Typed batch rows.
	 * @return array<int, array{state: string, conflicts: null, reason: ?string}>
	 */
	public function for_page( array $batches ): array {
		return $this->states( $batches, false );
	}

	/**
	 * The states of some batches, by batch id, in two grouped reads.
	 *
	 * @param list<array<string, mixed>> $batches Typed batch rows.
	 * @param bool                       $count   Whether to count conflicts (one plan per batch) or report null.
	 * @return array<int, array{state: string, conflicts: ?int, reason: ?string}>
	 */
	private function states( array $batches, bool $count ): array {
		$finished = [];
		foreach ( $batches as $batch ) {
			if ( ! in_array( $batch['status'], self::ACTIVE, true ) ) {
				$finished[] = (int) $batch['batch_id'];
			}
		}
		$undone  = [] === $finished ? [] : $this->batches->undone_by( $finished );
		$applied = [] === $finished ? [] : $this->changes->applied_counts( $finished );
		$days    = ( $this->settings ?? Settings::load() )->history_days();
		$cutoff  = $days > 0 ? gmdate( 'Y-m-d H:i:s', (int) ( $this->clock )() - $days * self::DAY ) : null;
		$states  = [];
		foreach ( $batches as $batch ) {
			$id            = (int) $batch['batch_id'];
			$states[ $id ] = $this->state( $batch, isset( $undone[ $id ] ), $applied[ $id ] ?? 0, $cutoff, $count );
		}
		return $states;
	}

	/**
	 * One batch's state from what the grouped reads found.
	 *
	 * @param array<string, mixed> $batch   Typed batch row.
	 * @param bool                 $undone  Whether a completed undo batch names it.
	 * @param int                  $applied Its applied journal rows.
	 * @param string|null          $cutoff  Batches created before this GMT time are outside retention; null keeps them all.
	 * @param bool                 $count   Whether to count conflicts; null is reported otherwise.
	 * @return array{state: string, conflicts: ?int, reason: ?string}
	 */
	private function state( array $batch, bool $undone, int $applied, ?string $cutoff, bool $count ): array {
		$none = $count ? 0 : null;
		if ( in_array( $batch['status'], self::ACTIVE, true ) ) {
			return self::answer( self::IN_PROGRESS, $none );
		}
		$reason = match ( true ) {
			null !== ( $batch['pruned_gmt'] ?? null )                 => self::PRUNED,
			$undone || 'undone' === $batch['status']                  => self::ALREADY_UNDONE,
			null !== $cutoff && (string) $batch['created_gmt'] < $cutoff => self::OUTSIDE_RETENTION,
			0 === $applied                                            => self::NOTHING_APPLIED,
			default                                                   => null,
		};
		if ( null !== $reason ) {
			return self::answer( self::NOT_REVERSIBLE, $none, $reason );
		}
		$manifest = $batch['extensions'] ?? null;
		$blocked  = is_array( $manifest ) ? Manifest::blocked( $manifest, $this->providers ??= Actions::instance()->providers(), false ) : null;
		if ( null !== $blocked ) {
			return self::answer( self::NOT_REVERSIBLE, $none, $blocked->kind ) + [ 'provider' => $blocked->label ] + ( $blocked->is_missing() ? [] : [ 'provider_reason' => $blocked->reason ] );
		}
		$conflicts = $count ? max( 0, (int) ( $this->conflicts )( $batch ) ) : null;
		if ( in_array( $batch['status'], self::WITH_ERRORS, true ) ) {
			return self::answer( self::COMPLETED_WITH_ERRORS, $conflicts );
		}
		return self::answer( null !== $conflicts && $conflicts > 0 ? self::PARTIALLY_REVERSIBLE : self::REVERSIBLE, $conflicts );
	}

	/**
	 * A state in the route's shape.
	 *
	 * @param string      $state     One of STATES.
	 * @param int|null    $conflicts Objects changed again since, or null when not counted.
	 * @param string|null $reason    One of REASONS, for not_reversible.
	 * @return array{state: string, conflicts: ?int, reason: ?string}
	 */
	private static function answer( string $state, ?int $conflicts = 0, ?string $reason = null ): array {
		return [
			'state'     => $state,
			'conflicts' => $conflicts,
			'reason'    => $reason,
		];
	}
}
