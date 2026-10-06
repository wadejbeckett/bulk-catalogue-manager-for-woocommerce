<?php
/**
 * Put a status and its Off record back (SPEC §9.4; T90): the one internal
 * step undo writes for an object whose journal holds `status`, the Off
 * record (`availability_off`) or both.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The internal undo step.
 *
 * Only the undo planner makes it (Undo\Planner, through validate_list()'s
 * internal mode); Operations\Registry keeps it out of `/fields`, the bulk
 * panel and every list a client sends, and resolves it for the writer so a
 * stored undo item runs. Its arguments are `field` (the Status field), and
 * `value` and `record`, each present only when the batch undone applied that
 * prop: the journal's old status and the journal's old record ('' for none).
 *
 * compute() never reads the record for the status it writes: it writes the
 * journal's status through the Status field itself — never through the
 * Off/On switch, so no record is made — and a scheduled status as publish,
 * with the note `restores_scheduled`, for WordPress's date rule to schedule
 * or publish. Without `value` the status is left as it is.
 *
 * follow() handles the record: with `record`, it is written back verbatim
 * (undoing an Off removes the record, undoing an On gives back the original
 * byte for byte); without it — a 1.0 batch or an ordinary status change — a
 * product that now carries its own record loses it when the status put back
 * is not draft, and keeps it (so it stays Off) when it is draft. A variation
 * never carries a record.
 *
 * The planner also gives it what it expects to find when it is written:
 * `expected_status` and `expected_record`, the status and own record the
 * object had when the undo was planned (at preview and again at Apply, so
 * Revert anyway expects the state it chose to overwrite). A queued undo runs
 * later, in the background: when the object no longer holds them — switched
 * Off again by a newer batch, its status changed outside this screen, its
 * record removed — compute() skips it as changed since, with the planner's
 * sentence, and nothing is written. A step stored before these arguments
 * existed has neither and is written as it was planned.
 */
final class RestoreStatus extends BaseOperation {
	/**
	 * The action id.
	 */
	public const ID = 'restore_status';

	/**
	 * The note on a scheduled status written back as publish.
	 */
	public const RESTORES_SCHEDULED = 'restores_scheduled';

	/**
	 * The code of an item left alone because it changed after the undo was planned.
	 */
	public const CHANGED_SINCE = 'bulkcat_changed_since';

	/**
	 * The statuses a journal may hold for the Status field.
	 */
	private const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	/**
	 * The action id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::PUBLISHING;
	}

	/**
	 * The plain label (never shown in the panel; History names undo batches itself).
	 */
	protected function label(): string {
		return __( 'Put the status back', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Puts back the status, and the status saved before Off, that an undone change replaced.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::SET ];
	}

	/**
	 * The Status field only.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return StatusSwitch::governs( $field );
	}

	/**
	 * The status and the record to put back.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'value'             => [
				'type'    => 'choice',
				'label'   => __( 'Status to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'The status to put back could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'choices' => array_combine( self::STATUSES, self::STATUSES ),
			],
			'record'            => [
				'type'        => 'text',
				'label'       => __( 'Status before Off to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The saved status before Off could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'allow_empty' => true,
			],
			'expected_status'   => [
				'type'        => 'text',
				'label'       => __( 'Status when the undo was planned', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The status this product had when the undo was planned could not be read, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'allow_empty' => true,
			],
			'password'          => [
				'type'        => 'text',
				'label'       => __( 'Password to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The recorded password could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'allow_empty' => true,
			],
			'expected_password' => [
				'type'        => 'text',
				'label'       => __( 'Password when the undo was planned', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The recorded password could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'allow_empty' => true,
			],
			'expected_record'   => [
				'type'        => 'text',
				'label'       => __( 'Saved status before Off when the undo was planned', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The saved status before Off this product had when the undo was planned could not be read, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'allow_empty' => true,
			],
		];
	}

	/**
	 * At least one of the two, and a record that is '' or a record.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The Status field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		unset( $field );
		if ( ! array_key_exists( 'value', $args ) && ! array_key_exists( 'record', $args ) && ! array_key_exists( 'password', $args ) ) {
			return __( 'There is no status to put back, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		foreach ( [ 'record', 'expected_record' ] as $key ) {
			if ( array_key_exists( $key, $args ) && '' !== $args[ $key ] && null === OffRecord::read( $args[ $key ] ) ) {
				return __( 'The saved status before Off could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
			}
		}
		return $args;
	}

	/**
	 * What a step planned now expects to find when it is written: the
	 * object's status and its own Off record ('' for none).
	 *
	 * @param object $subject The product or variation, as the planner reads it.
	 * @param Field  $status  The Status field.
	 * @return array{expected_status: string, expected_record: string}
	 */
	public static function expecting( object $subject, Field $status ): array {
		return [
			'expected_status' => (string) $status->read( $subject ),
			'expected_record' => OffRecord::raw( $subject ),
		];
	}

	/**
	 * The journal's status, through the Status field alone; a scheduled one
	 * as publish. Without a status to put back, the status as it is.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field = $this->field( $args );
		if ( self::changed_since( $field, $subject, $args ) ) {
			$variation = method_exists( $subject, 'is_type' ) && true === $subject->is_type( 'variation' );
			return Result::skipped(
				$field->key(),
				$field->read( $subject ),
				$variation
					? __( 'This variation was changed since this batch ran, so it is left alone unless you choose Revert anyway.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'This product was changed since this batch ran, so it is left alone unless you choose Revert anyway.', 'bulk-catalogue-manager-for-woocommerce' ),
				self::CHANGED_SINCE
			);
		}
		if ( ! array_key_exists( 'value', $args ) ) {
			return Result::already( $field->key(), $field->read( $subject ) );
		}
		$target = (string) $args['value'];
		$result = self::value_outcome( $field, $subject, 'future' === $target ? 'publish' : $target );
		if ( 'future' === $target && in_array( $result->state(), [ Result::CHANGED, Result::ALREADY ], true ) ) {
			return $result->with_note( self::RESTORES_SCHEDULED, [ 'restores' => 'future' ] );
		}
		return $result;
	}

	/**
	 * Whether the object no longer holds the status or own record the step
	 * was planned against. A step without those arguments expects nothing.
	 *
	 * @param Field                $field   The Status field.
	 * @param object               $subject The object, as it is now.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	private static function changed_since( Field $field, object $subject, array $args ): bool {
		$status = (string) $field->read( $subject );
		$record = OffRecord::raw( $subject );
		if ( array_key_exists( 'expected_password', $args ) && \BulkCatalogueManager\Publishing\PublicationPassword::field()->read( $subject ) !== $args['expected_password'] ) {
			return true;
		}
		if ( array_key_exists( 'expected_status', $args ) && $status !== (string) $args['expected_status'] ) {
			return true;
		}
		return array_key_exists( 'expected_record', $args ) && $record !== (string) $args['expected_record'];
	}

	/**
	 * The Off record's Result: the journalled record written back verbatim,
	 * or, for a status-only undo, the product's own record cleared unless the
	 * status put back is draft.
	 *
	 * @param object               $subject The product or variation, before anything is applied.
	 * @param Result               $checked The checked status Result.
	 * @param array<string, mixed> $args    Normalised arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		if ( ! in_array( $checked->state(), [ Result::CHANGED,Result::ALREADY ], true ) ) {
			return [];
		}
		$follow = $this->record_follow( $subject, $checked, $args );
		if ( array_key_exists( 'password', $args ) ) {
			$old = \BulkCatalogueManager\Publishing\PublicationPassword::field()->read( $subject );
			if ( $old !== $args['password'] ) {
				$follow[] = Result::changed( \BulkCatalogueManager\Publishing\PublicationPassword::FIELD, $old, $args['password'] );
			}
		} else {
			$follow = array_merge( $follow, \BulkCatalogueManager\Publishing\PublicationPassword::clearing( $subject, $checked ) );
		}
		return $follow;
	}
	/** Legacy record restoration only.
	 * @param object $subject Product.
	 * @param Result $checked Checked status.
	 * @param array $args Stored arguments.
	 * @return list<Result>
	 */
	private function record_follow( object $subject, Result $checked, array $args ): array {
		if ( ! StatusSwitch::applies( $this->field( $args ), $subject ) || ! in_array( $checked->state(), [ Result::CHANGED, Result::ALREADY ], true ) ) {
			return [];
		}
		$own = OffRecord::raw( $subject );
		if ( array_key_exists( 'record', $args ) ) {
			$record = (string) $args['record'];
			return $record === $own ? [] : [ Result::changed( OffRecord::FIELD, $own, $record ) ];
		}
		if ( '' !== $own && array_key_exists( 'value', $args ) && 'draft' !== $args['value'] ) {
			return [ Result::changed( OffRecord::FIELD, $own, '' ) ];
		}
		return [];
	}
}
