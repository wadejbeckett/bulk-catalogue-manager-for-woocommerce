<?php
/**
 * Encodes the journal's old_value and new_value (SPEC §9.2, §5.4). Empty is a
 * real value distinct from zero, so the stored form keeps the PHP type: null,
 * '', 0, '0', '0.00', 0.0 and false each decode as exactly what was encoded.
 * JSON is used rather than serialize() so a stored row is plain data that can
 * never instantiate an object when read back.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;

/**
 * JSON encoding that preserves type and null.
 */
final class ValueCodec {
	/**
	 * Flags for encoding. PRESERVE_ZERO_FRACTION keeps 1.0 a float.
	 */
	private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	/**
	 * Encode a field value: null, a scalar, or an array of those (a list of
	 * term ids, for instance).
	 *
	 * @param mixed $value Value.
	 * @throws \InvalidArgumentException When the value holds an object or resource, or cannot be encoded (a non-finite float).
	 */
	public static function encode( mixed $value ): string {
		self::assert_plain( $value );
		$json = wp_json_encode( $value, self::FLAGS );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'The value cannot be stored as JSON.' );
		}
		return $json;
	}

	/**
	 * Decode a stored value.
	 *
	 * @param string $json Stored JSON.
	 * @throws \UnexpectedValueException When the stored text is not valid JSON.
	 */
	public static function decode( string $json ): mixed {
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			// $e is the chained cause, not output.
			throw new \UnexpectedValueException( 'A stored journal value is not valid JSON.', 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal with nothing interpolated, so there is nothing to escape.
		}
	}

	/**
	 * Refuse anything that would not decode as itself.
	 *
	 * @param mixed $value Value.
	 * @throws \InvalidArgumentException When the value holds an object or resource.
	 */
	private static function assert_plain( mixed $value ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::assert_plain( $item );
			}
			return;
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new \InvalidArgumentException( 'Only null, scalars and arrays of them can be journalled.' );
		}
	}
}
