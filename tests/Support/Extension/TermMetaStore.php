<?php
/**
 * The test provider's category storage: term meta.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

/**
 * Term meta, by term id.
 */
final class TermMetaStore implements SampleStore {
	/**
	 * A stored string, or null.
	 *
	 * @param int    $id  Term id.
	 * @param string $key Meta key.
	 */
	public function get( int $id, string $key ): ?string {
		if ( ! metadata_exists( 'term', $id, $key ) ) {
			return null;
		}
		$value = get_term_meta( $id, $key, true );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Store a string.
	 *
	 * @param int    $id    Term id.
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 */
	public function set( int $id, string $key, string $value ): void {
		update_term_meta( $id, $key, wp_slash( $value ) );
	}

	/**
	 * Remove a value.
	 *
	 * @param int    $id  Term id.
	 * @param string $key Meta key.
	 */
	public function delete( int $id, string $key ): void {
		delete_term_meta( $id, $key );
	}

	/**
	 * Load the meta of several terms at once.
	 *
	 * @param list<int> $ids Term ids.
	 */
	public function prime( array $ids ): void {
		if ( [] !== $ids ) {
			update_meta_cache( 'term', $ids );
		}
	}
}
