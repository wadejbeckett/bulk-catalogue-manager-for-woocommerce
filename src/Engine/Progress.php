<?php
/**
 * Progress, cancellation and the stalled queue (SPEC §4.6, §12.4, §12.6;
 * T35, T36): what the screen polls while a batch runs, and what the history
 * screen shows once it has finished.
 *
 * report() answers a batch's status, counters (updated by the chunk runner at
 * the end of every chunk, so a polling client counts up), its times, and:
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
 * cancel() moves a queued or running batch to `cancelled`. The runner reads
 * the status before claiming, again after claiming (releasing the claim when
 * it sees the cancellation) and before enqueueing a successor, so the next
 * run exits without claiming. Applied chunks stay applied and journalled, so
 * the batch stays reversible for the part that ran (§9.4).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Batch progress, the stalled check, the grouped reasons and cancellation.
 */
final class Progress {
	/**
	 * Seconds without a claim after which a batch with pending items is stalled.
	 */
	public const STALL_SECONDS = BatchRepository::LEASE_SECONDS;

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
	 * Build over the repositories.
	 *
	 * @param BatchRepository|null  $batches Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes The journal; the plugin's by default.
	 * @param \Closure|null         $clock   Returns the Unix time; time() by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?\Closure $clock = null ) {
		$this->batches = $batches ?? new BatchRepository();
		$this->changes = $changes ?? new ChangeRepository();
		$this->clock   = $clock ?? static fn(): int => time();
	}

	/**
	 * A batch's full report, or null for no such batch.
	 *
	 * @param int $batch_id Batch.
	 * @return array<string, mixed>|null
	 */
	public function report( int $batch_id ): ?array {
		$batch = $this->batches->find( $batch_id );
		return null === $batch ? null : $this->report_for( $batch );
	}

	/**
	 * The report of a batch row already read.
	 *
	 * @param array<string, mixed> $batch   Typed batch row (BatchRepository::find()).
	 * @param bool                 $summary Whether to add the grouped reasons (two grouped reads).
	 * @return array<string, mixed>
	 */
	public function report_for( array $batch, bool $summary = true ): array {
		$active  = in_array( $batch['status'], self::ACTIVE, true );
		$pending = $active ? $this->batches->count_items( $batch['batch_id'], 'pending' ) : 0;
		$last    = $active ? $this->last_activity( $batch ) : null;
		$stalled = $active && $pending > 0 && null !== $last && self::seconds( $last ) < (int) ( $this->clock )() - self::STALL_SECONDS;
		$report  = [
			'batch_id'          => $batch['batch_id'],
			'status'            => $batch['status'],
			'source'            => $batch['source'],
			'user_id'           => $batch['user_id'],
			'undo_of'           => $batch['undo_of'],
			'created_gmt'       => $batch['created_gmt'],
			'finished_gmt'      => $batch['finished_gmt'],
			'total_items'       => $batch['total_items'],
			'done_items'        => $batch['done_items'],
			'changed_items'     => $batch['changed_items'],
			'failed_items'      => $batch['failed_items'],
			'pending_items'     => $pending,
			'chunk_size'        => $batch['chunk_size'],
			'note'              => $batch['note'],
			'operations'        => $batch['operations'],
			'cancellable'       => $active,
			'stalled'           => $stalled,
			'stalled_message'   => $stalled ? self::stalled_message() : '',
			'last_activity_gmt' => $last,
		];
		if ( $summary ) {
			$report['summary'] = $this->summary( $batch['batch_id'] );
		}
		return $report;
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
		// Each move is conditional on the status read; a batch that started meanwhile is caught by the second.
		if ( 'queued' === $batch['status'] ) {
			$this->batches->transition( $batch_id, 'queued', 'cancelled' );
			$batch = $this->batches->find( $batch_id ) ?? $batch;
		}
		if ( 'running' === $batch['status'] ) {
			$this->batches->transition( $batch_id, 'running', 'cancelled' );
			$batch = $this->batches->find( $batch_id ) ?? $batch;
		}
		return 'cancelled' === $batch['status'] ? $this->report_for( $batch ) : 'finished';
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
