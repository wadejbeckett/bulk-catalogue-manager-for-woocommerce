<?php
/**
 * Finalisation for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Finalisation immutable contract value. */
final class Finalisation {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $id Contract value.
	 * @param string $intent_id Contract value.
	 * @param int $generation Contract value.
	 * @param TargetRef $target Contract value.
	 * @param string $field Contract value.
	 * @param int $format Contract value.
	 * @param string $outcome Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $id, public readonly string $intent_id, public readonly int $generation, public readonly TargetRef $target, public readonly string $field, public readonly int $format, public readonly string $outcome ) {
		Rules::hex( $intent_id, 32 );
		Rules::hex( $id, 64 );
		Rules::key( $field );
		Rules::ensure( $generation > 0 && $format > 0 && hash( 'sha256', $intent_id . ':' . $generation ) === $id && in_array( $outcome, [ 'unknown', 'stored', 'partial', 'refused' ], true ), 'Invalid finalisation entry.' );
	}
}
