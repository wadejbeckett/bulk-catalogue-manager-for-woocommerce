<?php
/**
 * Choice for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Choice immutable contract value. */
final class Choice {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $value Contract value.
	 * @param string $label Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $value, public readonly string $label ) {
		Rules::opaque( $value );
		Rules::text( $label, 80 );
	}
}
