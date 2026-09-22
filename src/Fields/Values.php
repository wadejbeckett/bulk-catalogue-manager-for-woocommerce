<?php
/**
 * Plain value parsing shared by every field type (SPEC §5.4). Each parser
 * returns the value in its canonical form, or null when the input is not a
 * value of that kind; nothing is coerced into range or clamped. Empty (null or
 * a blank string) is its own value, distinct from zero.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Parsers for decimals, whole numbers, switches and id lists.
 */
final class Values {
	/**
	 * Most terms one term field may hold, and one term operation may name (SPEC §12.1).
	 */
	public const MAX_TERMS = 100;

	/**
	 * Whether a value is empty: null, or a string holding only whitespace.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_empty( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/**
	 * A decimal as a plain string with a full stop, such as "12.5" or "-3".
	 * Accepts integers, finite floats and strings using a full stop or the
	 * shop's decimal separator. Refuses thousands separators, exponents,
	 * currency signs and anything else, because guessing is how a price of
	 * 1,234 becomes 1.234.
	 *
	 * @param mixed $value Value.
	 */
	public static function decimal( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$value = rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
			return '-0' === $value ? '0' : $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value     = trim( $value );
		$separator = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		if ( '' !== $separator && '.' !== $separator && ! str_contains( $value, '.' ) ) {
			$value = str_replace( $separator, '.', $value );
		}
		if ( 1 !== preg_match( '/^-?(?:\d+(?:\.\d*)?|\.\d+)$/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * A whole number from an integer, a whole float or a string holding one.
	 *
	 * @param mixed $value Value.
	 */
	public static function integer( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) && floor( $value ) === $value && abs( $value ) < PHP_INT_MAX ? (int) $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
			$int = filter_var( trim( $value ), FILTER_VALIDATE_INT );
			return false === $int ? null : $int;
		}
		return null;
	}

	/**
	 * A switch from a boolean, 0 or 1, or the strings WordPress and
	 * WooCommerce use for them.
	 *
	 * @param mixed $value Value.
	 */
	public static function boolean( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( in_array( $value, [ 0, '0', 'false', 'no', 'off' ], true ) ) {
			return false;
		}
		if ( in_array( $value, [ 1, '1', 'true', 'yes', 'on' ], true ) ) {
			return true;
		}
		return null;
	}

	/**
	 * A list of positive ids, each once, in the order given. Accepts an array
	 * or a comma-separated string, as REST query strings send them.
	 *
	 * @param mixed $value Value.
	 * @return int[]|null
	 */
	public static function id_list( mixed $value ): ?array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? [] : explode( ',', $value );
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$ids = [];
		foreach ( $value as $item ) {
			$id = self::integer( $item );
			if ( null === $id || $id < 1 ) {
				return null;
			}
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}
}
