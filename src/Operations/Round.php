<?php
/**
 * Round (SPEC §6): round each price to a number of decimals, to the nearest
 * step, or to a charm ending such as .95 or .99, up, down or to the nearest.
 * Only the setting the chosen way uses is kept.
 *
 * Numbers are rounded as decimal text, digit by digit, so a price never
 * lands a cent off through binary floating point. The result is stored at
 * the shop's precision through the field's clean(), which calls
 * wc_format_decimal() with wc_get_price_decimals(). An ending rounded down
 * below zero is refused and the row skipped, never clamped (SPEC §5.4).
 *
 * The numeric helpers it shares with Adjust and CopyPrice (current_number(),
 * number_outcome(), refuse_below_zero(), decimal_of(), below_zero(),
 * to_places() and settle()) are protected on BaseOperation.
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
	 * A price step or an ending cannot have more decimals than the shop's prices.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
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
		if ( 'step' === $args['to'] && Field::MONEY === $field->type() && self::places_of( $args['step'] ) > wc_get_price_decimals() ) {
			return sprintf(
				/* translators: %d: the number of decimals the shop's prices have. */
				_n( 'Your prices have %d decimal, so the step cannot have more. Enter a step like 0.50 or 5.', 'Your prices have %d decimals, so the step cannot have more. Enter a step like 0.50 or 5.', wc_get_price_decimals(), 'bulk-catalogue-manager-for-woocommerce' ),
				wc_get_price_decimals()
			);
		}
		if ( 'ending' === $args['to'] ) {
			$point    = strpos( $args['ending'], '.' );
			$fraction = false === $point ? '' : rtrim( substr( $args['ending'], $point + 1 ), '0' );
			if ( strlen( $fraction ) > wc_get_price_decimals() ) {
				return sprintf(
					/* translators: %d: the number of decimals the shop's prices have. */
					_n( 'Your prices have %d decimal, so the ending cannot have more. Enter an ending like .95.', 'Your prices have %d decimals, so the ending cannot have more. Enter an ending like .95.', wc_get_price_decimals(), 'bulk-catalogue-manager-for-woocommerce' ),
					wc_get_price_decimals()
				);
			}
			$args['ending'] = '' === $fraction ? '0' : '0.' . $fraction;
		}
		return $args;
	}

	/**
	 * Round one object's current value. Writes nothing.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field  = $this->field( $args );
		$old    = $field->read( $subject );
		$number = self::current_number( $field, $subject, $old );
		if ( $number instanceof Result ) {
			return $number;
		}
		if ( self::below_zero( $number ) ) {
			return self::refuse_below_zero( $field, $old, $number );
		}
		$way     = (string) ( $args['direction'] ?? 'nearest' );
		$rounded = match ( $args['to'] ) {
			'decimals' => self::to_places( $number, (int) $args['decimals'], $way ),
			'step'     => self::to_step( $number, (string) $args['step'], $way ),
			default    => self::to_ending( $number, (string) $args['ending'], $way ),
		};
		if ( self::below_zero( $rounded ) ) {
			return self::refuse_below_zero( $field, $old, $rounded );
		}
		return self::number_outcome( $field, $old, $rounded );
	}








	/**
	 * Round to the nearest multiple of a step.
	 *
	 * @param string $number    Decimal text, zero or more.
	 * @param string $step      Step above zero.
	 * @param string $direction nearest, up or down.
	 */
	private static function to_step( string $number, string $step, string $direction ): string {
		$count = self::to_places( self::decimal_of( (float) $number / (float) $step ), 0, $direction );
		return self::decimal_of( (float) $count * (float) $step );
	}

	/**
	 * Round to a charm ending: the closest value at or below (down), at or
	 * above (up), or nearer of the two (nearest; a tie goes up, and so does a
	 * value whose ending below would be under zero).
	 *
	 * @param string $number    Decimal text, zero or more.
	 * @param string $ending    The ending, from 0 to below 1, such as 0.95.
	 * @param string $direction nearest, up or down.
	 * @return string Decimal text; below zero when down has no ending at or above zero.
	 */
	private static function to_ending( string $number, string $ending, string $direction ): string {
		$value = round( (float) $number, 9 );
		$down  = floor( $value ) + (float) $ending;
		if ( round( $down, 9 ) > $value ) {
			--$down;
		}
		$up = round( $down, 9 ) < $value ? $down + 1 : $down;
		if ( 'down' === $direction ) {
			return self::decimal_of( $down );
		}
		if ( 'up' === $direction || round( $down, 9 ) < 0 ) {
			return self::decimal_of( $up );
		}
		return self::decimal_of( round( $up - $value, 9 ) <= round( $value - $down, 9 ) ? $up : $down );
	}




	/**
	 * How many places decimal text has after the point, ignoring trailing zeros.
	 *
	 * @param string $number Decimal text.
	 */
	private static function places_of( string $number ): int {
		$point = strpos( $number, '.' );
		return false === $point ? 0 : strlen( rtrim( substr( $number, $point + 1 ), '0' ) );
	}
}
