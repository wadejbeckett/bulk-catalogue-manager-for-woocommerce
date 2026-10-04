<?php
/**
 * Computation for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Computation immutable contract value. */
final class Computation {
	/**
	 * Validate and retain the complete contract value.
	 * @param \BulkCatalogueManager\Operations\Result $result Contract value.
	 * @param Snapshot $origin Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly \BulkCatalogueManager\Operations\Result $result, public readonly Snapshot $origin ) {
		Rules::json( $result->old(), 65536 );
		Rules::json( $result->new(), 65536 );
		Rules::immutable( $result->notes() );
		Rules::immutable( $result->context() );
		Rules::immutable( $result->warning() );
	}
}
