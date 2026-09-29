<?php
/**
 * The sample provider's store in unit tests: an array. Counts writes, so a
 * test can prove a preview wrote nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * An in-memory store.
 */
final class MemoryStore implements SampleStore {
	/**
	 * Raw values by object id, then key.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $data = [];

	/**
	 * Every write, as [ verb, id, key ].
	 *
	 * @var list<array{0: string, 1: int, 2: string}>
	 */
	public array $writes = [];

	/**
	 * Primed id lists, in order.
	 *
	 * @var list<list<int>>
	 */
	public array $primed = [];

	/**
	 * The raw stored text, or null.
	 *
	 * @param int    $id  Object.
	 * @param string $key Key.
	 */
	public function get( int $id, string $key ): ?string {
		return $this->data[ $id ][ $key ] ?? null;
	}

	/**
	 * Store raw text.
	 *
	 * @param int    $id    Object.
	 * @param string $key   Key.
	 * @param string $value Raw text.
	 */
	public function set( int $id, string $key, string $value ): void {
		$this->writes[]            = [ 'set', $id, $key ];
		$this->data[ $id ][ $key ] = $value;
	}

	/**
	 * Remove a value.
	 *
	 * @param int    $id  Object.
	 * @param string $key Key.
	 */
	public function delete( int $id, string $key ): void {
		$this->writes[] = [ 'delete', $id, $key ];
		unset( $this->data[ $id ][ $key ] );
	}

	/**
	 * Record a prime.
	 *
	 * @param list<int> $ids Objects.
	 */
	public function prime( array $ids ): void {
		$this->primed[] = $ids;
	}
}
