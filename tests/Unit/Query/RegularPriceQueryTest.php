<?php
/**
 * The Regular price filter and sort read the regular price the grid's cell
 * shows (the row's own `_regular_price`), not WooCommerce's active price in
 * the lookup table, which is the sale price while a sale runs. Run as real
 * prepared SQL over the seeded catalogue plus products on sale.
 *
 * Added to the seed: 301 Sale Lamp, simple, regular 150 on sale at 80
 * (lookup 80); 302 Sale Chair, variable (its own regular price deleted, as
 * WooCommerce does, lookup 40 to 60) with 303 regular 120 on sale at 60 and
 * 304 regular 40, not on sale; 305 Free Gift, simple, regular 30 on sale at
 * 0, which WooCommerce never marks on sale (lookup 0).
 *
 * A variable product has no regular price of its own: its cell is empty, so
 * it never matches a Regular price filter and sorts with the other empty
 * cells; its variations match on their own prices.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class RegularPriceQueryTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		SeededCatalogue::seed( SeededCatalogue::on_sale() );
	}

	/**
	 * The ids a read lists, in order.
	 *
	 * @param array<string, mixed> $args Read arguments.
	 * @return list<int>
	 */
	private static function ids( array $args ): array {
		$query = ProductQuery::from_array( $args + [ 'per_page' => 100 ] );
		$ids   = array_column( $query->rows(), 'id' );
		self::assertSame( count( $ids ), $query->total(), 'The count agrees with the page.' );
		return $ids;
	}

	/**
	 * A regular price filter.
	 *
	 * @param string    $operator Range operator.
	 * @param int|array $value    Bound or bounds.
	 * @param string    $mode     Criteria mode.
	 * @return list<int> Sorted ids.
	 */
	private static function filtered( string $operator, int|array $value, string $mode = Criteria::MODE_PRODUCTS ): array {
		$ids = self::ids(
			[
				'mode'    => $mode,
				'filters' => [
					[
						'field'    => 'regular_price',
						'operator' => $operator,
						'value'    => $value,
					],
				],
			]
		);
		sort( $ids );
		return $ids;
	}

	public function test_a_product_on_sale_is_filtered_by_the_regular_price_its_cell_shows(): void {
		$this->assertSame( [ 301 ], self::filtered( 'greaterThanOrEqual', 100 ), '150 on sale at 80 is at least 100.' );
		$this->assertSame( [ 101, 102, 104, 106, 305 ], self::filtered( 'lessThanOrEqual', 100 ), '150 on sale at 80 is not at most 100.' );
		$this->assertSame( [ 301 ], self::filtered( 'between', [ 100, 200 ] ) );
		$this->assertSame( [], self::filtered( 'between', [ 70, 90 ] ), 'The sale price is not the regular price.' );
		$this->assertSame( [ 305 ], self::filtered( 'between', [ 25, 35 ] ), 'A sale price of 0 is not the regular price either.' );
		$this->assertSame( [ 101, 104, 106 ], self::filtered( 'lessThanOrEqual', 20 ) );
	}

	public function test_a_variation_on_sale_is_filtered_by_its_own_regular_price(): void {
		$this->assertSame( [ 303 ], self::filtered( 'greaterThanOrEqual', 100, Criteria::MODE_VARIATIONS ) );
		$this->assertSame( [ 304 ], self::filtered( 'lessThanOrEqual', 50, Criteria::MODE_VARIATIONS ) );
		$this->assertSame( [ 301, 303 ], self::filtered( 'greaterThanOrEqual', 100, Criteria::MODE_PRODUCTS_AND_VARIATIONS ) );
	}

	public function test_a_variable_product_has_no_regular_price_of_its_own_and_never_matches(): void {
		// 103 (lookup 60 to 80) and 302 (lookup 40 to 60) would match on their variations' active prices.
		$this->assertSame( [], array_intersect( [ 103, 107, 302 ], self::filtered( 'between', [ 0, 1000 ] ) ) );
		$this->assertSame( [ 101, 102, 104, 106, 301, 305 ], self::filtered( 'between', [ 0, 1000 ] ) );
	}

	public function test_the_sort_orders_by_the_regular_price_with_empty_cells_together(): void {
		$desc = self::ids(
			[
				'orderby' => 'regular_price',
				'order'   => 'desc',
			]
		);
		$this->assertSame( [ 301, 102, 305, 101, 106, 104 ], array_slice( $desc, 0, 6 ), 'Sorted as 150, not as its sale price 80.' );
		$this->assertSame( [ 302, 107, 105, 103 ], array_slice( $desc, 6 ), 'No regular price: last going down, newest first among them.' );

		$asc = self::ids(
			[
				'orderby' => 'regular_price',
				'order'   => 'asc',
			]
		);
		$this->assertSame( [ 103, 105, 107, 302, 104, 106, 101, 305, 102, 301 ], $asc, 'Going up the empty cells come first, as MySQL orders NULL.' );

		$variations = self::ids(
			[
				'mode'    => Criteria::MODE_VARIATIONS,
				'orderby' => 'regular_price',
				'order'   => 'desc',
			]
		);
		$this->assertSame( [ 303, 202, 201, 304 ], $variations );
	}

	public function test_the_price_is_read_once_per_row_through_its_alias(): void {
		$sql = ProductQuery::from_array(
			[
				'orderby' => 'regular_price',
				'order'   => 'desc',
			]
		)->statement();
		$this->assertSame( 1, substr_count( $sql, '`wp_postmeta`' ), $sql );
		$this->assertStringContainsString( 'ORDER BY regular_price DESC, p.ID DESC', $sql );
		$this->assertStringContainsString( 'LEFT JOIN `wp_wc_product_meta_lookup` AS l', $sql, 'A row not on sale reads its price from the lookup.' );
	}
}
