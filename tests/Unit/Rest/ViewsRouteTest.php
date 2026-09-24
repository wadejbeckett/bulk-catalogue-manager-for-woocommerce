<?php
/**
 * The saved views routes (SPEC §4.1, §10, §12.1, §13; T52): registered
 * through Routes behind Access::can_read; each user's views in their own
 * `bulkcat_views` user meta, so user A never lists, reads, changes or deletes
 * user B's; the view sanitised against the explicit schema on save; names
 * sanitised and unique; at most 50 views; one default; reorder by ids.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Data\Registry as DataRegistry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ViewsController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/user-meta-stubs.php';

final class ViewsRouteTest extends TestCase {
	private const A = 7;
	private const B = 8;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['caps']      = [ 'manage_woocommerce' => true ];
		self::as_user( self::A );
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	private static function as_user( int $id ): void {
		$GLOBALS['bulkcat_test']['user_id'] = $id;
	}

	/**
	 * Dispatch a request through the stub server: route match, argument
	 * validation, permission callback, handler.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below bulkcat/v1.
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function call( string $method, string $route, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bulkcat/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private static function http_status( \WP_REST_Response|\WP_Error $result ): int {
		return $result instanceof \WP_REST_Response ? $result->get_status() : (int) $result->get_error_data()['status'];
	}

	/**
	 * A view as the client sends it.
	 *
	 * @param array<string, mixed> $over Keys to change.
	 * @return array<string, mixed>
	 */
	private static function view( array $over = [] ): array {
		return $over + [
			'type'       => 'table',
			'page'       => 3,
			'perPage'    => 50,
			'search'     => 'shirt',
			'filters'    => [
				[
					'field'    => 'stock_status',
					'operator' => 'isAny',
					'value'    => [ 'outofstock' ],
				],
			],
			'sort'       => [
				'field'     => 'regular_price',
				'direction' => 'desc',
			],
			'fields'     => [ 'sku', 'regular_price', 'stock_status' ],
			'titleField' => 'name',
			'mediaField' => 'thumbnail',
			'showMedia'  => true,
			'layout'     => [ 'density' => 'compact' ],
		];
	}

	private static function create( string $name, array $over = [] ): \WP_REST_Response|\WP_Error {
		return self::call(
			'POST',
			'/views',
			[
				'name'     => $name,
				'view'     => self::view( $over ),
				'category' => [
					'id'              => 4,
					'ids'             => [ 4, 5 ],
					'includeChildren' => true,
				],
			]
		);
	}

	public function test_every_route_registers_behind_can_read(): void {
		$routes = $GLOBALS['bulkcat_test']['routes'];
		$want   = [
			'/bulkcat/v1/views'                      => [ 'GET', 'POST' ],
			'/bulkcat/v1/views/reorder'              => [ 'POST' ],
			'/bulkcat/v1/views/(?P<id>[a-f0-9]{12})' => [ 'PATCH, PUT', 'DELETE' ],
			'/bulkcat/v1/views/(?P<id>[a-f0-9]{12})/default' => [ 'POST' ],
		];
		foreach ( $want as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			$endpoints = array_values( array_filter( $routes[ $route ], 'is_int', ARRAY_FILTER_USE_KEY ) );
			$this->assertSame( $methods, array_column( $endpoints, 'methods' ) );
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( [ Access::class, 'can_read' ], $endpoint['permission_callback'] );
			}
			$this->assertTrue( is_callable( $routes[ $route ]['schema'] ) );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['wrong'], 'The registrar refused nothing.' );
	}

	public function test_a_user_without_the_read_capability_is_refused_everywhere(): void {
		$GLOBALS['bulkcat_test']['caps'] = [];
		$this->assertSame( 403, self::http_status( self::call( 'GET', '/views' ) ) );
		$this->assertSame( 403, self::http_status( self::create( 'Mine' ) ) );
		$this->assertSame( 403, self::http_status( self::call( 'POST', '/views/reorder', [ 'ids' => [] ] ) ) );
		$this->assertSame( 403, self::http_status( self::call( 'DELETE', '/views/abcdef012345' ) ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['user_meta'] ?? [], 'Nothing was stored.' );

		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$refused                              = self::call( 'GET', '/views' );
		$this->assertSame( 401, self::http_status( $refused ) );
		$this->assertSame( 'bulkcat_forbidden', $refused->get_error_code() );
	}

	public function test_create_read_update_delete(): void {
		$this->assertSame(
			[
				'items'   => [],
				'default' => null,
				'max'     => 50,
			],
			self::call( 'GET', '/views' )->get_data()
		);

		$created = self::create( '  <b>Out of stock</b> shirts ' );
		$this->assertSame( 201, $created->get_status() );
		$item = $created->get_data()['item'];
		$this->assertSame( 1, preg_match( '/^[a-f0-9]{12}$/', $item['id'] ) );
		$this->assertSame( 'Out of stock shirts', $item['name'], 'The name is sanitised.' );
		$this->assertFalse( $item['isDefault'] );
		$view = (array) $item['view'];
		$this->assertArrayNotHasKey( 'page', $view, 'A saved view opens on page 1.' );
		$this->assertSame( self::view()['filters'], $view['filters'] );
		$this->assertSame( [ 'sku', 'regular_price', 'stock_status' ], $view['fields'] );
		$this->assertEquals( (object) [ 'density' => 'compact' ], $view['layout'] );
		$this->assertEquals(
			(object) [
				'id'              => 4,
				'ids'             => [ 4, 5 ],
				'includeChildren' => true,
			],
			$item['category']
		);

		// Stored as JSON in the user's own meta.
		$stored = json_decode( $GLOBALS['bulkcat_test']['user_meta'][ self::A ][ ViewsController::META_KEY ], true );
		$this->assertSame( $item['id'], $stored['views'][0]['id'] );

		$listed = self::call( 'GET', '/views' )->get_data();
		$this->assertSame( [ $item['id'] ], array_column( $listed['items'], 'id' ) );

		$renamed = self::call(
			'PATCH',
			'/views/' . $item['id'],
			[
				'name' => 'Sold out',
				'view' => self::view( [ 'type' => 'grid' ] ),
			]
		);
		$this->assertSame( 200, $renamed->get_status() );
		$this->assertSame( 'Sold out', $renamed->get_data()['item']['name'] );
		$this->assertSame( 'grid', ( (array) $renamed->get_data()['item']['view'] )['type'], 'The layout is part of the saved view.' );
		$this->assertNotNull( $renamed->get_data()['item']['category'], 'A PATCH without category keeps it.' );

		$cleared = self::call( 'PATCH', '/views/' . $item['id'], [ 'category' => null ] );
		$this->assertNull( $cleared->get_data()['item']['category'] );

		$deleted = self::call( 'DELETE', '/views/' . $item['id'] );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertSame( [], $deleted->get_data()['items'] );
		$this->assertSame( 404, self::http_status( self::call( 'DELETE', '/views/' . $item['id'] ) ) );
	}

	public function test_one_users_views_are_invisible_to_another(): void {
		$id = self::create( 'Mine' )->get_data()['item']['id'];

		self::as_user( self::B );
		$this->assertSame( [], self::call( 'GET', '/views' )->get_data()['items'], 'B lists none of A\'s views.' );
		foreach (
			[
				[ 'PATCH', '/views/' . $id, [ 'name' => 'Taken' ] ],
				[ 'DELETE', '/views/' . $id, [] ],
				[ 'POST', '/views/' . $id . '/default', [] ],
			] as [ $method, $route, $params ]
		) {
			$refused = self::call( $method, $route, $params );
			$this->assertSame( 404, self::http_status( $refused ), "$method $route" );
			$this->assertSame( 'bulkcat_view_not_found', $refused->get_error_code() );
		}
		$this->assertSame( 400, self::http_status( self::call( 'POST', '/views/reorder', [ 'ids' => [ $id ] ] ) ), 'B cannot order A\'s ids.' );
		// B may use the same name: names are unique per user.
		$this->assertSame( 201, self::create( 'Mine' )->get_status() );

		self::as_user( self::A );
		$mine = self::call( 'GET', '/views' )->get_data()['items'];
		$this->assertSame( [ $id ], array_column( $mine, 'id' ) );
		$this->assertSame( 'Mine', $mine[0]['name'], 'A\'s view is untouched.' );
	}

	public function test_the_view_is_sanitised_against_the_schema(): void {
		$bad = [
			'layout type'     => [ 'type' => 'kanban' ],
			'page size'       => [ 'perPage' => 30 ],
			'unknown column'  => [ 'fields' => [ 'sku', '_regular_price' ] ],
			'unknown filter'  => [
				'filters' => [
					[
						'field'    => 'post_status',
						'operator' => 'isAny',
						'value'    => [ 'publish' ],
					],
				],
			],
			'operator'        => [
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'is any; drop',
						'value'    => 'publish',
					],
				],
			],
			'filter value'    => [
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'isAny',
						'value'    => [ [ 'nested' ] ],
					],
				],
			],
			'sort direction'  => [
				'sort' => [
					'field'     => 'sku',
					'direction' => 'sideways',
				],
			],
			'sort field'      => [ 'sort' => [ 'field' => 'nope' ] ],
			'mode'            => [ 'mode' => 'everything' ],
			'show media'      => [ 'showMedia' => 'yes' ],
			'layout list'     => [ 'layout' => [ 'a', 'b' ] ],
			'search too long' => [ 'search' => str_repeat( 'x', 201 ) ],
		];
		foreach ( $bad as $case => $over ) {
			$refused = self::create( 'Case ' . $case, $over );
			$this->assertSame( 400, self::http_status( $refused ), $case );
			$this->assertSame( 'bulkcat_invalid_view', $refused->get_error_code(), $case );
			$this->assertStringContainsString( 'could not be saved', $refused->get_error_message() );
		}
		foreach ( [
			'a list'   => [ 'type', 'table' ],
			'a string' => 'table',
		] as $case => $view ) {
			$refused = self::call(
				'POST',
				'/views',
				[
					'name' => 'X',
					'view' => $view,
				]
			);
			$this->assertSame( 'bulkcat_invalid_view', $refused->get_error_code(), $case );
		}
		$refused = self::call(
			'POST',
			'/views',
			[
				'name'     => 'X',
				'view'     => self::view(),
				'category' => [ 'id' => 'shirts' ],
			]
		);
		$this->assertSame( 'bulkcat_invalid_view', $refused->get_error_code(), 'A malformed category is refused.' );
		$this->assertSame( [], self::call( 'GET', '/views' )->get_data()['items'], 'Nothing refused was stored.' );

		// Strings are cleaned; an unrecognised plain key is kept as plain JSON,
		// one with any other name dropped; too deep a value is cut off.
		$kept = self::create(
			'Kept',
			[
				'search'       => '<script>x</script>shirt',
				'futureKey'    => [
					'a' => '<i>b</i>',
					'n' => 2,
				],
				'bad-key!'     => 1,
				'deep'         => [ 'a' => [ 'b' => [ 'c' => [ 'd' => 1 ] ] ] ],
				'groupByField' => null,
			]
		);
		$this->assertSame( 201, $kept->get_status() );
		$view = (array) $kept->get_data()['item']['view'];
		$this->assertSame( 'xshirt', $view['search'] );
		$this->assertSame(
			[
				'a' => 'b',
				'n' => 2,
			],
			$view['futureKey']
		);
		$this->assertArrayNotHasKey( 'bad-key!', $view );
		$this->assertSame( [ 'a' => [ 'b' => [ 'c' => null ] ] ], $view['deep'] );
		$this->assertArrayHasKey( 'groupByField', $view );
		$this->assertNull( $view['groupByField'] );
	}

	public function test_names_are_required_short_and_unique(): void {
		foreach ( [ '', '   ', '<b></b>', null, 12 ] as $name ) {
			$refused = self::call(
				'POST',
				'/views',
				[
					'name' => $name,
					'view' => self::view(),
				]
			);
			$this->assertSame( 'bulkcat_view_name_required', $refused->get_error_code(), (string) json_encode( $name ) );
		}
		$this->assertSame( 'bulkcat_view_name_required', self::create( str_repeat( 'é', 61 ) )->get_error_code() );
		$this->assertSame( 201, self::create( str_repeat( 'é', 60 ) )->get_status() );

		$first = self::create( 'Low stock' )->get_data()['item']['id'];
		$taken = self::create( 'low STOCK' );
		$this->assertSame( 'bulkcat_view_exists', $taken->get_error_code() );
		$this->assertStringContainsString( '"low STOCK"', $taken->get_error_message() );

		$second = self::create( 'Other' )->get_data()['item']['id'];
		$this->assertSame( 'bulkcat_view_exists', self::call( 'PATCH', '/views/' . $second, [ 'name' => 'Low Stock' ] )->get_error_code() );
		$this->assertSame( 200, self::call( 'PATCH', '/views/' . $first, [ 'name' => 'LOW STOCK' ] )->get_status(), 'A view may be renamed to its own name.' );
	}

	public function test_a_user_keeps_at_most_fifty_views(): void {
		for ( $n = 1; $n <= ViewsController::MAX_VIEWS; $n++ ) {
			$this->assertSame( 201, self::create( "View $n" )->get_status() );
		}
		$refused = self::create( 'One too many' );
		$this->assertSame( 400, self::http_status( $refused ) );
		$this->assertSame( 'bulkcat_too_many_views', $refused->get_error_code() );
		$this->assertStringContainsString( '50 saved views', $refused->get_error_message() );
		$this->assertCount( 50, self::call( 'GET', '/views' )->get_data()['items'] );

		// Another user has their own allowance.
		self::as_user( self::B );
		$this->assertSame( 201, self::create( 'One too many' )->get_status() );
	}

	public function test_one_view_is_the_default(): void {
		$one = self::create( 'One' )->get_data()['item']['id'];
		$two = self::call(
			'POST',
			'/views',
			[
				'name'      => 'Two',
				'view'      => self::view(),
				'isDefault' => true,
			]
		)->get_data();
		$this->assertSame( $two['item']['id'], $two['default'] );
		$this->assertTrue( $two['item']['isDefault'] );

		$answer = self::call( 'POST', '/views/' . $one . '/default' )->get_data();
		$this->assertSame( $one, $answer['default'] );
		$this->assertSame( [ true, false ], array_column( $answer['items'], 'isDefault' ), 'Only one view is the default.' );

		$kept = self::call( 'POST', '/views/' . $two['item']['id'] . '/default', [ 'default' => false ] )->get_data();
		$this->assertSame( $one, $kept['default'], 'Clearing another view leaves the default alone.' );

		$cleared = self::call( 'POST', '/views/' . $one . '/default', [ 'default' => false ] )->get_data();
		$this->assertNull( $cleared['default'] );

		self::call( 'POST', '/views/' . $one . '/default' );
		$this->assertNull( self::call( 'DELETE', '/views/' . $one )->get_data()['default'], 'Deleting the default clears it.' );
	}

	public function test_reorder_takes_every_id_once(): void {
		$ids = [];
		foreach ( [ 'A', 'B', 'C' ] as $name ) {
			$ids[] = self::create( $name )->get_data()['item']['id'];
		}
		$order  = [ $ids[2], $ids[0], $ids[1] ];
		$answer = self::call( 'POST', '/views/reorder', [ 'ids' => $order ] );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( $order, array_column( $answer->get_data()['items'], 'id' ) );
		$this->assertSame( [ 'C', 'A', 'B' ], array_column( self::call( 'GET', '/views' )->get_data()['items'], 'name' ) );

		foreach (
			[
				'missing one' => [ $ids[0], $ids[1] ],
				'twice'       => [ $ids[0], $ids[0], $ids[1] ],
				'unknown'     => [ $ids[0], $ids[1], 'abcdefabcdef' ],
				'empty'       => [],
			] as $case => $bad
		) {
			$refused = self::call( 'POST', '/views/reorder', [ 'ids' => $bad ] );
			$this->assertSame( 'bulkcat_invalid_view', $refused->get_error_code(), $case );
		}
		$this->assertSame( 400, self::http_status( self::call( 'POST', '/views/reorder' ) ), 'ids is required.' );
		$this->assertSame( $order, array_column( self::call( 'GET', '/views' )->get_data()['items'], 'id' ), 'A refused order changes nothing.' );
	}

	public function test_a_malformed_stored_value_reads_as_no_views(): void {
		foreach ( [ 'not json', '[]', '{"views":"x"}', '{"views":[{"id":"../x","name":"n","view":{}}],"default":"../x"}' ] as $raw ) {
			$GLOBALS['bulkcat_test']['user_meta'][ self::A ][ ViewsController::META_KEY ] = $raw;
			$answer = self::call( 'GET', '/views' )->get_data();
			$this->assertSame( [], $answer['items'], $raw );
			$this->assertNull( $answer['default'] );
		}
	}

	public function test_a_backslash_survives_the_round_trip(): void {
		$id = self::create( 'Path', [ 'search' => 'a\\b' ] )->get_data()['item']['id'];
		$this->assertSame( 'a\\b', ( (array) self::call( 'GET', '/views' )->get_data()['items'][0]['view'] )['search'] );
		$this->assertSame( 1, preg_match( '/^[a-f0-9]{12}$/', $id ) );
	}

	public function test_bulkcat_views_is_registered_for_uninstall(): void {
		$this->assertContains( ViewsController::META_KEY, DataRegistry::plugin()->user_meta() );
	}
}
