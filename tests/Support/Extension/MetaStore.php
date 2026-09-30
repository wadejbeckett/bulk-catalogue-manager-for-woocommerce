<?php
/**
 * The sample provider's store on a real site: product post meta, as a
 * separately installed plugin would keep its own values.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * Post meta.
 */
final class MetaStore implements SampleStore {
	/**
	 * The raw stored text, or null.
	 *
	 * @param int    $id  Object.
	 * @param string $key Meta key.
	 */
	public function get( int $id, string $key ): ?string {
		if ( ! metadata_exists( 'post', $id, $key ) ) {
			return null;
		}
		$value = get_post_meta( $id, $key, true );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Store raw text; slashed, as update_post_meta() unslashes.
	 *
	 * @param int    $id    Object.
	 * @param string $key   Meta key.
	 * @param string $value Raw text.
	 */
	public function set( int $id, string $key, string $value ): void {
		update_post_meta( $id, $key, wp_slash( $value ) );
	}

	/**
	 * Remove a value.
	 *
	 * @param int    $id  Object.
	 * @param string $key Meta key.
	 */
	public function delete( int $id, string $key ): void {
		delete_post_meta( $id, $key );
	}

	/**
	 * Load the objects' meta in one query.
	 *
	 * @param list<int> $ids Objects.
	 */
	public function prime( array $ids ): void {
		if ( [] !== $ids ) {
			update_meta_cache( 'post', $ids );
		}
	}
}
