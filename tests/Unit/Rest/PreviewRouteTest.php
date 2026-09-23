<?php
/**
 * `POST /bcm/v1/preview` (T30; SPEC §9.1, §12.1, §13) through the REST
 * dispatcher: registered behind Access::can_write with the batch route's
 * operations, ids and token plus the sample size; an id list and a
 * selection token each answer totals, sample, warnings, unreadable,
 * selection and hash; `all` returns every row up to 1,000 and another size
 * is refused before the handler; a visitor is refused 401 and a reader 403;
 * an operation list the registry refuses is 400 with the registry's code;
 * a body without exactly one of ids and token, or with a bad token, is
 * refused; nothing is saved or written.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use BulkCatalogueManager\Tests\Support\WatchedProduct;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class PreviewRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
		$GLOBALS['bcm_test']['caps']['edit_post']          = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		SeededCatalogue::seed();
		foreach ( [ ...SeededCatalogue::PRODUCTS, ...SeededCatalogue::VARIATIONS ] as $id ) {
			$GLOBALS['bcm_test']['products'][ $id ] = new WatchedProduct(
				in_array( $id, SeededCatalogue::VARIATIONS, true ) ? 'variation' : 'simple',
				[
					'id'       => $id,
					'name'     => "Object $id",
					'featured' => false,
					'status'   => 'publish',
				]
			);
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		$this->assertNotContains( 'save', array_column( $GLOBALS['bcm_test']['watched'] ?? [], 0 ), 'Preview saved an object.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [], 'Preview wrote terms.' );
		$this->assertSame( [], $wpdb->writes, 'Preview wrote to the database.' );
	}

	/**
	 * Dispatch a POST to the route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function post( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bcm/v1/preview' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * One step making objects featured.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function operations(): array {
		return [
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'featured',
					'value' => true,
				],
			],
		];
	}

	private function assert_refused( mixed $response, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
	}

	public function test_the_route_needs_the_write_capability(): void {
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/preview'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ 'operations', 'ids', 'token', 'sample_size' ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['operations']['required'] );
		$this->assertSame( [ 25, 100, 'all' ], $route[0]['args']['sample_size']['enum'] );
		$this->assertSame( 25, $route[0]['args']['sample_size']['default'] );
	}

	public function test_an_id_list_answers_the_preview(): void {
		$response = self::post(
			[
				'operations' => self::operations(),
				'ids'        => [ 104, 201, 999 ],
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'totals', 'summary', 'sample', 'warnings', 'unreadable', 'selection', 'hash' ], array_keys( $data ) );
		$this->assertSame( 2, $data['totals']['matched'] );
		$this->assertSame( 1, $data['totals']['would_change'] );
		$this->assertEquals( (object) [ 'Featured belongs to the product, not to each variation.' => 1 ], $data['totals']['would_fail'] );
		$this->assertSame( [ 104, 201 ], array_column( $data['sample'], 'object_id' ) );
		$this->assertSame( [ 999 ], $data['unreadable'] );
		$this->assertSame(
			[
				'kind'             => 'ids',
				'count'            => 3,
				'variations_added' => 0,
				'parents_left_out' => 0,
			],
			$data['selection']
		);
		$this->assertSame(
			'{}',
			wp_json_encode(
				self::post(
					[
						'operations' => self::operations(),
						'ids'        => [ 104 ],
					]
				)->get_data()['totals']['would_fail']
			),
			'An empty reason map is a JSON object.'
		);
	}

	public function test_a_token_answers_the_filter_and_its_current_count(): void {
		$token    = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ), 5 ) );
		$response = self::post(
			[
				'operations' => self::operations(),
				'token'      => $token,
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame(
			[
				'kind'             => 'filter',
				'count'            => 5,
				'current_count'    => 7,
				'variations_added' => 0,
				'parents_left_out' => 0,
			],
			$data['selection']
		);
		$this->assertSame( 7, $data['totals']['would_change'] );
		$this->assertNotSame(
			$data['hash'],
			self::post(
				[
					'operations' => self::operations(),
					'token'      => SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_VARIATIONS ] ), 2 ) ),
				]
			)->get_data()['hash'],
			'Another selection, another hash.'
		);
	}

	public function test_sample_sizes(): void {
		$body = [
			'operations' => self::operations(),
			'ids'        => [ ...SeededCatalogue::PRODUCTS, ...SeededCatalogue::VARIATIONS ],
		];
		$this->assertCount( 9, self::post( $body )->get_data()['sample'], 'Fewer rows than the default 25.' );
		$this->assertCount( 9, self::post( $body + [ 'sample_size' => 'all' ] )->get_data()['sample'] );
		$this->assertCount( 9, self::post( $body + [ 'sample_size' => '100' ] )->get_data()['sample'] );
		$this->assert_refused( self::post( $body + [ 'sample_size' => 50 ] ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( $body + [ 'sample_size' => 'every' ] ), 'rest_invalid_param', 400 );

		$sanitise = $GLOBALS['bcm_test']['routes']['/bcm/v1/preview'][0]['args']['sample_size']['sanitize_callback'];
		$this->assertSame( [ 25, 100, Preview::SAMPLE_ALL ], [ $sanitise( 25 ), $sanitise( '100' ), $sanitise( 'all' ) ] );
	}

	public function test_a_visitor_is_refused_401_and_a_reader_403(): void {
		$GLOBALS['bcm_test']['logged_in'] = false;
		$GLOBALS['bcm_test']['user_id']   = 0;
		$GLOBALS['bcm_test']['caps']      = [];
		$body                             = [
			'operations' => self::operations(),
			'ids'        => [ 101 ],
		];
		$this->assert_refused( self::post( $body ), 'bcm_forbidden', 401 );

		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$this->assert_refused( self::post( $body ), 'bcm_forbidden', 403 );
		$this->assertSame( [], $GLOBALS['bcm_test']['products_loaded'] ?? [], 'Nothing was loaded.' );
	}

	public function test_invalid_operations_are_refused_400_with_the_registry_codes(): void {
		$step = self::operations()[0];
		foreach ( [
			'bcm_no_operations'       => [],
			'bcm_too_many_operations' => array_fill( 0, 21, $step ),
			'bcm_unknown_action'      => [
				[
					'action' => 'nope',
					'args'   => [ 'field' => 'name' ],
				],
			],
			'bcm_field_not_accepted'  => [
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'total_sales',
						'value' => 3,
					],
				],
			],
			'bcm_invalid_operation'   => [
				[
					'action' => 'adjust',
					'args'   => [ 'field' => 'regular_price' ],
				],
			],
		] as $code => $operations ) {
			$this->assert_refused(
				self::post(
					[
						'operations' => $operations,
						'ids'        => [ 101 ],
					]
				),
				$code,
				400
			);
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['products_loaded'] ?? [], 'Nothing was loaded.' );
		$this->assert_refused( self::post( [ 'ids' => [ 101 ] ] ), 'rest_missing_callback_param', 400 );
	}

	public function test_the_selection_must_be_exactly_one_of_ids_and_token(): void {
		$this->assert_refused( self::post( [ 'operations' => self::operations() ] ), 'bcm_selection_required', 400 );
		$this->assert_refused(
			self::post(
				[
					'operations' => self::operations(),
					'ids'        => [ 101 ],
					'token'      => SelectionToken::mint( Selection::of_ids( [ 101 ] ) ),
				]
			),
			'bcm_selection_required',
			400
		);
		$this->assert_refused(
			self::post(
				[
					'operations' => self::operations(),
					'token'      => 'not-a-token',
				]
			),
			'bcm_invalid_selection',
			403
		);
	}
}
