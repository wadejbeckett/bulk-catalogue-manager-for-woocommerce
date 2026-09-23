<?php
/**
 * `GET /bcm/v1/terms/{taxonomy}`: every tag or shipping class as a flat
 * array of `{ id, name, count }`, empty terms included, ordered by name,
 * names decoded to text; any other taxonomy matches no route; behind
 * Access::can_read (RoutesTest refuses a visitor on every endpoint).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\TermsController;
use PHPUnit\Framework\TestCase;

final class TermsRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a GET to /bcm/v1/terms/{taxonomy}.
	 *
	 * @param string $taxonomy Taxonomy as it appears in the path.
	 */
	private static function get( string $taxonomy ): \WP_REST_Response|\WP_Error {
		return rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/bcm/v1/terms/' . $taxonomy ) );
	}

	/**
	 * A stored term.
	 *
	 * @param int    $id       Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name as stored.
	 * @param int    $count    Stored count.
	 */
	private static function term( int $id, string $taxonomy, string $name, int $count ): object {
		return (object) [
			'term_id'  => $id,
			'taxonomy' => $taxonomy,
			'name'     => $name,
			'slug'     => 'term-' . $id,
			'count'    => $count,
		];
	}

	public function test_the_route_registers_for_tags_and_shipping_classes_behind_the_read_permission(): void {
		$route = $GLOBALS['bcm_test']['routes'][ '/bcm/v1' . TermsController::ROUTE ] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[0]['permission_callback'] );
		$this->assertSame( [ 'product_tag', 'product_shipping_class' ], $route[0]['args']['taxonomy']['enum'] );
		$this->assertSame( 'bcm-term', call_user_func( $route['schema'] )['title'] );
	}

	public function test_every_term_comes_by_name_with_its_count_and_a_text_name(): void {
		$GLOBALS['bcm_test']['term_list'] = [
			self::term( 31, 'product_tag', 'new', 4 ),
			self::term( 32, 'product_tag', 'Salt &amp; Pepper', 0 ),
			self::term( 30, 'product_tag', 'Autumn', 2 ),
		];
		$response                         = self::get( 'product_tag' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				[
					'id'    => 30,
					'name'  => 'Autumn',
					'count' => 2,
				],
				[
					'id'    => 31,
					'name'  => 'new',
					'count' => 4,
				],
				[
					'id'    => 32,
					'name'  => 'Salt & Pepper',
					'count' => 0,
				],
			],
			$response->get_data()
		);
		$call = end( $GLOBALS['bcm_test']['get_terms_calls'] );
		$this->assertSame( 'product_tag', $call['taxonomy'] );
		$this->assertFalse( $call['hide_empty'], 'Empty terms are listed.' );

		$schema = call_user_func( $GLOBALS['bcm_test']['routes'][ '/bcm/v1' . TermsController::ROUTE ]['schema'] );
		foreach ( $response->get_data() as $item ) {
			$this->assertTrue( rest_validate_value_from_schema( $item, $schema, 'item' ) );
		}
	}

	public function test_shipping_classes_are_read_from_their_own_taxonomy(): void {
		$GLOBALS['bcm_test']['term_list'] = [ self::term( 40, 'product_shipping_class', 'Bulky', 1 ) ];
		$this->assertSame(
			[
				[
					'id'    => 40,
					'name'  => 'Bulky',
					'count' => 1,
				],
			],
			self::get( 'product_shipping_class' )->get_data()
		);
		$this->assertSame( 'product_shipping_class', end( $GLOBALS['bcm_test']['get_terms_calls'] )['taxonomy'] );

		$GLOBALS['bcm_test']['term_list'] = [];
		$this->assertSame( [], self::get( 'product_shipping_class' )->get_data(), 'No terms: an empty array.' );
	}

	public function test_any_other_taxonomy_matches_no_route(): void {
		foreach ( [ 'product_cat', 'category', 'pa_colour', 'product_type' ] as $taxonomy ) {
			$refused = self::get( $taxonomy );
			$this->assertInstanceOf( \WP_Error::class, $refused, $taxonomy );
			$this->assertSame( 404, $refused->get_error_data()['status'], $taxonomy );
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['get_terms_calls'] ?? [] );
	}
}
