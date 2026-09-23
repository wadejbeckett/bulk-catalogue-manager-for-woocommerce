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
 * through outcome(). The cross-field and uniqueness checks are T28's.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

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
			'value'              => [
				'type'     => 'value',
				'label'    => __( 'New value', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Enter the new value, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
			],
			'clear_invalid_sale' => [
				'type'    => 'boolean',
				'label'   => __( 'Clear the sale price where it is not below the regular price', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Clear the sale price where it is not below the regular price has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
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
		return $args;
	}

	/**
	 * The object's value would become the chosen value.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		return self::outcome( $this->field( $args ), $subject, $args['value'] ?? null );
	}

	/**
	 * What writing a target value to one object would do, without writing:
	 * failed when the field does not apply to the object or refuses the
	 * value (the field's sentence and code), already when the object holds
	 * it, otherwise a change from the value read to the canonical target.
	 *
	 * Prices and decimals compare in canonical form, so 10 and 10.00 are
	 * the same price; every other type compares exactly as read.
	 *
	 * @param Field  $field   The field written.
	 * @param object $subject A product or variation.
	 * @param mixed  $target  The value to write, before cleaning.
	 */
	public static function outcome( Field $field, object $subject, mixed $target ): Result {
		$old    = $field->read( $subject );
		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::failed( $field->key(), $old, $reason, 'bcm_not_available' );
		}
		$clean = $field->clean( $target );
		if ( $clean instanceof \WP_Error ) {
			return Result::failed( $field->key(), $old, $clean->get_error_message(), (string) $clean->get_error_code() );
		}
		$current = $old;
		if ( Field::MONEY === $field->type() || Field::DECIMAL === $field->type() ) {
			$canonical = $field->clean( $old );
			$current   = $canonical instanceof \WP_Error ? $old : $canonical;
		}
		return $current === $clean ? Result::already( $field->key(), $old ) : Result::changed( $field->key(), $old, $clean );
	}
}
