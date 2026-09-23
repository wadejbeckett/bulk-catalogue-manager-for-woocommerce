<?php
/**
 * Whether a batch can still be undone (SPEC §4.6, §9.4, §9.5, §12.6; T54):
 * the state the history list and the batch read show beside Undo, derived
 * from the batch row, its journal and the retention setting — never stored.
 *
 * `{ state, conflicts, reason }`, checked in this order:
 * - `in_progress`: the batch is queued, running or undoing; the screen shows
 *   a progress row in place of Undo. No reason, no conflicts.
 * - `not_reversible` with a reason:
 *   - `pruned`: retention pruning removed its journal (`pruned_gmt` set;
 *     the pruner sets it, T60). The batch stays listed;
 *   - `already_undone`: its status is `undone`, or an undo batch naming it
 *     in `undo_of` has completed (BatchRepository::undone_by()). A redo is
 *     an undo of that undo batch, so the undo batch itself stays reversible;
 *   - `outside_retention`: it was created more than *Keep history for* days
 *     ago (Settings::history_days(); never when kept indefinitely);
 *   - `nothing_applied`: it finished with no `applied` journal row — every
 *     row was already at its target, skipped or failed, or it was
 *     cancelled before its first chunk — so there is nothing to put back.
 * - `completed_with_errors`: it ended partial or failed with applied rows;
 *   the applied part can be undone on its own (§12.6).
 * - `partially_reversible`: some applied rows were changed again since
 *   (`conflicts` > 0), so undo would leave those alone unless forced.
 * - `reversible` otherwise (a cancelled batch with applied rows included).
 *
 * Conflicts are T55's undo planner's to count. They come through the
 * injectable `$conflicts` callable, `fn( array $batch ): int`, called only
 * for a batch that is not in progress and not refused; it defaults to 0, so
 * the planner plugs in by being passed here (Progress and BatchesController
 * take a Reversibility) with no change to the routes. The list calls it once
 * per row of a page, so it should answer from a bounded read.
 *
 * for_page() answers a whole page with two grouped reads (the completed
 * undo batches and the applied counts), so the history list costs the same
 * however many rows it shows.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The derived reversibility state of a batch.
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
	 * Every reason a batch is not reversible, for the route schema.
	 */
	public const REASONS = [ self::OUTSIDE_RETENTION, self::ALREADY_UNDONE, self::PRUNED, self::NOTHING_APPLIED ];

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
	 * Build over the repositories.
	 *
	 * @param BatchRepository|null  $batches   Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes   The journal; the plugin's by default.
	 * @param Settings|null         $settings  Settings; read when needed by default.
	 * @param \Closure|null         $clock     Returns the Unix time; time() by default.
	 * @param \Closure|null         $conflicts Counts a batch's conflicts (T55); none by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $conflicts = null ) {
		$this->batches   = $batches ?? new BatchRepository();
		$this->changes   = $changes ?? new ChangeRepository();
		$this->settings  = $settings;
		$this->clock     = $clock ?? static fn(): int => time();
		$this->conflicts = $conflicts ?? static fn(): int => 0;
	}

	/**
	 * The state of one batch.
	 *
	 * @param array<string, mixed> $batch Typed batch row (BatchRepository::find()).
	 * @return array{state: string, conflicts: int, reason: ?string}
	 */
	public function of( array $batch ): array {
		return $this->for_page( [ $batch ] )[ $batch['batch_id'] ];
	}

	/**
	 * The states of a page of batches, by batch id, in two grouped reads.
	 *
	 * @param list<array<string, mixed>> $batches Typed batch rows.
	 * @return array<int, array{state: string, conflicts: int, reason: ?string}>
	 */
	public function for_page( array $batches ): array {
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
			$states[ $id ] = $this->state( $batch, isset( $undone[ $id ] ), $applied[ $id ] ?? 0, $cutoff );
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
	 * @return array{state: string, conflicts: int, reason: ?string}
	 */
	private function state( array $batch, bool $undone, int $applied, ?string $cutoff ): array {
		if ( in_array( $batch['status'], self::ACTIVE, true ) ) {
			return self::answer( self::IN_PROGRESS );
		}
		$reason = match ( true ) {
			null !== ( $batch['pruned_gmt'] ?? null )                 => self::PRUNED,
			$undone || 'undone' === $batch['status']                  => self::ALREADY_UNDONE,
			null !== $cutoff && (string) $batch['created_gmt'] < $cutoff => self::OUTSIDE_RETENTION,
			0 === $applied                                            => self::NOTHING_APPLIED,
			default                                                   => null,
		};
		if ( null !== $reason ) {
			return self::answer( self::NOT_REVERSIBLE, 0, $reason );
		}
		$conflicts = max( 0, (int) ( $this->conflicts )( $batch ) );
		if ( in_array( $batch['status'], self::WITH_ERRORS, true ) ) {
			return self::answer( self::COMPLETED_WITH_ERRORS, $conflicts );
		}
		return self::answer( $conflicts > 0 ? self::PARTIALLY_REVERSIBLE : self::REVERSIBLE, $conflicts );
	}

	/**
	 * A state in the route's shape.
	 *
	 * @param string      $state     One of STATES.
	 * @param int         $conflicts Applied rows changed again since.
	 * @param string|null $reason    One of REASONS, for not_reversible.
	 * @return array{state: string, conflicts: int, reason: ?string}
	 */
	private static function answer( string $state, int $conflicts = 0, ?string $reason = null ): array {
		return [
			'state'     => $state,
			'conflicts' => $conflicts,
			'reason'    => $reason,
		];
	}
}
