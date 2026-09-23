<?php
/**
 * T37: a stand-in for a loaded WooCommerce product whose save() also stores
 * each prop it was given as the `_<prop>` meta fixture the read stubs
 * hydrate from, so a row re-read after the write shows the new value.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

/**
 * A loaded product whose save() also stores what was set as its meta
 * fixtures (`_<prop>`), so the read stubs hydrate the saved values.
 */
final class StoredProduct {
	/**
	 * Setter calls, in order: [ method, value ].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	public array $sets = [];

	/**
	 * How many times save() ran.
	 */
	public int $saves = 0;

	public function __construct( private string $type = 'simple', public array $props = [] ) {}

	public function is_type( string $type ): bool {
		return $type === $this->type;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function save(): int {
		++$this->saves;
		$id = (int) ( $this->props['id'] ?? 0 );
		foreach ( $this->sets as [ $method ] ) {
			$prop  = substr( $method, 4 );
			$value = $this->props[ $prop ];
			$GLOBALS['bcm_test']['meta'][ $id ][ '_' . $prop ] = is_scalar( $value ) || null === $value ? (string) $value : $value;
		}
		return $id;
	}

	public function __call( string $method, array $args ): mixed {
		if ( str_starts_with( $method, 'get_' ) ) {
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
