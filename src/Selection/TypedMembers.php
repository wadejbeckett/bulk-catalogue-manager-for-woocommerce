<?php
/**
 * Immutable full-tuple membership in the existing snapshot storage.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Extension\V2\TargetRef;
use BulkCatalogueManager\External\V2\Rules;
defined( 'ABSPATH' ) || exit;
/** Version 3 never groups product and term identities by numeric id alone. */
final class TypedMembers {
	/**
	 * Validate, deduplicate and sort native references.
	 * @param array $targets Exact reference wire list.
	 * @return array Canonical tuples.
	 */
	public static function normalize( array $targets ): array {
		Rules::ensure( array_is_list( $targets ), 'Invalid typed membership.' );
		$out = [];
		foreach ( $targets as $target ) {
			Rules::ensure( is_array( $target ), 'Invalid target.' );
			$ref                = TargetRef::from_array( $target );
			$out[ $ref->key() ] = $ref->to_array();}
		if ( count( $out ) > Selection::MAX_OBJECTS ) {
			throw new \LengthException( 'Selection is too large.' );
		}ksort( $out, SORT_STRING );
		return array_values( $out );
	}
	/**
	 * Explicitly decode either legacy membership or format 3.
	 * @param array $members Stored membership.
	 * @return array Full tuples, never a rewritten legacy row.
	 */
	public static function decode( array $members ): array {
		if ( array_key_exists( 'version', $members ) ) {
			Rules::ensure( 3 === $members['version'] && count( $members ) === 2 && is_array( $members['targets'] ?? null ), 'Unsupported membership format.' );
			return self::normalize( $members['targets'] );
		}
		Rules::ensure( count( $members ) === 2 && isset( $members['products'], $members['variations'] ), 'Invalid legacy members.' );
		$out = [];
		foreach ( [
			'products'   => 'product',
			'variations' => 'variation',
		] as $key => $kind ) {
			foreach ( $members[ $key ] as $id ) {
				$out[] = [
					'kind'     => $kind,
					'taxonomy' => null,
					'id'       => $id,
				];
			}
		}return self::normalize( $out );
	}
	/**
	 * Frozen full-reference union.
	 * @param array $left Left tuples.
	 * @param array $right Right tuples.
	 */
	public static function union( array $left, array $right ): array {
		return self::normalize( [ ...$left,...$right ] );}
	/**
	 * Remove only exact tuples.
	 * @param array $left Base tuples.
	 * @param array $right Removed tuples.
	 */
	public static function subtract( array $left, array $right ): array {
		$keys = [];
		foreach ( self::normalize( $right ) as $ref ) {
			$keys[ TargetRef::from_array( $ref )->key() ] = true;}
		return array_values( array_filter( self::normalize( $left ), static fn( array $ref ): bool=> ! isset( $keys[ TargetRef::from_array( $ref )->key() ] ) ) );
	}
}
