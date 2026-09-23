<?php
/**
 * Copy between the prices (SPEC §5.4, §6): copy the regular price into the
 * sale price, optionally less a percentage discount in the same step, or
 * copy the sale price back into the regular price. The chosen field is the
 * one written: a field allowing copy from regular takes the regular price,
 * one allowing copy from sale takes the sale price. compute() lands in T26;
 * the sale price rule is T28's, with the explicit choice to clear a sale
 * price that would not be below the regular price.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

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
