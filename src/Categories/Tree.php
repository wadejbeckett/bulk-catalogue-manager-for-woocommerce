<?php
/**
 * The product category hierarchy as the category panel reads it (SPEC §7,
 * §4.7): built in PHP from one flat list of terms, each node carrying its
 * id, name, slug, parent, depth and sibling order, then its own product
 * count and its count including descendants.
 *
 * A term whose parent is not in the list (removed, or in a loop of parents)
 * becomes a top-level node and reports parent 0, so the tree is always a
 * forest the panel can draw. Siblings sort by the plugin's own tree order
 * (`bcm_order`, written by a move) first, then those without one by
 * WooCommerce's stored category order, then by name, then by id; a node's
 * `order` is whichever of the two decided its place.
 *
 * Counts come from Counts::totals() over the tree's own (category,
 * ancestor) pairs (to_array_counted()), or from groups: each group a set of
 * category ids some number of products carry exactly (to_array()). Either
 * way a product counts once on every category it has (its own count) and
 * once on each of those categories and all their ancestors (its count
 * including descendants), so a product in both a child and a grandchild is
 * not counted twice on the parent.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

defined( 'ABSPATH' ) || exit;

/**
 * A category forest.
 */
final class Tree {
	/**
	 * Nodes by id: id, name, slug, parent, depth, order.
	 *
	 * @var array<int, array{id: int, name: string, slug: string, parent: int, depth: int, order: int}>
	 */
	private array $nodes = [];

	/**
	 * Child ids in display order by parent id; 0 holds the top-level nodes.
	 *
	 * @var array<int, list<int>>
	 */
	private array $children = [ 0 => [] ];

	/**
	 * Build from a flat term list.
	 *
	 * @param iterable<object>  $terms       Terms with term_id, name, slug and parent, as get_terms() returns them.
	 * @param array<int, mixed> $orders      WooCommerce's stored category order by term id; missing or non-numeric reads as 0.
	 * @param array<int, mixed> $tree_orders The plugin's tree order (`bcm_order`) by term id; a numeric value places the term ahead of siblings without one.
	 */
	public static function from_terms( iterable $terms, array $orders = [], array $tree_orders = [] ): self {
		$tree   = new self();
		$flat   = [];
		$ranked = [];
		foreach ( $terms as $term ) {
			$id = (int) ( $term->term_id ?? 0 );
			if ( $id <= 0 || isset( $flat[ $id ] ) ) {
				continue;
			}
			$flat[ $id ] = [
				'id'     => $id,
				'name'   => html_entity_decode( (string) ( $term->name ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'slug'   => (string) ( $term->slug ?? '' ),
				'parent' => (int) ( $term->parent ?? 0 ),
				'depth'  => 0,
				'order'  => is_numeric( $orders[ $id ] ?? null ) ? (int) $orders[ $id ] : 0,
			];
			if ( is_numeric( $tree_orders[ $id ] ?? null ) ) {
				$flat[ $id ]['order'] = (int) $tree_orders[ $id ];
				$ranked[ $id ]        = true;
			}
		}

		// Group by parent, treating an unknown or self parent as none.
		$by_parent = [];
		foreach ( $flat as $id => $node ) {
			$parent = $node['parent'];
			if ( $parent === $id || ! isset( $flat[ $parent ] ) ) {
				$parent = 0;
			}
			$flat[ $id ]['parent']  = $parent;
			$by_parent[ $parent ][] = $id;
		}
		// Tree order before none, stored order, then name in natural order ignoring case, then id.
		$compare = static fn( int $a, int $b ): int => [ isset( $ranked[ $a ] ) ? 0 : 1, $flat[ $a ]['order'], strnatcasecmp( $flat[ $a ]['name'], $flat[ $b ]['name'] ), $a ] <=> [ isset( $ranked[ $b ] ) ? 0 : 1, $flat[ $b ]['order'], 0, $b ];
		foreach ( $by_parent as $parent => $ids ) {
			usort( $ids, $compare );
			$by_parent[ $parent ] = $ids;
		}

		// Walk down from the top level. Terms caught in a loop of parents are
		// never reached: the lowest id of what is left becomes top-level, until
		// every term is placed.
		$tree->attach( 0, $by_parent, $flat );
		$left = array_diff_key( $flat, $tree->nodes );
		while ( [] !== $left ) {
			$id                    = (int) min( array_keys( $left ) );
			$flat[ $id ]['parent'] = 0;
			$tree->children[0][]   = $id;
			$tree->nodes[ $id ]    = $flat[ $id ];
			$tree->attach( $id, $by_parent, $flat );
			$left = array_diff_key( $flat, $tree->nodes );
		}
		usort( $tree->children[0], $compare );
		return $tree;
	}

	/**
	 * Place the children of one placed node (or of the top level), depth first.
	 *
	 * @param int                              $parent_id Parent id, 0 for the top level.
	 * @param array<int, list<int>>            $by_parent Sorted child ids by parent.
	 * @param array<int, array<string, mixed>> $flat      Nodes by id.
	 */
	private function attach( int $parent_id, array $by_parent, array $flat ): void {
		$stack = [ $parent_id ];
		while ( [] !== $stack ) {
			$current = (int) array_pop( $stack );
			$depth   = 0 === $current ? 0 : $this->nodes[ $current ]['depth'] + 1;
			foreach ( $by_parent[ $current ] ?? [] as $child ) {
				if ( isset( $this->nodes[ $child ] ) ) {
					continue;
				}
				$node                         = $flat[ $child ];
				$node['depth']                = $depth;
				$this->nodes[ $child ]        = $node;
				$this->children[ $current ][] = $child;
				$stack[]                      = $child;
			}
		}
	}

	/**
	 * Whether the tree has no categories.
	 */
	public function is_empty(): bool {
		return [] === $this->nodes;
	}

	/**
	 * How many categories the tree holds.
	 */
	public function size(): int {
		return count( $this->nodes );
	}

	/**
	 * One node without children or counts, or null.
	 *
	 * @param int $id Term id.
	 * @return array{id: int, name: string, slug: string, parent: int, depth: int, order: int}|null
	 */
	public function node( int $id ): ?array {
		return $this->nodes[ $id ] ?? null;
	}

	/**
	 * The ids directly below a node (or the top level), in display order.
	 *
	 * @param int $parent_id Parent id, 0 for the top level.
	 * @return list<int>
	 */
	public function children( int $parent_id ): array {
		return $this->children[ $parent_id ] ?? [];
	}

	/**
	 * A category and its ancestors, nearest first.
	 *
	 * @param int $id Term id.
	 * @return list<int> Empty when the id is not in the tree.
	 */
	public function lineage( int $id ): array {
		$line = [];
		while ( isset( $this->nodes[ $id ] ) && ! in_array( $id, $line, true ) ) {
			$line[] = $id;
			$id     = $this->nodes[ $id ]['parent'];
		}
		return $line;
	}

	/**
	 * Every (category, ancestor) pair of the tree, each node with itself
	 * first and then each node above it, as lineage() walks them: what
	 * Counts joins product categories to, so a count reaches exactly the
	 * nodes the panel draws above a category.
	 *
	 * @return list<array{0: int, 1: int}>
	 */
	public function pairs(): array {
		$pairs = [];
		foreach ( array_keys( $this->nodes ) as $id ) {
			foreach ( $this->lineage( $id ) as $ancestor ) {
				$pairs[] = [ $id, $ancestor ];
			}
		}
		return $pairs;
	}

	/**
	 * The nested array the route returns, from counts already made (Counts::totals()).
	 *
	 * @param array{own: array<int, int>, total: array<int, int>} $counts Own and including-descendants counts by id.
	 * @return list<array<string, mixed>>
	 */
	public function to_array_counted( array $counts ): array {
		return $this->branch( 0, $counts['own'], $counts['total'] );
	}

	/**
	 * Own and including-descendants counts per category from product groups.
	 * Ids not in the tree are ignored.
	 *
	 * @param iterable<array{0: list<int>, 1: int}> $groups Category ids a set of products carries, and how many products.
	 * @return array{own: array<int, int>, total: array<int, int>}
	 */
	public function counts( iterable $groups ): array {
		$own   = array_fill_keys( array_keys( $this->nodes ), 0 );
		$total = $own;
		foreach ( $groups as [ $ids, $products ] ) {
			$reach = [];
			foreach ( array_unique( $ids ) as $id ) {
				if ( ! isset( $this->nodes[ $id ] ) ) {
					continue;
				}
				$own[ $id ] += $products;
				foreach ( $this->lineage( $id ) as $ancestor ) {
					$reach[ $ancestor ] = true;
				}
			}
			foreach ( array_keys( $reach ) as $id ) {
				$total[ $id ] += $products;
			}
		}
		return [
			'own'   => $own,
			'total' => $total,
		];
	}

	/**
	 * The nested array the route returns: top-level nodes in order, each
	 * with `count`, `countWithDescendants` and `children`.
	 *
	 * @param iterable<array{0: list<int>, 1: int}> $groups Product groups; see counts().
	 * @return list<array<string, mixed>>
	 */
	public function to_array( iterable $groups = [] ): array {
		$counts = $this->counts( $groups );
		return $this->branch( 0, $counts['own'], $counts['total'] );
	}

	/**
	 * The nested nodes under one parent.
	 *
	 * @param int             $parent_id Parent id, 0 for the top level.
	 * @param array<int, int> $own       Own counts.
	 * @param array<int, int> $total     Counts including descendants.
	 * @return list<array<string, mixed>>
	 */
	private function branch( int $parent_id, array $own, array $total ): array {
		$branch = [];
		foreach ( $this->children[ $parent_id ] ?? [] as $id ) {
			$branch[] = $this->nodes[ $id ] + [
				'count'                => $own[ $id ] ?? 0,
				'countWithDescendants' => $total[ $id ] ?? 0,
				'children'             => $this->branch( $id, $own, $total ),
			];
		}
		return $branch;
	}
}
