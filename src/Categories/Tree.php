<?php
/**
 * The product category hierarchy as the category panel reads it (SPEC §7,
 * §4.7): built in PHP from one flat list of terms, each node carrying its
 * id, name, slug, description, parent, depth and sibling order, then its
 * own product count and its count including descendants. The description
 * is the term's stored text, unchanged, for the details pane to show as text.
 *
 * A term whose parent is not in the list (removed, or in a loop of parents)
 * becomes a top-level node and reports parent 0, so the tree is always a
 * forest the panel can draw. Siblings sort exactly as WooCommerce sorts
 * them for its own screen and the shop (`menu_order`: its `order` term meta,
 * numerically, a category without one first, then by name as the database
 * sorts names, `ORDER BY t.name`), then by id; a node's `order` is that
 * stored value (0 when there is none). The name order is the database's own
 * (1.3.4: "26WC" before "2RM", not naturally), given as ranks by
 * CategoryTree, which reads the names in that order. The plugin's
 * old tree-only order (`bulkcat_order`) is no longer read (ticket 23). The
 * shop's default product category carries `default: true`.
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
	 * Nodes by id: id, name, slug, description, parent, depth, order, default.
	 *
	 * @var array<int, array{id: int, name: string, slug: string, description: string, parent: int, depth: int, order: int, default: bool}>
	 */
	private array $nodes = [];

	/**
	 * Child ids in display order by parent id; 0 holds the top-level nodes.
	 *
	 * @var array<int, list<int>>
	 */
	private array $children = [ 0 => [] ];

	/**
	 * Each id's place in the database's name order, when known.
	 *
	 * @var array<int, int>
	 */
	private array $ranks = [];

	/**
	 * Build from a flat term list.
	 *
	 * @param iterable<object>  $terms      Terms with term_id, name, slug, description and parent, as get_terms() returns them.
	 * @param array<int, mixed> $orders     WooCommerce's stored category order (`order` term meta) by term id; missing or non-numeric is none.
	 * @param int               $default_id The shop's default product category (`default_product_cat`), 0 for none.
	 * @param array<int, int>   $ranks      Each id's place when the database sorts the names (`ORDER BY t.name`); compared in PHP when not given.
	 */
	public static function from_terms( iterable $terms, array $orders = [], int $default_id = 0, array $ranks = [] ): self {
		$tree        = new self();
		$tree->ranks = $ranks;
		$flat        = [];
		$ranked      = [];
		foreach ( $terms as $term ) {
			$id = (int) ( $term->term_id ?? 0 );
			if ( $id <= 0 || isset( $flat[ $id ] ) ) {
				continue;
			}
			$flat[ $id ] = [
				'id'          => $id,
				'name'        => html_entity_decode( (string) ( $term->name ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'slug'        => (string) ( $term->slug ?? '' ),
				'description' => (string) ( $term->description ?? '' ),
				'parent'      => (int) ( $term->parent ?? 0 ),
				'depth'       => 0,
				'order'       => is_numeric( $orders[ $id ] ?? null ) ? (int) $orders[ $id ] : 0,
				'default'     => $id === $default_id,
			];
			if ( is_numeric( $orders[ $id ] ?? null ) ) {
				$ranked[ $id ] = true;
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
		$compare = self::comparator( $flat, $ranked, $ranks );
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
	 * WooCommerce's sibling order (wc_terms_clauses(): `order` meta + 0
	 * ascending, where a missing value is NULL and sorts first, then
	 * t.name), then the id: the one rule the tree and Writer::place() share.
	 * Names compare by their database ranks; without ranks, by character
	 * and without case, as a case-insensitive collation does (never
	 * naturally: WooCommerce puts "26WC" before "2RM").
	 *
	 * @param array<int, array<string, mixed>> $nodes  Nodes by id, each with name and order.
	 * @param array<int, bool>                 $ranked Ids with a stored order.
	 * @param array<int, int>                  $ranks  Ids' places in the database's name order, when known.
	 * @return \Closure( int, int ): int
	 */
	public static function comparator( array $nodes, array $ranked, array $ranks = [] ): \Closure {
		$names = static fn( int $a, int $b ): int => isset( $ranks[ $a ], $ranks[ $b ] )
			? $ranks[ $a ] <=> $ranks[ $b ]
			: strcmp( mb_strtolower( (string) $nodes[ $a ]['name'] ), mb_strtolower( (string) $nodes[ $b ]['name'] ) );
		return static fn( int $a, int $b ): int => [ isset( $ranked[ $a ] ) ? 1 : 0, $nodes[ $a ]['order'], $names( $a, $b ), $a ] <=> [ isset( $ranked[ $b ] ) ? 1 : 0, $nodes[ $b ]['order'], 0, $b ];
	}

	/**
	 * Each id's place in the database's name order, as the tree was built with.
	 *
	 * @return array<int, int>
	 */
	public function ranks(): array {
		return $this->ranks;
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
	 * @return array{id: int, name: string, slug: string, description: string, parent: int, depth: int, order: int, default: bool}|null
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
