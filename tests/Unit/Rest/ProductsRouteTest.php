<?php
/**
 * `GET /bcm/v1/products` as T14 leaves it (SPEC §12.1): registered with the
 * generated arguments and item schema, a malformed argument refused 400
 * `rest_invalid_param` by core's validation before any catalogue query, and
 * a well-formed read reaching the query builder and returning hydrated
 * items (T16, T17). T18 asserts filters and search on a seeded catalogue.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ProductsController;
use BulkCatalogueManager\Rest\ReadController;
use BulkCatalogueManager\Rest\RouteSchema;
use PHPUnit\Framework\TestCase;

final class ProductsRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a GET to /bcm/v1/products with query parameters.
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

	public function test_the_route_registers_with_generated_arguments_and_schema(): void {
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/products'] ?? null;
		$this->assertNotNull( $route );
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[0]['permission_callback'] );
		$this->assertSame( array_keys( ( new RouteSchema( Registry::instance() ) )->query_args() ), array_keys( $route[0]['args'] ) );
		$this->assertTrue( is_callable( $route['schema'] ) );
		$this->assertSame( ( new ProductsController( new Registry() ) )->get_item_schema(), call_user_func( $route['schema'] ) );
		$this->assertSame( 'bcm-product', call_user_func( $route['schema'] )['title'] );
	}

	public function test_every_read_route_publishes_its_item_schema(): void {
		foreach ( $GLOBALS['bcm_test']['routes'] as $path => $route ) {
			$endpoints = isset( $route['callback'] ) ? [ $route ] : array_filter( $route, 'is_int', ARRAY_FILTER_USE_KEY );
			$methods   = implode( ',', array_map( static fn( array $endpoint ): string => (string) $endpoint['methods'], $endpoints ) );
			if ( ! str_contains( $methods, 'GET' ) ) {
				continue;
			}
			$this->assertArrayHasKey( 'schema', $route, "$path has no schema." );
			$this->assertTrue( is_array( $route['schema'] ) && $route['schema'][0] instanceof ReadController && 'get_item_schema' === $route['schema'][1], "$path's schema is its controller's get_item_schema." );
			$this->assertSame( 'object', call_user_func( $route['schema'] )['type'] );
		}
	}

	public function test_malformed_arguments_are_refused_400_before_any_query(): void {
		$cases = [
			'per_page=abc'       => [ 'per_page' => 'abc' ],
			'orderby=DROP'       => [ 'orderby' => 'DROP' ],
			'orderby=name;DROP'  => [ 'orderby' => 'name;DROP TABLE wp_posts' ],
			'mode=pages'         => [ 'mode' => 'pages' ],
			'order=sideways'     => [ 'order' => 'sideways' ],
			'page=0'             => [ 'page' => '0' ],
			'unknown operator'   => [
				'stock_status' => [
					'operator' => 'like',
					'value'    => [ 'instock' ],
				],
			],
			'injected term id'   => [
				'category_ids' => [
					'operator' => 'isAny',
					'value'    => [ '1 OR 1=1' ],
				],
			],
			'unknown choice'     => [
				'status' => [
					'operator' => 'isAny',
					'value'    => [ 'trash' ],
				],
			],
			'filter as a string' => [ 'featured' => '1' ],
		];
		foreach ( $cases as $case => $params ) {
			bcm_test_reset();
			$GLOBALS['bcm_test']['logged_in']                  = true;
			$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
			( new Plugin() )->boot();
			do_action( 'rest_api_init' );

			$result = self::get( $params );
			$this->assertInstanceOf( \WP_Error::class, $result, $case );
			$this->assertSame( 'rest_invalid_param', $result->get_error_code(), $case );
			$this->assertSame( 400, $result->get_error_data()['status'], $case );
			$this->assertSame( array_keys( $params ), array_keys( $result->get_error_data()['params'] ), $case );
			$this->assertSame( [], $GLOBALS['wpdb']->queries, "$case ran a query." );
			$this->assertSame( [], $GLOBALS['wpdb']->prepared, "$case prepared a statement." );
		}
	}

	public function test_a_well_formed_read_reaches_the_query_builder(): void {
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT ] = '57';
		$response = self::get(
			[
				'per_page'     => '500',
				'orderby'      => 'name,id',
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [ 'outofstock' ],
				],
			]
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'items'      => [],
				'pagination' => [
					'page'       => 1,
					'perPage'    => 100,
					'total'      => 57,
					'totalPages' => 1,
				],
			],
			$response->get_data()
		);
		$this->assertCount( 2, $GLOBALS['wpdb']->queries, 'The count, then the page, which found no rows to hydrate.' );
		$this->assertSame( 1, preg_match( \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT, $GLOBALS['wpdb']->queries[0] ) );
		$this->assertStringContainsString( "l.stock_status IN ('outofstock')", $GLOBALS['wpdb']->queries[0] );
		$this->assertSame( 1, preg_match( \BulkCatalogueManager\Tests\Support\SqlEvaluator::PAGE, $GLOBALS['wpdb']->queries[1] ) );
		$this->assertStringEndsWith( 'LIMIT 100 OFFSET 0', $GLOBALS['wpdb']->queries[1] );
	}

	public function test_a_read_returns_hydrated_items_for_the_requested_fields(): void {
		$GLOBALS['bcm_test']['posts'][41] = [
			'post_type'   => 'product',
			'post_title'  => 'Blue shirt',
			'post_status' => 'draft',
		];
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT ] = '1';
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::PAGE ]  = [
			[
				'id'        => '41',
				'parent_id' => '0',
			],
		];
		$response = self::get( [ 'fields' => 'name,status' ] );
		$this->assertSame(
			[
				'items'      => [
					[
						'id'        => 41,
						'parent_id' => 0,
						'name'      => 'Blue shirt',
						'status'    => 'draft',
					],
				],
				'pagination' => [
					'page'       => 1,
					'perPage'    => 25,
					'total'      => 1,
					'totalPages' => 1,
				],
			],
			$response->get_data()
		);
		$schema = call_user_func( $GLOBALS['bcm_test']['routes']['/bcm/v1/products']['schema'] );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data()['items'][0], $schema, 'item' ) );
		$this->assertSame( 'integer', $schema['properties']['parent_id']['type'] );
		$this->assertSame( 'string', $schema['properties']['thumbnail_url']['type'] );
	}

	public function test_defaults_fill_paging_and_mode(): void {
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT ] = '51';
		$response = self::get( [] );
		$this->assertSame(
			[
				'page'       => 1,
				'perPage'    => 25,
				'total'      => 51,
				'totalPages' => 3,
			],
			$response->get_data()['pagination']
		);
		$this->assertStringContainsString( "p.post_type IN ('product')", $GLOBALS['wpdb']->queries[0] );
	}

	public function test_an_anonymous_read_is_refused(): void {
		$GLOBALS['bcm_test']['logged_in'] = false;
		$GLOBALS['bcm_test']['caps']      = [];
		$result                           = self::get( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bcm_forbidden', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}
}
