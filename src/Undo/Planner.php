<?php
/**
 * The undo planner (SPEC §9.4, §9.5, §12.6; T55): turns a finished batch
 * into an Undo\Plan without writing anything.
 *
 * It refuses, 409 `bcm_not_reversible` with the reason code in
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
 *   no longer the same kind, where the batch's recorded user may no longer
 *   edit it, or where a value can no longer be written (a deleted term, a
 *   field no longer in the registry);
 * - conflicted where a field's current value, read through Field::read(),
 *   differs from what the batch wrote — `conflicts` lists each such field
 *   with the value expected and the value found, and the operations still
 *   cover every field, for **Revert anyway**;
 * - revertible otherwise.
 *
 * Values compare in the field type's canonical form, never loosely: a price
 * through wc_format_decimal() at the shop's precision (10 and 10.00 are the
 * same price, '' and 0.00 are not), a whole number with null distinct from
 * 0, a switch as a boolean, a term list as a sorted id list, text exactly.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Terms;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plans the undo of one batch.
 */
final class Planner {
	/**
	 * Refusal code for a batch that cannot be undone; the reason is in `data.reason`.
	 */
	public const NOT_REVERSIBLE = 'bcm_not_reversible';

	/*
	 * Refusal reasons.
	 */
	public const IN_PROGRESS      = 'in_progress';
	public const ALREADY_UNDONE   = 'already_undone';
	public const UNDO_IN_PROGRESS = 'undo_in_progress';
	public const RETENTION        = 'retention';
	public const PRUNED           = 'pruned';

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
	 * Journal rows read at a time.
	 */
	private const PAGE = ChangeRepository::MAX_PAGE;

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
	 * Plan the undo of a batch, writing nothing.
	 *
	 * @param int $batch_id The batch to undo.
	 * @return Plan|\WP_Error The plan, or 404 `bcm_not_found`, or 409 `bcm_not_reversible` with its reason.
	 */
	public function plan( int $batch_id ): Plan|\WP_Error {
		$batch = $batch_id > 0 ? $this->batches->find( $batch_id ) : null;
		if ( null === $batch ) {
			return Refusal::create( Writer::NOT_FOUND, __( 'That batch could not be found. It may have been removed. Reload the history and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 404 );
		}
		$refusal = $this->refusal( $batch );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$objects = $this->journal( $batch_id );
		if ( [] === $objects && $batch['total_items'] > 0 && $batch['done_items'] > 0 ) {
			return self::not_reversible( self::PRUNED, self::pruned_sentence() );
		}

		$items = [];
		foreach ( $objects as $object_id => $journal ) {
			$items[] = $this->item( $object_id, $journal, $batch['user_id'] );
		}
		return new Plan( $batch_id, $items, $this->hash( $batch_id, $items ) );
	}

	/**
	 * How many of a batch's objects were changed again since it ran (the
	 * plan's conflicted items), or 0 where it cannot be planned. This is the
	 * count Engine\Reversibility shows as `conflicts` and turns into
	 * `partially_reversible`.
	 *
	 * @param array<string, mixed> $batch Typed batch row; only its id is read.
	 */
	public function conflicts( array $batch ): int {
		$plan = $this->plan( (int) $batch['batch_id'] );
		return $plan instanceof Plan ? $plan->counts()[ Plan::CONFLICTED ] : 0;
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
			if ( in_array( $undo['status'], self::UNDO_DONE, true ) ) {
				return self::not_reversible( self::ALREADY_UNDONE, self::undone_sentence(), [ 'undo_batch_id' => $undo['batch_id'] ] );
			}
			if ( in_array( $undo['status'], self::UNDO_RUNNING, true ) ) {
				return self::not_reversible( self::UNDO_IN_PROGRESS, self::undoing_sentence(), [ 'undo_batch_id' => $undo['batch_id'] ] );
			}
		}
		if ( null !== ( $batch['pruned_gmt'] ?? null ) ) {
			return self::not_reversible( self::PRUNED, self::pruned_sentence() );
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
	 * The batch's journal grouped by object, the object journalled last
	 * first. Per object: its type, whether it has any row, and per applied
	 * field (newest first) the value the batch left and the value before it.
	 *
	 * @param int $batch_id Batch.
	 * @return array<int, array{type: string, fields: array<string, array{expected: mixed, target: mixed}>}>
	 */
	private function journal( int $batch_id ): array {
		$objects = [];
		$before  = 0;
		do {
			$rows = $this->changes->newest_first( $batch_id, $before, self::PAGE );
			$full = self::PAGE === count( $rows );
			foreach ( $rows as $row ) {
				$before           = $row['change_id'];
				$id               = $row['object_id'];
				$objects[ $id ] ??= [
					'type'   => $row['object_type'],
					'fields' => [],
				];
				if ( 'applied' !== $row['result'] ) {
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
		} while ( $full );
		return $objects;
	}

	/**
	 * Plan one object.
	 *
	 * @param int                                                                                   $object_id Object.
	 * @param array{type: string, fields: array<string, array{expected: mixed, target: mixed}>} $journal   Its journal.
	 * @param int                                                                                   $user_id   The batch's recorded user.
	 * @return array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}
	 */
	private function item( int $object_id, array $journal, int $user_id ): array {
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

		$subject = self::load( $object_id );
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
		if ( true !== Access::user_can_edit_object( $user_id, $object_id ) ) {
			return self::skipped( $object_id, $type, self::CANNOT_EDIT, __( 'The person who ran this batch can no longer edit this product, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}

		$fields     = $this->actions->fields();
		$operations = [];
		$conflicts  = [];
		foreach ( $journal['fields'] as $key => $values ) {
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
			$operation = self::operation( $field, $values['target'], $values['expected'] );
			if ( null !== $operation ) {
				$operations[] = $operation;
			}
		}

		$normalised = [] === $operations ? [] : $this->actions->validate_list( $operations );
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
	 * The hash binding a plan: the batch, the items and the settings a
	 * preview binds (Preview::SETTINGS), in canonical order.
	 *
	 * @param int                        $batch_id Batch.
	 * @param list<array<string, mixed>> $items    Items.
	 */
	private function hash( int $batch_id, array $items ): string {
		$bound = array_intersect_key( $this->settings->values(), array_flip( Preview::SETTINGS ) );
		ksort( $bound );
		$payload = [
			'batch'    => $batch_id,
			'items'    => self::sorted( $items ),
			'settings' => $bound,
		];
		return hash_hmac( 'sha256', self::CONTEXT . (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
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
	private static function undoing_sentence(): string {
		return __( 'This batch is being undone now. Wait for that to finish, then check the history.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The sentence for a batch whose journal was pruned.
	 */
	private static function pruned_sentence(): string {
		return __( 'The record of this batch\'s changes has been cleared by the history clean-up, so it can no longer be undone.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
