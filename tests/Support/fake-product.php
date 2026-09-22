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
