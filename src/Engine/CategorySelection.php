<?php
/**
 * The exact categories a category change covers (docs/EXTENSIONS-v2.md
 * §5.3): an explicit list of term ids the operator chose, never "all
 * categories" by inference. With Include subcategories off (the default) a
 * parent targets itself only; on, each chosen category is expanded to its
 * descendants once, here, at preview, duplicates from overlapping branches
 * removed and the set sorted. The tree is read straight from the term
 * tables (Categories\CategoryTree::complete()), never through a term query
 * another plugin may filter for the viewer.
 *
 * The scope hash binds the taxonomy, the choice, the switch, the captured
 * set and which of it exists now, so Apply refuses when a category was
 * added under a chosen parent, moved, or deleted since the preview
 * (`scope_changed`) instead of widening or narrowing the set silently. A
 * chosen id that is not a product category is kept in the set as
 * unavailable: recorded failed, never written.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\Tree;
use BulkCatalogueManager\External\TermSubject;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and checks category scopes.
 */
final class CategorySelection {
	/**
	 * Most categories in one change.
	 */
	public const MAX = 1000;

	/**
	 * Context mixed into the scope hash.
	 */
	private const CONTEXT = 'bulk-catalogue-manager-for-woocommerce/category-scope/1/';

	/**
	 * Chosen term ids as sent, sorted and each once.
	 *
	 * @param mixed $ids The ids.
	 * @return list<int>
	 * @throws \InvalidArgumentException When it is not a list of 1 to MAX positive integers.
	 * @throws \LengthException          When it holds more than MAX.
	 */
	public static function ids( mixed $ids ): array {
		if ( ! is_array( $ids ) || [] === $ids || ! array_is_list( $ids ) ) {
			throw new \InvalidArgumentException( 'Choose categories as a list of ids.' );
		}
		$clean = [];
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) ) {
				throw new \InvalidArgumentException( 'A category id is a positive integer.' );
			}
			if ( (int) $id < 1 ) {
				throw new \InvalidArgumentException( 'A category id is a positive integer.' );
			}
			$clean[ (int) $id ] = true;
		}
		if ( count( $clean ) > self::MAX ) {
			throw new \LengthException( 'Too many categories.' );
		}
		$clean = array_keys( $clean );
		sort( $clean, SORT_NUMERIC );
		return $clean;
	}

	/**
	 * The scope of a choice, as it stands now.
	 *
	 * @param list<int> $chosen        Chosen term ids, from ids().
	 * @param bool      $subcategories Whether to include each chosen category's descendants.
	 * @param Tree|null $tree          The complete tree; read unfiltered when null.
	 * @return array{chosen: list<int>, subcategories: bool, ids: list<int>, available: list<int>, unavailable: list<int>, scope_hash: string}
	 * @throws \LengthException When the expanded set holds more than MAX.
	 */
	public static function build( array $chosen, bool $subcategories, ?Tree $tree = null ): array {
		$tree ??= CategoryTree::complete();
		$set    = [];
		foreach ( $chosen as $id ) {
			$set[ $id ] = true;
			if ( $subcategories && null !== $tree->node( $id ) ) {
				$stack = $tree->children( $id );
				while ( [] !== $stack ) {
					$child = (int) array_pop( $stack );
					if ( ! isset( $set[ $child ] ) ) {
						$set[ $child ] = true;
						$stack         = [ ...$stack, ...$tree->children( $child ) ];
					}
					if ( count( $set ) > self::MAX ) {
						throw new \LengthException( 'Too many categories.' );
					}
				}
			}
		}
		if ( count( $set ) > self::MAX ) {
			throw new \LengthException( 'Too many categories.' );
		}
		$ids = array_keys( $set );
		sort( $ids, SORT_NUMERIC );
		$available   = array_values( array_filter( $ids, static fn( int $id ): bool => null !== $tree->node( $id ) ) );
		$unavailable = array_values( array_diff( $ids, $available ) );
		return [
			'chosen'        => $chosen,
			'subcategories' => $subcategories,
			'ids'           => $ids,
			'available'     => $available,
			'unavailable'   => $unavailable,
			'scope_hash'    => self::hash( $chosen, $subcategories, $ids, $unavailable, $tree ),
		];
	}

	/**
	 * The scope hash: the choice, the captured set, what of it is gone, and
	 * each member's parent now (a move changes the scope's meaning).
	 *
	 * @param list<int> $chosen        Chosen ids.
	 * @param bool      $subcategories The switch.
	 * @param list<int> $ids           The captured set.
	 * @param list<int> $unavailable   Members that are gone.
	 * @param Tree      $tree          The complete tree.
	 */
	private static function hash( array $chosen, bool $subcategories, array $ids, array $unavailable, Tree $tree ): string {
		$parents = [];
		foreach ( $ids as $id ) {
			$node      = $tree->node( $id );
			$parents[] = null === $node ? -1 : (int) ( $node['parent'] ?? 0 );
		}
		$payload = [
			'taxonomy'      => TermSubject::TAXONOMY,
			'chosen'        => $chosen,
			'subcategories' => $subcategories,
			'ids'           => $ids,
			'parents'       => $parents,
			'unavailable'   => $unavailable,
		];
		return hash_hmac( 'sha256', self::CONTEXT . (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	/**
	 * The refusal for a choice of more than MAX categories.
	 */
	public static function too_large(): \WP_Error {
		return \BulkCatalogueManager\Rest\Refusal::invalid(
			'bulkcat_selection_too_large',
			sprintf(
				/* translators: %s: the most categories one change may cover, such as "1,000". */
				__( 'One change can cover at most %s categories. Choose fewer categories, or turn off Include subcategories, and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( self::MAX )
			)
		);
	}
}
