<?php
/**
 * A single materialisation shared by scope verification and accepted batch items.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Requests\CaptureIntent;
use BulkCatalogueManager\Selection\Selection;
defined( 'ABSPATH' ) || exit;

/** No field values are locked by a scope signature. */
final class ExactScope {
	/**
	 * Read once, expand once, and keep missing selected identities in the result.
	 *
	 * @param Selection $selection Exact selection.
	 * @param array $operations Normalized ordered operations.
	 * @param BatchFactory $factory Existing native expansion semantics.
	 * @return array
	 */
	public static function build( Selection $selection, array $operations, BatchFactory $factory ): array {
		if ( $selection->is_filter() ) {
			throw new \InvalidArgumentException( 'Capture the filter before previewing.' );}
		$current     = $factory->materialise( $selection );
		$found       = array_column( $current, 1, 0 );
		$selected    = $selection->members ?? array_map( static fn( int $id ): array=>[ $id,$found[ $id ] ?? 'product' ], $selection->ids );
		$available   = [];
		$unavailable = [];
		$missing     = [];
		foreach ( $selected as $entry ) {
			if ( ( $found[ $entry[0] ] ?? null ) !== $entry[1] ) {
				$unavailable[] = $entry[0];
				$missing[]     = $entry;
			} else {
				$available[] = $entry;}
		}
		$expanded            = $factory->expand( $available, $operations );
		$expanded['objects'] = array_merge( $expanded['objects'], $missing );
		if ( count( $expanded['objects'] ) > Selection::MAX_OBJECTS ) {
			throw new \LengthException( 'Expanded selection exceeds its limit.' );}
		return $expanded + [
			'selected'    => $selected,
			'unavailable' => $unavailable,
			'scope_hash'  => self::signature( $expanded['objects'], $operations, $unavailable ),
		];
	}
	/**
	 * Authenticate identities, types, per-item reach and unavailable identities.
	 *
	 * @param array $objects Ordered items.
	 * @param array $operations Batch defaults.
	 * @param int[] $unavailable Missing selected identities.
	 */
	public static function signature( array $objects, array $operations, array $unavailable ): string {
		$items = array_map( static fn( array $entry ): array=>[ $entry[0],$entry[1],$entry[2] ?? $operations ], $objects );
		return hash_hmac(
			'sha256',
			'bulk-catalogue-manager-for-woocommerce/scope/1/' . CaptureIntent::fingerprint(
				[
					'items'       => $items,
					'unavailable' => $unavailable,
				]
			),
			wp_salt( 'auth' )
		);
	}
}
