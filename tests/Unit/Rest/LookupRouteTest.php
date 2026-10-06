<?php
/**
 * The lookup route (ticket 25; docs/EXTENSIONS-v2.md §4.3, §4.7): one
 * bounded, searchable page of a lookup argument's choices, after the screen
 * right, availability and the action's capability, and for a row the
 * object's edit right (edit_term for a category row) and the provider's
 * authorise(); every refusal named;
 * never cached.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\LookupController;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\PicksDirectory;
use BulkCatalogueManager\Tests\Support\Extension\PicksFaults;
use BulkCatalogueManager\Tests\Support\Extension\PicksProvider;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class LookupRouteTest extends TestCase {
	private const USER = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		PicksProvider::reset();
		$GLOBALS['bulkcat_test']['user_id']                                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']                               = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]                 = [
			'edit_products' => true,
			'edit_post:10'  => true,
			'edit_post:11'  => false,
		];
		$GLOBALS['bulkcat_test']['products'][10]                            = new \BulkCatalogueManager\Tests\Support\FakeProduct( 'simple', [ 'id' => 10 ] );
		$GLOBALS['bulkcat_test']['products'][11]                            = new \BulkCatalogueManager\Tests\Support\FakeProduct( 'simple', [ 'id' => 11 ] );
		$GLOBALS['bulkcat_test']['products'][12]                            = new \BulkCatalogueManager\Tests\Support\FakeProduct( 'simple', [ 'id' => 12 ] );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_post:12'] = true;
	}

	/**
	 * The controller over both test providers.
	 */
	private static function controller(): LookupController {
		return new LookupController( static fn(): Actions => new Actions( new Fields(), null, Providers::of( [ new PicksProvider( new MemoryStore(), new MemoryStore(), false ), new SampleProvider( new MemoryStore() ) ] ) ) );
	}

	/**
	 * Ask the route.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private static function ask( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/lookup' );
		$request->set_query_params(
			$params + [
				'action'   => 'x_picks_add',
				'argument' => 'recipients',
			]
		);
		return self::controller()->get_items( $request );
	}

	/**
	 * The refusal's code and status.
	 *
	 * @param mixed $answer The answer.
	 * @return array{0: string, 1: int}
	 */
	private static function refusal( mixed $answer ): array {
		return $answer instanceof \WP_Error ? [ (string) $answer->get_error_code(), (int) ( $answer->get_error_data()['status'] ?? 0 ) ] : [ 'answered', 200 ];
	}

	public function test_the_route_is_registered_for_writers_only(): void {
		$routes = new \BulkCatalogueManager\Rest\Routes( [ self::controller() ] );
		$routes->register();
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/lookup'] ?? null;
		$this->assertNotNull( $route );
		$this->assertSame( [ \BulkCatalogueManager\Rest\Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'GET', $route[0]['methods'] );
	}

	public function test_a_page_of_five_thousand_choices_is_bounded_and_searchable(): void {
		$first = self::ask( [] );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$data = $first->get_data();
		$this->assertCount( 20, $data['items'] );
		$this->assertTrue( $data['hasMore'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame(
			[
				'id'     => 'r0001',
				'label'  => 'Recipient 0001',
				'detail' => 'Team B',
			],
			$data['items'][0]
		);
		$last = self::ask( [ 'page' => 250 ] )->get_data();
		$this->assertSame( 'r5000', $last['items'][19]['id'] );
		$this->assertFalse( $last['hasMore'] );
		$search = self::ask( [ 'query' => '  Recipient 004 ' ] )->get_data();
		$this->assertCount( 10, $search['items'] );
		$this->assertFalse( $search['hasMore'] );
		$this->assertSame( [ 'recipients', 'Recipient 004', 1, 'lookup', self::USER ], PicksDirectory::$lookups[2], 'Trimmed search, page, lookup mode and the requester reach the provider.' );
	}

	public function test_bad_requests_are_refused_before_the_provider_is_asked(): void {
		$cases = [
			'unknown action'          => [ 'action' => 'x_picks_nothing' ],
			'native action'           => [ 'action' => 'set_regular_price' ],
			'version 1 action'        => [
				'action'   => 'x_sample_add',
				'argument' => 'labels',
			],
			'not a lookup'            => [ 'argument' => 'field' ],
			'page zero'               => [ 'page' => 0 ],
			'page above the most'     => [ 'page' => 501 ],
			'page that is not a page' => [ 'page' => 'two' ],
			'query too long'          => [ 'query' => str_repeat( 'a', 101 ) ],
			'query not text'          => [ 'query' => [ 'a' ] ],
			'malformed object'        => [ 'object' => '10' ],
			'category object'         => [ 'object' => 'term:product_cat:10' ],
		];
		foreach ( $cases as $name => $params ) {
			$this->assertSame( [ 'bulkcat_invalid_lookup', 400 ], self::refusal( self::ask( $params ) ), $name );
		}
		$this->assertSame( [], PicksDirectory::$lookups );
	}

	public function test_permissions_and_availability_come_first(): void {
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_products'] = false;
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], self::refusal( self::ask( [] ) ), 'The action capability.' );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_products'] = true;
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], self::refusal( self::ask( [ 'object' => 'product:11' ] ) ), 'A row the user may not edit.' );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], self::refusal( self::ask( [ 'object' => 'product:99' ] ) ), 'A row that is not a product.' );
		update_option( PicksFaults::OPTION, [ 'refuse' => [ 12 ] ] );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], self::refusal( self::ask( [ 'object' => 'product:12' ] ) ), 'The provider narrows a row.' );
		$this->assertSame( [ 'answered', 200 ], self::refusal( self::ask( [ 'object' => 'product:10' ] ) ), 'A row the user and the provider allow.' );
		update_option( PicksFaults::OPTION, [ 'unavailable' => true ] );
		$this->assertSame( [ 'bulkcat_provider_unavailable', 409 ], self::refusal( self::ask( [] ) ) );
		$this->assertSame( 1, count( PicksDirectory::$lookups ), 'Only the allowed row reached the provider.' );
	}

	public function test_a_provider_failure_is_a_plain_refusal(): void {
		update_option( PicksFaults::OPTION, [ 'lookup_error' => true ] );
		$answer = self::ask( [] );
		$this->assertSame( [ 'bulkcat_lookup_failed', 409 ], self::refusal( $answer ) );
		$this->assertSame( 'The recipient directory cannot be searched right now.', $answer->get_error_message() );
	}

	public function test_no_matches_is_an_empty_last_page(): void {
		$data = self::ask( [ 'query' => 'nobody by that name' ] )->get_data();
		$this->assertSame( [], $data['items'] );
		$this->assertFalse( $data['hasMore'] );
	}

	public function test_a_category_row_is_checked_as_a_category_never_as_a_product(): void {
		$GLOBALS['bulkcat_test']['term_list']                = [
			(object) [
				'term_id'  => 10,
				'name'     => 'Kitchen',
				'slug'     => 'kitchen',
				'parent'   => 0,
				'taxonomy' => 'product_cat',
			],
			(object) [
				'term_id'  => 12,
				'name'     => 'Pots',
				'slug'     => 'pots',
				'parent'   => 10,
				'taxonomy' => 'product_cat',
			],
		];
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] += [
			'manage_product_terms' => true,
			'edit_term'            => true,
			'edit_term:12'         => false,
		];
		$controller = new LookupController( static fn(): Actions => new Actions( new Fields(), null, Providers::of( [ new PicksProvider( new MemoryStore(), new MemoryStore(), true, false ) ], [ 'lookup', 'category_target' ] ) ) );
		$ask        = static function ( string $action, string $object ) use ( $controller ): array {
			$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/lookup' );
			$request->set_query_params(
				[
					'action'   => $action,
					'argument' => 'recipients',
					'object'   => $object,
				]
			);
			return self::refusal( $controller->get_items( $request ) );
		};
		$this->assertSame( [ 'answered', 200 ], $ask( 'x_picks_category_add', 'term:product_cat:10' ), 'A category the user may edit and the provider allows.' );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], $ask( 'x_picks_category_add', 'term:product_cat:12' ), 'No edit_term on it.' );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], $ask( 'x_picks_category_add', 'term:product_cat:99' ), 'No such category, though product 10 exists.' );
		$this->assertSame( [ 'bulkcat_invalid_lookup', 400 ], $ask( 'x_picks_category_add', 'product:10' ), 'A product row for a category action.' );
		$this->assertSame( [ 'bulkcat_invalid_lookup', 400 ], $ask( 'x_picks_add', 'term:product_cat:10' ), 'A category row for a product action.' );
		update_option( PicksFaults::OPTION, [ 'refuse_terms' => [ 10 ] ] );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], $ask( 'x_picks_category_add', 'term:product_cat:10' ), 'The provider narrows a category.' );
		$this->assertSame( [ [ 'product_cat', 10 ], [ 'product_cat', 10 ] ], array_map( static fn( array $call ): array => [ $call[1], $call[2] ], PicksProvider::$calls['authorise'] ), 'authorise() is asked about the typed category only after the core checks.' );
		$this->assertCount( 1, PicksDirectory::$lookups );
	}
}
