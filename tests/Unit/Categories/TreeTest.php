<?php
/**
 * The category tree built in PHP from a flat term list (T21; SPEC §7):
 * nesting, depth, sibling order, a term whose parent was removed and a loop
 * of parents; own counts and counts including descendants from product
 * groups equal a naive per-node count.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Tree;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';

final class TreeTest extends TestCase {
	/**
	 * Groups from fixture rows, as Counts::groups() parses them.
	 *
	 * @param string[]|null $statuses Statuses that match.
	 * @return list<array{0: list<int>, 1: int}>
	 */
	private static function product_groups( ?array $statuses = null ): array {
		return array_map(
			static fn( array $row ): array => [ array_map( 'intval', explode( ',', $row['terms'] ) ), (int) $row['products'] ],
			CatalogueFixture::rows( $statuses )
		);
	}

	/**
	 * The nested tree reduced to names, to read its shape at a glance.
	 *
	 * @param list<array<string, mixed>> $tree Nested tree.
	 * @return array<string, mixed>
	 */
	private static function shape( array $tree ): array {
		$shape = [];
		foreach ( $tree as $node ) {
			$shape[ $node['name'] ] = self::shape( $node['children'] );
		}
		return $shape;
	}

	public function test_a_flat_list_nests_in_order_with_orphans_and_loops_at_the_top(): void {
		$tree = Tree::from_terms( CatalogueFixture::terms(), CatalogueFixture::ORDERS )->to_array();
		$this->assertSame(
			[
				'Clothing' => [
					'Women' => [],
					'Men'   => [
						'Shirts' => [
							'Formal & Office' => [],
						],
					],
				],
				'Loop A'   => [
					'Loop B' => [],
				],
				'Orphans'  => [],
				'Shoes'    => [
					'Boots' => [
						'Hiking' => [],
					],
				],
			],
			self::shape( $tree )
		);
	}

	public function test_each_node_carries_id_name_slug_description_parent_depth_and_order(): void {
		$flat = CatalogueFixture::flatten( Tree::from_terms( CatalogueFixture::terms(), CatalogueFixture::ORDERS )->to_array() );
		$this->assertSame(
			[
				'id'                   => 4,
				'name'                 => 'Formal & Office',
				'slug'                 => 'formal',
				'description'          => '',
				'parent'               => 3,
				'depth'                => 3,
				'order'                => 0,
				'count'                => 0,
				'countWithDescendants' => 0,
			],
			$flat[4]
		);
		$this->assertSame( 2, $flat[2]['order'] );
		$this->assertSame( 1, $flat[5]['order'] );
		foreach ( CatalogueFixture::PARENTS as $id => $parent ) {
			$this->assertSame( $parent, $flat[ $id ]['parent'], "Parent of $id." );
			$this->assertSame( 0 === $parent ? 0 : $flat[ $parent ]['depth'] + 1, $flat[ $id ]['depth'], "Depth of $id." );
		}
		$this->assertCount( count( CatalogueFixture::TERMS ), $flat, 'Every term appears exactly once.' );

		$described = Tree::from_terms(
			[
				(object) [
					'term_id'     => 1,
					'name'        => 'Hats',
					'slug'        => 'hats',
					'description' => '<em>Warm</em> & dry',
					'parent'      => 0,
				],
			]
		)->node( 1 );
		$this->assertSame( '<em>Warm</em> & dry', $described['description'], 'The stored description, unchanged, for the pane to show as text.' );
	}

	public function test_a_removed_parent_or_a_self_parent_puts_the_term_at_the_top(): void {
		$tree = Tree::from_terms(
			CatalogueFixture::terms(
				[
					5 => [ 'Self', 'self', 5 ],
					6 => [ 'Lost', 'lost', 42 ],
					7 => [ 'Child of lost', 'child', 6 ],
				]
			)
		)->to_array();
		$this->assertSame( [ 'Lost', 'Self' ], array_column( $tree, 'name' ) );
		$this->assertSame( [ 0, 0 ], array_column( $tree, 'parent' ) );
		$this->assertSame( 1, $tree[0]['children'][0]['depth'] );
	}

	public function test_names_sort_naturally_without_case_when_orders_tie(): void {
		$tree = Tree::from_terms(
			CatalogueFixture::terms(
				[
					1 => [ 'size 10', 'a', 0 ],
					2 => [ 'Size 9', 'b', 0 ],
					3 => [ 'apple', 'c', 0 ],
					4 => [ 'apple', 'd', 0 ],
				]
			)
		)->to_array();
		$this->assertSame( [ 3, 4, 2, 1 ], array_column( $tree, 'id' ) );
	}

	public function test_counts_equal_a_naive_count_per_node(): void {
		foreach ( [ null, [ 'publish' ], [ 'draft' ] ] as $statuses ) {
			$flat  = CatalogueFixture::flatten( Tree::from_terms( CatalogueFixture::terms() )->to_array( self::product_groups( $statuses ) ) );
			$label = null === $statuses ? 'any status' : implode( ',', $statuses );
			foreach ( CatalogueFixture::naive( $statuses ) as $id => [ $own, $total ] ) {
				$this->assertSame( $own, $flat[ $id ]['count'], "Own count of $id, $label." );
				$this->assertSame( $total, $flat[ $id ]['countWithDescendants'], "Count including descendants of $id, $label." );
			}
		}
	}

	public function test_a_parent_whose_only_products_sit_on_a_grandchild_counts_them(): void {
		$flat = CatalogueFixture::flatten( Tree::from_terms( CatalogueFixture::terms() )->to_array( self::product_groups() ) );
		$this->assertSame( [ 0, 2 ], [ $flat[6]['count'], $flat[6]['countWithDescendants'] ] );
		$this->assertSame( [ 0, 2 ], [ $flat[7]['count'], $flat[7]['countWithDescendants'] ] );
		$this->assertSame( [ 2, 2 ], [ $flat[8]['count'], $flat[8]['countWithDescendants'] ] );
	}

	public function test_a_product_in_a_child_and_a_grandchild_counts_once_on_each_ancestor(): void {
		$counts = Tree::from_terms( CatalogueFixture::terms() )->counts( [ [ [ 1, 3, 4 ], 1 ] ] );
		$this->assertSame( [ 1, 0, 1, 1 ], [ $counts['own'][1], $counts['own'][2], $counts['own'][3], $counts['own'][4] ] );
		$this->assertSame( [ 1, 1, 1, 1 ], [ $counts['total'][1], $counts['total'][2], $counts['total'][3], $counts['total'][4] ] );
		$this->assertSame( 0, $counts['total'][5] );
	}

	public function test_unknown_ids_in_a_group_are_ignored(): void {
		$counts = Tree::from_terms( CatalogueFixture::terms() )->counts( [ [ [ 404, 8 ], 3 ] ] );
		$this->assertSame( 3, $counts['own'][8] );
		$this->assertSame( 3, $counts['total'][6] );
		$this->assertArrayNotHasKey( 404, $counts['own'] );
	}

	public function test_no_terms_is_an_empty_tree(): void {
		$tree = Tree::from_terms( [] );
		$this->assertTrue( $tree->is_empty() );
		$this->assertSame( [], $tree->to_array( [ [ [ 1 ], 1 ] ] ) );
	}
}
