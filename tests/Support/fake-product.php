<?php
/**
 * A stand-in for a WooCommerce product object: every get_{prop}( 'edit' )
 * returns the prop, every set_{prop}( value ) stores it and records the call,
 * is_type() answers from the type given. Lets the field tests drive the
 * getter and setter contract without WooCommerce.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support;

final class FakeProduct {
	/**
	 * Setter calls, in order: [ method, value ].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	public array $sets = [];

	/**
	 * Getter calls, in order: [ method, arguments ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public array $gets = [];

	public function __construct( private string $type = 'simple', public array $props = [] ) {}

	public function is_type( string $type ): bool {
		return $type === $this->type;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function __call( string $method, array $args ): mixed {
		if ( str_starts_with( $method, 'get_' ) ) {
			$this->gets[] = [ $method, $args ];
			return $this->props[ substr( $method, 4 ) ] ?? null;
		}
		if ( str_starts_with( $method, 'set_' ) ) {
			$this->sets[]                        = [ $method, $args[0] ];
			$this->props[ substr( $method, 4 ) ] = $args[0];
			return null;
		}
		throw new \BadMethodCallException( $method );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- T32's stand-in sits beside FakeProduct.

/**
 * T32: a stand-in for a loaded WooCommerce product the writer saves. Like
 * FakeProduct, get_{prop}() answers the prop and set_{prop}() stores and
 * records it; save() records a copy of the props at that moment in $saves.
 * A setter named in $refuse throws WC_Data_Exception with that message, as
 * WooCommerce's set_sku() does for a duplicate. get_id() and
 * get_parent_id() answer the `id` and `parent_id` props.
 */
final class SavedProduct {
	/**
	 * Setter calls, in order: [ method, value ].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	public array $sets = [];

	/**
	 * The props at each save().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Setter => message for setters that throw WC_Data_Exception.
	 *
	 * @var array<string, string>
	 */
	public array $refuse = [];

	public function __construct( private string $type = 'simple', public array $props = [] ) {}

	public function is_type( string $type ): bool {
		return $type === $this->type;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function save(): int {
		$this->saves[] = $this->props;
		return (int) ( $this->props['id'] ?? 0 );
	}

	public function __call( string $method, array $args ): mixed {
		if ( str_starts_with( $method, 'get_' ) ) {
			return $this->props[ substr( $method, 4 ) ] ?? null;
		}
		if ( str_starts_with( $method, 'set_' ) ) {
			if ( isset( $this->refuse[ $method ] ) ) {
				throw new \WC_Data_Exception( 'product_invalid_value', $this->refuse[ $method ] );
			}
			$this->sets[]                        = [ $method, $args[0] ];
			$this->props[ substr( $method, 4 ) ] = $args[0];
			return null;
		}
		throw new \BadMethodCallException( $method );
	}
}

/**
 * T30: a stand-in for a loaded WooCommerce product the preview must never
 * save. Like SavedProduct, get_{prop}() answers the prop and set_{prop}()
 * stores it; every set, save() and clone — on the object or on any copy of
 * it — is also recorded in $GLOBALS['bulkcat_test']['watched'] as
 * [ 'set' | 'save' | 'clone', id, method or null ], so a test sees a save
 * made on a detached copy too. A setter named in $refuse throws
 * WC_Data_Exception with that message.
 */
final class WatchedProduct {
	/**
	 * Setter => message for setters that throw WC_Data_Exception.
	 *
	 * @var array<string, string>
	 */
	public array $refuse = [];

	public function __construct( private string $type = 'simple', public array $props = [] ) {}

	public function __clone() {
		$GLOBALS['bulkcat_test']['watched'][] = [ 'clone', (int) ( $this->props['id'] ?? 0 ), null ];
	}

	public function is_type( string $type ): bool {
		return $type === $this->type;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function save(): int {
		$GLOBALS['bulkcat_test']['watched'][] = [ 'save', (int) ( $this->props['id'] ?? 0 ), null ];
		return (int) ( $this->props['id'] ?? 0 );
	}

	public function __call( string $method, array $args ): mixed {
		if ( str_starts_with( $method, 'get_' ) ) {
			return $this->props[ substr( $method, 4 ) ] ?? null;
		}
		if ( str_starts_with( $method, 'set_' ) ) {
			if ( isset( $this->refuse[ $method ] ) ) {
				throw new \WC_Data_Exception( 'product_invalid_value', $this->refuse[ $method ] );
			}
			$GLOBALS['bulkcat_test']['watched'][] = [ 'set', (int) ( $this->props['id'] ?? 0 ), $method ];
			$this->props[ substr( $method, 4 ) ]  = $args[0];
			return null;
		}
		throw new \BadMethodCallException( $method );
	}
}
