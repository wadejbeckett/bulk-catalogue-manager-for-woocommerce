<?php
/**
 * Round (SPEC §6): round each price to a number of decimals, to the nearest
 * step, or to a charm ending such as .95 or .99, up, down or to the nearest.
 * Only the setting the chosen way uses is kept. compute() lands in T26,
 * storing through wc_format_decimal() at the shop's precision.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The round action.
 */
final class Round extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'round';
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
		return __( 'Round prices', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Rounds each price to a number of decimals, to the nearest step, or to a tidy ending such as .95 or .99, going up, down or to the nearest.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::ROUND ];
	}

	/**
	 * How to round, which way, and the setting for that way.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		$decimals = wc_get_price_decimals();
		return [
			'to'        => [
				'type'     => 'choice',
				'label'    => __( 'Round to', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Choose how to round, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
				'choices'  => [
					'decimals' => __( 'A number of decimals', 'bulk-catalogue-manager-for-woocommerce' ),
					'step'     => __( 'The nearest step, such as 5 or 0.50', 'bulk-catalogue-manager-for-woocommerce' ),
					'ending'   => __( 'An ending, such as .95 or .99', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			],
			'direction' => [
				'type'    => 'choice',
				'label'   => __( 'Which way', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Choose up, down or nearest, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'choices' => [
					'nearest' => __( 'To the nearest', 'bulk-catalogue-manager-for-woocommerce' ),
					'up'      => __( 'Up', 'bulk-catalogue-manager-for-woocommerce' ),
					'down'    => __( 'Down', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				'default' => 'nearest',
			],
			'decimals'  => [
				'type'  => 'integer',
				'label' => __( 'Decimals', 'bulk-catalogue-manager-for-woocommerce' ),
				/* translators: %d: the number of decimals the shop's prices have. */
				'rule'  => sprintf( __( 'Enter a whole number of decimals from 0 to %d.', 'bulk-catalogue-manager-for-woocommerce' ), $decimals ),
				'min'   => 0,
				'max'   => $decimals,
			],
			'step'      => [
				'type'          => 'decimal',
				'label'         => __( 'Step', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'          => __( 'Enter the step to round to, as a number above 0 like 5 or 0.50.', 'bulk-catalogue-manager-for-woocommerce' ),
				'min'           => 0,
				'exclusive_min' => true,
			],
			'ending'    => [
				'type'          => 'decimal',
				'label'         => __( 'Ending', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'          => __( 'Enter the ending as the part after the decimal point, like .95 or .99.', 'bulk-catalogue-manager-for-woocommerce' ),
				'min'           => 0,
				'max'           => 1,
				'exclusive_max' => true,
			],
		];
	}

	/**
	 * The chosen way needs its setting; the other two settings are dropped.
	 * An ending cannot have more decimals than the shop's prices.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		unset( $field );
		$ways = [
			'decimals' => __( 'Enter how many decimals to round to, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			'step'     => __( 'Enter the step to round to, as a number above 0 like 5 or 0.50.', 'bulk-catalogue-manager-for-woocommerce' ),
			'ending'   => __( 'Enter the ending as the part after the decimal point, like .95 or .99.', 'bulk-catalogue-manager-for-woocommerce' ),
		];
		if ( ! array_key_exists( $args['to'], $args ) ) {
			return $ways[ $args['to'] ];
		}
		foreach ( array_keys( $ways ) as $way ) {
			if ( $way !== $args['to'] ) {
				unset( $args[ $way ] );
			}
		}
		if ( 'ending' === $args['to'] ) {
			$point    = strpos( $args['ending'], '.' );
			$fraction = false === $point ? '' : rtrim( substr( $args['ending'], $point + 1 ), '0' );
			if ( strlen( $fraction ) > wc_get_price_decimals() ) {
				return sprintf(
					/* translators: %d: the number of decimals the shop's prices have. */
					__( 'Your prices have %d decimals, so the ending cannot have more. Enter an ending like .95.', 'bulk-catalogue-manager-for-woocommerce' ),
					wc_get_price_decimals()
				);
			}
			$args['ending'] = '' === $fraction ? '0' : '0.' . $fraction;
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
