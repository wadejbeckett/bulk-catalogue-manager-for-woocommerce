<?php
/**
 * A seeded catalogue for the T18 and T20 tests, held twice from one
 * definition: as database tables the stub `$wpdb` answers through the
 * in-memory SqlEvaluator (so ProductQuery's real prepared statements pick
 * the rows), and as the posts, meta and object-term fixtures the read stubs
 * hydrate from.
 *
 * Categories: Clothing (10) > Shirts (11) > Formal (12); Outerwear (20).
 * Tags: sale (30), new (31). Shipping class: bulky (40). Visibility terms:
 * featured (50), exclude-from-catalog (51), exclude-from-search (52).
 * Types: simple (60), variable (61), grouped (62). Colour attribute terms:
 * blue (70, "Blue"), dark-green (71, "Dark Green").
 *
 * Products (created 2026-09-0N for id 10N, so newest first is highest id):
 * 101 Blue Shirt, 102 Formal Shirt, 103 Rain Jacket (variable, two listed
 * variations), 104 Canvas Tote, 105 Gift Box (grouped, no lookup row),
 * 106 Striped Tee, 107 Plain Hoodie (variable, no variations), 108 Old Scarf
 * (trashed, never listed). Variations of 103: 201 Blue / Large (menu order
 * 2), 202 Dark Green / any size (private, menu order 1), 203 (trashed).
 * 5000 is an attachment.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Tests\Support\SqlEvaluator;

final class SeededCatalogue {
	/**
	 * Terms: id => [ taxonomy, slug, name, parent ].
	 */
	public const TERMS = [
		10 => [ 'product_cat', 'clothing', 'Clothing', 0 ],
		11 => [ 'product_cat', 'shirts', 'Shirts', 10 ],
		12 => [ 'product_cat', 'formal', 'Formal', 11 ],
		20 => [ 'product_cat', 'outerwear', 'Outerwear', 0 ],
		30 => [ 'product_tag', 'sale', 'Sale', 0 ],
		31 => [ 'product_tag', 'new', 'New', 0 ],
		40 => [ 'product_shipping_class', 'bulky', 'Bulky', 0 ],
		50 => [ 'product_visibility', 'featured', 'featured', 0 ],
		51 => [ 'product_visibility', 'exclude-from-catalog', 'exclude-from-catalog', 0 ],
		52 => [ 'product_visibility', 'exclude-from-search', 'exclude-from-search', 0 ],
		60 => [ 'product_type', 'simple', 'simple', 0 ],
		61 => [ 'product_type', 'variable', 'variable', 0 ],
		62 => [ 'product_type', 'grouped', 'grouped', 0 ],
		70 => [ 'pa_colour', 'blue', 'Blue', 0 ],
		71 => [ 'pa_colour', 'dark-green', 'Dark Green', 0 ],
	];

	/**
	 * Every product that lists in the products mode, by id.
	 */
	public const PRODUCTS = [ 101, 102, 103, 104, 105, 106, 107 ];

	/**
	 * Every variation that lists in the variations mode, by id.
	 */
	public const VARIATIONS = [ 201, 202 ];

	/**
	 * Rows: id => [ post fields, lookup row or null, term ids, extra meta ].
	 * Lookup: [ sku, min price, max price, stock status, stock quantity, tax class ].
	 *
	 * @return array<int, array{0: array<string, mixed>, 1: array{0: string, 1: float|null, 2: float|null, 3: string, 4: int|null, 5: string}|null, 2: int[], 3: array<string, string>}>
	 */
	private static function rows(): array {
		$product   = static fn( string $title, string $status, int $day, string $modified ): array => [
			'post_type'         => 'product',
			'post_title'        => $title,
			'post_status'       => $status,
			'post_parent'       => 0,
			'menu_order'        => 0,
			'comment_status'    => 'open',
			'post_date_gmt'     => sprintf( '2026-09-%02d 08:00:00', $day ),
			'post_modified_gmt' => $modified,
		];
		$variation = static fn( string $title, string $status, int $order, int $day ): array => [
			'post_type'         => 'product_variation',
			'post_title'        => $title,
			'post_status'       => $status,
			'post_parent'       => 103,
			'menu_order'        => $order,
			'comment_status'    => 'closed',
			'post_date_gmt'     => sprintf( '2026-09-%02d 08:00:00', $day ),
			'post_modified_gmt' => sprintf( '2026-09-%02d 08:00:00', $day ),
		];
		return [
			101 => [ $product( 'Blue Shirt', 'publish', 1, '2026-09-20 10:00:00' ), [ 'SHIRT-BLUE', 20.0, 20.0, 'instock', 5, '' ], [ 11, 30, 40, 50, 60 ], [] ],
			102 => [ $product( 'Formal Shirt', 'draft', 2, '2026-08-01 10:00:00' ), [ 'SHIRT-FORMAL', 45.0, 45.0, 'outofstock', 0, 'reduced-rate' ], [ 12, 51, 52, 60 ], [] ],
			103 => [ $product( 'Rain Jacket', 'publish', 3, '2026-09-22 09:00:00' ), [ 'JACKET', 60.0, 80.0, 'instock', null, '' ], [ 20, 31, 52, 61 ], [] ],
			104 => [ $product( 'Canvas Tote', 'private', 4, '2026-07-15 12:00:00' ), [ 'TOTE-1', 10.0, 10.0, 'onbackorder', -2, 'zero-rate' ], [ 51, 60 ], [] ],
			105 => [ $product( 'Gift Box', 'pending', 5, '2026-09-10 12:00:00' ), null, [ 10, 62 ], [] ],
			106 => [ $product( 'Striped Tee', 'publish', 6, '2026-09-21 07:00:00' ), [ 'TEE-STRIPE', 15.0, 15.0, 'instock', 12, '' ], [ 11, 20, 30, 31, 50, 60 ], [] ],
			107 => [ $product( 'Plain Hoodie', 'publish', 7, '2026-09-05 12:00:00' ), [ 'HOODIE', null, null, 'outofstock', null, '' ], [ 11, 61 ], [] ],
			108 => [ $product( 'Old Scarf', 'trash', 8, '2026-09-08 12:00:00' ), [ 'SCARF', 5.0, 5.0, 'instock', null, '' ], [ 10, 60 ], [] ],
			201 => [
				$variation( 'Rain Jacket - Blue, Large', 'publish', 2, 11 ),
				[ 'JACKET-BL-L', 60.0, 60.0, 'instock', 3, '' ],
				[ 40 ],
				[
					'attribute_pa_colour' => 'blue',
					'attribute_size'      => 'Large',
				],
			],
			202 => [
				$variation( 'Rain Jacket - Dark Green', 'private', 1, 12 ),
				[ 'JACKET-DG', 80.0, 80.0, 'outofstock', 0, '' ],
				[],
				[
					'attribute_pa_colour' => 'dark-green',
					'attribute_size'      => '',
				],
			],
			203 => [ $variation( 'Rain Jacket - Old', 'trash', 0, 13 ), [ 'JACKET-OLD', 70.0, 70.0, 'instock', null, '' ], [], [ 'attribute_pa_colour' => 'blue' ] ],
		];
	}

	/**
	 * Seed the stubs and the evaluator.
	 *
	 * @param array<int, array<string, mixed>> $extra Further rows in the shape rows() returns, such as filler products.
	 */
	public static function seed( array $extra = [] ): SqlEvaluator {
		global $wpdb;
		$state  = &$GLOBALS['bcm_test'];
		$tables = [
			'wp_posts'                  => [],
			'wp_wc_product_meta_lookup' => [],
			'wp_term_relationships'     => [],
			'wp_term_taxonomy'          => [],
			'wp_terms'                  => [],
		];

		$state['term_list'] = [];
		foreach ( self::TERMS as $id => [ $taxonomy, $slug, $name, $parent ] ) {
			$tables['wp_term_taxonomy'][] = [
				'term_taxonomy_id' => $id,
				'term_id'          => $id,
				'taxonomy'         => $taxonomy,
				'parent'           => $parent,
			];
			$tables['wp_terms'][]         = [
				'term_id' => $id,
				'name'    => $name,
				'slug'    => $slug,
			];
			$state['term_list'][]         = (object) [
				'term_id'  => $id,
				'name'     => $name,
				'slug'     => $slug,
				'parent'   => $parent,
				'taxonomy' => $taxonomy,
			];
		}

		foreach ( self::rows() + $extra as $id => [ $post, $lookup, $terms, $meta ] ) {
			$tables['wp_posts'][]         = [ 'ID' => $id ] + $post;
			$state['posts'][ $id ]        = $post;
			$state['meta'][ $id ]         = [];
			$state['object_terms'][ $id ] = [];
			if ( null !== $lookup ) {
				[ $sku, $min, $max, $stock_status, $quantity, $tax_class ] = $lookup;
				$tables['wp_wc_product_meta_lookup'][]                     = [
					'product_id'     => $id,
					'sku'            => $sku,
					'min_price'      => $min,
					'max_price'      => $max,
					'stock_status'   => $stock_status,
					'stock_quantity' => $quantity,
					'tax_class'      => $tax_class,
					'total_sales'    => 0,
				];
				$state['meta'][ $id ]                                      = [
					'_sku'           => $sku,
					'_regular_price' => null === $min || $min !== $max ? '' : (string) (int) $min,
					'_stock_status'  => $stock_status,
					'_manage_stock'  => null === $quantity ? 'no' : 'yes',
					'_stock'         => null === $quantity ? '' : (string) $quantity,
					'_tax_class'     => $tax_class,
				];
			}
			$state['meta'][ $id ] += $meta;
			foreach ( $terms as $term ) {
				$tables['wp_term_relationships'][] = [
					'object_id'        => $id,
					'term_taxonomy_id' => $term,
				];
				$state['object_terms'][ $id ][]    = [ self::TERMS[ $term ][0], $term, self::TERMS[ $term ][1] ];
			}
		}
		$state['posts'][5000] = [
			'post_type'   => 'attachment',
			'post_title'  => 'A picture',
			'post_status' => 'inherit',
		];
		$tables['wp_posts'][] = [
			'ID'          => 5000,
			'post_type'   => 'attachment',
			'post_title'  => 'A picture',
			'post_status' => 'inherit',
			'post_parent' => 0,
			'menu_order'  => 0,
		];

		$db              = new SqlEvaluator( $tables );
		$wpdb->responses = [
			'/^SELECT COUNT\(\*\) FROM/' => static fn( string $sql ): ?string => $db->value( $sql ),
			'/^SELECT /'                 => static fn( string $sql ): array => $db->rows( $sql ),
		];
		return $db;
	}

	/**
	 * Filler simple products for paging past 100 rows: ids from 1001, each
	 * published, in stock, created 2026-08-01 so they list after the seed.
	 *
	 * @param int $count How many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fillers( int $count ): array {
		$rows = [];
		for ( $index = 1; $index <= $count; $index++ ) {
			$rows[ 1000 + $index ] = [
				[
					'post_type'         => 'product',
					'post_title'        => sprintf( 'Filler %03d', $index ),
					'post_status'       => 'publish',
					'post_parent'       => 0,
					'menu_order'        => 0,
					'comment_status'    => 'open',
					'post_date_gmt'     => '2026-08-01 08:00:00',
					'post_modified_gmt' => '2026-08-01 08:00:00',
				],
				[ sprintf( 'FILL-%03d', $index ), 1.0, 1.0, 'instock', null, '' ],
				[ 60 ],
				[],
			];
		}
		return $rows;
	}

	/**
	 * Variations of one variable product for the query-budget tests: ids
	 * from $first, alternating blue and dark green, sizes Small to Large.
	 *
	 * @param int $parent Variable product id (added too, as a variable product).
	 * @param int $first  First variation id.
	 * @param int $count  How many variations.
	 * @return array<int, array<string, mixed>>
	 */
	public static function variable( int $parent, int $first, int $count ): array {
		$rows  = [
			$parent => [
				[
					'post_type'         => 'product',
					'post_title'        => 'Many Colours',
					'post_status'       => 'publish',
					'post_parent'       => 0,
					'menu_order'        => 0,
					'comment_status'    => 'open',
					'post_date_gmt'     => '2026-07-01 08:00:00',
					'post_modified_gmt' => '2026-07-01 08:00:00',
				],
				[ 'MANY', 1.0, 9.0, 'instock', null, '' ],
				[ 61 ],
				[],
			],
		];
		$sizes = [ 'Small', 'Medium', 'Large' ];
		for ( $index = 0; $index < $count; $index++ ) {
			$rows[ $first + $index ] = [
				[
					'post_type'         => 'product_variation',
					'post_title'        => 'Many Colours - ' . $index,
					'post_status'       => 'publish',
					'post_parent'       => $parent,
					'menu_order'        => $index,
					'comment_status'    => 'closed',
					'post_date_gmt'     => '2026-07-02 08:00:00',
					'post_modified_gmt' => '2026-07-02 08:00:00',
				],
				[ 'MANY-' . $index, 1.0, 1.0, 'instock', 1, '' ],
				[],
				[
					'attribute_pa_colour' => 0 === $index % 2 ? 'blue' : 'dark-green',
					'attribute_size'      => $sizes[ $index % 3 ],
				],
			];
		}
		return $rows;
	}
}
