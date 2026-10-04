<?php
/**
 * ReconcileDecision for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ReconcileDecision immutable contract value. */
final class ReconcileDecision {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $state Contract value.
	 * @param mixed $expected Contract value.
	 * @param mixed $target Contract value.
	 * @param string $reason Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $state, public readonly mixed $expected, public readonly mixed $target, public readonly string $reason = '' ) {
		Rules::ensure( in_array( $state, [ 'stored', 'retry', 'refuse' ], true ), 'Invalid reconcile state.' );
		Rules::json( $expected, 65536 );
		Rules::json( $target, 65536 );
		Rules::text( $reason, 255, 'refuse' !== $state );
	}
}
