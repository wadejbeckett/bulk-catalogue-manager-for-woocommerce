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
 * The public static helpers (current_number(), outcome(),
 * refuse_below_zero(), decimal_of(), below_zero(), to_places() and
 * settle()) are shared with Adjust and CopyPrice.
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
				__( 'Your prices have %d decimals, so the step cannot have more. Enter a step like 0.50 or 5.', 'bulk-catalogue-manager-for-woocommerce' ),
				wc_get_price_decimals()
			);
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
		return self::outcome( $field, $old, $rounded );
	}

	/**
	 * The object's current value as a decimal, or the skipped Result saying
	 * why there is nothing to work on: the field does not apply to this
	 * object, the value is empty (never read as zero, SPEC §6), or it is not
	 * a number.
	 *
	 * @param Field  $field   The field.
	 * @param object $subject A product or variation.
	 * @param mixed  $old     The value Field::read() returned.
	 */
	public static function current_number( Field $field, object $subject, mixed $old ): string|Result {
		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::skipped( $field->key(), $old, $reason, 'bcm_not_available' );
		}
		if ( Values::is_empty( $old ) ) {
			return Result::skipped(
				$field->key(),
				$old,
				sprintf(
					/* translators: %s: a field's name, such as "Regular price". */
					__( '%s is empty, so there was nothing to change.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				)
			);
		}
		$number = Values::decimal( $old );
		if ( null === $number ) {
			return Result::skipped(
				$field->key(),
				$old,
				sprintf(
					/* translators: %s: a field's name, such as "Weight". */
					__( '%s does not hold a number, so it was not changed.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				)
			);
		}
		return $number;
	}

	/**
	 * The Result for a computed value: failed when the field refuses it,
	 * already when it equals the current value, otherwise the change to the
	 * field's canonical form (prices through wc_format_decimal() at the
	 * shop's precision, stock as a whole number).
	 *
	 * @param Field  $field  The field.
	 * @param mixed  $old    The current value.
	 * @param string $number The computed value, zero or more.
	 */
	public static function outcome( Field $field, mixed $old, string $number ): Result {
		$clean = $field->clean( self::settle( $field, $number ) );
		if ( $clean instanceof \WP_Error ) {
			return Result::failed( $field->key(), $old, $clean->get_error_message(), (string) $clean->get_error_code() );
		}
		return self::same( $old, $clean ) ? Result::already( $field->key(), $old ) : Result::changed( $field->key(), $old, $clean );
	}

	/**
	 * The skipped Result for a value that would go below zero: refused,
	 * never clamped (SPEC §5.4).
	 *
	 * @param Field  $field  The field.
	 * @param mixed  $old    The current value.
	 * @param string $number The computed value, below zero.
	 */
	public static function refuse_below_zero( Field $field, mixed $old, string $number ): Result {
		return Result::skipped(
			$field->key(),
			$old,
			sprintf(
				/* translators: 1: a field's name, such as "Regular price", 2: the value it would have had, such as -2.50. */
				__( '%1$s would go below zero (%2$s), so it was not changed.', 'bulk-catalogue-manager-for-woocommerce' ),
				$field->label(),
				'-' . self::settle( $field, ltrim( $number, '-' ) )
			),
			'bcm_invalid_value'
		);
	}

	/**
	 * A computed float as decimal text, cut to nine places so the noise of
	 * binary arithmetic (0.1 + 0.2) never reaches a rounding decision.
	 *
	 * @param float $number The number.
	 */
	public static function decimal_of( float $number ): string {
		$text = sprintf( '%.9F', round( $number, 9 ) );
		return str_starts_with( $text, '-' ) && 0.0 === (float) $text ? substr( $text, 1 ) : $text;
	}

	/**
	 * Whether decimal text is below zero.
	 *
	 * @param string $number Decimal text.
	 */
	public static function below_zero( string $number ): bool {
		return str_starts_with( $number, '-' ) && 0.0 !== (float) $number;
	}

	/**
	 * Round decimal text of zero or more to a number of places, digit by
	 * digit: nearest (half up), up or down.
	 *
	 * @param string $number    Decimal text, zero or more.
	 * @param int    $places    Places to keep, 0 or more.
	 * @param string $direction nearest, up or down.
	 * @throws \InvalidArgumentException When the number is below zero.
	 */
	public static function to_places( string $number, int $places, string $direction ): string {
		$number = ltrim( $number, '+' );
		if ( str_starts_with( $number, '-' ) ) {
			if ( self::below_zero( $number ) ) {
				throw new \InvalidArgumentException( 'Only a number of zero or more is rounded here.' );
			}
			$number = substr( $number, 1 );
		}
		$places   = max( 0, $places );
		$parts    = explode( '.', $number, 2 );
		$whole    = ltrim( $parts[0], '0' );
		$fraction = $parts[1] ?? '';
		$kept     = substr( str_pad( $fraction, $places, '0' ), 0, $places );
		$rest     = (string) substr( $fraction, $places );
		$bump     = match ( $direction ) {
			'up'    => '' !== trim( $rest, '0' ),
			'down'  => false,
			default => '' !== $rest && (int) $rest[0] >= 5,
		};
		$digits = ( '' === $whole ? '0' : $whole ) . $kept;
		if ( $bump ) {
			$digits = self::increment( $digits );
		}
		return 0 === $places ? $digits : substr( $digits, 0, -$places ) . '.' . substr( $digits, -$places );
	}

	/**
	 * A computed value in the precision the field stores: a price at the
	 * shop's decimals, a whole number for an integer field (to the nearest,
	 * half up), any other decimal at WooCommerce's rounding precision.
	 *
	 * @param Field  $field  The field.
	 * @param string $number Decimal text, zero or more.
	 */
	public static function settle( Field $field, string $number ): string|int {
		return match ( $field->type() ) {
			Field::INTEGER => (int) self::to_places( $number, 0, 'nearest' ),
			Field::MONEY   => self::to_places( $number, wc_get_price_decimals(), 'nearest' ),
			default        => self::trim_zeros( self::to_places( $number, wc_get_rounding_precision(), 'nearest' ) ),
		};
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
	 * Whether a computed value equals the current one as a number, so "10"
	 * and "10.00" are already the target.
	 *
	 * @param mixed $old   The current value.
	 * @param mixed $clean The computed value, canonical.
	 */
	private static function same( mixed $old, mixed $clean ): bool {
		if ( Values::is_empty( $old ) || Values::is_empty( $clean ) ) {
			return Values::is_empty( $old ) && Values::is_empty( $clean );
		}
		$a = Values::decimal( $old );
		$b = Values::decimal( $clean );
		return null !== $a && null !== $b && (float) $a === (float) $b;
	}

	/**
	 * Add one to a string of digits.
	 *
	 * @param string $digits Digits.
	 */
	private static function increment( string $digits ): string {
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
		}
		return '1' . $digits;
	}

	/**
	 * Decimal text without trailing zeros after the point.
	 *
	 * @param string $number Decimal text.
	 */
	private static function trim_zeros( string $number ): string {
		return str_contains( $number, '.' ) ? rtrim( rtrim( $number, '0' ), '.' ) : $number;
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
