<?php
/**
 * ResolvedChoice for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ResolvedChoice immutable contract value. */
final class ResolvedChoice {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $value Contract value.
	 * @param ?string $label Contract value.
	 * @param bool $available Contract value.
	 * @param string $reason Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $value, public readonly ?string $label, public readonly bool $available, public readonly string $reason = '' ) {
		Rules::opaque( $value );
		Rules::text( $reason, 255, $available );
		Rules::ensure( $available ? null !== $label : null === $label, 'Unavailable choices must not disclose a label.' );
		if ( null !== $label ) {
			Rules::text( $label, 80 );
		}
	}
}
