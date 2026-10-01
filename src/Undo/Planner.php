<?php
/**
 * The undo planner (SPEC §9.4, §9.5, §12.6; T55): turns a finished batch
 * into an Undo\Plan without writing anything.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

use BulkCatalogueManager\Requests\InlineReceipt;
use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Engine\Memory;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\External;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\RestoreStatus;
use BulkCatalogueManager\Operations\Terms;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plans the undo of one batch.
 *
 * It refuses, 409 `bulkcat_not_reversible` with the reason code in
 * `data.reason`, a batch that has not finished (queued, running, undoing),
 * one already undone (its status is `undone`, or a complete or partial undo
 * batch names it in `undo_of`), one being undone now (a queued or running
 * undo batch names it), one outside the retention window, and one whose
 * journal was pruned (`pruned_gmt` set, as Reversibility reads it, or no
 * journal rows left although the batch processed items). A partial, failed
 * or cancelled batch is planned for the part it applied.
 *
 * Otherwise it reads the batch's journal newest first and plans one item
 * per object, the object journalled last first. For each field the batch
 * applied, the value to expect now is the `new_value` of its newest applied
 * row and the value to put back the `old_value` of its oldest, and the
 * operations set the fields back in reverse order, in the operation
 * registry's normalised shape (validated through validate_list(), as
 * BatchFactory stores them): `replace_terms` for a term list, `remove_terms`
 * of what the batch left when the list was empty before, `clear` for an
 * empty value where the field takes it, `set_value` otherwise. Each item is:
 *
 * - skipped where the batch applied nothing to the object (its rows were
 *   themselves skipped or failed), where the object no longer exists or is
 *   no longer the same kind, where the person undoing (not the batch's author) may not
 *   edit it, or where a value can no longer be written (a deleted term, a
 *   field no longer in the registry);
 * - conflicted where a field's current value, read through Field::read(),
 *   differs from what the batch wrote — `conflicts` lists each such field
 *   with the value expected and the value found, and the operations still
 *   cover every field, for **Revert anyway**;
 * - revertible otherwise.
 *
 * The plan is walked, never held (T111, ticket 11): walk() reads which
 * objects the batch journalled, newest first, keeping only their order (a
 * few dozen bytes an object), then plans them a page at a time — the page's
 * journal rows read by object, its products primed, planned and released
 * (Engine\Memory) — handing each item to the caller and folding it into the
 * counts and the hash, so previewing, counting and accepting the undo of a
 * whole-catalogue change fit a web request's memory. plan() collects the
 * same items into an Undo\Plan for callers that want them all (tests, small
 * batches); items() and checker() plan named objects as they stand now, for
 * the preview's page and for the check each undo item gets again as its
 * chunk writes it (Engine\ChunkRunner).
 * Status and its Off record (T90, SPEC §9.4) fold into one internal step,
 * `restore_status`, carrying the journal's old status (`value`) and old
 * record (`record`), each only when the batch applied that prop; it is
 * validated through validate_list()'s internal mode. Conflicts stay per prop
 * (a record conflict lists as the field "Status before Off"), and a product
 * carrying its own record written by a later batch than the one undone is
 * conflicted too, "changed since", with that record as its current value:
 * batch ids only grow, and a record the undone batch wrote carries its own
 * id, so it never trips. A journalled record that does not name its object
 * is skipped, never written. The step's status comes from the journal
 * alone, never from the record.
 *
 * Values compare in the field type's canonical form, never loosely: a price
 * through wc_format_decimal() at the shop's precision (10 and 10.00 are the
 * same price, '' and 0.00 are not), a whole number with null distinct from
 * 0, a switch as a boolean, a term list as a sorted id list, text exactly.
 *
 * External values (T108, docs/EXTENSIONS.md §8.4): a batch whose manifest
 * names a provider that is not registered is refused 409 with `data.reason:
 * provider_missing` ("… is not active now. Turn it on again …"), and one
 * that is registered but not available or of an unsupported contract with
 * `provider_unavailable` and the add-on's own reason after the core's
 * words (External\Blocked). Otherwise, for each external field of an object,
 * after the core's own edit check: the capability of every action of the
 * provider that changes the field and the provider's authorise() (a refusal
 * skips the object, `cannot_edit`); the value read now (an Unknown skips,
 * `cannot_restore`); the journalled values converted to the field's format
 * (older through upgrade(); newer, or an upgrade error, skips
 * `cannot_restore`); a conflict, listed with the expected and current values
 * as the provider describes them, when the value now is not the value the
 * batch left; and the internal step `restore_external` putting the oldest
 * old value back.
 */
final class Planner {
	/**
	 * Refusal code for a batch that cannot be undone; the reason is in `data.reason`.
	 */
	public const NOT_REVERSIBLE = 'bulkcat_not_reversible';

	/*
	 * Refusal reasons.
	 */
	public const IN_PROGRESS      = 'in_progress';
	public const ALREADY_UNDONE   = 'already_undone';
	public const UNDO_IN_PROGRESS = 'undo_in_progress';
	public const RETENTION        = 'retention';
	public const PRUNED           = 'pruned';

	/**
	 * Refusal reason: the add-on that made the change is registered but cannot run now (T108).
	 */
	public const PROVIDER_UNAVAILABLE = External\Blocked::UNAVAILABLE;

	/**
	 * Refusal reason: the add-on that made the change is not registered (not active).
	 */
	public const PROVIDER_MISSING = External\Blocked::MISSING;

	/*
	 * Item codes: why an item is conflicted or skipped.
	 */
	public const CHANGED_SINCE  = 'changed_since';
	public const NOT_APPLIED    = 'not_applied';
	public const MISSING        = 'missing';
	public const CANNOT_EDIT    = 'cannot_edit';
	public const CANNOT_RESTORE = 'cannot_restore';

	/**
	 * Batch statuses of a batch still at work.
	 */
	private const UNFINISHED = [ 'queued', 'running', 'undoing' ];

	/**
	 * Statuses of an undo batch that has undone its batch.
	 */
	private const UNDO_DONE = [ 'complete', 'partial' ];

	/**
	 * Statuses of an undo batch still at work.
	 */
	private const UNDO_RUNNING = [ 'queued', 'running' ];

	/**
	 * Objects planned a page at a time: primed, planned and released together.
	 */
	private const PAGE = Preview::PAGE;

	/**
	 * Context mixed into the hash, so it cannot be mistaken for another signature.
	 */
	private const CONTEXT = 'bulk-catalogue-manager-for-woocommerce/undo/1/';

	/**
	 * Seconds in a day.
	 */
	private const DAY = 86400;

	/**
	 * Batches.
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
	 * The action registry, with its field registry.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The clock: the current Unix time.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $now;

	/**
	 * Build a planner; everything defaults to the plugin's own.
	 *
	 * @param BatchRepository|null  $batches  Batches.
	 * @param ChangeRepository|null $changes  The journal.
	 * @param Actions|null          $actions  The action registry.
	 * @param Settings|null         $settings The settings.
	 * @param \Closure|null         $now      Returns the current Unix time.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Actions $actions = null, ?Settings $settings = null, ?\Closure $now = null ) {
		$this->batches  = $batches ?? new BatchRepository();
		$this->changes  = $changes ?? new ChangeRepository();
		$this->actions  = $actions ?? Actions::instance();
		$this->settings = $settings ?? Settings::load();
		$this->now      = $now ?? static fn(): int => time();
	}

	/**
	 * Plan the undo of a batch, writing nothing, and keep every item: the
	 * whole plan in memory. Each object is checked against the person
	 * undoing — the user who will run the undo batch — not the batch's
	 * original author, who may since have been demoted or removed. The
	 * routes walk() instead, holding a page at a time.
	 *
	 * @param int      $batch_id The batch to undo.
	 * @param int|null $user_id  The person undoing; the current user by default.
	 * @return Plan|\WP_Error The plan, or 404 `bulkcat_not_found`, or 409 `bulkcat_not_reversible` with its reason.
	 */
	public function plan( int $batch_id, ?int $user_id = null ): Plan|\WP_Error {
		$items   = [];
		$summary = $this->walk(
			$batch_id,
			$user_id,
			static function ( array $item ) use ( &$items ): void {
				$items[] = $item;
			}
		);
		return $summary instanceof \WP_Error ? $summary : new Plan( $batch_id, $items, $summary->hash() );
	}

	/**
	 * Walk the undo plan of a batch, writing nothing (T111): each item, in
	 * the order the undo writes them (the object journalled last first), is
	 * handed to $each as soon as it is planned and then let go, so memory
	 * holds the batch's object order and one page, never the plan. The
	 * summary carries the counts and the same hash plan() gives.
	 *
	 * @param int                               $batch_id The batch to undo.
	 * @param int|null                          $user_id  The person undoing; the current user by default.
	 * @param callable( array<string, mixed> ): void|null $each Given each item in plan order.
	 * @return PlanSummary|\WP_Error The summary, or 404 `bulkcat_not_found`, or 409 `bulkcat_not_reversible` with its reason.
	 */
	public function walk( int $batch_id, ?int $user_id = null, ?callable $each = null ): PlanSummary|\WP_Error {
		$user_id ??= get_current_user_id();
		$batch     = $batch_id > 0 ? $this->batches->find( $batch_id ) : null;
		if ( null === $batch ) {
			return Refusal::create( Writer::NOT_FOUND, __( 'That batch could not be found. It may have been removed. Reload the history and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 404 );
		}
		$refusal = $this->refusal( $batch );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$order = $this->order( $batch_id );
		if ( [] === $order && $batch['total_items'] > 0 && $batch['done_items'] > 0 ) {
			return self::not_reversible( self::PRUNED, self::pruned_sentence() );
		}

		$context = $this->hash_start( $batch_id );
		$counts  = array_fill_keys( Plan::STATES, 0 );
		$first   = true;
		// What WordPress counts from here: each page's per-product hook names are forgotten with its objects (ticket 11).
		$mark = Memory::mark();
		$page = [];
		$done = 0;
		foreach ( $order as $object_id => $type ) {
			$page[ $object_id ] = $type;
			if ( self::PAGE > count( $page ) ) {
				continue;
			}
			$this->walk_page( $batch_id, $page, $user_id, 0 < $done ? $mark : null, $context, $counts, $first, $each );
			$done += count( $page );
			$page  = [];
		}
		if ( [] !== $page ) {
			$this->walk_page( $batch_id, $page, $user_id, 0 < $done ? $mark : null, $context, $counts, $first, $each );
		}
		return new PlanSummary( $batch_id, $counts, $this->hash_end( $context ) );
	}

	/**
	 * Plan named objects of a batch as they stand now, keyed by object id
	 * in the order asked (T111): the preview's page, planned again after the
	 * walk that counted it. No batch-level refusal applies; an object the
	 * batch did not journal is left out.
	 *
	 * @param int       $batch_id   The batch undone.
	 * @param list<int> $object_ids Objects.
	 * @param int|null  $user_id    The person undoing; the current user by default.
	 * @return array<int, array<string, mixed>> Plan items by object id.
	 */
	public function items( int $batch_id, array $object_ids, ?int $user_id = null ): array {
		$out = [];
		foreach ( array_chunk( self::ids( $object_ids ), self::PAGE ) as $ids ) {
			$check = $this->checker( $batch_id, $ids, $user_id );
			foreach ( $ids as $id ) {
				$item = $check( $id );
				if ( null !== $item ) {
					$out[ $id ] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * A check of each of these objects as it stands at the moment it is
	 * asked (T111): their journal rows in the batch are read once, and each
	 * call plans the object then — every field the batch changed, status and
	 * its Off record included — from the object given (the one the writer
	 * has just loaded, so an undo item is checked just before it is written)
	 * or loaded afresh. The closure answers the plan item, or null for an
	 * object the batch did not journal.
	 *
	 * @param int       $batch_id   The batch undone.
	 * @param list<int> $object_ids Objects, at most ChangeRepository::MAX_OBJECTS.
	 * @param int|null  $user_id    The person undoing; the current user by default.
	 * @return \Closure( int, ?object ): ?array<string, mixed>
	 */
	public function checker( int $batch_id, array $object_ids, ?int $user_id = null ): \Closure {
		$user_id ??= get_current_user_id();
		$ids       = self::ids( $object_ids );
		$journals  = $this->journals( $batch_id, $ids );
		Memory::next_page( $ids, false );
		return function ( int $object_id, ?object $subject = null ) use ( $journals, $user_id, $batch_id ): ?array {
			return isset( $journals[ $object_id ] ) ? $this->item( $object_id, $journals[ $object_id ], $user_id, $batch_id, $subject ) : null;
		};
	}

	/**
	 * How many of a batch's objects were changed again since it ran (the
	 * plan's conflicted items), or 0 where it cannot be planned. This is the
	 * count Engine\Reversibility shows as `conflicts` and turns into
	 * `partially_reversible`. The plan is walked, not kept.
	 *
	 * @param array<string, mixed> $batch Typed batch row; only its id is read.
	 */
	public function conflicts( array $batch ): int {
		$summary = $this->walk( (int) $batch['batch_id'] );
		return $summary instanceof PlanSummary ? $summary->counts()[ Plan::CONFLICTED ] : 0;
	}

	/**
	 * The conflict counter Engine\Reversibility takes, over the given
	 * repositories and clock. The planner is built on the first count, so a
	 * page that never asks (every row in progress or refused) loads nothing.
	 *
	 * @param BatchRepository|null  $batches Batches.
	 * @param ChangeRepository|null $changes The journal.
	 * @param \Closure|null         $now     Returns the current Unix time.
	 * @return \Closure( array<string, mixed> ): int
	 */
	public static function counter( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?\Closure $now = null ): \Closure {
		$planner = null;
		return static function ( array $batch ) use ( &$planner, $batches, $changes, $now ): int {
			$planner ??= new self( $batches, $changes, null, null, $now );
			return $planner->conflicts( $batch );
		};
	}

	/**
	 * A value in its field type's canonical form, for comparison.
	 *
	 * @param Field $field The field.
	 * @param mixed $value A recorded or read value.
	 */
	public static function canonical( Field $field, mixed $value ): mixed {
		switch ( $field->type() ) {
			case Field::MONEY:
			case Field::DECIMAL:
				if ( Values::is_empty( $value ) ) {
					return '';
				}
				$decimal = Values::decimal( $value );
				if ( null === $decimal ) {
					return $value;
				}
				return Field::MONEY === $field->type() ? wc_format_decimal( $decimal, wc_get_price_decimals() ) : wc_format_decimal( $decimal, false, true );
			case Field::INTEGER:
				return Values::is_empty( $value ) ? null : ( Values::integer( $value ) ?? $value );
			case Field::TERM:
				return Values::is_empty( $value ) ? 0 : ( Values::integer( $value ) ?? $value );
			case Field::BOOLEAN:
				return Values::boolean( $value ) ?? $value;
			case Field::TERMS:
				$ids = Values::id_list( $value );
				if ( null === $ids ) {
					return $value;
				}
				sort( $ids );
				return $ids;
			default:
				return null === $value ? '' : ( is_scalar( $value ) ? (string) $value : $value );
		}
	}

	/**
	 * Whether two values of a field are the same value.
	 *
	 * @param Field $field The field.
	 * @param mixed $a     One value.
	 * @param mixed $b     The other.
	 */
	public static function same( Field $field, mixed $a, mixed $b ): bool {
		return self::canonical( $field, $a ) === self::canonical( $field, $b );
	}

	/**
	 * Why the batch as a whole cannot be undone, or null.
	 *
	 * @param array<string, mixed> $batch The batch row.
	 */
	private function refusal( array $batch ): ?\WP_Error {
		if ( InlineReceipt::unclosed( $batch ) ) {
			return self::not_reversible( self::IN_PROGRESS, \BulkCatalogueManager\Engine\Progress::uncertain_message() );
		}
		if ( 'undoing' === $batch['status'] ) {
			return self::not_reversible( self::UNDO_IN_PROGRESS, self::undoing_sentence() );
		}
		if ( in_array( $batch['status'], self::UNFINISHED, true ) ) {
			return self::not_reversible( self::IN_PROGRESS, __( 'This batch is still running, so it cannot be undone yet. Wait for it to finish, then try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( 'undone' === $batch['status'] ) {
			return self::not_reversible( self::ALREADY_UNDONE, self::undone_sentence() );
		}
		foreach ( $this->batches->undos_of( $batch['batch_id'] ) as $undo ) {
			// An undo that put nothing back (every item changed again first) undid nothing (F2).
			if ( in_array( $undo['status'], self::UNDO_DONE, true ) && 0 < ( $undo['changed_items'] ?? 1 ) ) {
				return self::not_reversible( self::ALREADY_UNDONE, self::undone_sentence(), [ 'undo_batch_id' => $undo['batch_id'] ] );
			}
			if ( in_array( $undo['status'], self::UNDO_RUNNING, true ) ) {
				return self::not_reversible( self::UNDO_IN_PROGRESS, self::undoing_sentence(), [ 'undo_batch_id' => $undo['batch_id'] ] );
			}
		}
		if ( null !== ( $batch['pruned_gmt'] ?? null ) ) {
			return self::not_reversible( self::PRUNED, self::pruned_sentence() );
		}
		$manifest = $batch['extensions'] ?? null;
		$blocked  = is_array( $manifest ) ? External\Manifest::blocked( $manifest, $this->actions->providers(), false ) : null;
		if ( null !== $blocked ) {
			return self::not_reversible( $blocked->kind, $blocked->undo_sentence() );
		}
		$days    = $this->settings->history_days();
		$created = strtotime( $batch['created_gmt'] . ' UTC' );
		if ( $days > 0 && false !== $created && $created < ( $this->now )() - $days * self::DAY ) {
			return self::not_reversible(
				self::RETENTION,
				sprintf(
					/* translators: %d: how many days of history the shop keeps. */
					_n( 'This batch is older than the %d day of history the shop keeps, so it can no longer be undone.', 'This batch is older than the %d days of history the shop keeps, so it can no longer be undone.', $days, 'bulk-catalogue-manager-for-woocommerce' ),
					$days
				),
				[ 'history_days' => $days ]
			);
		}
		return null;
	}

	/**
	 * The objects a batch journalled, the object journalled last first, each
	 * with its type (that of its newest row): read a page of rows at a time
	 * with no values, keeping only this order.
	 *
	 * @param int $batch_id Batch.
	 * @return array<int, string> Object id => type, in plan order.
	 */
	private function order( int $batch_id ): array {
		$order  = [];
		$types  = [];
		$before = 0;
		do {
			$rows = $this->changes->objects_newest_first( $batch_id, $before, ChangeRepository::OBJECTS_PAGE );
			$full = ChangeRepository::OBJECTS_PAGE === count( $rows );
			foreach ( $rows as $row ) {
				$before = $row['change_id'];
				if ( ! isset( $order[ $row['object_id'] ] ) ) {
					// One string per type, shared by every object of it.
					$types[ $row['object_type'] ] ??= $row['object_type'];
					$order[ $row['object_id'] ]     = $types[ $row['object_type'] ];
				}
			}
		} while ( $full );
		return $order;
	}

	/**
	 * Some objects' journal in the batch, grouped: per object its type (that
	 * of its newest row) and, per applied field (newest first), the value
	 * the batch left and the value before it.
	 *
	 * @param int       $batch_id Batch.
	 * @param list<int> $ids      Objects.
	 * @return array<int, array{type: string, fields: array<string, array{expected: mixed, target: mixed}>}>
	 */
	private function journals( int $batch_id, array $ids ): array {
		$objects = [];
		foreach ( $this->changes->for_objects( $batch_id, $ids ) as $row ) {
			$id               = $row['object_id'];
			$objects[ $id ] ??= [
				'type'   => $row['object_type'],
				'fields' => [],
			];
			// An intent row (§12.3) a killed run left unsettled counts as applied: whether its save
			// landed shows as the object holding the value, or as a conflict when it does not.
			if ( 'applied' !== $row['result'] && Schema::CHANGE_INTENT !== $row['result'] ) {
				continue;
			}
			$prop = $row['prop'];
			if ( isset( $objects[ $id ]['fields'][ $prop ] ) ) {
				// An older applied row for the same field: the value to put back is the oldest one.
				$objects[ $id ]['fields'][ $prop ]['target'] = $row['old_value'];
				continue;
			}
			$objects[ $id ]['fields'][ $prop ] = [
				'expected' => $row['new_value'],
				'target'   => $row['old_value'],
			];
		}
		return $objects;
	}

	/**
	 * Plan one page of the walk: release what the previous page loaded,
	 * prime this one's objects, read their journal rows, and plan each in
	 * order, folding it into the hash and the counts and handing it on.
	 *
	 * @param int                                   $batch_id The batch undone.
	 * @param array<int, string>                    $page     Object id => type, in plan order.
	 * @param int                                   $user_id  The person undoing.
	 * @param array{0: int, 1: int}|null            $mark     Memory::mark() at the walk's start, to release the previous page; null on the first.
	 * @param \HashContext                          $context  The plan hash so far.
	 * @param array<string, int>                    $counts   Items by state so far.
	 * @param bool                                  $first    Whether no item has been hashed yet.
	 * @param callable( array<string, mixed> ): void|null $each Given each item.
	 */
	private function walk_page( int $batch_id, array $page, int $user_id, ?array $mark, \HashContext $context, array &$counts, bool &$first, ?callable $each ): void {
		if ( null !== $mark ) {
			// T76: a 5,000-product batch's plan outgrew 128 MB with every object kept loaded.
			Memory::release( $mark );
		}
		$ids = array_keys( $page );
		Memory::next_page( $ids, false );
		$journals = $this->journals( $batch_id, $ids );
		foreach ( $page as $object_id => $type ) {
			$item = $this->item(
				$object_id,
				$journals[ $object_id ] ?? [
					'type'   => $type,
					'fields' => [],
				],
				$user_id,
				$batch_id
			);
			self::hash_item( $context, $item, $first );
			$first = false;
			if ( ! isset( $counts[ $item['state'] ] ) ) {
				throw new \InvalidArgumentException( 'An undo plan item has an unknown state.' );
			}
			++$counts[ $item['state'] ];
			if ( null !== $each ) {
				$each( $item );
			}
		}
	}

	/**
	 * Positive object ids, each once, in the order given.
	 *
	 * @param array<mixed> $ids Candidate ids.
	 * @return list<int>
	 */
	private static function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Plan one object.
	 *
	 * @param int                                                                                   $object_id Object.
	 * @param array{type: string, fields: array<string, array{expected: mixed, target: mixed}>} $journal   Its journal.
	 * @param int                                                                                   $user_id   The person undoing.
	 * @param int                                                                                   $batch_id  The batch undone.
	 * @param object|null                                                                           $loaded    The object, already loaded; loaded here when null.
	 * @return array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}
	 */
	private function item( int $object_id, array $journal, int $user_id, int $batch_id = 0, ?object $loaded = null ): array {
		$type      = $journal['type'];
		$variation = 'variation' === $type;
		if ( [] === $journal['fields'] ) {
			return self::skipped(
				$object_id,
				$type,
				self::NOT_APPLIED,
				$variation
					? __( 'This batch did not change this variation, so there is nothing to undo.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'This batch did not change this product, so there is nothing to undo.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		if ( 'product' !== $type && ! $variation ) {
			return self::skipped( $object_id, $type, self::CANNOT_RESTORE, __( 'This change cannot be undone here, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}

		$subject = $loaded ?? self::load( $object_id );
		if ( null === $subject || self::is_variation( $subject ) !== $variation ) {
			return self::skipped(
				$object_id,
				$type,
				self::MISSING,
				$variation
					? __( 'This variation no longer exists, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'This product no longer exists, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		if ( true !== Access::user_can_edit_object( $user_id, $object_id, $variation ? max( 0, (int) $subject->get_parent_id( 'edit' ) ) : 0 ) ) {
			return self::skipped( $object_id, $type, self::CANNOT_EDIT, __( 'You can no longer edit this product, so it was left alone. Ask an administrator for access to it.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}

		$fields     = $this->actions->fields();
		$operations = [];
		$conflicts  = [];
		$restore    = null;
		$external   = false;
		$checked    = [];
		foreach ( $journal['fields'] as $key => $values ) {
			if ( External\Providers::is_external( (string) $key ) ) {
				// T108: an add-on's value goes back through its provider.
				$part = $this->external( $subject, (string) $key, $values, $user_id, $batch_id, $checked );
				if ( isset( $part['skip'] ) ) {
					return self::skipped( $object_id, $type, $part['skip'][0], $part['skip'][1] );
				}
				if ( null !== $part['conflict'] ) {
					$conflicts[] = $part['conflict'];
				}
				$operations[] = $part['operation'];
				$external     = true;
				continue;
			}
			$field = $fields->get( $key );
			if ( null === $field || $field->read_only() ) {
				return self::skipped( $object_id, $type, self::CANNOT_RESTORE, __( 'One of the values this batch changed can no longer be changed here, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$current = $field->read( $subject );
			if ( ! self::same( $field, $current, $values['expected'] ) ) {
				$conflicts[] = [
					'field'    => $key,
					'expected' => $values['expected'],
					'current'  => $current,
				];
			}
			$status = StatusSwitch::governs( $field );
			if ( $status || OffRecord::FIELD === $key || \BulkCatalogueManager\Publishing\PublicationPassword::FIELD === $key ) {
				// Status and its Off record go back together, as one internal step, where the first of them was.
				if ( OffRecord::FIELD === $key && '' !== (string) $values['target'] && null === OffRecord::parse( $values['target'], $object_id ) ) {
					return self::skipped( $object_id, $type, self::CANNOT_RESTORE, __( 'The saved status before Off this batch changed could not be read for this product, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				if ( null === $restore ) {
					$restore      = count( $operations );
					$operations[] = [];
				}
				$operations[ $restore ][ $status ? 'value' : ( OffRecord::FIELD === $key ? 'record' : 'password' ) ] = $values['target'] ?? '';
				continue;
			}
			$operation = self::operation( $field, $values['target'], $values['expected'] );
			if ( null !== $operation ) {
				$operations[] = $operation;
			}
		}
		if ( null !== $restore ) {
			$operations[ $restore ] = self::restore_step( $fields, $operations[ $restore ], $subject );
			$newer                  = $this->newer_record( $subject, $batch_id, $conflicts );
			if ( null !== $newer ) {
				$conflicts[] = $newer;
			}
		}

		$normalised = [] === $operations ? [] : $this->actions->validate_list( $operations, null !== $restore || $external );
		if ( $normalised instanceof \WP_Error ) {
			return self::skipped( $object_id, $type, self::CANNOT_RESTORE, $normalised->get_error_message() );
		}
		if ( [] !== $conflicts ) {
			return [
				'object_id'   => $object_id,
				'object_type' => $type,
				'operations'  => $normalised,
				'state'       => Plan::CONFLICTED,
				'code'        => self::CHANGED_SINCE,
				'reason'      => $variation
					? __( 'This variation was changed since this batch ran, so it is left alone unless you choose Revert anyway.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'This product was changed since this batch ran, so it is left alone unless you choose Revert anyway.', 'bulk-catalogue-manager-for-woocommerce' ),
				'conflicts'   => $conflicts,
			];
		}
		return [
			'object_id'   => $object_id,
			'object_type' => $type,
			'operations'  => $normalised,
			'state'       => Plan::REVERTIBLE,
			'code'        => '',
			'reason'      => '',
			'conflicts'   => [],
		];
	}

	/**
	 * One external field of an object (T108): the step putting it back and
	 * its conflict, or why the object is skipped.
	 *
	 * @param object                                  $subject  The loaded object.
	 * @param string                                  $key      Field key.
	 * @param array{expected: mixed, target: mixed}   $values   The journalled envelopes.
	 * @param int                                     $user_id  The person undoing.
	 * @param int                                     $batch_id The batch undone.
	 * @param array<string, bool>                     $checked  Providers whose permission is already checked for this object.
	 * @return array{operation?: array<string, mixed>, conflict?: array<string, mixed>|null, skip?: array{0: string, 1: string}}
	 */
	private function external( object $subject, string $key, array $values, int $user_id, int $batch_id, array &$checked ): array {
		$provider = $this->actions->providers()->owning( $key );
		if ( null === $provider || null === $provider->field( $key ) ) {
			return [ 'skip' => [ self::CANNOT_RESTORE, __( 'One of the values this batch changed can no longer be changed here, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) ] ];
		}
		$context = new External\RunContext( External\RunContext::UNDO, $user_id, $batch_id );
		$object  = External\ProductSubject::of( $subject, $provider );
		$step    = [
			'action' => External\RestoreExternal::ID,
			'args'   => [ 'field' => $key ],
		];
		if ( ! ( new External\Computer( $provider, $context ) )->may( $user_id, $step ) ) {
			return [ 'skip' => [ self::CANNOT_EDIT, External\Refusals::no_permission() ] ];
		}
		if ( ! isset( $checked[ $provider->id ] ) ) {
			$refusal = self::authorise( $provider, $user_id, $object );
			if ( null !== $refusal ) {
				return [ 'skip' => [ self::CANNOT_EDIT, $refusal ] ];
			}
			$checked[ $provider->id ] = true;
		}
		$current = $object->fresh( $key );
		if ( $current instanceof \BulkCatalogueManager\Extension\Unknown ) {
			return [ 'skip' => [ self::CANNOT_RESTORE, External\Text::plain( $current->reason(), External\Text::REASON ) ] ];
		}
		$expected = External\Envelope::current( $provider, $key, $values['expected'] );
		$target   = External\Envelope::current( $provider, $key, $values['target'] );
		if ( $expected instanceof \WP_Error || $target instanceof \WP_Error ) {
			return [ 'skip' => [ self::CANNOT_RESTORE, __( 'A value this batch changed was saved in a shape the add-on can no longer read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) ] ];
		}
		$field = $provider->field( $key );
		try {
			$same = true === External\Guard::run( $provider->id, 'same', static fn(): mixed => $field?->same( $current, $expected ) );
		} catch ( External\ProviderFailure $e ) {
			return [ 'skip' => [ self::CANNOT_RESTORE, External\Refusals::error_sentence() ] ];
		}
		return [
			'operation' => [
				'action' => External\RestoreExternal::ID,
				'args'   => [
					'field' => $key,
					'value' => $target,
				],
			],
			'conflict'  => $same ? null : [
				'field'    => $key,
				'expected' => $provider->describe( $object, $key, $expected ),
				'current'  => $provider->describe( $object, $key, $current ),
			],
		];
	}

	/**
	 * The provider's refusal of the person undoing on an object, or null when it allows it.
	 *
	 * @param External\Registered     $provider The provider.
	 * @param int            $user_id  The person undoing.
	 * @param External\ProductSubject $item     The object.
	 */
	private static function authorise( External\Registered $provider, int $user_id, External\ProductSubject $item ): ?string {
		try {
			$answer = External\Guard::run( $provider->id, 'authorise', static fn(): mixed => $provider->provider->authorise( $user_id, $item ) );
		} catch ( External\ProviderFailure $e ) {
			return External\Refusals::error_sentence();
		}
		if ( true === $answer ) {
			return null;
		}
		$reason = $answer instanceof \WP_Error ? External\Text::plain( $answer->get_error_message(), External\Text::REASON ) : '';
		return '' === $reason ? __( 'The add-on does not allow a change to this product, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) : $reason;
	}

	/**
	 * The internal step putting a status and its Off record back (T90).
	 *
	 * @param \BulkCatalogueManager\Fields\Registry $fields  The field registry.
	 * @param array<string, mixed>                   $parts   `value` and/or `record` from the journal.
	 * @param object                                 $subject The object as planned: the step expects to find it so when written.
	 * @return array{action: string, args: array<string, mixed>}
	 */
	private static function restore_step( \BulkCatalogueManager\Fields\Registry $fields, array $parts, object $subject ): array {
		$status = null;
		if ( array_key_exists( 'password', $parts ) ) {
			$parts['expected_password'] = \BulkCatalogueManager\Publishing\PublicationPassword::field()->read( $subject );}
		foreach ( $fields->editable() as $field ) {
			if ( StatusSwitch::governs( $field ) ) {
				$status = $field;
				break;
			}
		}
		return [
			'action' => RestoreStatus::ID,
			'args'   => [ 'field' => null === $status ? '' : $status->key() ] + $parts + ( null === $status ? [] : RestoreStatus::expecting( $subject, $status ) ) + [ 'apply_to_variations' => false ],
		];
	}

	/**
	 * The newer-record guard (T90): the conflict for a product carrying its
	 * own Off record written by a batch later than the one undone, unless the
	 * record is already listed as a conflict; null otherwise.
	 *
	 * @param object                                                      $subject   The object.
	 * @param int                                                         $batch_id  The batch undone.
	 * @param list<array{field: string, expected: mixed, current: mixed}> $conflicts Conflicts found so far.
	 * @return array{field: string, expected: mixed, current: mixed}|null
	 */
	private function newer_record( object $subject, int $batch_id, array $conflicts ): ?array {
		$record = OffRecord::for_product( $subject );
		if ( null === $record || $record->batch <= $batch_id || in_array( OffRecord::FIELD, array_column( $conflicts, 'field' ), true ) ) {
			return null;
		}
		return [
			'field'    => OffRecord::FIELD,
			'expected' => '',
			'current'  => $record->to_json(),
		];
	}

	/**
	 * The operation putting one field back, as submitted to validate_list(),
	 * or null when there is nothing to put back.
	 *
	 * @param Field $field    The field.
	 * @param mixed $target   The value before the batch.
	 * @param mixed $expected The value the batch left.
	 * @return array{action: string, args: array<string, mixed>}|null
	 */
	private static function operation( Field $field, mixed $target, mixed $expected ): ?array {
		$key = $field->key();
		if ( Field::TERMS === $field->type() ) {
			$terms = Values::id_list( $target ) ?? [];
			if ( [] !== $terms ) {
				return [
					'action' => Terms::REPLACE,
					'args'   => [
						'field' => $key,
						'terms' => $terms,
					],
				];
			}
			// The list was empty: remove what the batch left, since replace_terms takes at least one.
			$left = Values::id_list( $expected ) ?? [];
			return [] === $left ? null : [
				'action' => Terms::REMOVE,
				'args'   => [
					'field' => $key,
					'terms' => $left,
				],
			];
		}
		if ( Values::is_empty( $target ) && $field->accepts( Field::CLEAR ) ) {
			return [
				'action' => 'clear',
				'args'   => [ 'field' => $key ],
			];
		}
		return [
			'action' => 'set_value',
			'args'   => [
				'field' => $key,
				// set_value refuses a null argument; an empty string cleans to the field's empty value.
				'value' => $target ?? '',
			],
		];
	}

	/**
	 * Start the hash binding a plan: the batch, the items and the settings a
	 * preview binds (Preview::SETTINGS), in canonical order — the HMAC of
	 * { batch, items, settings } in canonical JSON, fed a part at a time
	 * (T85): a sorted copy of every item and one encoding of them all cost
	 * about 1.7 KB an item. JSON encoding is compositional, so the hash is
	 * the one the whole-payload form gave; each item is added as it is
	 * planned (walk_page()), and hash_end() closes it.
	 *
	 * @param int $batch_id Batch.
	 */
	private function hash_start( int $batch_id ): \HashContext {
		$context = hash_init( 'sha256', HASH_HMAC, wp_salt( 'auth' ) );
		hash_update( $context, self::CONTEXT . '{"batch":' . wp_json_encode( $batch_id ) . ',"items":[' );
		return $context;
	}

	/**
	 * Add one item to the hash of a plan, in canonical order.
	 *
	 * @param \HashContext         $context The hash so far.
	 * @param array<string, mixed> $item    The item.
	 * @param bool                 $first   Whether it is the plan's first item.
	 */
	private static function hash_item( \HashContext $context, array $item, bool $first ): void {
		hash_update( $context, ( $first ? '' : ',' ) . wp_json_encode( self::sorted( $item ) ) );
	}

	/**
	 * Close the hash of a plan with the settings it binds.
	 *
	 * @param \HashContext $context The hash with every item added.
	 */
	private function hash_end( \HashContext $context ): string {
		$bound = array_intersect_key( $this->settings->values(), array_flip( Preview::SETTINGS ) );
		ksort( $bound );
		hash_update( $context, '],"settings":' . wp_json_encode( $bound ) . '}' );
		return hash_final( $context );
	}

	/**
	 * A structure with every map's keys sorted, lists left in order.
	 *
	 * @param mixed $value Value.
	 */
	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( [ self::class, 'sorted' ], $value );
	}

	/**
	 * Load an object for reading, or null when it is not a product.
	 *
	 * @param int $id Object id.
	 * @throws \LogicException A programming error is not swallowed.
	 */
	private static function load( int $id ): ?object {
		try {
			$product = wc_get_product( $id );
		} catch ( \LogicException $e ) {
			throw $e;
		} catch ( \Exception $e ) {
			// WooCommerce's data store throws for a post it cannot read as a product.
			return null;
		}
		return is_object( $product ) ? $product : null;
	}

	/**
	 * Whether a loaded object is a variation.
	 *
	 * @param object $subject Product or variation.
	 */
	private static function is_variation( object $subject ): bool {
		return method_exists( $subject, 'is_type' ) && true === $subject->is_type( 'variation' );
	}

	/**
	 * A skipped item.
	 *
	 * @param int    $object_id Object.
	 * @param string $type      Object type.
	 * @param string $code      One of the item codes.
	 * @param string $reason    Plain sentence.
	 * @return array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}
	 */
	private static function skipped( int $object_id, string $type, string $code, string $reason ): array {
		return [
			'object_id'   => $object_id,
			'object_type' => $type,
			'operations'  => [],
			'state'       => Plan::SKIPPED,
			'code'        => $code,
			'reason'      => $reason,
			'conflicts'   => [],
		];
	}

	/**
	 * The 409 refusal of a batch that cannot be undone.
	 *
	 * @param string               $reason  One of the refusal reasons.
	 * @param string               $message Plain sentence.
	 * @param array<string, mixed> $data    Extra data.
	 */
	private static function not_reversible( string $reason, string $message, array $data = [] ): \WP_Error {
		return Refusal::create( self::NOT_REVERSIBLE, $message, 409, [ 'reason' => $reason ] + $data );
	}

	/**
	 * The sentence for a batch already undone.
	 */
	private static function undone_sentence(): string {
		return __( 'This batch has already been undone. To put its changes back, undo the undo instead.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The sentence for a batch being undone now.
	 */
	public static function undoing_sentence(): string {
		return __( 'This batch is being undone now. Wait for that to finish, then check the history.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The sentence for a batch whose journal was pruned.
	 */
	private static function pruned_sentence(): string {
		return __( 'The record of this batch\'s changes has been cleared by the history clean-up, so it can no longer be undone.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
