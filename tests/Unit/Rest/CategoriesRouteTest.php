<?php
/**
 * `GET /bulkcat/v1/categories` (T21; SPEC §7, §12.1, §13): registered through the
 * registrar behind Access::can_read with the grid's generated search, mode
 * and filter arguments and its own item schema; refuses a logged-out visitor
 * (401) and a subscriber (403) before any work; answers a shop manager 200
 * with the nested tree whose counts follow the grid's filters; refuses a
 * malformed filter 400 before any query; adds the cache's term hooks.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Categories\Cache;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\CategoriesController;
use BulkCatalogueManager\Rest\RouteSchema;
use BulkCatalogueManager\Tests\Unit\Categories\CatalogueFixture;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Categories/CatalogueFixture.php';

final class CategoriesRouteTest extends TestCase {
	/**
	 * Matches the grouped statement.
	 */
	private const GROUPED = '/^SELECT a\.ancestor AS term_id, /';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		CatalogueFixture::seed();
		$GLOBALS['wpdb']->responses[ self::GROUPED ] = CatalogueFixture::answer();
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a GET to /bulkcat/v1/categories with query parameters.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private static function get( array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/categories' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_route_registers_behind_can_read_with_the_grid_arguments_and_a_schema(): void {
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/categories'] ?? null;
		$this->assertNotNull( $route );
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[0]['permission_callback'] );

		$schema = new RouteSchema( Registry::instance() );
		$this->assertSame( [ 'search', 'mode', ...array_keys( $schema->filter_args() ) ], array_keys( $route[0]['args'] ) );
		$this->assertArrayHasKey( 'category_ids', $route[0]['args'] );
		$this->assertArrayNotHasKey( 'per_page', $route[0]['args'] );
		$this->assertArrayNotHasKey( 'orderby', $route[0]['args'] );

		$item = call_user_func( $route['schema'] );
		$this->assertSame( 'bulkcat-category', $item['title'] );
		$this->assertSame( [ 'id', 'name', 'slug', 'description', 'parent', 'depth', 'order', 'count', 'countWithDescendants', 'children' ], array_keys( $item['properties'] ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['wrong'] );
	}

	public function test_a_logged_out_visitor_is_refused_401_before_any_work(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$result                               = self::get();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['get_terms_calls'] ?? [] );
		$this->assertSame( [], self::counted() );
	}

	public function test_a_subscriber_is_refused_403_before_any_work(): void {
		$GLOBALS['bulkcat_test']['caps'] = [ 'read' => true ];
		$result                          = self::get();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['get_terms_calls'] ?? [] );
		$this->assertSame( [], self::counted() );
	}

	public function test_a_shop_manager_gets_the_nested_tree_with_counts(): void {
		$response = self::get();
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$tree = $response->get_data();
		$this->assertSame( [ 'Clothing', 'Loop A', 'Orphans', 'Shoes' ], array_column( $tree, 'name' ) );
		$flat = CatalogueFixture::flatten( $tree );
		foreach ( CatalogueFixture::naive() as $id => [ $own, $total ] ) {
			$this->assertSame( [ $own, $total ], [ $flat[ $id ]['count'], $flat[ $id ]['countWithDescendants'] ], "Counts of $id." );
		}
		$this->assertSame( 1, self::term_reads() );
		$this->assertCount( 1, self::counted() );

		$this->assertSame( $tree, self::get()->get_data() );
		$this->assertSame( 1, self::term_reads(), 'The second read is served from the cache.' );
		$this->assertCount( 1, self::counted() );
	}

	public function test_counts_follow_the_grid_filters_but_not_its_category_filter(): void {
		$response = self::get(
			[
				'status'       => [
					'operator' => 'isAny',
					'value'    => [ 'draft' ],
				],
				'category_ids' => [
					'operator' => 'isAny',
					'value'    => [ 8 ],
				],
				'per_page'     => 5,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$flat = CatalogueFixture::flatten( $response->get_data() );
		foreach ( CatalogueFixture::naive( [ 'draft' ] ) as $id => [ $own, $total ] ) {
			$this->assertSame( [ $own, $total ], [ $flat[ $id ]['count'], $flat[ $id ]['countWithDescendants'] ], "Draft counts of $id." );
		}
		$this->assertCount( 1, self::counted() );
		$this->assertStringContainsString( "(p.post_status = 'draft' AND NOT EXISTS", self::counted()[0] );
		$this->assertStringNotContainsString( 'tt.term_id IN', self::counted()[0], 'The category filter does not narrow the counts.' );
	}

	public function test_a_malformed_filter_is_refused_400_before_any_query(): void {
		$result = self::get(
			[
				'status' => [
					'operator' => 'isAny',
					'value'    => [ 'trash' ],
				],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['get_terms_calls'] ?? [] );
		$this->assertSame( [], self::counted() );
	}

	public function test_registering_the_routes_adds_the_cache_hooks(): void {
		foreach ( array_keys( Cache::HOOKS ) as $hook ) {
			$this->assertNotEmpty( $GLOBALS['bulkcat_test']['filters'][ $hook ] ?? [], "$hook is hooked." );
		}
	}

	public function test_the_controller_publishes_its_item_schema(): void {
		$controller = new CategoriesController( new Registry() );
		$this->assertSame( 'object', $controller->get_item_schema()['type'] );
		$this->assertTrue( $controller->get_item_schema()['properties']['id']['required'] );
	}
	/**
	 * The statements run, apart from the read of the term tables and its
	 * meta cache priming (1.4.2: the tree reads every category unfiltered,
	 * through $wpdb, not get_terms()).
	 *
	 * @return list<string>
	 */
	private static function counted(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => ! self::is_term_read( $sql ) ) );
	}

	/**
	 * How many times the term tables were read for the tree.
	 */
	private static function term_reads(): int {
		return count( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT t.term_id, t.name' ) ) );
	}

	private static function is_term_read( string $sql ): bool {
		return str_starts_with( $sql, 'SELECT t.term_id, t.name' ) || str_starts_with( $sql, '/* update_meta_cache */' );
	}
}
