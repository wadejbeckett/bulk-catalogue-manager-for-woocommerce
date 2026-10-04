<?php
/**
 * Snapshot for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Snapshot immutable contract value. */
final class Snapshot {
	/**
	 * Validate and retain the complete contract value.
	 * @param int $version Contract value.
	 * @param mixed $data Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly int $version, public readonly mixed $data ) {
		Rules::ensure( $version > 0, 'Invalid snapshot version.' );
		Rules::json( $data, 16384 );
	}
}
