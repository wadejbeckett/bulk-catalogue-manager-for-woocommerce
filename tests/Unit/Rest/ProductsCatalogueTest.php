<?php
/**
 * `GET /bcm/v1/products` over a seeded catalogue (T18, T20; SPEC §4.1, §4.2,
 * §12.1, §12.2): each §4.1 filter narrows to the expected ids, two filters
 * combine as an AND, a category includes the categories below it,
 * `per_page=500` returns 100 rows reporting `perPage` 100, a search matches a
 * name and a SKU, sorts order as asked with a stable tiebreak on id, a page
 * past the last answers empty items with truthful pagination, the three
 * modes list the expected rows, and each variable row carries its variation
 * count from one grouped statement per page.
 *
 * The route runs through the stub REST server and the real ProductQuery;
 * the stub `$wpdb` answers its prepared statements from the seeded tables
 * with tests/Support/SqlEvaluator.php, so each assertion is about the rows
 * the SQL picks, not the SQL's text. The same assertions against a real
 * WooCommerce catalogue under wp-env are still to run.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ProductsCatalogueTest extends TestCase {
	protected function setUp(): void {
		self::boot();
	}

	/**
	 * Reset the stubs, log in a shop manager, register the routes and seed.
	 *
	 * @param array<int, array<string, mixed>> $extra Extra seeded rows.
	 */
	private static function boot( array $extra = [] ): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		SeededCatalogue::seed( $extra );
	}

	/**
	 * Dispatch a GET to /bcm/v1/products.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private static function get( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bcm/v1/products' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The ids a read returns, in order.
	 *
	 * @param array<string, mixed> $params Query parameters; name only, 100 a page, by default.
	 * @return int[]
	 */
	private function ids( array $params ): array {
		$response = self::get( $params + [ 'fields' => 'name' ] + [ 'per_page' => 100 ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, (string) wp_json_encode( $params ) );
		return array_column( $response->get_data()['items'], 'id' );
	}

	/**
	 * The ids a filtered read returns, in ascending order.
	 *
	 * @param string               $field    Filter field.
	 * @param string               $operator Operator.
	 * @param mixed                $value    Value.
	 * @param array<string, mixed> $params   Further parameters.
	 * @return int[]
	 */
	private function filtered( string $field, string $operator, mixed $value, array $params = [] ): array {
		return $this->ids(
			[
				$field    => [
					'operator' => $operator,
					'value'    => $value,
				],
				'orderby' => 'id',
			] + $params
		);
	}

	public function test_without_filters_every_listed_product_is_returned_newest_first(): void {
		$this->assertSame( array_reverse( SeededCatalogue::PRODUCTS ), $this->ids( [] ), 'Trashed products and variations never list in the products mode.' );
		$this->assertSame( SeededCatalogue::PRODUCTS, $this->ids( [ 'orderby' => 'id' ] ) );
	}

	public function test_each_filter_narrows_to_the_expected_products(): void {
		$cases   = [
			'category Clothing and below'  => [ 'category_ids', 'isAny', [ 10 ], [ 101, 102, 105, 106, 107 ] ],
			'category Shirts and below'    => [ 'category_ids', 'isAny', [ 11 ], [ 101, 102, 106, 107 ] ],
			'category Formal, a leaf'      => [ 'category_ids', 'isAny', [ 12 ], [ 102 ] ],
			'no category under Clothing'   => [ 'category_ids', 'isNone', [ 10 ], [ 103, 104 ] ],
			'in Clothing and in Outerwear' => [ 'category_ids', 'isAll', [ 10, 20 ], [ 106 ] ],
			'tag sale'                     => [ 'tag_ids', 'isAny', [ 30 ], [ 101, 106 ] ],
			'tags sale and new'            => [ 'tag_ids', 'isAll', [ 30, 31 ], [ 106 ] ],
			'no tag sale'                  => [ 'tag_ids', 'isNone', [ 30 ], [ 102, 103, 104, 105, 107 ] ],
			'type variable'                => [ 'type', 'isAny', [ 'variable' ], [ 103, 107 ] ],
			'type grouped'                 => [ 'type', 'isAny', [ 'grouped' ], [ 105 ] ],
			'type not simple'              => [ 'type', 'isNone', [ 'simple' ], [ 103, 105, 107 ] ],
			'status draft or pending'      => [ 'status', 'isAny', [ 'draft', 'pending' ], [ 102, 105 ] ],
			'status not published'         => [ 'status', 'isNone', [ 'publish' ], [ 102, 104, 105 ] ],
			'visibility hidden'            => [ 'catalog_visibility', 'isAny', [ 'hidden' ], [ 102 ] ],
			'visibility shop only'         => [ 'catalog_visibility', 'isAny', [ 'catalog' ], [ 103 ] ],
			'visibility search only'       => [ 'catalog_visibility', 'isAny', [ 'search' ], [ 104 ] ],
			'visibility shop and search'   => [ 'catalog_visibility', 'isAny', [ 'visible' ], [ 101, 105, 106, 107 ] ],
			'visibility not visible'       => [ 'catalog_visibility', 'isNone', [ 'visible' ], [ 102, 103, 104 ] ],
			'featured'                     => [ 'featured', 'is', true, [ 101, 106 ] ],
			'not featured'                 => [ 'featured', 'is', false, [ 102, 103, 104, 105, 107 ] ],
			'out of stock'                 => [ 'stock_status', 'isAny', [ 'outofstock' ], [ 102, 107 ] ],
			'not in stock'                 => [ 'stock_status', 'isNone', [ 'instock' ], [ 102, 104, 107 ] ],
			'stock managed'                => [ 'manage_stock', 'is', true, [ 101, 102, 104, 106 ] ],
			'stock not managed'            => [ 'manage_stock', 'is', false, [ 103, 105, 107 ] ],
			'price between 15 and 50'      => [ 'regular_price', 'between', [ 15, 50 ], [ 101, 102, 106 ] ],
			'price 50 or more'             => [ 'regular_price', 'greaterThanOrEqual', 50, [ 103 ] ],
			'price 15 or less'             => [ 'regular_price', 'lessThanOrEqual', 15, [ 104, 106 ] ],
			'stock between 0 and 5'        => [ 'stock_quantity', 'between', [ 0, 5 ], [ 101, 102 ] ],
			'stock 0 or less'              => [ 'stock_quantity', 'lessThanOrEqual', 0, [ 102, 104 ] ],
			'stock 10 or more'             => [ 'stock_quantity', 'greaterThanOrEqual', 10, [ 106 ] ],
			'tax class standard'           => [ 'tax_class', 'isAny', [ '' ], [ 101, 103, 106, 107 ] ],
			'tax class not standard'       => [ 'tax_class', 'isNone', [ '' ], [ 102, 104 ] ],
			'shipping class bulky'         => [ 'shipping_class_id', 'isAny', [ 40 ], [ 101 ] ],
			'no shipping class bulky'      => [ 'shipping_class_id', 'isNone', [ 40 ], [ 102, 103, 104, 105, 106, 107 ] ],
			'modified after 20 September'  => [ 'date_modified', 'after', '2026-09-20', [ 103, 106 ] ],
			'modified before 1 August'     => [ 'date_modified', 'before', '2026-08-01', [ 104 ] ],
			'created before 3 September'   => [ 'date_created', 'before', '2026-09-03', [ 101, 102 ] ],
			'created after a moment'       => [ 'date_created', 'after', '2026-09-06 08:00:00', [ 107 ] ],
		];
		$covered = [];
		foreach ( $cases as $name => [ $field, $operator, $value, $expected ] ) {
			$this->assertSame( $expected, $this->filtered( $field, $operator, $value ), $name );
			$this->assertNotSame( SeededCatalogue::PRODUCTS, $expected, "$name must narrow the catalogue." );
			$covered[ $field ] = true;
		}
		$filterable = array_keys( Registry::instance()->filterable() );
		$covered    = array_keys( $covered );
		sort( $filterable );
		sort( $covered );
		$this->assertSame( $filterable, $covered, 'Every filterable field (SPEC §4.1) is asserted on the seed.' );
	}

	public function test_two_filters_combine_as_and(): void {
		$both = [
			'category_ids' => [
				'operator' => 'isAny',
				'value'    => [ 11 ],
			],
			'featured'     => [
				'operator' => 'is',
				'value'    => true,
			],
			'orderby'      => 'id',
		];
		$this->assertSame( [ 101, 102, 106, 107 ], $this->filtered( 'category_ids', 'isAny', [ 11 ] ) );
		$this->assertSame( [ 101, 106 ], $this->filtered( 'featured', 'is', true ) );
		$this->assertSame( [ 101, 106 ], $this->ids( $both ), 'Shirts and below that are featured.' );

		$both['stock_status'] = [
			'operator' => 'isAny',
			'value'    => [ 'outofstock' ],
		];
		$this->assertSame( [], $this->ids( $both ), 'A third filter that no featured shirt meets empties the list.' );

		$this->assertSame(
			[ 103, 106 ],
			$this->ids(
				[
					'category_ids' => [
						'operator' => 'isAny',
						'value'    => [ 20 ],
					],
					'stock_status' => [
						'operator' => 'isAny',
						'value'    => [ 'instock' ],
					],
					'orderby'      => 'id',
				]
			)
		);
	}

	public function test_a_category_filter_with_include_children_off_matches_only_the_chosen_categories(): void {
		$only = fn( string $operator, array $ids, mixed $children, array $params = [] ): array => $this->ids(
			[
				'category_ids' => [
					'operator'         => $operator,
					'value'            => $ids,
					'include_children' => $children,
				],
				'orderby'      => 'id',
			] + $params
		);
		$this->assertSame( [ 105 ], $only( 'isAny', [ 10 ], false ), 'Clothing itself holds only the gift box; its shirts sit below it.' );
		$this->assertSame( [ 101, 106, 107 ], $only( 'isAny', [ 11 ], 'false' ), 'Shirts only, from a query string; the formal shirt is in Formal below it.' );
		$this->assertSame( [ 101, 102, 106, 107 ], $only( 'isAny', [ 11 ], true ), 'On is the whole branch, as when the flag is left out.' );
		$this->assertSame( [ 101, 102, 106, 107 ], $only( 'isAny', [ 11 ], '1' ) );
		$this->assertSame( $this->filtered( 'category_ids', 'isAny', [ 11 ] ), $only( 'isAny', [ 11 ], true ) );
		$this->assertSame( [ 101, 102, 103, 104, 106, 107 ], $only( 'isNone', [ 10 ], false ), 'None of Clothing itself.' );
		$this->assertSame( [], $only( 'isAll', [ 10, 20 ], false ), 'No product is filed in Clothing itself and in Outerwear.' );
		$this->assertSame( [ 201, 202 ], $only( 'isAny', [ 20 ], false, [ 'mode' => 'variations' ] ), 'Variations still resolve against their parent.' );

		foreach ( [ 'maybe', [ true ], 2 ] as $bad ) {
			$response = self::get(
				[
					'category_ids' => [
						'operator'         => 'isAny',
						'value'            => [ 11 ],
						'include_children' => $bad,
					],
				]
			);
			$this->assertInstanceOf( \WP_Error::class, $response, (string) wp_json_encode( $bad ) );
			$this->assertSame( 'rest_invalid_param', $response->get_error_code() );
			$this->assertSame( [ 'category_ids' ], array_keys( $response->get_error_data()['params'] ) );
		}
		$response = self::get(
			[
				'tag_ids' => [
					'operator'         => 'isAny',
					'value'            => [ 30 ],
					'include_children' => false,
				],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $response, 'Only the category filter takes the flag.' );
		$this->assertSame( [ 'tag_ids' ], array_keys( $response->get_error_data()['params'] ) );
	}

	public function test_a_search_reaches_a_variation_by_its_own_sku_where_variations_are_listed(): void {
		$search = fn( string $text, string $mode ): array => $this->ids(
			[
				'search'  => $text,
				'mode'    => $mode,
				'orderby' => 'id',
			]
		);
		$this->assertSame( [], $search( 'JACKET-BL', 'products' ), 'The products mode lists no variation.' );
		$this->assertSame( [ 201 ], $search( 'JACKET-BL', 'products_and_variations' ), 'Its own SKU finds the variation, not its parent.' );
		$this->assertSame( [ 103, 201, 202 ], $search( 'jacket', 'products_and_variations' ) );
		$this->assertSame( [ 202 ], $search( 'jacket-dg', 'variations' ) );
	}

	public function test_per_page_500_returns_100_rows_reporting_per_page_100(): void {
		self::boot( SeededCatalogue::fillers( 150 ) );
		$response = self::get(
			[
				'per_page' => '500',
				'fields'   => 'name',
			]
		);
		$this->assertCount( 100, $response->get_data()['items'] );
		$this->assertSame(
			[
				'page'       => 1,
				'perPage'    => 100,
				'total'      => 157,
				'totalPages' => 2,
			],
			$response->get_data()['pagination']
		);
		$this->assertSame( array_reverse( SeededCatalogue::PRODUCTS ), array_slice( array_column( $response->get_data()['items'], 'id' ), 0, 7 ) );

		$second = self::get(
			[
				'per_page' => 500,
				'page'     => 2,
				'fields'   => 'name',
			]
		)->get_data();
		$this->assertCount( 57, $second['items'] );
		$this->assertSame( [], array_intersect( array_column( $response->get_data()['items'], 'id' ), array_column( $second['items'], 'id' ) ), 'The pages do not overlap.' );
	}

	public function test_a_search_matches_a_name_and_a_sku(): void {
		$search = fn( string $text, string $mode = 'products' ): array => $this->ids(
			[
				'search'  => $text,
				'mode'    => $mode,
				'orderby' => 'id',
			]
		);
		$this->assertSame( [ 101, 102 ], $search( 'shirt' ), 'Names, without regard to case.' );
		$this->assertSame( [ 106 ], $search( 'TEE-STR' ), 'A SKU fragment; the name "Striped Tee" does not hold it.' );
		$this->assertSame( [ 104 ], $search( 'tote-1' ), 'A SKU without regard to case.' );
		$this->assertSame( [ 103 ], $search( 'jacket' ), 'Name and SKU on one product count it once.' );
		$this->assertSame( [ 201, 202 ], $search( 'jacket', 'variations' ) );
		$this->assertSame( [], $search( 'SHIRT_BLUE' ), 'An underscore is a character, not a wildcard.' );
		$this->assertSame( [], $search( '%' ), 'A percent sign is a character, not a wildcard.' );
		$this->assertSame( [], $search( 'Scarf' ), 'A trashed product is never found.' );
	}

	public function test_sort_keys_and_directions_with_a_stable_tiebreak_on_id(): void {
		$this->assertSame( [ 101, 104, 102, 105, 107, 103, 106 ], $this->ids( [ 'orderby' => 'name' ] ), 'By name.' );
		$this->assertSame(
			[ 106, 103, 107, 105, 102, 104, 101 ],
			$this->ids(
				[
					'orderby' => 'name',
					'order'   => 'desc',
				]
			)
		);
		$this->assertSame( [ 105, 101, 103, 106, 104, 102, 107 ], $this->ids( [ 'orderby' => 'stock_status' ] ), 'No stock status first, then ties in id order.' );
		$this->assertSame(
			[ 107, 102, 104, 106, 103, 101, 105 ],
			$this->ids(
				[
					'orderby' => 'stock_status',
					'order'   => 'desc',
				]
			),
			'Descending, ties in descending id order.'
		);
		$this->assertSame(
			[ 105, 106, 103, 101, 104, 107, 102 ],
			$this->ids(
				[
					'orderby' => 'stock_status,name',
					'order'   => 'asc,desc',
				]
			),
			'Two keys: stock status up, then name down.'
		);
		$this->assertSame( [ 105, 107, 104, 106, 101, 102, 103 ], $this->ids( [ 'orderby' => 'regular_price' ] ), 'Price up on the lowest price, no price first.' );
		$this->assertSame(
			[ 103, 102, 101, 106, 104, 107, 105 ],
			$this->ids(
				[
					'orderby' => 'regular_price',
					'order'   => 'desc',
				]
			),
			'Price down on the highest price.'
		);
		$this->assertSame(
			[ 105, 107, 103, 104, 102, 101, 106 ],
			$this->ids(
				[
					'orderby' => [ 'stock_quantity', 'sku' ],
					'order'   => [ 'asc' ],
				]
			),
			'A missing direction is ascending; no quantity sorts first, those by SKU with no SKU first.'
		);
		$this->assertSame( [ 104, 102, 107, 105, 101, 106, 103 ], $this->ids( [ 'orderby' => 'date_modified' ] ) );
	}

	public function test_the_last_page_and_pages_past_it(): void {
		$page = static fn( int $number ): array => self::get(
			[
				'per_page' => 3,
				'page'     => $number,
				'orderby'  => 'id',
				'fields'   => 'name',
			]
		)->get_data();

		$this->assertSame( [ 101, 102, 103 ], array_column( $page( 1 )['items'], 'id' ) );
		$this->assertSame( [ 104, 105, 106 ], array_column( $page( 2 )['items'], 'id' ) );
		$last = $page( 3 );
		$this->assertSame( [ 107 ], array_column( $last['items'], 'id' ), 'The last page holds the remainder.' );
		$this->assertSame(
			[
				'page'       => 3,
				'perPage'    => 3,
				'total'      => 7,
				'totalPages' => 3,
			],
			$last['pagination']
		);
		foreach ( [ 4, 50 ] as $past ) {
			$response = self::get(
				[
					'per_page' => 3,
					'page'     => $past,
				]
			);
			$this->assertSame( 200, $response->get_status(), 'A page past the last is not an error.' );
			$this->assertSame(
				[
					'items'      => [],
					'pagination' => [
						'page'       => $past,
						'perPage'    => 3,
						'total'      => 7,
						'totalPages' => 3,
					],
				],
				$response->get_data()
			);
		}
	}

	public function test_the_modes_list_products_variations_or_both(): void {
		$this->assertSame(
			[ 101, 102, 103, 104, 105, 106, 107, 201, 202 ],
			$this->ids(
				[
					'mode'    => 'products_and_variations',
					'orderby' => 'id',
				]
			)
		);
		$this->assertSame(
			SeededCatalogue::VARIATIONS,
			$this->ids(
				[
					'mode'    => 'variations',
					'orderby' => 'id',
				]
			),
			'The trashed variation never lists.'
		);

		$this->assertSame( [ 202 ], $this->filtered( 'stock_status', 'isAny', [ 'outofstock' ], [ 'mode' => 'variations' ] ), 'Every variation that is out of stock.' );
		$this->assertSame( [ 102, 107, 202 ], $this->filtered( 'stock_status', 'isAny', [ 'outofstock' ], [ 'mode' => 'products_and_variations' ] ) );
		$this->assertSame( [ 201, 202 ], $this->filtered( 'category_ids', 'isAny', [ 20 ], [ 'mode' => 'variations' ] ), 'Category resolves against the parent.' );
		$this->assertSame( [ 103, 106, 201, 202 ], $this->filtered( 'category_ids', 'isAny', [ 20 ], [ 'mode' => 'products_and_variations' ] ) );
		$this->assertSame( [], $this->filtered( 'category_ids', 'isAny', [ 10 ], [ 'mode' => 'variations' ] ) );
		$this->assertSame( [ 201, 202 ], $this->filtered( 'tag_ids', 'isAny', [ 31 ], [ 'mode' => 'variations' ] ), 'Tag resolves against the parent.' );
		$this->assertSame( [ 201, 202 ], $this->filtered( 'featured', 'is', false, [ 'mode' => 'variations' ] ), 'Featured resolves against the parent.' );
		$this->assertSame( [ 201, 202 ], $this->filtered( 'catalog_visibility', 'isAny', [ 'catalog' ], [ 'mode' => 'variations' ] ), 'Visibility resolves against the parent.' );
		$this->assertSame( [ 201 ], $this->filtered( 'shipping_class_id', 'isAny', [ 40 ], [ 'mode' => 'variations' ] ), 'Shipping class is the variation\'s own.' );
		$this->assertSame( [ 202 ], $this->filtered( 'regular_price', 'greaterThanOrEqual', 70, [ 'mode' => 'variations' ] ), 'Price is the variation\'s own.' );
		$this->assertSame( [ 201, 202 ], $this->filtered( 'type', 'isAny', [ 'variation' ], [ 'mode' => 'products_and_variations' ] ) );

		$refused = self::get( [ 'mode' => 'everything' ] );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'rest_invalid_param', $refused->get_error_code() );
		$this->assertSame( 400, $refused->get_error_data()['status'] );
		$this->assertSame( [ 'mode' ], array_keys( $refused->get_error_data()['params'] ) );
	}

	public function test_variation_rows_carry_their_product_name_and_attributes(): void {
		$items = self::get(
			[
				'mode'    => 'products_and_variations',
				'orderby' => 'id',
				'fields'  => 'name,sku',
			]
		)->get_data()['items'];
		$by_id = array_column( $items, null, 'id' );
		$this->assertSame(
			[
				'id'                => 201,
				'parent_id'         => 103,
				'name'              => 'Rain Jacket - Blue, Large',
				'sku'               => 'JACKET-BL-L',
				'parent_name'       => 'Rain Jacket',
				'attribute_summary' => 'Blue / Large',
			],
			$by_id[201]
		);
		$this->assertArrayNotHasKey( 'parent_name', $by_id[103], 'A product row carries neither.' );
		$this->assertArrayNotHasKey( 'attribute_summary', $by_id[103] );
	}

	public function test_a_parent_argument_on_the_products_route_is_ignored(): void {
		$this->assertSame(
			SeededCatalogue::PRODUCTS,
			$this->ids(
				[
					'orderby' => 'id',
					'parent'  => 103,
				]
			)
		);
	}

	public function test_each_variable_row_carries_its_variation_count_from_one_grouped_statement(): void {
		foreach ( [ 3, 25, 100 ] as $per_page ) {
			self::boot( SeededCatalogue::fillers( 150 ) );
			$items   = self::get(
				[
					'per_page' => $per_page,
					'orderby'  => 'id',
					'fields'   => 'name,variation_count',
				]
			)->get_data()['items'];
			$grouped = preg_grep( '/^SELECT post_parent AS parent_id, COUNT\(\*\)/', $GLOBALS['wpdb']->queries );
			$this->assertCount( 1, $grouped, "One grouped count at $per_page rows." );
			$this->assertCount( $per_page, $items );
		}

		self::boot();
		$by_id = array_column( self::get( [ 'fields' => 'variation_count' ] )->get_data()['items'], 'variation_count', 'id' );
		$this->assertSame( 2, $by_id[103], 'The published and the private variation; not the trashed one.' );
		$this->assertSame( 0, $by_id[107], 'A variable product without variations.' );
		$this->assertSame( 0, $by_id[101] );
	}
}
