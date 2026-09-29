<?php
/**
 * The sample provider's fault switches (docs/EXTENSIONS.md §15.1), read from
 * the test-only option `sample_provider_faults`, so a probe or a unit test
 * can make the provider misbehave in each way the contract names:
 *
 * - `unavailable` (bool): availability() refuses;
 * - `contract` (int): the contract version it claims;
 * - `version` (string): the version it claims;
 * - `malformed` (bool): an action changes another provider's field;
 * - `throw_describe` (bool): an action's describe() throws;
 * - `throw_compute`, `write_error`, `write_conflict`, `unknown`, `refuse`,
 *   `wrong_field`, `not_stored`, `huge` (lists of object ids): compute()
 *   throws, write() fails, write() reports a compare-and-set miss, read()
 *   answers Unknown, authorise() refuses, compute() names the wrong field,
 *   write() answers true without storing, compute() makes a value above 64 KB;
 * - `state` (string): mixed into state_token();
 * - `format` (int): the labels field's format;
 * - `slow_write` (int): microseconds each write() sleeps (the lease path);
 * - `throw_flush` (bool): flush() throws.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * Fault switches.
 */
final class SampleFaults {
	/**
	 * The option holding the switches.
	 */
	public const OPTION = 'sample_provider_faults';

	/**
	 * One switch.
	 *
	 * @param string $name    Switch.
	 * @param mixed  $fallback Value when unset.
	 */
	public static function get( string $name, mixed $fallback = null ): mixed {
		$faults = get_option( self::OPTION, [] );
		return is_array( $faults ) && array_key_exists( $name, $faults ) ? $faults[ $name ] : $fallback;
	}

	/**
	 * Whether an id is in a list switch.
	 *
	 * @param string $name Switch.
	 * @param int    $id   Object.
	 */
	public static function has( string $name, int $id ): bool {
		$ids = self::get( $name, [] );
		return is_array( $ids ) && in_array( $id, array_map( 'intval', $ids ), true );
	}
}
