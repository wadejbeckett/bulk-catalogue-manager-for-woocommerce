<?php
/**
 * Copy between the prices (SPEC §5.4, §6): copy the regular price into the
 * sale price, optionally less a percentage discount in the same step, or
 * copy the sale price back into the regular price. The chosen field is the
 * one written: a field allowing copy from regular takes the regular price,
 * one allowing copy from sale takes the sale price. The source is found by
 * edit kind too: the field allowing the opposite copy. An empty source is
 * skipped and counted, never read as zero. The copy is stored at the shop's
 * precision through wc_format_decimal() (half up).
 *
 * Copying into the sale price applies the §5.4 rule in the same step: a sale
 * price not below the regular price is skipped and reported, or, with
 * `clear_invalid_sale` on, the sale price is cleared (an empty string, as
 * WooCommerce expects). Clearing the sale price on its own is the Clear
 * action. Copying the sale price into the regular price leaves the sale
 * price as it is; follow it with Clear to end a sale.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * The copy price action.
 */
final class CopyPrice extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'copy_price';
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::PRICING;
	}

	/**
	 * The plain label.
	 */
	protected function label(): string {
		return __( 'Copy between regular and sale price', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Copies the regular price into the sale price, optionally less a percentage discount, or copies the sale price back into the regular price.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::COPY_FROM_REGULAR, Field::COPY_FROM_SALE ];
	}

	/**
	 * The discount and T28's explicit choice.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'discount'           => [
				'type'          => 'decimal',
				'label'         => __( 'Discount (per cent)', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'          => __( 'Enter a discount of 0 or more and below 100 per cent, like 20.', 'bulk-catalogue-manager-for-woocommerce' ),
				'min'           => 0,
				'max'           => 100,
				'exclusive_max' => true,
				'default'       => '0',
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
	 * A discount only applies when copying the regular price into the sale price.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		if ( 0.0 !== (float) $args['discount'] && ! $field->accepts( Field::COPY_FROM_REGULAR ) ) {
			return __( 'A discount only applies when copying the regular price into the sale price. Set the discount to 0 and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return $args;
	}

	/**
	 * Copy one object's other price into the chosen field. Writes nothing.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$target = $this->field( $args );
		$source = $this->source( $target );
		$old    = $target->read( $subject );
		$reason = $target->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::skipped( $target->key(), $old, $reason, 'bcm_not_available' );
		}

		$from = $source->read( $subject );
		if ( Values::is_empty( $from ) ) {
			return Result::skipped(
				$target->key(),
				$old,
				sprintf(
					/* translators: 1: the price copied from, such as "Regular price", 2: the price copied into, such as "Sale price". */
					__( '%1$s is empty, so there was nothing to copy into %2$s.', 'bulk-catalogue-manager-for-woocommerce' ),
					$source->label(),
					$target->label()
				)
			);
		}
		$number = Values::decimal( $from );
		if ( null === $number || Round::below_zero( $number ) ) {
			return Result::skipped(
				$target->key(),
				$old,
				sprintf(
					/* translators: 1: the price copied from, such as "Regular price", 2: the price copied into, such as "Sale price". */
					__( '%1$s is not a price of 0 or more, so it was not copied into %2$s.', 'bulk-catalogue-manager-for-woocommerce' ),
					$source->label(),
					$target->label()
				)
			);
		}

		$copy = Round::decimal_of( (float) $number * ( 100 - (float) ( $args['discount'] ?? '0' ) ) / 100 );
		if ( $target->accepts( Field::COPY_FROM_REGULAR ) && (float) Round::settle( $target, $copy ) >= (float) $number ) {
			if ( ! empty( $args['clear_invalid_sale'] ) ) {
				return Values::is_empty( $old ) ? Result::already( $target->key(), $old ) : Result::changed( $target->key(), $old, '' );
			}
			return Result::skipped(
				$target->key(),
				$old,
				sprintf(
					/* translators: 1: the sale price field's name, 2: the regular price field's name. */
					__( '%1$s would not be below %2$s, so it was not changed. Enter a discount, or choose to clear the sale price where it is not below the regular price.', 'bulk-catalogue-manager-for-woocommerce' ),
					$target->label(),
					$source->label()
				),
				'bcm_invalid_value'
			);
		}
		return Round::outcome( $target, $old, $copy );
	}

	/**
	 * The field copied from: the other editable field taking part in a copy,
	 * the one allowing the opposite copy kind (the regular price allows copy
	 * from sale, so it is the source of a copy into the sale price).
	 *
	 * @param Field $target The field written.
	 * @throws \LogicException When the registry has no such field.
	 */
	private function source( Field $target ): Field {
		$kind = $target->accepts( Field::COPY_FROM_REGULAR ) ? Field::COPY_FROM_SALE : Field::COPY_FROM_REGULAR;
		foreach ( $this->fields->editable() as $field ) {
			if ( $field->key() !== $target->key() && $field->accepts( $kind ) ) {
				return $field;
			}
		}
		throw new \LogicException( esc_html( sprintf( 'No field allows "%s" to copy into "%s".', $kind, $target->key() ) ) );
	}
}
