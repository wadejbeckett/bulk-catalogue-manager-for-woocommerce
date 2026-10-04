<?php
/**
 * ValidatedStep for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ValidatedStep immutable contract value. */
final class ValidatedStep {
	/**
	 * Validate and retain the complete contract value.
	 * @param int $index Contract value.
	 * @param string $action Contract value.
	 * @param string $field Contract value.
	 * @param array $args Contract value.
	 * @param Snapshot $validation Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly int $index, public readonly string $action, public readonly string $field, public readonly array $args, public readonly Snapshot $validation ) {
		Rules::ensure( $index >= 0 && $index < 20, 'Invalid step index.' );
		Rules::key( $action );
		Rules::key( $field );
		Rules::json( $args, 65536 );
	}
}
