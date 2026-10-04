<?php
/**
 * RestoreDecision for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** RestoreDecision immutable contract value. */
final class RestoreDecision {
	/**
	 * Validate and retain the complete contract value.
	 * @param mixed $target Contract value.
	 * @param array $conflicts Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly mixed $target, public readonly array $conflicts ) {
		Rules::json( $target, 65536 );
		Rules::ensure( array_is_list( $conflicts ), 'Invalid conflicts.' );
		foreach ( $conflicts as $conflict ) {
			Rules::ensure( is_array( $conflict ) && count( $conflict ) === 2 && isset( $conflict['domain'], $conflict['reason'] ) && is_string( $conflict['domain'] ) && is_string( $conflict['reason'] ), 'Invalid conflict.' );
			Rules::opaque( $conflict['domain'] );
			Rules::text( $conflict['reason'], 255 );
		}
		Rules::json( $conflicts, 65536 );
	}
}
