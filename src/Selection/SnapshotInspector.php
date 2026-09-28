<?php
/**
 * Bounded inspection of frozen identities under present read permissions.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
defined( 'ABSPATH' ) || exit;

/** Only a page receives metadata; unavailable identities retain their slots. */
final class SnapshotInspector {
	/**
	 * Inspect without broadening the set or disclosing inaccessible metadata.
	 *
	 * @param array<string,mixed> $row Authorized snapshot.
	 * @param array<string,mixed> $input Page and visible probe IDs.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On unbounded input.
	 */
	public function inspect( array $row, array $input ): array {
		$page     = $input['page'] ?? 1;
		$per_page = $input['per_page'] ?? 25;
		$probe    = $input['probe_ids'] ?? [];
		if ( ! is_int( $page ) || $page < 1 || $page > Selection::MAX_OBJECTS || ! is_int( $per_page ) || $per_page < 1 || $per_page > 100 || ! is_array( $probe ) || ! array_is_list( $probe ) || count( $probe ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid inspection page or probe.' );
		}
		foreach ( $probe as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new \InvalidArgumentException( 'Probe identities must be positive integers.' );
			}
		}
		$members = json_decode( $row['members'], true );
		$types   = array_fill_keys( $members['products'], 'product' ) + array_fill_keys( $members['variations'], 'product_variation' );
		ksort( $types, SORT_NUMERIC );
		$window  = array_slice( $types, ( $page - 1 ) * $per_page, $per_page, true );
		$query   = new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ) );
		$present = $query->snapshot_members( array_keys( $window ) );
		$present = array_fill_keys( array_merge( $present['products'], $present['variations'] ), true );
		_prime_post_caches( array_keys( $present ), false, false );
		$parents = [];
		foreach ( $window as $id => $type ) {
			$post = isset( $present[ $id ] ) ? get_post( $id ) : null;
			if ( 'product_variation' === $type && $post && $post->post_parent > 0 ) {
				$parents[] = (int) $post->post_parent;
			}
		}
		$parent_members = $query->snapshot_members( array_values( array_unique( $parents ) ) );
		$parents        = array_fill_keys( $parent_members['products'], true );
		_prime_post_caches( array_keys( $parents ), false, false );
		$readable = [];
		foreach ( $window as $id => $type ) {
			$post = isset( $present[ $id ] ) ? get_post( $id ) : null;
			if ( ! $this->readable( $post, $type ) ) {
				continue;
			}
			$parent = 'product_variation' === $type ? (int) $post->post_parent : 0;
			if ( 'product_variation' === $type && ( ! isset( $parents[ $parent ] ) || ! $this->readable( get_post( $parent ), 'product' ) ) ) {
				continue;
			}
			$readable[] = [
				'id'        => $id,
				'parent_id' => $parent,
			];
		}
		$hydrated = ( new Hydrator( Registry::instance() ) )->hydrate( $readable, [ 'id', 'name', 'sku', 'type' ] );
		$by_id    = array_column( $hydrated, null, 'id' );
		$items    = [];
		foreach ( $window as $id => $type ) {
			$item    = [
				'id'          => $id,
				'object_type' => $type,
				'state'       => isset( $by_id[ $id ] ) ? 'available' : 'unavailable',
				'removal'     => [
					'token' => SnapshotToken::issue( $row ),
					'id'    => $id,
				],
			];
			$items[] = $item + ( $by_id[ $id ] ?? [] );
		}
		return [
			'items'        => $items,
			'pagination'   => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => (int) $row['object_count'],
				'total_pages' => (int) ceil( $row['object_count'] / $per_page ),
			],
			'selected_ids' => array_values( array_unique( array_filter( $probe, static fn( int $id ): bool => isset( $types[ $id ] ) ) ) ),
		];
	}

	/**
	 * Check identity/type/status and current per-object read authorization.
	 *
	 * @param \WP_Post|null $post Native cached post.
	 * @param string $type Frozen native post type.
	 */
	private function readable( ?\WP_Post $post, string $type ): bool {
		return null !== $post && $type === $post->post_type && in_array( $post->post_status, ProductQuery::STATUSES, true ) && current_user_can( 'read_post', $post->ID );
	}
}
