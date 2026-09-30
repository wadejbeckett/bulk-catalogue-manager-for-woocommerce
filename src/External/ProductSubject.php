<?php
/**
 * The core's Subject (docs/EXTENSIONS.md §4, §7.3): one available product or
 * variation the core has loaded and checked. value() answers what an
 * earlier step of the same change computed (the overlay), or the value its
 * provider read, read once.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Extension\Unknown;

defined( 'ABSPATH' ) || exit;

/**
 * An object and its overlay.
 */
final class ProductSubject implements Subject {
	/**
	 * Values read, by field key.
	 *
	 * @var array<string, mixed>
	 */
	private array $read = [];

	/**
	 * Values earlier steps computed, by field key.
	 *
	 * @var array<string, mixed>
	 */
	private array $overlay = [];

	/**
	 * An object for one provider.
	 *
	 * @param int        $id        Product or variation id.
	 * @param string     $type      `product` or `variation`.
	 * @param int        $parent_id The variation's product, or 0.
	 * @param object     $product   The loaded WooCommerce object.
	 * @param Registered $provider  The provider asking.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $type,
		private readonly int $parent_id,
		private readonly object $product,
		private readonly Registered $provider
	) {}

	/**
	 * The object for a loaded WooCommerce product or variation.
	 *
	 * @param object     $product  The loaded object.
	 * @param Registered $provider The provider asking.
	 */
	public static function of( object $product, Registered $provider ): self {
		$variation = method_exists( $product, 'is_type' ) && true === $product->is_type( 'variation' );
		$parent    = $variation && method_exists( $product, 'get_parent_id' ) ? max( 0, (int) $product->get_parent_id( 'edit' ) ) : 0;
		return new self( (int) $product->get_id(), $variation ? 'variation' : 'product', $parent, $product, $provider );
	}

	/**
	 * The id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * `product` or `variation`.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The variation's product, or 0.
	 */
	public function parent_id(): int {
		return $this->parent_id;
	}

	/**
	 * The WooCommerce object, read-only.
	 *
	 * @throws \LogicException When the object is not a WooCommerce product.
	 */
	public function product(): \WC_Product {
		if ( ! $this->product instanceof \WC_Product ) {
			throw new \LogicException( 'This object is not a WooCommerce product.' );
		}
		return $this->product;
	}

	/**
	 * A field of the asking provider as the change has it so far.
	 *
	 * @param string $field Field key.
	 * @return mixed The value, or an Unknown.
	 */
	public function value( string $field ): mixed {
		if ( array_key_exists( $field, $this->overlay ) ) {
			return $this->overlay[ $field ];
		}
		return $this->read( $field );
	}

	/**
	 * The value the provider reads for one of its fields, read once; an
	 * Unknown when it cannot be read, including when the read throws.
	 *
	 * @param string $field Field key.
	 * @return mixed The value, or an Unknown.
	 */
	public function read( string $field ): mixed {
		if ( ! array_key_exists( $field, $this->read ) ) {
			$this->read[ $field ] = $this->fresh( $field );
		}
		return $this->read[ $field ];
	}

	/**
	 * The value read now, not from what was read before (settling and replay).
	 *
	 * @param string $field Field key.
	 * @return mixed The value, or an Unknown.
	 */
	public function fresh( string $field ): mixed {
		$external = $this->provider->field( $field );
		if ( null === $external ) {
			return Unknown::because( Refusals::error_sentence(), Refusals::ERROR );
		}
		try {
			$value = Guard::run( $this->provider->id, 'read', fn(): mixed => $external->read( $this ) );
		} catch ( ProviderFailure $e ) {
			return Unknown::because( Refusals::error_sentence(), Refusals::ERROR );
		}
		return $value instanceof Unknown || Envelope::plain( $value ) ? $value : Unknown::because( Refusals::error_sentence(), Refusals::ERROR );
	}

	/**
	 * Keep what a step computed, so later steps see it.
	 *
	 * @param string $field Field key.
	 * @param mixed  $value The value.
	 */
	public function overlay( string $field, mixed $value ): void {
		$this->overlay[ $field ] = $value;
	}

	/**
	 * The loaded object.
	 */
	public function loaded(): object {
		return $this->product;
	}
}
