<?php
/**
 * Increase or decrease (SPEC §5.4, §6): raise or lower each object's own
 * current value — a price, stock quantity, weight or any field allowing an
 * adjustment — by a fixed amount or a percentage. compute() lands in T26:
 * an object with no current value is skipped and counted, and a result
 * below zero is refused, never clamped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The increase or decrease action.
 */
final class Adjust extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'adjust';
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
		return __( 'Increase or decrease', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Takes each product\'s current value and raises or lowers it by an amount or a percentage. Products with no current value are skipped and counted.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::ADJUST ];
	}

	/**
	 * Which way, by what, and how much.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'direction' => [
				'type'     => 'choice',
				'label'    => __( 'Increase or decrease', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Choose whether to increase or decrease, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
				'choices'  => [
					'increase' => __( 'Increase', 'bulk-catalogue-manager-for-woocommerce' ),
					'decrease' => __( 'Decrease', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			],
			'by'        => [
				'type'     => 'choice',
				'label'    => __( 'By', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Choose an amount or a percentage, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
				'choices'  => [
					'amount'  => __( 'An amount', 'bulk-catalogue-manager-for-woocommerce' ),
					'percent' => __( 'A percentage', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			],
			'amount'    => [
				'type'          => 'decimal',
				'label'         => __( 'How much', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'          => __( 'Enter how much to change it by, as a number above 0 like 10 or 2.5.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required'      => true,
				'min'           => 0,
				'exclusive_min' => true,
			],
		];
	}

	/**
	 * A decrease cannot take more than everything, and a whole-number field
	 * moves by whole numbers.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		if ( 'percent' === $args['by'] && 'decrease' === $args['direction'] && (float) $args['amount'] > 100 ) {
			return __( 'A decrease cannot be more than 100 per cent. Enter a smaller percentage and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( 'amount' === $args['by'] && Field::INTEGER === $field->type() && ! ctype_digit( $args['amount'] ) ) {
			return sprintf(
				/* translators: %s: a field's name, such as "Stock quantity". */
				__( '%s changes by whole numbers. Enter a number like 5.', 'bulk-catalogue-manager-for-woocommerce' ),
				$field->label()
			);
		}
		return $args;
	}

	/**
	 * Implemented in T26.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		unset( $subject, $args );
		$this->not_yet( 'T26' );
	}
}
