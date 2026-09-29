<?php
/**
 * External values in the journal (docs/EXTENSIONS.md §2, §8.1): a value is
 * JSON-plain (null, a scalar, or arrays of those) and at most 64 KB encoded;
 * the journal stores it wrapped with its format and a plain-text rendering,
 * `{ "external": 1, "format": 1, "value": …, "text": "…" }`. REST responses
 * and exports carry the text, never the value.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Data\ValueCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Value rules and the envelope.
 */
final class Envelope {
	/**
	 * Most bytes one encoded value may take.
	 */
	public const LIMIT = 65536;

	/**
	 * Whether a value is JSON-plain: null, a finite scalar, or arrays of those.
	 *
	 * @param mixed $value The value.
	 */
	public static function plain( mixed $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::plain( $item ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		return null === $value || is_scalar( $value );
	}

	/**
	 * Whether a plain value fits the size limit once encoded.
	 *
	 * @param mixed $value A plain value.
	 */
	public static function fits( mixed $value ): bool {
		try {
			return strlen( ValueCodec::encode( $value ) ) <= self::LIMIT;
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
	}

	/**
	 * A value wrapped for the journal.
	 *
	 * @param mixed  $value  The provider's canonical value.
	 * @param int    $format Its format.
	 * @param string $text   Its plain rendering.
	 * @return array{external: int, format: int, value: mixed, text: string}
	 */
	public static function wrap( mixed $value, int $format, string $text ): array {
		return [
			'external' => 1,
			'format'   => $format,
			'value'    => $value,
			'text'     => Text::plain( $text, Text::VALUE ),
		];
	}

	/**
	 * Whether a journalled value is an envelope.
	 *
	 * @param mixed $stored A journal value.
	 */
	public static function is( mixed $stored ): bool {
		return is_array( $stored ) && 1 === ( $stored['external'] ?? null ) && is_int( $stored['format'] ?? null ) && array_key_exists( 'value', $stored ) && is_string( $stored['text'] ?? null );
	}

	/**
	 * The text of a journalled external value, or '' when it is not an envelope.
	 *
	 * @param mixed $stored A journal value.
	 */
	public static function text( mixed $stored ): string {
		return self::is( $stored ) ? $stored['text'] : '';
	}
}
