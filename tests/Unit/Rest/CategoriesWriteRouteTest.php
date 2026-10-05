<?php
/**
 * The category write routes (T63, T64; SPEC §4.7, §7, §12.1, §13) through
 * the REST dispatcher: `POST /categories` beside the tree's `GET`,
 * `PATCH /categories/{id}` and `POST /categories/{id}/move`, each behind
 * Access::can_manage_terms; no delete route anywhere under `/categories`
 * (SPEC §2); a create answering 201 and an edit and a move 200 with the
 * category in the tree's node shape and their `tree` batch of one; 401 for
 * a visitor, 403 for a subscriber and for a shop manager without
 * `manage_product_terms`, 404 for an unknown id and 400 for a move inside
 * itself, each with nothing written.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\Writer;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\CategoriesWriteController;
use BulkCatalogueManager\Tests\Unit\Categories\CatalogueFixture;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';
require_once dirname( __DIR__ ) . '/Categories/CatalogueFixture.php';

final class CategoriesWriteRouteTest extends TestCase {
	private InlineStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                    = true;
		$GLOBALS['bulkcat_test']['user_id']                      = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce']   = true;
		$GLOBALS['bulkcat_test']['caps']['manage_product_terms'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_product_terms']   = true;
		$GLOBALS['bulkcat_test']['caps']['edit_term']            = true;
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html']      = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->store = new InlineStore();
		CatalogueFixture::seed();
	}

	/**
	 * Dispatch a request.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function send( string $method, string $route, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bulkcat/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function assert_refused( mixed $response, string $code, int $status ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'Nothing was written.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['inserted_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['updated_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['term_meta_updates'] ?? [] );
		return $response;
	}

	/**
	 * One request to each write route.
	 *
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private static function writes(): array {
		return [
			[ 'POST', '/categories', [ 'name' => 'Hats' ] ],
			[ 'PATCH', '/categories/3', [ 'name' => 'Tops' ] ],
			[
				'POST',
				'/categories/2/move',
				[
					'parent'   => 1,
					'position' => 0,
				],
			],
			[ 'POST', '/categories/5/default', [] ],
		];
	}

	public function test_make_default_answers_the_node_and_sets_woocommerce_default(): void {
		$GLOBALS['bulkcat_test']['options']['default_product_cat'] = 9;
		$GLOBALS['bulkcat_test']['caps']['edit_term:5']            = true;
		$GLOBALS['bulkcat_test']['user_caps'][12]['edit_term:5']   = true;
		$response = self::send( 'POST', '/categories/5/default' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['item']['default'] );
		$this->assertSame( 5, $GLOBALS['bulkcat_test']['options']['default_product_cat'] );
		$this->assertSame( [ [ 5, 'term_default', 9, 5, 'applied' ] ], $this->store->journal() );
	}

	public function test_the_routes_need_manage_product_terms_and_none_deletes(): void {
		$routes = $GLOBALS['bulkcat_test']['routes'];
		$create = $routes[ '/bulkcat/v1' . CategoriesWriteController::ROUTE ];
		$this->assertSame( [ 'GET', 'POST' ], [ $create[0]['methods'], $create[1]['methods'] ], 'The create joins the tree read on one route.' );
		$this->assertSame( [ Access::class, 'can_read' ], $create[0]['permission_callback'] );
		$this->assertSame( 'bulkcat-category', call_user_func( $create['schema'] )['title'], 'The read keeps its schema.' );
		$this->assertSame( [ Access::class, 'can_manage_terms' ], $create[1]['permission_callback'] );
		$this->assertTrue( $create[1]['args']['name']['required'] );
		$this->assertSame( [ 'name', 'slug', 'description', 'parent', 'display_type', 'thumbnail_id' ], array_keys( $create[1]['args'] ) );

		$item = $routes[ '/bulkcat/v1' . CategoriesWriteController::ITEM_ROUTE ];
		$this->assertSame( [ 'GET', [ Access::class, 'can_manage_terms' ] ], [ $item[0]['methods'], $item[0]['permission_callback'] ], 'Ticket 22: one category\'s details for its editor.' );
		$this->assertSame( [ 'id' ], array_keys( $item[0]['args'] ) );
		$this->assertSame( [ 'PATCH', [ Access::class, 'can_manage_terms' ] ], [ $item[1]['methods'], $item[1]['permission_callback'] ] );
		$this->assertSame( [ 'id', 'name', 'slug', 'description', 'parent', 'display_type', 'thumbnail_id' ], array_keys( $item[1]['args'] ) );
		$move = $routes[ '/bulkcat/v1' . CategoriesWriteController::MOVE_ROUTE ];
		$this->assertSame( [ 'POST', [ Access::class, 'can_manage_terms' ] ], [ $move[0]['methods'], $move[0]['permission_callback'] ] );
		$this->assertSame( [ 'id', 'parent', 'position' ], array_keys( $move[0]['args'] ) );
		$default = $routes[ '/bulkcat/v1' . CategoriesWriteController::DEFAULT_ROUTE ];
		$this->assertSame( [ 'POST', [ Access::class, 'can_manage_terms' ] ], [ $default[0]['methods'], $default[0]['permission_callback'] ] );

		$methods = [];
		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			foreach ( str_starts_with( $route, '/bulkcat/v1/categories' ) ? $endpoints : [] as $endpoint ) {
				$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
			}
		}
		sort( $methods );
		$this->assertSame( [ 'GET', 'GET', 'PATCH', 'POST', 'POST', 'POST' ], $methods );
		$this->assertNotContains( 'DELETE', $methods, 'Deleting a category stays on the native screen (SPEC §2).' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['wrong'] );
	}

	public function test_the_tree_read_still_answers_beside_the_create(): void {
		$GLOBALS['wpdb']->responses = [ '/^SELECT a\.ancestor AS term_id, /' => CatalogueFixture::answer() ] + $GLOBALS['wpdb']->responses;
		$response                   = self::send( 'GET', '/categories' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( [ 5, 2 ], array_column( $response->get_data()[0]['children'], 'id' ) );
	}

	public function test_a_create_answers_201_with_the_node_and_its_batch(): void {
		$response = self::send(
			'POST',
			'/categories',
			[
				'name'        => 'Hats',
				'parent'      => 6,
				'description' => 'Warm ones',
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_message() : '' );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'item', 'batch_id', 'status', 'results' ], array_keys( $data ) );
		$this->assertSame( [ 'id', 'name', 'slug', 'description', 'parent', 'depth', 'order', 'default', 'display_type', 'thumbnail_id', 'thumbnail_url' ], array_keys( $data['item'] ) );
		$this->assertSame( [ 12, 'Hats', 'hats', 6, 1 ], [ $data['item']['id'], $data['item']['name'], $data['item']['slug'], $data['item']['parent'], $data['item']['depth'] ] );
		$this->assertSame( 'complete', $data['status'] );
		$batch = $this->store->batch( $data['batch_id'] );
		$this->assertSame( [ 'tree', 12 ], [ $batch['source'], $batch['user_id'] ] );
		$this->assertContains( [ 12, 'term_name', null, 'Hats', 'applied' ], $this->store->journal() );
	}

	public function test_an_edit_and_a_move_answer_200_and_are_journalled_as_terms(): void {
		$edit = self::send( 'PATCH', '/categories/3', [ 'name' => 'Tops' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $edit, $edit instanceof \WP_Error ? $edit->get_error_message() : '' );
		$this->assertSame( 200, $edit->get_status() );
		$this->assertSame( 'Tops', $edit->get_data()['item']['name'] );

		$move = self::send(
			'POST',
			'/categories/2/move',
			[
				'parent'   => 1,
				'position' => 0,
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $move, $move instanceof \WP_Error ? $move->get_error_message() : '' );
		$this->assertSame( 200, $move->get_status() );
		$this->assertSame( [ 2, 5 ], CategoryTree::tree()->children( 1 ) );
		$this->assertSame(
			[
				[ 3, 'term_name', 'Shirts', 'Tops', 'applied' ],
				[ 2, 'term_menu_order', 2, 0, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( [ 'term' ], array_values( array_unique( array_column( $this->store->changes, 'object_type' ) ) ) );
		$this->assertSame( [ 'tree', 'tree' ], array_column( $this->store->batches, 'source' ) );
	}

	public function test_a_visitor_is_refused_401(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		foreach ( self::writes() as [ $method, $route, $params ] ) {
			$this->assert_refused( self::send( $method, $route, $params ), 'bulkcat_forbidden', 401 );
		}
	}

	public function test_a_subscriber_is_refused_403(): void {
		$GLOBALS['bulkcat_test']['caps'] = [ 'read' => true ];
		foreach ( self::writes() as [ $method, $route, $params ] ) {
			$this->assert_refused( self::send( $method, $route, $params ), 'bulkcat_forbidden', 403 );
		}
	}

	public function test_a_shop_manager_without_manage_product_terms_is_refused_403(): void {
		$GLOBALS['bulkcat_test']['caps']['manage_product_terms'] = false;
		foreach ( self::writes() as [ $method, $route, $params ] ) {
			$refused = $this->assert_refused( self::send( $method, $route, $params ), 'bulkcat_forbidden', 403 );
			$this->assertStringContainsString( 'categories', $refused->get_error_message() );
		}
	}

	public function test_each_write_checks_the_per_term_capabilities_core_checks(): void {
		// Creating needs the taxonomy's edit_terms capability.
		$GLOBALS['bulkcat_test']['caps']['edit_product_terms'] = false;
		$this->assert_refused( self::send( 'POST', '/categories', [ 'name' => 'Hats' ] ), 'bulkcat_forbidden', 403 );
		$GLOBALS['bulkcat_test']['caps']['edit_product_terms'] = true;

		// Editing or moving one category needs edit_term on that category.
		$GLOBALS['bulkcat_test']['caps']['edit_term:3'] = false;
		$GLOBALS['bulkcat_test']['caps']['edit_term:2'] = false;
		$this->assert_refused( self::send( 'PATCH', '/categories/3', [ 'name' => 'Tops' ] ), 'bulkcat_forbidden', 403 );
		$this->assert_refused(
			self::send(
				'POST',
				'/categories/2/move',
				[
					'parent'   => 1,
					'position' => 0,
				]
			),
			'bulkcat_forbidden',
			403
		);
		$GLOBALS['bulkcat_test']['caps']['edit_term:3'] = true;
		$this->assertSame( 200, self::send( 'PATCH', '/categories/3', [ 'name' => 'Tops' ] )->get_status() );
	}

	public function test_an_unknown_id_is_404_and_a_move_inside_itself_400(): void {
		$this->assert_refused( self::send( 'PATCH', '/categories/999', [ 'name' => 'X' ] ), Writer::NOT_FOUND, 404 );
		$this->assert_refused( self::send( 'POST', '/categories/999/move', [ 'parent' => 0 ] ), Writer::NOT_FOUND, 404 );
		$refused = $this->assert_refused( self::send( 'POST', '/categories/1/move', [ 'parent' => 3 ] ), Writer::CYCLE, 400 );
		$this->assertSame( 'A category cannot be moved inside itself.', $refused->get_error_message() );
		$this->assert_refused(
			self::send(
				'POST',
				'/categories',
				[
					'name'   => 'Women',
					'parent' => 1,
				]
			),
			Writer::NAME_TAKEN,
			400
		);
	}

	public function test_malformed_arguments_are_refused_before_the_handler(): void {
		$this->assert_refused( self::send( 'POST', '/categories', [] ), 'rest_missing_callback_param', 400 );
		$this->assert_refused( self::send( 'POST', '/categories/2/move', [ 'position' => 0 ] ), 'rest_missing_callback_param', 400 );
		$this->assert_refused( self::send( 'POST', '/categories/2/move', [ 'parent' => -1 ] ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::send( 'PATCH', '/categories/2', [ 'name' => str_repeat( 'x', 201 ) ] ), 'rest_invalid_param', 400 );
	}

	public function test_one_categorys_details_are_read_for_its_editor(): void {
		$GLOBALS['bulkcat_test']['term_meta'][3]['display_type'] = 'both';
		$GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id'] = '0';
		$response = self::send( 'GET', '/categories/3' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$item = $response->get_data()['item'];
		$this->assertSame( [ 3, 'both', 0, null ], [ $item['id'], $item['display_type'], $item['thumbnail_id'], $item['thumbnail_url'] ] );
		$missing = self::send( 'GET', '/categories/999' );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
		unset( $GLOBALS['bulkcat_test']['caps']['manage_product_terms'] );
		$denied = self::send( 'GET', '/categories/3' );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
	}
}
