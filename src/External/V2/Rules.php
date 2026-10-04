<?php
/**
 * Internal validation shared by immutable V2 values and registration.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\External\Arguments;
use BulkCatalogueManager\External\Inspector as LegacyInspector;

defined( 'ABSPATH' ) || exit;

/** Constructor rules; exceptions are developer diagnostics, never browser messages. */
final class Rules {
	/**
	 * Refuse an invalid declaration.
	 * @param bool $valid Condition.
	 * @param string $reason Diagnostic.
	 * @throws \InvalidArgumentException When invalid.
	 */
	public static function ensure( bool $valid, string $reason ): void {
		if ( ! $valid ) {
			throw new \InvalidArgumentException( esc_html( $reason ) );
		}
	}
	/**
	 * A JSON-plain, reference-free bounded value.
	 * @param mixed $value Value.
	 * @param int $limit Encoded byte limit.
	 */
	public static function json( mixed $value, int $limit ): void {
		self::immutable( $value );
		$wire = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		self::ensure( false !== $wire && strlen( $wire ) <= $limit, 'Contract value exceeds its JSON byte bound.' );
	}
	/**
	 * Validate retained plain data without imposing a new encoded byte bound.
	 * @param mixed $value Value.
	 */
	public static function immutable( mixed $value ): void {
		self::ensure( self::plain( $value, 0 ), 'A contract value must be JSON-plain and immutable.' );
	}
	/**
	 * Reject invalid UTF-8 before WordPress can repair it, recursive data and references.
	 * @param mixed $value Value.
	 * @param int $depth Nesting depth.
	 */
	private static function plain( mixed $value, int $depth ): bool {
		if ( $depth > 64 ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( null !== \ReflectionReference::fromArrayElement( $value, $key ) || ( is_string( $key ) && 1 !== preg_match( '//u', $key ) ) || ! self::plain( $item, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) || ( is_string( $value ) && 1 === preg_match( '//u', $value ) );
	}
	/**
	 * Stable provider ID.
	 * @param string $id Provider ID.
	 */
	public static function provider( string $id ): void {
		self::ensure( 1 === preg_match( '/^[a-z][a-z0-9]{2,19}$/D', $id ), 'Invalid provider ID.' );
	}
	/**
	 * Immutable origin, distinct from version and label.
	 * @param string $identity Origin.
	 */
	public static function identity( string $identity ): void {
		self::ensure( 1 === preg_match( '~^[a-zA-Z0-9._:/-]{1,128}$~D', $identity ), 'Invalid provider origin.' );
	}
	/**
	 * External immutable key.
	 * @param string $key Key.
	 * @param string|null $owner Provider, when known.
	 */
	public static function key( string $key, ?string $owner = null ): void {
		self::ensure( strlen( $key ) <= 64 && 1 === preg_match( '/^x_([a-z][a-z0-9]{2,19})_[a-z0-9][a-z0-9_]*$/D', $key, $match ) && ( null === $owner || $owner === $match[1] ), 'Invalid external key or owner.' );
	}
	/**
	 * Short argument name.
	 * @param string $name Name.
	 */
	public static function name( string $name ): void {
		self::ensure( 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $name ), 'Invalid argument name.' );
	}
	/**
	 * Opaque UTF-8 value ID, measured in bytes.
	 * @param string $value ID.
	 */
	public static function opaque( string $value ): void {
		self::ensure( '' !== $value && strlen( $value ) <= 128 && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/\p{Cc}/u', $value ), 'Invalid opaque value.' );
	}
	/**
	 * Plain bounded text, measured in Unicode characters.
	 * @param string $value Text.
	 * @param int $limit Character limit.
	 * @param bool $allow_empty Whether empty is allowed.
	 */
	public static function text( string $value, int $limit, bool $allow_empty = false ): void {
		$plain = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure PHP contract tooling has no WordPress runtime.
		self::ensure( ( $allow_empty || '' !== trim( $value ) ) && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/\p{Cc}/u', $value ) && $plain === $value && mb_strlen( $value ) <= $limit, 'Invalid plain text.' );
	}
	/**
	 * Exact lowercase hexadecimal identity.
	 * @param string $value Identity.
	 * @param int $length Length.
	 */
	public static function hex( string $value, int $length ): void {
		self::ensure( strlen( $value ) === $length && 1 === preg_match( '/^[a-f0-9]+$/D', $value ), 'Invalid hexadecimal identity.' );
	}
	/**
	 * One native target family.
	 * @param array $kinds Kinds.
	 */
	public static function kinds( array $kinds ): void {
		self::json( $kinds, 128 );
		self::ensure( array_is_list( $kinds ) && [] !== $kinds && count( array_filter( $kinds, 'is_string' ) ) === count( $kinds ) && count( array_unique( $kinds ) ) === count( $kinds ) && [] === array_diff( $kinds, [ 'product', 'variation', 'term' ] ) && ( ! in_array( 'term', $kinds, true ) || [ 'term' ] === $kinds ), 'Invalid or mixed target kinds.' );
	}
	/**
	 * Existing scalar/static rules plus bounded acyclic lookup dependencies.
	 * @param array $arguments Named argument specs.
	 */
	public static function arguments( array $arguments ): void {
		self::json( $arguments, 65536 );
		$edges = [];
		foreach ( $arguments as $name => $spec ) {
			self::ensure( is_string( $name ) && is_array( $spec ), 'Invalid argument declaration.' );
			self::name( $name );
			self::ensure( ! in_array( $name, LegacyInspector::RESERVED, true ), 'Reserved argument.' );
			$type = $spec['type'] ?? '';
			self::ensure( in_array( $type, [ ...LegacyInspector::KINDS, 'lookup' ], true ), 'Invalid argument type.' );
			self::ensure( is_string( $spec['label'] ?? null ) && is_string( $spec['rule'] ?? null ), 'Missing argument text.' );
			self::text( $spec['label'], 80 );
			self::text( $spec['rule'], 300 );
			$edges[ $name ] = [];
			if ( 'lookup' !== $type ) {
				self::scalar( $spec );
			}
		}
		// Validate every scalar controller before a lookup can evaluate its predicate.
		foreach ( $arguments as $name => $spec ) {
			if ( 'lookup' !== $spec['type'] ) {
				continue;
			}
			self::ensure( [] === array_diff( array_keys( $spec ), [ 'type', 'label', 'rule', 'source', 'multiple', 'min_items', 'max_items', 'depends_on', 'active_when' ] ) && is_string( $spec['source'] ?? null ) && is_bool( $spec['multiple'] ?? null ) && is_int( $spec['min_items'] ?? null ) && is_int( $spec['max_items'] ?? null ) && $spec['min_items'] >= 0 && $spec['min_items'] <= $spec['max_items'] && $spec['max_items'] >= 1 && $spec['max_items'] <= ( $spec['multiple'] ? 100 : 1 ), 'Invalid lookup bounds or shape.' );
			self::opaque( $spec['source'] );
			$deps = $spec['depends_on'] ?? null;
			self::ensure( is_array( $deps ) && array_is_list( $deps ) && count( $deps ) <= 20 && count( array_filter( $deps, 'is_string' ) ) === count( $deps ) && count( array_unique( $deps ) ) === count( $deps ), 'Invalid dependencies.' );
			foreach ( $deps as $dep ) {
				self::ensure( is_string( $dep ) && isset( $arguments[ $dep ] ), 'Unknown dependency.' );
			}
			$edges[ $name ] = $deps;
			if ( isset( $spec['active_when'] ) ) {
				$when = $spec['active_when'];
				self::ensure( is_array( $when ) && count( $when ) === 3 && isset( $when['argument'], $when['op'], $when['values'] ) && is_string( $when['argument'] ) && 'in' === $when['op'] && is_array( $when['values'] ) && array_is_list( $when['values'] ) && count( $when['values'] ) >= 1 && count( $when['values'] ) <= 20, 'Invalid active predicate.' );
				$controller = $arguments[ $when['argument'] ] ?? [];
				self::ensure( in_array( $controller['type'] ?? '', [ 'boolean', 'choice' ], true ), 'Predicate controller must be scalar choice or boolean.' );
				foreach ( $when['values'] as $value ) {
					self::ensure( is_scalar( $value ) && null !== Arguments::normalise( $value, $controller ), 'Invalid predicate value.' );
				}
				$edges[ $name ][] = $when['argument'];
			}
		}
		$done   = [];
		$active = [];
		$visit  = static function ( string $name ) use ( &$visit, &$done, &$active, $edges ): void {
			self::ensure( ! isset( $active[ $name ] ), 'Cyclic argument dependency.' );
			if ( isset( $done[ $name ] ) ) {
				return;
			}
			$active[ $name ] = true;
			foreach ( $edges[ $name ] as $dep ) {
				$visit( $dep );
			}
			unset( $active[ $name ] );
			$done[ $name ] = true;
		};
		foreach ( array_keys( $edges ) as $name ) {
			$visit( $name );
		}
	}
	/**
	 * Static arguments preserve the v1 500-choice and 100-selection limits.
	 * @param array $spec Argument spec.
	 */
	private static function scalar( array $spec ): void {
		self::ensure( [] === array_diff( array_keys( $spec ), [ 'type', 'label', 'rule', 'required', 'default', 'choices', 'min', 'max', 'exclusive_min', 'exclusive_max', 'max_length', 'allow_empty', 'min_items', 'max_items' ] ), 'Unknown argument property.' );
		foreach ( [ 'required', 'allow_empty', 'exclusive_min', 'exclusive_max' ] as $flag ) {
			self::ensure( ! array_key_exists( $flag, $spec ) || is_bool( $spec[ $flag ] ), 'Invalid argument flag.' );
		}
		foreach ( [ 'min', 'max' ] as $bound ) {
			self::ensure( ! array_key_exists( $bound, $spec ) || is_int( $spec[ $bound ] ) || is_float( $spec[ $bound ] ), 'Invalid argument bound.' );
		}
		foreach ( [ 'min_items', 'max_items', 'max_length' ] as $bound ) {
			self::ensure( ! array_key_exists( $bound, $spec ) || ( is_int( $spec[ $bound ] ) && $spec[ $bound ] >= 0 ), 'Invalid argument count.' );
		}
		self::ensure( ! isset( $spec['min'], $spec['max'] ) || $spec['min'] <= $spec['max'], 'Reversed argument bounds.' );
		if ( in_array( $spec['type'], [ 'choice', 'multichoice' ], true ) ) {
			$choices = $spec['choices'] ?? null;
			self::ensure( is_array( $choices ) && count( $choices ) >= 1 && count( $choices ) <= 500, 'Invalid static choice bound.' );
			foreach ( $choices as $value => $label ) {
				self::ensure( strlen( (string) $value ) <= 64 && is_string( $label ), 'Invalid static choice.' );
				self::opaque( (string) $value );
				self::text( $label, 80 );
			}
			if ( 'multichoice' === $spec['type'] ) {
				$max = $spec['max_items'] ?? min( 100, count( $choices ) );
				self::ensure( $max >= 1 && $max <= 100 && ( $spec['min_items'] ?? 0 ) <= $max, 'Invalid multichoice bounds.' );
			}
		} else {
			self::ensure( ! array_key_exists( 'choices', $spec ), 'Unexpected static choices.' );
		}
		self::ensure( ! array_key_exists( 'default', $spec ) || null !== Arguments::normalise( $spec['default'], $spec ), 'Invalid argument default.' );
	}
}
