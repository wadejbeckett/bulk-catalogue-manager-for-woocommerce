<?php
/**
 * The exact scope of a change (T83): one materialisation shared by the
 * preview's scope hash and an accepted batch's items.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Requests\CaptureIntent;
use BulkCatalogueManager\Selection\Selection;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and signs the exact scope of an explicit or snapshot selection.
 *
 * The chosen members are read once (BatchFactory::materialise()) and a
 * member is available only when it is still a listable product or
 * variation of the kind it was chosen as; anything else — deleted, in the
 * bin, in a status the list does not show, or changed in kind — is
 * unavailable. Available members are expanded once (BatchFactory::expand(),
 * "Also apply to variations"); unavailable ones stay in the scope, after
 * the others, so the batch and its outcome still name them, and are
 * recorded already failed (BatchFactory::record_scope()), never written.
 *
 * The signature binds the ordered identities, their kinds, each item's
 * step list and the unavailable members, so a new variation or a
 * same-count substitution changes it. It locks no field value.
 */
final class ExactScope {
	/**
	 * Read once, expand once, and keep unavailable selected members.
	 *
	 * @param Selection    $selection  An explicit or snapshot selection.
	 * @param array<mixed> $operations The normalised operation list.
	 * @param BatchFactory $factory    The materialisation and expansion.
	 * @return array{objects: list<array>, added: int, left_out: int, selected: list<array{0: int, 1: string}>, unavailable: list<int>, missing: list<array{0: int, 1: string}>, scope_hash: string}
	 * @throws \InvalidArgumentException For a filter selection.
	 * @throws \LengthException When the expanded scope passes Selection::MAX_OBJECTS.
	 */
	public static function build( Selection $selection, array $operations, BatchFactory $factory ): array {
		if ( $selection->is_filter() ) {
			throw new \InvalidArgumentException( 'Capture the filter before previewing.' );
		}
		$found     = array_column( $factory->materialise( $selection ), 1, 0 );
		$selected  = $selection->members ?? array_map( static fn( int $id ): array => [ $id, $found[ $id ] ?? 'product' ], $selection->ids );
		$available = [];
		$missing   = [];
		foreach ( $selected as $entry ) {
			if ( ( $found[ $entry[0] ] ?? null ) === $entry[1] ) {
				$available[] = $entry;
			} else {
				$missing[] = $entry;
			}
		}
		$unavailable         = array_column( $missing, 0 );
		$expanded            = $factory->expand( $available, $operations );
		$expanded['objects'] = array_merge( $expanded['objects'], $missing );
		if ( count( $expanded['objects'] ) > Selection::MAX_OBJECTS ) {
			throw new \LengthException( 'Expanded selection exceeds its limit.' );
		}
		return $expanded + [
			'selected'    => $selected,
			'unavailable' => $unavailable,
			'missing'     => $missing,
			'scope_hash'  => self::signature( $expanded['objects'], $operations, $unavailable ),
		];
	}

	/**
	 * Authenticate identities, kinds, per-item reach and unavailable members.
	 *
	 * @param list<array>  $objects     Ordered items: [ id, kind ] or [ id, kind, own steps ].
	 * @param array<mixed> $operations  The batch's steps, for items without their own.
	 * @param list<int>    $unavailable Unavailable selected members.
	 */
	public static function signature( array $objects, array $operations, array $unavailable ): string {
		$items = array_map( static fn( array $entry ): array => [ $entry[0], $entry[1], $entry[2] ?? $operations ], $objects );
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
