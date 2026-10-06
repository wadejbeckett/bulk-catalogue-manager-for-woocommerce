<?php
/**
 * Native publication lifecycle.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Publishing;

use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\Memory;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/** Streaming, complete-membership publication binding, independent of samples. */
final class PublicationBinding {
	/** Bind every reaching native Status step, with stable unreadable markers.
	 * @param array $objects Exact [id,kind,optional operations] members.
	 * @param array $operations Canonical steps.
	 * @param int $now Injected clock shared with preview/acceptance.
	 * @return array Versioned digest, or empty to preserve legacy hashes.
	 */
	public static function of( array $objects, array $operations, int $now ): array {
		$reaches = static fn( array $steps ): array => array_filter( $steps, static fn( array $step ): bool => 'set_status' === $step['action'] );
		$any     = false;
		foreach ( $objects as $entry ) {
			if ( [] !== $reaches( $entry[2] ?? $operations ) ) {
				$any = true;
				break;
			}
		}
		if ( ! $any ) {
			return [];
		}
		usort( $objects, static fn( array $a, array $b ): int => [ $a[0],$a[1] ] <=> [ $b[0],$b[1] ] );
		$digest    = hash_init( 'sha256' );
		$validator = Validator::for_request( new Registry() );
		foreach ( $objects as $index => $entry ) {
			if ( 0 === $index % 200 ) {
				Memory::next_page( array_column( array_slice( $objects, $index, 200 ), 0 ), $index > 0 );
			}
			[$id, $kind] = $entry;
			$rows        = [];
			$steps       = $reaches( $entry[2] ?? $operations );
			$product     = [] === $steps ? null : wc_get_product( $id );
			$readable    = is_object( $product ) && Journal::object_type( $product ) === $kind && 'trash' !== $product->get_status( 'edit' ) && null === $validator->authorise( $product );
			foreach ( $steps as $position => $step ) {
				$requested = (string) $step['args']['value'];
				$rows[]    = [ $position, $requested, $readable ? [ StatusTransition::inputs( $product ), StatusTransition::predict( $product, $requested, $now )['effective'] ] : 'unreadable' ];
				// One native save follows the list: all reaching steps bind the stored date inputs.
			}
			hash_update( $digest, (string) wp_json_encode( [ $id,$kind,$rows ] ) . "\n" );
		}
		return [
			'version' => 1,
			'digest'  => hash_final( $digest ),
		];
	}
}
