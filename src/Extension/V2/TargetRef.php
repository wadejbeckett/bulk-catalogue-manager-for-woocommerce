<?php
/**
 * TargetRef for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** TargetRef immutable contract value. */
final class TargetRef {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $kind Contract value.
	 * @param ?string $taxonomy Contract value.
	 * @param int $id Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $kind, public readonly ?string $taxonomy, public readonly int $id ) {
		Rules::ensure( $id > 0 && ( ( in_array( $kind, [ 'product', 'variation' ], true ) && null === $taxonomy ) || ( 'term' === $kind && 'product_cat' === $taxonomy ) ), 'Invalid native target reference.' );
	}
	/** Full tuple identity, never a bare numeric ID. */
	public function key(): string {
		return $this->kind . ':' . ( $this->taxonomy ?? '' ) . ':' . $this->id;
	}
	/** Native reference wire shape. */
	public function to_array(): array {
		return [
			'kind'     => $this->kind,
			'taxonomy' => $this->taxonomy,
			'id'       => $this->id,
		];
	}
	/**
	 * Read an exact wire tuple without scalar coercion.
	 * @param array $wire Wire tuple.
	 */
	public static function from_array( array $wire ): self {
		Rules::ensure( count( $wire ) === 3 && isset( $wire['kind'], $wire['id'] ) && array_key_exists( 'taxonomy', $wire ) && is_string( $wire['kind'] ) && is_int( $wire['id'] ) && ( null === $wire['taxonomy'] || is_string( $wire['taxonomy'] ) ), 'Invalid target wire shape.' );
		return new self( $wire['kind'], $wire['taxonomy'], $wire['id'] );
	}
}
