<?php
/**
 * Pure typed-set operations with no catalogue requery.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

defined( 'ABSPATH' ) || exit;

/** Immutable set arithmetic, capped after deduplication. */
final class Membership {
	/**
	 * Union two frozen sets.
	 *
	 * @param array<string,int[]> $left Base members.
	 * @param array<string,int[]> $right Added members.
	 * @return array<string,int[]>
	 * @throws \InvalidArgumentException On conflicting types.
	 * @throws \LengthException On overflow.
	 */
	public static function union( array $left, array $right ): array {
		$result = [];
		foreach ( [ 'products', 'variations' ] as $type ) {
			$result[ $type ] = array_values( array_unique( array_merge( $left[ $type ], $right[ $type ] ) ) );
			sort( $result[ $type ], SORT_NUMERIC );
		}
		if ( [] !== array_intersect( $result['products'], $result['variations'] ) ) {
			throw new \InvalidArgumentException( 'An identity has conflicting catalogue types.' );
		}
		if ( count( $result['products'] ) + count( $result['variations'] ) > Selection::MAX_OBJECTS ) {
			throw new \LengthException( 'Combined selection exceeds its limit.' );
		}
		return $result;
	}

	/**
	 * Remove identities even when their products no longer exist.
	 *
	 * @param array<string,int[]> $base Frozen members.
	 * @param int[] $ids Identities to remove.
	 * @return array<string,int[]>
	 */
	public static function subtract( array $base, array $ids ): array {
		return [
			'products'   => array_values( array_diff( $base['products'], $ids ) ),
			'variations' => array_values( array_diff( $base['variations'], $ids ) ),
		];
	}
}
