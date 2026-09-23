<?php
/**
 * A seeded four-level category tree with products, for the T21 tests: the
 * terms get_terms() returns, their stored order, the grouped rows of the
 * earlier count statement for a status (Tree::counts()' groups), what the
 * counts statement answers (answer()), and the naive per-node counts the
 * tree's counts must equal.
 *
 * Clothing (1) > Men (2) > Shirts (3) > Formal (4); Clothing > Women (5);
 * Shoes (6) > Boots (7) > Hiking (8), whose products are the only ones under
 * Shoes; Orphans (9), whose parent 99 was removed; Loop A (10) and Loop B
 * (11), each the other's parent.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

final class CatalogueFixture {
	/**
	 * Terms: id => [ name, slug, stored parent ].
	 */
	public const TERMS = [
		1  => [ 'Clothing', 'clothing', 0 ],
		2  => [ 'Men', 'men', 1 ],
		3  => [ 'Shirts', 'shirts', 2 ],
		4  => [ 'Formal &amp; Office', 'formal', 3 ],
		5  => [ 'Women', 'women', 1 ],
		6  => [ 'Shoes', 'shoes', 0 ],
		7  => [ 'Boots', 'boots', 6 ],
		8  => [ 'Hiking', 'hiking', 7 ],
		9  => [ 'Orphans', 'orphans', 99 ],
		10 => [ 'Loop A', 'loop-a', 11 ],
		11 => [ 'Loop B', 'loop-b', 10 ],
	];

	/**
	 * Stored order: Women before Men.
	 */
	public const ORDERS = [
		2 => '2',
		5 => '1',
	];

	/**
	 * Products: id => [ category ids, status ].
	 */
	public const PRODUCTS = [
		101 => [ [ 4 ], 'publish' ],
		102 => [ [ 3, 4 ], 'publish' ],
		103 => [ [ 2 ], 'draft' ],
		104 => [ [ 5 ], 'publish' ],
		105 => [ [ 8 ], 'publish' ],
		106 => [ [ 1, 5 ], 'publish' ],
		107 => [ [ 9 ], 'draft' ],
		108 => [ [ 8 ], 'draft' ],
		109 => [ [ 11 ], 'publish' ],
	];

	/**
	 * Effective parents once the tree is built: the orphan and the lower id
	 * of the loop go to the top level.
	 */
	public const PARENTS = [
		1  => 0,
		2  => 1,
		3  => 2,
		4  => 3,
		5  => 1,
		6  => 0,
		7  => 6,
		8  => 7,
		9  => 0,
		10 => 0,
		11 => 10,
	];

	/**
	 * The terms as get_terms() returns them, shuffled out of hierarchy order.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int}> $terms Terms; TERMS by default.
	 * @return list<object>
	 */
	public static function terms( array $terms = self::TERMS ): array {
		$list = [];
		foreach ( array_reverse( $terms, true ) as $id => [ $name, $slug, $parent ] ) {
			$list[] = (object) [
				'term_id'  => $id,
				'name'     => $name,
				'slug'     => $slug,
				'parent'   => $parent,
				'taxonomy' => 'product_cat',
			];
		}
		return $list;
	}

	/**
	 * Store the terms and their order in the stubs.
	 */
	public static function seed(): void {
		$GLOBALS['bcm_test']['term_list'] = self::terms();
		foreach ( self::ORDERS as $id => $order ) {
			$GLOBALS['bcm_test']['term_meta'][ $id ]['order'] = $order;
		}
	}

	/**
	 * The rows the grouped statement returns, as MySQL would: products
	 * grouped by their sorted, comma-joined category ids.
	 *
	 * @param string[]|null $statuses Statuses that match; any when null.
	 * @return list<array{terms: string, products: string}>
	 */
	public static function rows( ?array $statuses = null ): array {
		$groups = [];
		foreach ( self::PRODUCTS as [ $ids, $status ] ) {
			if ( null !== $statuses && ! in_array( $status, $statuses, true ) ) {
				continue;
			}
			sort( $ids );
			$key            = implode( ',', $ids );
			$groups[ $key ] = ( $groups[ $key ] ?? 0 ) + 1;
		}
		$rows = [];
		foreach ( $groups as $key => $count ) {
			$rows[] = [
				'terms'    => (string) $key,
				'products' => (string) $count,
			];
		}
		return $rows;
	}

	/**
	 * What MySQL answers to Counts' statement: the statement's own (category,
	 * ancestor) pairs are read back from it, and each product counts once as
	 * `own` on every category it carries and once in `total` on every
	 * ancestor one of its categories pairs with. A statement filtered to
	 * drafts counts only draft products.
	 *
	 * @param array<int, array{0: list<int>, 1: string}> $products Products: id => [ category ids, status ]; PRODUCTS by default.
	 * @return \Closure( string ): list<array{term_id: string, own: string, total: string}>
	 */
	public static function answer( array $products = self::PRODUCTS ): \Closure {
		return static function ( string $sql ) use ( $products ): array {
			if ( 1 !== preg_match( '/INNER JOIN \(SELECT (\d+) AS term_id, (\d+) AS ancestor((?: UNION ALL SELECT \d+, \d+)*)\) AS a /', $sql, $m ) ) {
				throw new \LogicException( "Not the counts statement: $sql" );
			}
			preg_match_all( '/ UNION ALL SELECT (\d+), (\d+)/', $m[3], $more, PREG_SET_ORDER );
			$pairs    = array_merge( [ [ (int) $m[1], (int) $m[2] ] ], array_map( static fn( array $x ): array => [ (int) $x[1], (int) $x[2] ], $more ) );
			$statuses = str_contains( $sql, "p.post_status IN ('draft')" ) ? [ 'draft' ] : null;
			$own      = [];
			$total    = [];
			foreach ( $products as [ $ids, $status ] ) {
				if ( null !== $statuses && ! in_array( $status, $statuses, true ) ) {
					continue;
				}
				$reach = [];
				foreach ( $pairs as [ $term, $ancestor ] ) {
					if ( in_array( $term, $ids, true ) ) {
						$reach[ $ancestor ] = true;
						$own[ $ancestor ]   = ( $own[ $ancestor ] ?? 0 ) + ( $term === $ancestor ? 1 : 0 );
					}
				}
				foreach ( array_keys( $reach ) as $ancestor ) {
					$total[ $ancestor ] = ( $total[ $ancestor ] ?? 0 ) + 1;
				}
			}
			$rows = [];
			foreach ( $total as $id => $count ) {
				$rows[] = [
					'term_id' => (string) $id,
					'own'     => (string) ( $own[ $id ] ?? 0 ),
					'total'   => (string) $count,
				];
			}
			return $rows;
		};
	}

	/**
	 * Naive counts, one node at a time: products carrying the node, and
	 * products carrying the node or any node below it.
	 *
	 * @param string[]|null $statuses Statuses that match; any when null.
	 * @return array<int, array{0: int, 1: int}> id => [ own, including descendants ].
	 */
	public static function naive( ?array $statuses = null ): array {
		$counts = [];
		foreach ( array_keys( self::PARENTS ) as $node ) {
			$below = self::subtree( $node );
			$own   = 0;
			$total = 0;
			foreach ( self::PRODUCTS as [ $ids, $status ] ) {
				if ( null !== $statuses && ! in_array( $status, $statuses, true ) ) {
					continue;
				}
				$own   += in_array( $node, $ids, true ) ? 1 : 0;
				$total += [] !== array_intersect( $ids, $below ) ? 1 : 0;
			}
			$counts[ $node ] = [ $own, $total ];
		}
		return $counts;
	}

	/**
	 * A node and every node below it.
	 *
	 * @param int $node Node id.
	 * @return list<int>
	 */
	public static function subtree( int $node ): array {
		$ids = [ $node ];
		foreach ( self::PARENTS as $id => $parent ) {
			if ( $parent === $node ) {
				$ids = array_merge( $ids, self::subtree( $id ) );
			}
		}
		return $ids;
	}

	/**
	 * Flatten a nested tree depth first: id => node without children.
	 *
	 * @param list<array<string, mixed>> $tree Nested tree.
	 * @return array<int, array<string, mixed>>
	 */
	public static function flatten( array $tree ): array {
		$flat = [];
		foreach ( $tree as $node ) {
			$children = $node['children'];
			unset( $node['children'] );
			$flat[ $node['id'] ] = $node;
			$flat               += self::flatten( $children );
		}
		return $flat;
	}
}
