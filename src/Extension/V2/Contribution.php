<?php
/**
 * Contribution for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Contribution immutable contract value. */
final class Contribution {
	/**
	 * Validate and retain the complete contract value.
	 * @param ValidatedStep $step Contract value.
	 * @param string $state Contract value.
	 * @param Snapshot $origin Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly ValidatedStep $step, public readonly string $state, public readonly Snapshot $origin ) {
		Rules::ensure( in_array( $state, \BulkCatalogueManager\Operations\Result::STATES, true ), 'Invalid contribution state.' );
	}
}
