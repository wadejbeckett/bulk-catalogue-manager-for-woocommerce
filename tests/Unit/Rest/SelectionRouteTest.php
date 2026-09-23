<?php
/**
 * `POST /bcm/v1/selection` (T19; SPEC §9.1, §12.1, §13) over the seeded
 * catalogue: a filter answers `{ count, token }` with the count `/products`
 * reports and a token that re-materialises to the same normalised
 * conditions; an `ids` list answers its distinct ids in order; the count is
 * the cached `COUNT(*)`, so the same filter posted twice counts once; a
 * visitor is refused 401 and a user who may read but not edit products 403;
 * and a filter matching 100,001 seeded objects, or 100,001 ids, is refused
 * 400 naming the count.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class SelectionRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		SeededCatalogue::seed();
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function send( string $method, string $route, array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bcm/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A category and stock filter with a search, in the grid's argument shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function view(): array {
		return [
			'mode'         => Criteria::MODE_PRODUCTS,
			'search'       => 'e',
			'category_ids' => [
				'operator' => 'isAny',
				'value'    => [ 11, 20 ],
			],
			'stock_status' => [
				'operator' => 'isAny',
				'value'    => [ 'instock', 'outofstock' ],
			],
		];
	}

	public function test_the_route_needs_the_write_capability(): void {
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/selection'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertArrayHasKey( 'ids', $route[0]['args'] );
		$this->assertArrayHasKey( 'stock_status', $route[0]['args'] );
		$this->assertArrayNotHasKey( 'per_page', $route[0]['args'] );
	}

	public function test_a_filter_answers_the_grid_count_and_a_token_of_its_conditions(): void {
		$response = self::send(
			'POST',
			'/selection',
			self::view() + [
				'orderby' => 'name',
				'page'    => 4,
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'count', 'token' ], array_keys( $data ) );

		$grid = self::send( 'GET', '/products', self::view() + [ 'fields' => [ 'name' ] ] );
		$this->assertSame( $grid->get_data()['pagination']['total'], $data['count'] );
		$this->assertSame( [ 101, 103, 106, 107 ], self::sorted_ids( $grid ), 'Blue Shirt, Rain Jacket, Striped Tee, Plain Hoodie.' );
		$this->assertSame( 4, $data['count'] );

		$selection = SelectionToken::parse( $data['token'] );
		$this->assertInstanceOf( Selection::class, $selection );
		$this->assertSame( 4, $selection->count );
		$expected = Criteria::from_array(
			[
				'search'  => 'e',
				'filters' => [
					[
						'field'    => 'category_ids',
						'operator' => 'isAny',
						'value'    => [ 11, 20 ],
					],
					[
						'field'    => 'stock_status',
						'operator' => 'isAny',
						'value'    => [ 'instock', 'outofstock' ],
					],
				],
			]
		);
		$this->assertSame( $expected->conditions(), $selection->criteria->conditions() );
		$this->assertSame( 4, ( new ProductQuery( $selection->criteria ) )->total(), 'The materialised conditions match the same rows.' );
	}

	/**
	 * A page's ids, ascending.
	 *
	 * @param \WP_REST_Response $response A /products response.
	 * @return int[]
	 */
	private static function sorted_ids( \WP_REST_Response $response ): array {
		$ids = array_column( $response->get_data()['items'], 'id' );
		sort( $ids );
		return $ids;
	}

	public function test_the_same_filter_posted_twice_counts_once(): void {
		$statement = ( new ProductQuery( Criteria::from_array( [ 'search' => 'shirt' ] ) ) )->count_statement();
		foreach ( [ 1, 2 ] as $attempt ) {
			$GLOBALS['bcm_test']['cache'] = [];
			$response                     = self::send( 'POST', '/selection', [ 'search' => 'shirt' ] );
			$this->assertSame( 2, $response->get_data()['count'], "Attempt $attempt." );
		}
		$this->assertCount( 1, array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => $sql === $statement ) );
	}

	public function test_an_id_list_answers_its_distinct_ids_in_order(): void {
		$response = self::send( 'POST', '/selection', [ 'ids' => [ 106, 101, '201', 106, 101 ] ] + self::view() );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 3, $response->get_data()['count'] );
		$selection = SelectionToken::parse( $response->get_data()['token'] );
		$this->assertInstanceOf( Selection::class, $selection );
		$this->assertSame( [ 106, 101, 201 ], $selection->ids );
		$this->assertNull( $selection->criteria, 'With ids the filter arguments are ignored.' );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT COUNT(*)' ) ), 'An id list is not counted in SQL.' );
	}

	public function test_invalid_ids_are_refused_before_the_handler(): void {
		foreach ( [ [ 0 ], [ -3 ], [ 'x' ], [] ] as $ids ) {
			$response = self::send( 'POST', '/selection', [ 'ids' => $ids ] );
			$this->assertInstanceOf( \WP_Error::class, $response, (string) wp_json_encode( $ids ) );
			$this->assertSame( 400, $response->get_error_data()['status'] );
		}
	}

	public function test_a_visitor_is_refused_401_and_a_reader_403(): void {
		$GLOBALS['bcm_test']['logged_in'] = false;
		$GLOBALS['bcm_test']['user_id']   = 0;
		$GLOBALS['bcm_test']['caps']      = [];
		$response                         = self::send( 'POST', '/selection', [ 'ids' => [ 101 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 401, $response->get_error_data()['status'] );
		$this->assertSame( 'bcm_forbidden', $response->get_error_code() );

		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$response = self::send( 'POST', '/selection', [ 'ids' => [ 101 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( 'bcm_forbidden', $response->get_error_code() );
	}

	public function test_a_token_is_refused_to_another_user(): void {
		$token                          = self::send( 'POST', '/selection', [ 'ids' => [ 101 ] ] )->get_data()['token'];
		$GLOBALS['bcm_test']['user_id'] = 13;
		$refused                        = SelectionToken::parse( $token );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bcm_invalid_selection', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
	}

	/**
	 * Seed $count published products straight into the evaluator (no lookup
	 * rows, so the LEFT JOIN stays cheap) and answer COUNT(*) from them. The
	 * in-memory join holds every row a few times over, so the memory limit is
	 * raised to 512 MB where it is lower.
	 *
	 * @param int $count How many products.
	 */
	private static function seed_many( int $count ): void {
		$limit = strtolower( trim( (string) ini_get( 'memory_limit' ) ) );
		$bytes = (int) $limit * ( [
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		][ substr( $limit, -1 ) ] ?? 1 );
		if ( '-1' !== $limit && $bytes < 512 * 1048576 ) {
			ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test only: 100,001 rows in memory.
		}
		$GLOBALS['wpdb']->responses = [];
		$posts                      = [];
		for ( $id = 1; $id <= $count; $id++ ) {
			$posts[] = [
				'ID'          => $id,
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_parent' => 0,
			];
		}
		$db                         = new SqlEvaluator(
			[
				'wp_posts'                  => $posts,
				'wp_wc_product_meta_lookup' => [],
			]
		);
		$GLOBALS['wpdb']->responses = [ '/^SELECT COUNT\(\*\) FROM/' => static fn( string $sql ): ?string => $db->value( $sql ) ];
	}

	public function test_a_filter_matching_100001_seeded_objects_is_refused_with_the_count(): void {
		self::seed_many( Selection::MAX_OBJECTS + 1 );
		$response = self::send( 'POST', '/selection', [ 'mode' => Criteria::MODE_PRODUCTS ] );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'bcm_selection_too_large', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( 100001, $response->get_error_data()['count'] );
		$this->assertSame( 'This selection holds 100,001 products, more than the 100,000 one change can include. Add a filter to narrow it and try again.', $response->get_error_message() );

		self::seed_many( Selection::MAX_OBJECTS );
		$GLOBALS['bcm_test']['transients'] = [];
		$response                          = self::send( 'POST', '/selection', [ 'mode' => Criteria::MODE_PRODUCTS ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, 'Exactly 100,000 is allowed.' );
		$this->assertSame( 100000, $response->get_data()['count'] );
	}

	public function test_more_than_100000_ids_are_refused_with_the_count(): void {
		$response = self::send( 'POST', '/selection', [ 'ids' => range( 1, Selection::MAX_OBJECTS + 1 ) ] );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'bcm_selection_too_large', $response->get_error_code() );
		$this->assertStringContainsString( '100,001', $response->get_error_message() );
	}
}
