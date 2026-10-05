<?php
/**
 * The core's CategorySubject (docs/EXTENSIONS-v2.md §5.2): one product
 * category the core has read straight from the term tables (never through
 * a term query another plugin may filter for the viewer, or for a
 * background run with no user) and checked. value() answers what an
 * earlier step of the same change computed, or the value its provider read,
 * read once. parent_id() is always 0 and product() throws, so no code
 * written for products reads a category as a product or a variation.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\CategorySubject;
use BulkCatalogueManager\Extension\ObjectRef;
use BulkCatalogueManager\Extension\Unknown;

defined( 'ABSPATH' ) || exit;

/**
 * A category and its overlay.
 */
final class TermSubject implements CategorySubject {
	/**
	 * The taxonomy every category subject belongs to.
	 */
	public const TAXONOMY = ActionDescriptor::TARGET_CATEGORY;

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
	 * A category for one provider.
	 *
	 * @param \WP_Term   $term     The category, read unfiltered.
	 * @param Registered $provider The provider asking.
	 */
	public function __construct( private readonly \WP_Term $term, private readonly Registered $provider ) {}

	/**
	 * The category with this id as the term tables hold it now, unfiltered,
	 * or null when there is none in product_cat.
	 *
	 * @param int $id Term id.
	 */
	public static function load( int $id ): ?\WP_Term {
		$row = $id > 0 ? CategoryTree::stored_term( $id ) : null;
		if ( null === $row ) {
			return null;
		}
		$row->term_id  = (int) $row->term_id;
		$row->parent   = (int) ( $row->parent ?? 0 );
		$row->taxonomy = self::TAXONOMY;
		return new \WP_Term( $row );
	}

	/**
	 * The term id.
	 */
	public function id(): int {
		return (int) $this->term->term_id;
	}

	/**
	 * `product_cat`.
	 */
	public function type(): string {
		return self::TAXONOMY;
	}

	/**
	 * Always 0: a category is never a variation's product.
	 */
	public function parent_id(): int {
		return 0;
	}

	/**
	 * A category is not a product.
	 *
	 * @throws \LogicException Always.
	 */
	public function product(): \WC_Product {
		throw new \LogicException( 'A category is not a WooCommerce product; use term().' );
	}

	/**
	 * The typed reference.
	 */
	public function ref(): ObjectRef {
		return ObjectRef::term( self::TAXONOMY, $this->id() );
	}

	/**
	 * `product_cat`.
	 */
	public function taxonomy(): string {
		return self::TAXONOMY;
	}

	/**
	 * The category, read-only.
	 */
	public function term(): \WP_Term {
		return $this->term;
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
	 * The value the provider reads, read once; an Unknown when it cannot be
	 * read, including when the read throws.
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
	 * The loaded category.
	 */
	public function loaded(): object {
		return $this->term;
	}
}
