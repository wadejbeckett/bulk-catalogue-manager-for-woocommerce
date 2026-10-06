<?php
/**
 * A typed reference to one object the core acts on (docs/EXTENSIONS-v2.md
 * §5.2): a product, a variation, or a term of a named taxonomy. A term
 * reference is never equal to a product reference with the same number.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable object reference, contract version 2.
 */
final class ObjectRef {
	/**
	 * A product.
	 */
	public const PRODUCT = 'product';

	/**
	 * A variation.
	 */
	public const VARIATION = 'variation';

	/**
	 * A taxonomy term.
	 */
	public const TERM = 'term';

	/**
	 * The taxonomies a term reference may name in contract version 2.
	 */
	public const TAXONOMIES = [ 'product_cat' ];

	/**
	 * Built through the named constructors only.
	 *
	 * @param string $kind     PRODUCT, VARIATION or TERM.
	 * @param string $taxonomy The taxonomy of a term, else ''.
	 * @param int    $id       The positive post or term id.
	 */
	private function __construct(
		private readonly string $kind,
		private readonly string $taxonomy,
		private readonly int $id
	) {
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( 'An object reference needs a positive id.' );
		}
	}

	/**
	 * A product.
	 *
	 * @param int $id The product id.
	 */
	public static function product( int $id ): self {
		return new self( self::PRODUCT, '', $id );
	}

	/**
	 * A variation.
	 *
	 * @param int $id The variation id.
	 */
	public static function variation( int $id ): self {
		return new self( self::VARIATION, '', $id );
	}

	/**
	 * A term of a supported taxonomy (term id, never a term-taxonomy id).
	 *
	 * @param string $taxonomy The taxonomy, `product_cat`.
	 * @param int    $term_id  The term id.
	 */
	public static function term( string $taxonomy, int $term_id ): self {
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			throw new \InvalidArgumentException( 'An object reference names a taxonomy the contract does not support.' );
		}
		return new self( self::TERM, $taxonomy, $term_id );
	}

	/**
	 * The reference a key names, or null for a malformed key.
	 *
	 * @param string $key `product:41`, `variation:41` or `term:product_cat:41`.
	 */
	public static function from_key( string $key ): ?self {
		if ( 1 === preg_match( '/^(product|variation):([1-9][0-9]{0,18})$/', $key, $match ) ) {
			return new self( $match[1], '', (int) $match[2] );
		}
		if ( 1 === preg_match( '/^term:([a-z_]{1,32}):([1-9][0-9]{0,18})$/', $key, $match ) && in_array( $match[1], self::TAXONOMIES, true ) ) {
			return new self( self::TERM, $match[1], (int) $match[2] );
		}
		return null;
	}

	/**
	 * PRODUCT, VARIATION or TERM.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * The term's taxonomy, or '' for a product or variation.
	 */
	public function taxonomy(): string {
		return $this->taxonomy;
	}

	/**
	 * The post or term id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * A stable key: `product:41`, `variation:41` or `term:product_cat:41`.
	 */
	public function key(): string {
		return self::TERM === $this->kind ? 'term:' . $this->taxonomy . ':' . $this->id : $this->kind . ':' . $this->id;
	}

	/**
	 * Whether another reference names the same object.
	 *
	 * @param ObjectRef $other The other reference.
	 */
	public function equals( ObjectRef $other ): bool {
		return $this->key() === $other->key();
	}
}
