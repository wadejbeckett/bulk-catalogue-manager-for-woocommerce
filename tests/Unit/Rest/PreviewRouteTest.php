<?php
/**
 * `POST /bulkcat/v1/preview` (T30; SPEC §9.1, §12.1, §13) through the REST
 * dispatcher: registered behind Access::can_write with the batch route's
 * operations, ids and token plus the sample size; an id list and a
 * selection token each answer totals, sample, warnings, unreadable,
 * selection and hash, then (T83) unavailable_count, scope_hash and the
 * normalised operations, all named by the route's published schema; `all` returns every row up to 1,000 and another size
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
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products']      = true;
		$GLOBALS['bulkcat_test']['caps']['edit_post']          = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		SeededCatalogue::seed();
		foreach ( [ ...SeededCatalogue::PRODUCTS, ...SeededCatalogue::VARIATIONS ] as $id ) {
			$GLOBALS['bulkcat_test']['products'][ $id ] = new WatchedProduct(
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
		$this->assertNotContains( 'save', array_column( $GLOBALS['bulkcat_test']['watched'] ?? [], 0 ), 'Preview saved an object.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['set_object_terms'] ?? [], 'Preview wrote terms.' );
		$this->assertSame( [], $wpdb->writes, 'Preview wrote to the database.' );
	}

	/**
	 * Dispatch a POST to the route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function post( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
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
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/preview'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_family_request' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ 'operations', 'ids', 'token', 'sample_size', 'serializer', 'family', 'external', 'entry', 'targets', 'typed_token' ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['operations']['required'] );
		$this->assertSame( [ 25, 100, 'all' ], $route[0]['args']['sample_size']['enum'] );
		$this->assertSame( 25, $route[0]['args']['sample_size']['default'] );
		$schema = call_user_func( $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/preview']['schema'] );
		$this->assertSame( 'bulkcat-preview', $schema['title'] );
		$this->assertSame( [ 'serializer', 'family', 'external', 'entry', 'totals', 'summary', 'sample', 'warnings', 'unreadable', 'selection', 'hash', 'unavailable_count', 'scope_hash', 'operations' ], array_keys( $schema['properties'] ), 'The published schema names every key the preview answers, in order.' );
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
		$this->assertSame( [ 'totals', 'summary', 'sample', 'warnings', 'unreadable', 'selection', 'hash', 'unavailable_count', 'scope_hash', 'operations', 'availability' ], array_keys( $data ), 'The 1.0 keys keep their order; the exact-selection keys (T83) follow them, then the Off/On block (T91).' );
		$this->assertNull( $data['availability'], 'No step sets Status.' );
		$this->assertSame( 1, $data['unavailable_count'] );
		$this->assertTrue( is_array( $data['operations'] ) && 'featured' === $data['operations'][0]['args']['field'], 'The normalised operation list comes back for the durable request intent.' );
		$this->assertSame( 3, $data['totals']['matched'], 'The exact scope keeps the missing id (T83).' );
		$this->assertSame( 2, $data['totals']['computed'] );
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
				'variations_kept'  => 0,
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

	public function test_an_id_list_is_previewed_exactly_in_id_order_with_its_scope(): void {
		$first  = self::post(
			[
				'operations' => self::operations(),
				'ids'        => [ 201, 104, 104 ],
			]
		)->get_data();
		$sorted = self::post(
			[
				'operations' => self::operations(),
				'ids'        => [ 104, 201 ],
			]
		)->get_data();
		$this->assertSame( [ 104, 201 ], array_column( $first['sample'], 'object_id' ), 'Ids are sorted and each kept once.' );
		$this->assertSame( $sorted['hash'], $first['hash'], 'The same exact selection, the same hash.' );
		$this->assertSame( 1, preg_match( '/^[a-f0-9]{64}$/', (string) $first['scope_hash'] ) );
		$this->assertSame( $sorted['scope_hash'], $first['scope_hash'] );
		$other = self::post(
			[
				'operations' => self::operations(),
				'ids'        => [ 104, 202 ],
			]
		)->get_data();
		$this->assertNotSame( $first['scope_hash'], $other['scope_hash'], 'A substituted member of the same count changes the scope.' );
		$this->assertNotSame( $first['hash'], $other['hash'] );
	}

	public function test_a_1_0_selection_token_is_refused_and_never_resolved_as_a_filter(): void {
		// T83: transient tokens from before the exact selection must be chosen again.
		foreach ( [
			SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ), 5 ) ),
			SelectionToken::mint( Selection::of_ids( [ 101 ] ) ),
		] as $token ) {
			$response = self::post(
				[
					'operations' => self::operations(),
					'token'      => $token,
				]
			);
			$this->assert_refused( $response, SelectionToken::INVALID, 403 );
			$this->assertStringContainsString( 'Choose the products again', $response->get_error_message() );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'] ?? [], 'Nothing was loaded.' );
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

		$sanitise = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/preview'][0]['args']['sample_size']['sanitize_callback'];
		$this->assertSame( [ 25, 100, Preview::SAMPLE_ALL ], [ $sanitise( 25 ), $sanitise( '100' ), $sanitise( 'all' ) ] );
	}

	public function test_a_visitor_is_refused_401_and_a_reader_403(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['user_id']   = 0;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$body                                 = [
			'operations' => self::operations(),
			'ids'        => [ 101 ],
		];
		$this->assert_refused( self::post( $body ), 'bulkcat_forbidden', 401 );

		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$this->assert_refused( self::post( $body ), 'bulkcat_forbidden', 403 );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'] ?? [], 'Nothing was loaded.' );
	}

	public function test_invalid_operations_are_refused_400_with_the_registry_codes(): void {
		$step = self::operations()[0];
		foreach ( [
			'bulkcat_no_operations'       => [],
			'bulkcat_too_many_operations' => array_fill( 0, 21, $step ),
			'bulkcat_unknown_action'      => [
				[
					'action' => 'nope',
					'args'   => [ 'field' => 'name' ],
				],
			],
			'bulkcat_field_not_accepted'  => [
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'total_sales',
						'value' => 3,
					],
				],
			],
			'bulkcat_invalid_operation'   => [
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
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'] ?? [], 'Nothing was loaded.' );
		$this->assert_refused( self::post( [ 'ids' => [ 101 ] ] ), 'rest_missing_callback_param', 400 );
	}

	public function test_the_selection_must_be_exactly_one_of_ids_and_token(): void {
		$this->assert_refused( self::post( [ 'operations' => self::operations() ] ), 'bulkcat_selection_required', 400 );
		$this->assert_refused(
			self::post(
				[
					'operations' => self::operations(),
					'ids'        => [ 101 ],
					'token'      => SelectionToken::mint( Selection::of_ids( [ 101 ] ) ),
				]
			),
			'bulkcat_selection_required',
			400
		);
		$this->assert_refused(
			self::post(
				[
					'operations' => self::operations(),
					'token'      => 'not-a-token',
				]
			),
			'bulkcat_invalid_selection',
			403
		);
	}
}
