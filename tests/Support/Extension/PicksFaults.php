<?php
/**
 * Fault switches of the contract version 2 test provider, read from a
 * test-only option: `unavailable`, `version`, `contract`, `size` (how many
 * recipients the directory holds, 5,000 by default), `withdrawn` (recipient
 * ids no longer offered), `restricted` (recipient ids only a user who can
 * `manage_options` may choose), `renamed` (id => label), `lookup_error`,
 * `refuse` (product ids authorise() refuses), `refuse_terms` (term ids
 * authorise() refuses), `write_conflict` (object ids), `state`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * Reads the faults.
 */
final class PicksFaults {
	/**
	 * The option holding the switches.
	 */
	public const OPTION = 'sample_picks_faults';

	/**
	 * One switch.
	 *
	 * @param string $name     The switch.
	 * @param mixed  $fallback Its value when unset.
	 */
	public static function get( string $name, mixed $fallback = null ): mixed {
		$faults = get_option( self::OPTION, [] );
		return is_array( $faults ) && array_key_exists( $name, $faults ) ? $faults[ $name ] : $fallback;
	}

	/**
	 * Whether a list switch names an id.
	 *
	 * @param string     $name The switch.
	 * @param int|string $id   The id.
	 */
	public static function has( string $name, int|string $id ): bool {
		$ids = self::get( $name, [] );
		return is_array( $ids ) && in_array( (string) $id, array_map( 'strval', $ids ), true );
	}
}
