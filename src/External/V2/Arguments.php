<?php
/**
 * Authoritative argument normalization shared by typed preview and discovery.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\ActionDescriptor;
use BulkCatalogueManager\External\Arguments as Scalars;
use BulkCatalogueManager\Rest\RequestsController;
defined( 'ABSPATH' ) || exit;
/** Pure shape/default normalization; provider validation owns choice existence. */
final class Arguments {
	/**
	 * Normalize all declared arguments and core flags without trusting client labels.
	 * @param ActionDescriptor $descriptor Registered declaration.
	 * @param array $input Submitted map.
	 * @return array|\WP_Error
	 */
	public static function normalize( ActionDescriptor $descriptor, array $input ): array|\WP_Error {
		try {
			Rules::json( $input, 65536 );
			Rules::ensure( [] === array_diff( array_keys( $input ), [ ...array_keys( $descriptor->arguments ),'field','skip_already','apply_to_variations' ] ), 'Unknown argument.' );
			Rules::ensure( ( $input['field'] ?? null ) === $descriptor->field, 'Wrong action field.' );
			$out = [ 'field' => $descriptor->field ];
			foreach ( $descriptor->arguments as $name => $spec ) {
				if ( 'lookup' === $spec['type'] ) {
					continue;}
				$value = array_key_exists( $name, $input ) ? $input[ $name ] : ( $spec['default'] ?? null );
				if ( null === $value && empty( $spec['required'] ) && ! array_key_exists( 'default', $spec ) ) {
					$out[ $name ] = null;
					continue;
				}
				$value = Scalars::normalise( $value, $spec );
				Rules::ensure( null !== $value, 'Invalid scalar argument.' );
				$out[ $name ] = $value;
			}
			foreach ( $descriptor->arguments as $name => $spec ) {
				if ( 'lookup' !== $spec['type'] ) {
					continue;}
				$when   = $spec['active_when'] ?? null;
				$active = null === $when || in_array( $out[ $when['argument'] ] ?? null, $when['values'], true );
				$empty  = $spec['multiple'] ? [] : null;
				$value  = array_key_exists( $name, $input ) ? $input[ $name ] : $empty;
				if ( ! $active ) {
					Rules::ensure( $value === $empty, 'Inactive argument has residual values.' );
					$out[ $name ] = $empty;
					continue;}
				$values = $spec['multiple'] ? $value : ( null === $value ? [] : [ $value ] );
				Rules::ensure( is_array( $values ) && array_is_list( $values ) && count( $values ) >= $spec['min_items'] && count( $values ) <= $spec['max_items'], 'Invalid lookup count.' );
				foreach ( $values as $id ) {
					Rules::ensure( is_string( $id ), 'Invalid lookup identity.' );
					Rules::opaque( $id );}
				Rules::ensure( count( array_unique( $values, SORT_STRING ) ) === count( $values ), 'Duplicate lookup identity.' );
				sort( $values, SORT_STRING );
				$out[ $name ] = $spec['multiple'] ? $values : ( $values[0] ?? null );
			}
			// Declaration order is stable, even when a lookup precedes its controller.
			$out = [ 'field' => $descriptor->field ] + array_replace( array_fill_keys( array_keys( $descriptor->arguments ), null ), array_diff_key( $out, [ 'field' => true ] ) );
			foreach ( [
				'skip_already'        => true,
				'apply_to_variations' => false,
			] as $name => $default ) {
				$value = Scalars::normalise( array_key_exists( $name, $input ) ? $input[ $name ] : $default, [ 'type' => 'boolean' ] );
				Rules::ensure( null !== $value, 'Invalid core flag.' );
				$out[ $name ] = $value;
			}
			return $out;
		} catch ( \InvalidArgumentException $error ) {
			return RequestsController::unknown();}
	}
}
