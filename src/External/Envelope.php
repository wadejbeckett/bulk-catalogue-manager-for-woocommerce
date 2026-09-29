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
	 * A journalled envelope's value in its field's current format: as it is,
	 * converted by the field's upgrade() when older, or a WP_Error when newer,
	 * unreadable or not convertible (T107, T108; docs/EXTENSIONS.md §8.4, §12).
	 *
	 * @param Registered $provider The field's provider.
	 * @param string     $key      Field key.
	 * @param mixed      $stored   The journalled envelope.
	 * @return mixed The value, or a WP_Error.
	 */
	public static function current( Registered $provider, string $key, mixed $stored ): mixed {
		$meta  = $provider->field_meta( $key );
		$field = $provider->field( $key );
		if ( ! self::is( $stored ) || null === $meta || null === $field || $stored['format'] > $meta['format'] ) {
			return Refusals::unavailable( $provider->label );
		}
		if ( $stored['format'] === $meta['format'] ) {
			return $stored['value'];
		}
		try {
			$value = Guard::run( $provider->id, 'upgrade', static fn(): mixed => $field->upgrade( $stored['value'], $stored['format'] ) );
		} catch ( ProviderFailure $e ) {
			return Refusals::unavailable( $provider->label );
		}
		return $value instanceof \WP_Error || ! self::plain( $value ) ? Refusals::unavailable( $provider->label ) : $value;
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
