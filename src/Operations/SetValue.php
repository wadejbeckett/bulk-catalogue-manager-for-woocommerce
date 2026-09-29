<?php
/**
 * Set value (SPEC §6): give every chosen object the same value for one
 * field. Accepts every field allowing set except the term lists, whose
 * whole-list set is replace_terms (T27). The value is checked and put in
 * canonical form by the field's own validator and sanitiser, so what is
 * validated here is exactly what the field would write.
 *
 * compute() reads the current value through the field, refuses an object
 * the field does not apply to (a parent-only field on a variation, a price
 * on a variable parent, stock quantity where stock is not managed) as
 * failed with the field's own sentence, runs the value through the field
 * again under the current user — so a name written without unfiltered_html
 * is filtered here, and the preview shows what will be stored — and answers
 * already when the object holds that value. The other actions that end in
 * "write this value" (clear, find and replace, prefix or suffix) share this
 * through BaseOperation::value_outcome(). The row validator (T28,
 * Validator) adds the cross-field, uniqueness and permission checks.
 *
 * On a product's Status (T89, SPEC §5.5) the value goes through
 * Availability\StatusSwitch: Draft switches the product Off and saves the
 * status it had, Published switches it On and restores exactly that status,
 * and follow() returns the Off record's Result to write with it.
 * `keep_changed_status` (Keep current status) is taken only for Status.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The set value action.
 */
final class SetValue extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'set_value';
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::PUBLISHING;
	}

	/**
	 * The plain label.
	 */
	protected function label(): string {
		return __( 'Set a value', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Gives every chosen product the same value for one field, replacing what is there now.', 'bulk-catalogue-manager-for-woocommerce' );
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
	 * Every field allowing set, except the term lists.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return $field->accepts( Field::SET ) && Field::TERMS !== $field->type();
	}

	/**
	 * The value, and for a price the explicit choice of T28.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'value'               => [
				'type'     => 'value',
				'label'    => __( 'New value', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Enter the new value, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
			],
			'clear_invalid_sale'  => [
				'type'    => 'boolean',
				'label'   => __( 'Clear the sale price where it is not below the regular price', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Clear the sale price where it is not below the regular price has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
				'default' => false,
			],
			'keep_changed_status' => [
				'type'    => 'boolean',
				'label'   => __( 'For products changed by someone else while Off: keep their current status and clear the Off record', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Keep current status for products changed by someone else while Off has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
				'default' => false,
			],
		];
	}

	/**
	 * The value through the field's validator and sanitiser; the sale price
	 * choice kept only for a price.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		$clean = $field->clean( $args['value'] );
		if ( $clean instanceof \WP_Error ) {
			return $clean->get_error_message();
		}
		$args['value'] = $clean;
		if ( Field::MONEY !== $field->type() ) {
			unset( $args['clear_invalid_sale'] );
		}
		if ( ! StatusSwitch::governs( $field ) ) {
			if ( true === ( $args['keep_changed_status'] ?? false ) ) {
				return __( 'Keep current status only applies when changing Status. Turn it off and try again.', 'bulk-catalogue-manager-for-woocommerce' );
			}
			unset( $args['keep_changed_status'] );
		}
		return $args;
	}

	/**
	 * The object's value would become the chosen value.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field = $this->field( $args );
		$plain = self::value_outcome( $field, $subject, $args['value'] ?? null );
		if ( ! StatusSwitch::applies( $field, $subject ) ) {
			return $plain;
		}
		$keep = (bool) ( $args['keep_changed_status'] ?? false );
		return StatusSwitch::decide( $subject, $plain, $keep )['status'];
	}

	/**
	 * The Off record's Result for a Status step on a product (T89), decided
	 * as compute() decided the status; nothing for any other step, or for a
	 * step the row validator did not leave changed or already.
	 *
	 * @param object               $subject The product or variation, as compute() saw it.
	 * @param Result               $checked The checked Result.
	 * @param array<string, mixed> $args    Normalised arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		$field = $this->field( $args );
		if ( ! StatusSwitch::applies( $field, $subject ) || ! in_array( $checked->state(), [ Result::CHANGED, Result::ALREADY ], true ) ) {
			return [];
		}
		$keep   = (bool) ( $args['keep_changed_status'] ?? false );
		$record = StatusSwitch::decide( $subject, self::value_outcome( $field, $subject, $args['value'] ?? null ), $keep )['record'];
		return null === $record ? [] : [ $record ];
	}
}
