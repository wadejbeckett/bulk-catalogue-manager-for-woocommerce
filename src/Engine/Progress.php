<?php
/**
 * Progress, cancellation and the stalled queue (SPEC §4.6, §12.4, §12.6;
 * T35, T36): what the screen polls while a batch runs, and what the history
 * screen shows once it has finished.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Requests\InlineReceipt;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\External\Disclosure;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Undo\Original;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Batch progress, the stalled check, the grouped reasons and cancellation.
 *
 * report() answers a batch's status, counters (updated by the chunk runner as
 * each item finishes, so a polling client counts up), its times, and:
 * - `stalled`: the batch is queued or running with items still pending, and
 *   no chunk has claimed anything for STALL_SECONDS (five minutes) — the
 *   latest claimed_gmt of its items, or its creation time when none was
 *   ever claimed. The screen then says so and points at WooCommerce's
 *   scheduled actions list instead of showing false progress; nothing has
 *   been applied that the counters do not show;
 * - `summary`: the reasons of the failed and skipped rows grouped with a
 *   count, as §4.8 sentences such as "A product with the SKU SHOE-42
 *   already exists, so this SKU was not applied to 2 products." Journal rows
 *   are grouped per object; items that failed without a journal row (given
 *   up on after three tries, a write that threw) are added from the items.
 *
 * - `previewed_count` and `selection_difference` (T38, §12.6): for a filter
 *   selection, the count its preview showed, and, when that differs from the
 *   objects the selection materialised (`total_items`, less any variations
 *   "Also apply to variations" added: the snapshot's `selected`), one §4.8 sentence stating both numbers and
 *   the difference; '' otherwise. The history list carries both too.
 *
 * Inline batches have no background executor. Unclosed receipts (and legacy
 * unfinished inline batches) stay uncertain regardless of age: reads cannot
 * fail, cancel, queue or undo them. Only the original invocation may finish.
 *
 * - `reversibility` (T54, §4.6): `{ state, conflicts, reason }` from
 *   Reversibility, overridden to in_progress for unclosed inline work;
 *   reports() answers a page of history with one grouped
 *   pair of reads for every row's state and no plan (`conflicts: null`,
 *   T56), and report() counts the one batch's conflicts with the planner.
 *   `actions` lists the action ids the batch's operations use, for the
 *   list's "what" column and its filter.
 *
 * - `undo` (T56, §4.6, §9.4): null except on an undo batch, where it names
 *   the batch undone ("Undo of #418") and, from the batch's selection
 *   snapshot (the single read; a list row carries the label only), whether
 *   Revert anyway was chosen, the plan's counts, how many objects were left
 *   alone because they changed since, how many could not be put back, the
 *   plain sentence ("12 of 1,284 were changed again after this batch and
 *   were left alone.") and the grouped reasons of those not put back.
 *
 * cancel() moves a queued or running batch to `cancelled`. The runner reads
 * the status before claiming, again after claiming (releasing the claim when
 * it sees the cancellation) and before enqueueing a successor, so the next
 * run exits without claiming. Applied chunks stay applied and journalled, so
 * the batch stays reversible for the part that ran (§9.4).
 */
final class Progress {
	/**
	 * Seconds without a claim after which a batch with pending items is stalled.
	 */
	public const STALL_SECONDS = BatchRepository::LEASE_SECONDS;

	/**
	 * Seconds without activity after which a running inline batch is given up on (T38).
	 */
	public const INLINE_STALE_SECONDS = 600;

	/**
	 * Most grouped reasons a report carries.
	 */
	public const MAX_REASONS = 20;

	/**
	 * Statuses in which a batch is still to run, and may be cancelled.
	 */
	public const ACTIVE = [ 'queued', 'running' ];

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
	 * Returns the current Unix time in seconds.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Whether each batch can still be undone.
	 *
	 * @var Reversibility
	 */
	private Reversibility $reversibility;

	/**
	 * The providers registered now, for which external arguments a reader may see; read when first needed.
	 *
	 * @var Providers|null
	 */
	private ?Providers $providers;

	/**
	 * Build over the repositories.
	 *
	 * @param BatchRepository|null  $batches       Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes       The journal; the plugin's by default.
	 * @param \Closure|null         $clock         Returns the Unix time; time() by default.
	 * @param Reversibility|null    $reversibility The reversibility state; by default one over the same repositories and clock, able to count conflicts with the undo planner (T55) when a report asks for them.
	 * @param Providers|null        $providers     The providers; the request's by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?\Closure $clock = null, ?Reversibility $reversibility = null, ?Providers $providers = null ) {
		$this->providers     = $providers;
		$this->batches       = $batches ?? new BatchRepository();
		$this->changes       = $changes ?? new ChangeRepository();
		$this->clock         = $clock ?? static fn(): int => time();
		$this->reversibility = $reversibility ?? new Reversibility( $this->batches, $this->changes, null, $this->clock, Planner::counter( $this->batches, $this->changes, $this->clock ) );
	}

	/**
	 * A batch's full report, or null for no such batch. Every open screen
	 * reads this until a change finishes, so conflicts are not counted
	 * (`null`) unless `$count` asks: counting builds the whole undo plan —
	 * every journal row and every object — which for a whole-catalogue
	 * change costs a web request most of its memory (T85).
	 *
	 * @param int  $batch_id Batch.
	 * @param bool $count    Whether to count conflicts with the undo planner.
	 * @return array<string, mixed>|null
	 */
	public function report( int $batch_id, bool $count = false ): ?array {
		$batch = $this->batches->find( $batch_id );
		return null === $batch ? null : $this->report_for( $batch, true, null, $count );
	}

	/**
	 * The history list's reports of a page of batch rows, without the
	 * grouped reasons, their reversibility read for the page at once.
	 *
	 * @param list<array<string, mixed>> $batches Typed batch rows (BatchRepository::history()).
	 * @return list<array<string, mixed>>
	 */
	public function reports( array $batches ): array {
		$states = $this->reversibility->for_page( $batches );
		return array_map( fn( array $batch ): array => $this->report_for( $batch, false, $states[ $batch['batch_id'] ] ), $batches );
	}

	/**
	 * The report of a batch row already read.
	 *
	 * @param array<string, mixed>                                   $batch         Typed batch row (BatchRepository::find()).
	 * @param bool                                                   $summary       Whether to add the grouped reasons (two grouped reads).
	 * @param array{state: string, conflicts: ?int, reason: ?string}|null $reversibility The batch's state when already read for its page; read here otherwise.
	 * @param bool                                                    $count         When read here, whether to count conflicts (builds the undo plan); null otherwise.
	 * @return array<string, mixed>
	 */
	public function report_for( array $batch, bool $summary = true, ?array $reversibility = null, bool $count = false ): array {
		$unclosed = InlineReceipt::unclosed( $batch );
		$active   = in_array( $batch['status'], self::ACTIVE, true );
		$pending  = $active ? $this->batches->count_items( $batch['batch_id'], 'pending' ) : 0;
		$last     = $active ? $this->last_activity( $batch ) : null;
		$stalled  = ! $unclosed && $active && $pending > 0 && null !== $last && self::seconds( $last ) < (int) ( $this->clock )() - self::STALL_SECONDS;
		$report   = [
			'batch_id'             => $batch['batch_id'],
			'status'               => $batch['status'],
			'source'               => $batch['source'],
			'user_id'              => $batch['user_id'],
			'undo_of'              => $batch['undo_of'],
			'created_gmt'          => $batch['created_gmt'],
			'finished_gmt'         => $batch['finished_gmt'],
			'total_items'          => $batch['total_items'],
			'done_items'           => $batch['done_items'],
			'changed_items'        => $batch['changed_items'],
			'failed_items'         => $batch['failed_items'],
			'pending_items'        => $pending,
			'chunk_size'           => $batch['chunk_size'],
			'note'                 => $batch['note'],
			// An add-on's own argument values only for a reader holding the step's capability (EXTENSIONS §18.20).
			'operations'           => Disclosure::operations( $batch['operations'], get_current_user_id(), $this->providers ??= Actions::instance()->providers() ),
			'cancellable'          => $active && ! InlineReceipt::is_inline( $batch ),
			'stalled'              => $stalled,
			'stalled_message'      => $stalled ? self::stalled_message() : '',
			'last_activity_gmt'    => $last,
			'previewed_count'      => $batch['previewed_items'] ?? null,
			// The selection's own objects, before any variations "Also apply to variations" added (T69).
			'selection_difference' => self::difference( $batch['previewed_items'] ?? null, (int) ( $batch['selection']['selected'] ?? $batch['total_items'] ) ),
			'actions'              => $batch['actions'] ?? [],
			'reversibility'        => $unclosed ? [
				'state'     => 'in_progress',
				'conflicts' => null,
				'reason'    => null,
			] : ( $reversibility ?? ( $count ? $this->reversibility->of( $batch ) : $this->reversibility->for_page( [ $batch ] )[ $batch['batch_id'] ] ) ),
			'undo'                 => self::undo_summary( $batch ),
		];
		if ( array_key_exists( 'inline_receipt', $batch['selection'] ) || $unclosed ) {
			$id     = (int) ( $batch['selection']['inline_receipt']['object_id'] ?? $batch['selection']['ids'][0] ?? 0 );
			$post   = $id > 0 ? get_post( $id ) : null;
			$parent = is_object( $post ) && 'product_variation' === $post->post_type ? (int) $post->post_parent : 0;
			if ( ! isset( $batch['selection']['inline_receipt'] ) || ( get_current_user_id() === $batch['user_id'] && true === \BulkCatalogueManager\Rest\Access::can_read() && true === \BulkCatalogueManager\Rest\Access::can_edit_object( $id, $parent ) ) ) {
				$report['inline_receipt'] = InlineReceipt::read( $batch );
			}
		}
		if ( $unclosed ) {
			$report['reversibility'] = [
				'state'     => 'in_progress',
				'conflicts' => null,
				'reason'    => null,
			];
			$report['note']          = self::uncertain_message();
		}
		if ( is_array( $batch['extensions'] ?? null ) ) {
			// T108: an external change's add-on and labels, from its manifest; absent for a native change.
			$report['external'] = \BulkCatalogueManager\External\Manifest::client( $batch['extensions'] );
		}
		if ( $summary ) {
			$report['summary'] = $this->summary( $batch['batch_id'] );
		}
		return $report;
	}

	/**
	 * An undo batch's account of itself (T56): the batch undone and, when
	 * the row carries its selection snapshot, what was left alone; null for
	 * any other batch.
	 *
	 * @param array<string, mixed> $batch Typed batch row.
	 * @return array{undo_of: int, label: string, forced: ?bool, counts: ?array<string, int>, left_alone: ?int, not_undone: ?int, sentence: string, reasons: list<array{message: string, count: int, sentence: string}>, by_type: ?array{products: int, variations: int}}|null
	 */
	public static function undo_summary( array $batch ): ?array {
		if ( 'undo' !== ( $batch['source'] ?? '' ) || null === ( $batch['undo_of'] ?? null ) ) {
			return null;
		}
		$snapshot = is_array( $batch['selection'] ?? null ) && 'undo' === ( $batch['selection']['kind'] ?? null ) ? $batch['selection'] : null;
		$counts   = null === $snapshot ? null : array_map( 'intval', (array) ( $snapshot['counts'] ?? [] ) );
		$left     = null === $snapshot ? null : (int) ( $snapshot['left_alone'] ?? 0 );
		$forced   = null === $snapshot ? null : true === ( $snapshot['force'] ?? false );
		$reasons  = [];
		foreach ( null === $snapshot ? [] : (array) ( $snapshot['reasons'] ?? [] ) as $reason ) {
			if ( is_array( $reason ) && is_string( $reason['message'] ?? null ) ) {
				$reasons[] = [
					'message'  => $reason['message'],
					'count'    => (int) ( $reason['count'] ?? 0 ),
					'sentence' => self::sentence( $reason['message'], (int) ( $reason['count'] ?? 0 ) ),
				];
			}
		}
		$conflicted = (int) ( $counts['conflicted'] ?? 0 );
		$total      = (int) ( $counts['revertible'] ?? 0 ) + $conflicted;
		$sentence   = '';
		if ( 'partial' === ( $batch['status'] ?? '' ) && 0 === (int) ( $batch['changed_items'] ?? 0 ) && 0 === (int) ( $batch['failed_items'] ?? 0 ) && (int) ( $batch['done_items'] ?? 0 ) > 0 ) {
			// Main Build's F2: every item was changed again before the undo reached it; the batch stays undoable.
			$sentence = __( 'Nothing was put back, because everything had been changed again by the time the undo reached it. The change can still be undone: choose Revert anyway to put the old values back regardless.', 'bulk-catalogue-manager-for-woocommerce' );
		} elseif ( null !== $left && $left > 0 ) {
			$sentence = sprintf(
				/* translators: 1: number of products left alone, such as "12"; 2: number of products the batch changed, such as "1,284". */
				_n( '%1$s of %2$s was changed again after this batch and was left alone.', '%1$s of %2$s were changed again after this batch and were left alone.', $left, 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $left ),
				number_format_i18n( $total )
			);
		} elseif ( true === $forced && $conflicted > 0 ) {
			$sentence = sprintf(
				/* translators: 1: number of products put back although they changed since, such as "12"; 2: number of products the batch changed, such as "1,284". */
				_n( '%1$s of %2$s was changed again after this batch and was put back anyway, because Revert anyway was chosen.', '%1$s of %2$s were changed again after this batch and were put back anyway, because Revert anyway was chosen.', $conflicted, 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $conflicted ),
				number_format_i18n( $total )
			);
		}
		return [
			'undo_of'    => (int) $batch['undo_of'],
			'label'      => sprintf(
				/* translators: %s: the number of the batch undone, such as "418". */
				__( 'Undo of #%s', 'bulk-catalogue-manager-for-woocommerce' ),
				(string) (int) $batch['undo_of']
			),
			'forced'     => $forced,
			'counts'     => $counts,
			'left_alone' => $left,
			'not_undone' => null === $snapshot ? null : (int) ( $snapshot['not_undone'] ?? 0 ),
			'sentence'   => $sentence,
			'reasons'    => $reasons,
			// T114: how many products and variations the undo holds, null for an undo recorded before it was kept.
			'by_type'    => is_array( $snapshot['by_type'] ?? null )
				? [
					'products'   => (int) ( $snapshot['by_type']['products'] ?? 0 ),
					'variations' => (int) ( $snapshot['by_type']['variations'] ?? 0 ),
				]
				: null,
		];
	}

	/**
	 * The reasons of a batch's failed and skipped rows, grouped by message
	 * with the number of objects carrying each, most first.
	 *
	 * @param int $batch_id Batch.
	 * @return list<array{message: string, count: int, sentence: string}>
	 */
	public function summary( int $batch_id ): array {
		$counts = [];
		foreach ( array_merge( $this->changes->reasons( $batch_id, self::MAX_REASONS ), $this->batches->unjournalled_reasons( $batch_id, self::MAX_REASONS ) ) as $reason ) {
			$counts[ $reason['message'] ] = ( $counts[ $reason['message'] ] ?? 0 ) + $reason['objects'];
		}
		$groups = [];
		foreach ( $counts as $message => $count ) {
			$groups[] = [
				'message'  => (string) $message,
				'count'    => $count,
				'sentence' => self::sentence( (string) $message, $count ),
			];
		}
		usort( $groups, static fn( array $a, array $b ): int => [ $b['count'], $a['message'] ] <=> [ $a['count'], $b['message'] ] );
		return array_slice( $groups, 0, self::MAX_REASONS );
	}

	/**
	 * One reason with its count, as one sentence (§4.8): a reason ending
	 * "was not applied." names the count there ("… was not applied to 2
	 * products."); any other is led by the count ("2 products: …").
	 *
	 * @param string $message The reason, one or two sentences.
	 * @param int    $count   Objects carrying it.
	 */
	public static function sentence( string $message, int $count ): string {
		/* translators: The exact ending shared by reasons such as "… so this SKU was not applied.". Translate it as those reasons end, so their counted form below can replace it; a reason that does not end with it is shown after its count instead. */
		$tail = __( 'was not applied.', 'bulk-catalogue-manager-for-woocommerce' );
		if ( str_ends_with( $message, ' ' . $tail ) ) {
			return substr( $message, 0, -strlen( $tail ) ) . sprintf(
				/* translators: %s: number of products. */
				_n( 'was not applied to %s product.', 'was not applied to %s products.', $count, 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $count )
			);
		}
		return sprintf(
			/* translators: 1: number of products, 2: the reason, a full sentence. */
			_n( '%1$s product: %2$s', '%1$s products: %2$s', $count, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( $count ),
			$message
		);
	}

	/**
	 * Cancel a queued or running batch. A batch already cancelled answers as
	 * it is, so a second click changes nothing.
	 *
	 * @param int $batch_id Batch.
	 * @return array<string, mixed>|string The cancelled batch's report, or `missing` or `finished` (it can no longer be cancelled).
	 */
	public function cancel( int $batch_id ): array|string {
		$batch = $this->batches->find( $batch_id );
		if ( null === $batch ) {
			return 'missing';
		}
		if ( InlineReceipt::unclosed( $batch ) ) {
			return 'unclosed'; }
		// Each move is conditional on the status read; a batch that started meanwhile is caught by the second.
		if ( 'queued' === $batch['status'] ) {
			$this->batches->transition( $batch_id, 'queued', 'cancelled' );
			$batch = $this->batches->find( $batch_id ) ?? $batch;
		}
		if ( 'running' === $batch['status'] ) {
			$this->batches->transition( $batch_id, 'running', 'cancelled' );
			$batch = $this->batches->find( $batch_id ) ?? $batch;
		}
		if ( 'cancelled' === $batch['status'] ) {
			// A cancelled undo gives the batch it was undoing back its old status (T56).
			Original::settle( $this->batches, $batch, 'cancelled' );
		}
		return 'cancelled' === $batch['status'] ? $this->report_for( $batch ) : 'finished';
	}

	/** A timeout cannot establish that a synchronous invocation has stopped. */
	public static function uncertain_message(): string {
		return __( 'This save has no confirmed result yet. Check its result again; it cannot be retried, cancelled or undone while its outcome is uncertain.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The difference between the count a filter's preview showed and the
	 * objects the batch materialised, as one §4.8 sentence; '' when they are
	 * the same or no count was previewed.
	 *
	 * @param int|null $previewed The count the preview showed.
	 * @param int      $total     Objects materialised.
	 */
	public static function difference( ?int $previewed, int $total ): string {
		if ( null === $previewed || $previewed === $total ) {
			return '';
		}
		$gap = abs( $total - $previewed );
		return sprintf(
			$total > $previewed
				/* translators: 1: number of products the preview showed; 2: number the change included; 3: the difference. */
				? _n( 'The preview showed %1$s products, but %2$s matched the filters when the change started, so %3$s more product was included.', 'The preview showed %1$s products, but %2$s matched the filters when the change started, so %3$s more products were included.', $gap, 'bulk-catalogue-manager-for-woocommerce' )
				/* translators: 1: number of products the preview showed; 2: number the change included; 3: the difference. */
				: _n( 'The preview showed %1$s products, but %2$s matched the filters when the change started, so %3$s fewer product was included.', 'The preview showed %1$s products, but %2$s matched the filters when the change started, so %3$s fewer products were included.', $gap, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( $previewed ),
			number_format_i18n( $total ),
			number_format_i18n( $gap )
		);
	}

	/**
	 * When the queue last worked the batch: its latest claim, else its creation.
	 *
	 * @param array<string, mixed> $batch Typed batch row.
	 */
	private function last_activity( array $batch ): ?string {
		$claimed = $this->batches->last_claimed_gmt( $batch['batch_id'] );
		$created = '' === $batch['created_gmt'] ? null : $batch['created_gmt'];
		if ( null === $claimed ) {
			return $created;
		}
		return null === $created || $claimed > $created ? $claimed : $created;
	}

	/**
	 * A GMT `Y-m-d H:i:s` time as Unix seconds (0 when unreadable).
	 *
	 * @param string $gmt Time.
	 */
	private static function seconds( string $gmt ): int {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $gmt, new \DateTimeZone( 'UTC' ) );
		return false === $time ? 0 : $time->getTimestamp();
	}

	/**
	 * What the screen says about a stalled batch.
	 */
	public static function stalled_message(): string {
		return __( 'This change has not moved for five minutes because the site\'s background tasks are not running, so nothing more has been changed. Open WooCommerce > Status > Scheduled Actions to check them.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
