<?php
/**
 * Lookup for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Lookup immutable contract value. */
final class Lookup {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $argument Contract value.
	 * @param string $source Contract value.
	 * @param array $dependencies Contract value.
	 * @param string $query Contract value.
	 * @param ?string $cursor Contract value.
	 * @param int $limit Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $argument, public readonly string $source, public readonly array $dependencies, public readonly string $query, public readonly ?string $cursor, public readonly int $limit ) {
		Rules::name( $argument );
		Rules::opaque( $source );
		Rules::json( $dependencies, 65536 );
		Rules::text( $query, 128, true );
		Rules::ensure( $limit >= 1 && $limit <= 50 && ( null === $cursor || ( strlen( $cursor ) <= 2048 && 1 === preg_match( '//u', $cursor ) ) ), 'Invalid lookup page.' );
	}
}
