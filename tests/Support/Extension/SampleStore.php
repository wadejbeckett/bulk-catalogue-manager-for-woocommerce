<?php
/**
 * Where the sample provider keeps its values: product post meta on a real
 * site (MetaStore), an array in unit tests (MemoryStore). Test code only;
 * never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * A raw key-value store per product.
 */
interface SampleStore {
	/**
	 * The raw stored text, or null when nothing is stored.
	 *
	 * @param int    $id  Product or variation.
	 * @param string $key Storage key.
	 */
	public function get( int $id, string $key ): ?string;

	/**
	 * Store raw text.
	 *
	 * @param int    $id    Product or variation.
	 * @param string $key   Storage key.
	 * @param string $value Raw text.
	 */
	public function set( int $id, string $key, string $value ): void;

	/**
	 * Remove a stored value.
	 *
	 * @param int    $id  Product or variation.
	 * @param string $key Storage key.
	 */
	public function delete( int $id, string $key ): void;

	/**
	 * Load the values of several objects in one go (or nothing).
	 *
	 * @param list<int> $ids Objects.
	 */
	public function prime( array $ids ): void;
}
