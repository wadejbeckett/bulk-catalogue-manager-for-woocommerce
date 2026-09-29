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
			'value'  => [
				'type'    => 'choice',
				'label'   => __( 'Status to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'The status to put back could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'choices' => array_combine( self::STATUSES, self::STATUSES ),
			],
			'record' => [
				'type'        => 'text',
				'label'       => __( 'Status before Off to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'        => __( 'The saved status before Off could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
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
		if ( ! array_key_exists( 'value', $args ) && ! array_key_exists( 'record', $args ) ) {
			return __( 'There is no status to put back, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( array_key_exists( 'record', $args ) && '' !== $args['record'] && null === OffRecord::read( $args['record'] ) ) {
			return __( 'The saved status before Off could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return $args;
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
