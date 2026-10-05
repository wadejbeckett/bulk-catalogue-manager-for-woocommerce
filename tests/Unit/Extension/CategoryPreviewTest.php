<?php
/**
 * The category target's scope, preview, grant and acceptance
 * (docs/EXTENSIONS-v2.md §5.3; acceptance test V7): an explicit choice of
 * categories, Include subcategories expanded once at preview, duplicates
 * from overlapping branches removed, read unfiltered; the preview lists
 * category records by path with each outcome and the provider's own
 * descriptions, never products; one change acts on products or on
 * categories, never both; and an Apply records exactly the captured set,
 * refusing a scope or provider that changed since the preview.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Engine\CategoryPreview;
use BulkCatalogueManager\Engine\CategorySelection;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Requests\BatchIntent;
use BulkCatalogueManager\Requests\CategoryAcceptance;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\PicksProvider;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesField;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesProvider;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class CategoryPreviewTest extends TestCase {
	private const USER = 3;

	private MemoryStore $store;

	private ?Actions $shared = null;

	protected function setUp(): void {
		bulkcat_test_reset();
		ShelvesProvider::reset();
		ShelvesField::$written                              = [];
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [
			'manage_product_terms' => true,
			'edit_term'            => true,
			'edit_products'        => true,
		];
		// Kitchen › Pots › Lids, Kitchen › Pans, Garden.
		$GLOBALS['bulkcat_test']['term_list']    = [
			self::term( 41, 'Kitchen', 0 ),
			self::term( 42, 'Pots', 41 ),
			self::term( 43, 'Pans', 41 ),
			self::term( 44, 'Lids', 42 ),
			self::term( 50, 'Garden', 0 ),
		];
		$GLOBALS['bulkcat_test']['products'][42] = new SavedProduct(
			'simple',
			[
				'id'   => 42,
				'name' => 'Product 42',
			]
		);
		$this->store                             = new MemoryStore();
		$this->store->data[42]                   = [ ShelvesField::META => '["north"]' ];
		$this->shared                            = null;
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][42]->saves, 'The product with the same number was saved.' );
		$this->assertSame( [], $this->store->writes, 'A preview wrote.' );
		delete_option( ShelvesProvider::FAULTS );
		$this->share( null );
	}

	private static function term( int $id, string $name, int $parent ): object {
		return (object) [
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => strtolower( $name ),
			'parent'   => $parent,
			'taxonomy' => 'product_cat',
		];
	}

	private function actions(): Actions {
		return $this->shared ??= new Actions( new Fields(), null, Providers::of( [ new ShelvesProvider( $this->store ), new SampleProvider( new MemoryStore() ) ], [ 'lookup', 'category_target' ] ) );
	}

	/**
	 * Make a registry the shared one (BatchIntent reads Actions::instance()).
	 *
	 * @param Actions|null $actions The registry, or null to forget it.
	 */
	private function share( ?Actions $actions ): void {
		$property = new \ReflectionProperty( Actions::class, 'instance' );
		$property->setValue( null, $actions );
	}

	private static function add( array $shelves = [ 'south' ] ): array {
		return [
			[
				'action' => 'x_shelves_add',
				'args'   => [
					'field'   => ShelvesField::KEY,
					'shelves' => $shelves,
				],
			],
		];
	}

	private function preview( array $chosen, bool $subcategories = false, ?array $operations = null ): array|\WP_Error {
		return ( new CategoryPreview( $this->actions() ) )->run( $operations ?? self::add(), $chosen, $subcategories );
	}

	public function test_a_parent_targets_itself_until_subcategories_are_included_once(): void {
		$this->assertSame( [ 41 ], CategorySelection::build( [ 41 ], false )['ids'], 'A parent alone.' );
		// Kitchen and Pots overlap: Pots and Lids are in both branches.
		$scope = CategorySelection::build( CategorySelection::ids( [ 42, '41', 42 ] ), true );
		$this->assertSame( [ 41, 42 ], $scope['chosen'] );
		$this->assertSame( [ 41, 42, 43, 44 ], $scope['ids'], 'Expanded, each once, sorted.' );
		$this->assertSame( [], $scope['unavailable'] );
		// A background read with categories hidden from term queries still sees every one.
		$GLOBALS['bulkcat_test']['user_id']      = 0;
		$GLOBALS['bulkcat_test']['hidden_terms'] = [ 42, 43, 44 ];
		$this->assertSame( [ 41, 42, 43, 44 ], CategorySelection::build( [ 41 ], true )['ids'] );
		$gone = CategorySelection::build( [ 41, 99 ], false );
		$this->assertSame( [ [ 41, 99 ], [ 41 ], [ 99 ] ], [ $gone['ids'], $gone['available'], $gone['unavailable'] ], 'A chosen id that is not a category stays, unavailable.' );
		try {
			CategorySelection::ids( range( 1, CategorySelection::MAX + 1 ) );
			$this->fail( 'More than the most categories is refused.' );
		} catch ( \LengthException $e ) {
			$this->assertSame( 'Too many categories.', $e->getMessage() );
		}
	}

	public function test_a_later_child_changes_the_scope_hash(): void {
		$before                                 = CategorySelection::build( [ 41 ], true );
		$GLOBALS['bulkcat_test']['term_list'][] = self::term( 45, 'Woks', 43 );
		$after                                  = CategorySelection::build( [ 41 ], true );
		$this->assertSame( [ 41, 42, 43, 44, 45 ], $after['ids'] );
		$this->assertNotSame( $before['scope_hash'], $after['scope_hash'] );
		$this->assertSame( CategorySelection::build( [ 41 ], false )['scope_hash'], CategorySelection::build( [ 41 ], false )['scope_hash'] );
	}

	public function test_the_preview_lists_category_records_by_path_with_the_providers_words(): void {
		update_option( ShelvesProvider::FAULTS, [ 'refuse_terms' => [ 43 ] ] );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_term:44'] = false;
		$answer = $this->preview( [ 41, 42, 99 ], true );
		$this->assertTrue( is_array( $answer ) );
		$records = array_column( $answer['records'], null, 'id' );
		$this->assertSame( [ 41, 42, 43, 44, 99 ], array_keys( $records ) );
		$this->assertSame( 'Kitchen › Pots', $records[42]['path'] );
		$this->assertSame( 'term:product_cat:42', $records[42]['ref'] );
		$this->assertSame( [ 'change', 'change', 'skip', 'skip', 'unavailable' ], array_column( $answer['records'], 'outcome' ) );
		$this->assertSame( 'North wall', $records[42]['rows'][0]['current'] );
		$this->assertSame( 'North wall, South wall', $records[42]['rows'][0]['new'] );
		$this->assertSame( 'Puts it on South wall', $records[42]['rows'][0]['change'] );
		$this->assertSame( [ 'Products in this category show the new shelves at their next page load.' ], $records[42]['rows'][0]['notes'], 'Product impact is only what the provider says.' );
		$this->assertSame( 'Sample shelves does not allow changes to this category.', $records[43]['rows'][0]['reason'] );
		$this->assertSame( [ '', '' ], [ $records[44]['rows'][0]['current'], $records[44]['rows'][0]['change'] ], 'Nothing of a category the user may not edit is shown.' );
		$this->assertSame( 'This category no longer exists, so it was left alone.', $records[99]['rows'][0]['reason'] );
		$this->assertSame( 'Shelves will change on 2 categories.', $answer['summary'] );
		$this->assertSame( [ 41, 42, 99 ], $answer['categories']['chosen'] );
		$this->assertSame( [ 41, 42, 43, 44, 99 ], $answer['categories']['ids'] );
		$this->assertSame( 'product_cat', $answer['external']['target'] );
		$this->assertSame( [ [ 'product_cat', [ 41, 42, 43, 44 ], 'preview' ] ], ShelvesProvider::$calls['state_token'], 'The state token sees a category scope of the available term ids.' );
		$this->assertSame( [ 41, 42, 43 ], array_column( ShelvesProvider::$calls['authorise'], 2 ), 'authorise() follows the edit_term check.' );
		$this->assertSame( [], ShelvesField::$written );
		$this->assertArrayNotHasKey( 'flush_terms', ShelvesProvider::$calls );
	}

	public function test_one_change_acts_on_products_or_categories_never_both(): void {
		$product = [
			[
				'action' => 'x_sample_add',
				'args'   => [
					'field'  => 'x_sample_labels',
					'labels' => [ 'red' ],
				],
			],
		];
		$answer  = $this->preview( [ 41 ], false, $product );
		$this->assertSame( 'bulkcat_mixed_change', $answer->get_error_code(), 'A product action on categories.' );
		$product_preview = ( new Preview( $this->actions() ) )->run( Selection::of_ids( [ 42 ] ), self::add() );
		$this->assertSame( 'bulkcat_mixed_change', $product_preview->get_error_code(), 'A category action on products.' );
		// One provider's product and category steps together.
		$picks = new Actions( new Fields(), null, Providers::of( [ new PicksProvider( new MemoryStore(), new MemoryStore(), true, false ) ], [ 'lookup', 'category_target' ] ) );
		$mixed = RunContext::within(
			RunContext::apply( self::USER, 0 ),
			static fn(): mixed => $picks->validate_list(
				[
					[
						'action' => 'x_picks_add',
						'args'   => [
							'field'      => 'x_picks_items',
							'recipients' => [ 'r0001' ],
						],
					],
					[
						'action' => 'x_picks_category_add',
						'args'   => [
							'field'      => 'x_picks_category_items',
							'recipients' => [ 'r0001' ],
						],
					],
				]
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $mixed );
		$this->assertSame( 'bulkcat_mixed_change', $mixed->get_error_code() );
	}

	public function test_a_grant_carries_the_exact_captured_categories(): void {
		$this->share( $this->actions() );
		$preview = $this->preview( [ 42, 41 ], true );
		$intent  = BatchIntent::normalize(
			'batch.apply',
			[
				'source'     => 'bulk',
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
				'categories' => [
					'chosen'        => [ 42, 41 ],
					'subcategories' => true,
					'ids'           => $preview['categories']['ids'],
				],
			],
			self::USER
		);
		$this->assertSame(
			[
				'chosen'        => [ 41, 42 ],
				'subcategories' => true,
				'ids'           => [ 41, 42, 43, 44 ],
			],
			$intent['categories']
		);
		$this->assertSame( $intent, BatchIntent::normalize( 'batch.apply', $intent, self::USER ), 'A canonical intent is checked for shape only.' );
		$product = BatchIntent::normalize(
			'batch.apply',
			[
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
				'ids'        => [ 42 ],
			],
			self::USER
		);
		$this->assertSame( 'bulkcat_mixed_change', $product->get_error_code(), 'A category change sent with product ids.' );
		try {
			BatchIntent::normalize( 'batch.apply', [ 'categories' => $intent['categories'] + [ 'extra' => 1 ] ] + $intent, self::USER );
			$this->fail( 'An intent with an unknown category key is refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'Not a canonical Apply intent.', $e->getMessage() );
		}
	}

	/**
	 * A canonical intent and the credentials of a fresh preview.
	 *
	 * @param list<int> $chosen        Chosen.
	 * @param bool      $subcategories Include subcategories.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function intent( array $chosen, bool $subcategories ): array {
		$preview = $this->preview( $chosen, $subcategories );
		return [
			[
				'version'    => 1,
				'source'     => 'bulk',
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
				'categories' => [
					'chosen'        => $preview['categories']['chosen'],
					'subcategories' => $subcategories,
					'ids'           => $preview['categories']['ids'],
				],
			],
			[ 'preview_hash' => $preview['hash'] ],
		];
	}

	public function test_acceptance_records_exactly_the_captured_set(): void {
		[ $intent, $credentials ] = $this->intent( [ 42, 41 ], true );
		$checked                  = CategoryAcceptance::check( $intent, $credentials, self::USER, $this->actions() );
		$this->assertTrue( is_array( $checked ) );
		$this->assertSame( [ [ 41, 'product_cat' ], [ 42, 'product_cat' ], [ 43, 'product_cat' ], [ 44, 'product_cat' ] ], $checked['objects'] );
		$this->assertSame( [], $checked['unavailable'] );
		$this->assertSame( 'categories', $checked['summary']['kind'] );
		$this->assertSame( [ 41, 42 ], $checked['summary']['chosen'] );
		$this->assertSame( 'Sample shelves', $checked['manifest']['shelves']['label'] );
	}

	public function test_acceptance_refuses_a_scope_or_provider_changed_since_the_preview(): void {
		[ $intent, $credentials ]               = $this->intent( [ 41 ], true );
		$GLOBALS['bulkcat_test']['term_list'][] = self::term( 45, 'Woks', 43 );
		$added                                  = CategoryAcceptance::check( $intent, $credentials, self::USER, $this->actions() );
		$this->assertSame( [ 'bulkcat_preview_stale', 'scope_changed' ], [ $added->get_error_code(), $added->get_error_data()['reason'] ], 'A subcategory added later never widens the set.' );

		$this->setUp();
		[ $intent, $credentials ]             = $this->intent( [ 41, 42 ], false );
		$GLOBALS['bulkcat_test']['term_list'] = array_values( array_filter( $GLOBALS['bulkcat_test']['term_list'], static fn( object $term ): bool => 42 !== $term->term_id ) );
		$deleted                              = CategoryAcceptance::check( $intent, $credentials, self::USER, $this->actions() );
		$this->assertSame( 'scope_changed', $deleted->get_error_data()['reason'], 'A member deleted since never narrows the set silently.' );

		$this->setUp();
		[ $intent, $credentials ] = $this->intent( [ 41 ], false );
		update_option( ShelvesProvider::FAULTS, [ 'state' => 'moved on' ] );
		$state = CategoryAcceptance::check( $intent, $credentials, self::USER, $this->actions() );
		$this->assertSame( 'provider_changed', $state->get_error_data()['reason'] );
	}

	public function test_the_preview_route_is_for_writers_and_takes_only_its_own_arguments(): void {
		\BulkCatalogueManager\Rest\Routes::plugin()->register();
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/categories/actions/preview'] ?? null;
		$this->assertNotNull( $route );
		$this->assertSame( [ \BulkCatalogueManager\Rest\Access::class, 'can_manage_terms' ], $route[0]['permission_callback'] );
		$this->assertSame( CategorySelection::MAX, $route[0]['args']['categories']['maxItems'] );
		$controller                      = new \BulkCatalogueManager\Rest\CategoryActionsController( fn(): Actions => $this->actions() );
		$GLOBALS['bulkcat_test']['caps'] = [ 'manage_woocommerce' => true ];
		$request                         = new \WP_REST_Request( 'POST', '/bulkcat/v1/categories/actions/preview' );
		$request->set_param( 'operations', self::add() );
		$request->set_param( 'categories', [ 42 ] );
		$this->assertSame( 'bulkcat_forbidden', $controller->create_item( $request )->get_error_code(), 'An Apply follows, so the write right is needed too.' );
		$GLOBALS['bulkcat_test']['caps'] = [
			'manage_woocommerce' => true,
			'edit_products'      => true,
		];
		$request                         = new \WP_REST_Request( 'POST', '/bulkcat/v1/categories/actions/preview' );
		$request->set_param( 'operations', self::add() );
		$request->set_param( 'categories', [ 42 ] );
		$this->assertSame( 'Shelves will change on 1 category.', $controller->create_item( $request )->get_data()['summary'] );
		$request->set_param( 'ids', [ 42 ] );
		$this->assertSame( 'bulkcat_invalid_value', $controller->create_item( $request )->get_error_code() );
		$bad = new \WP_REST_Request( 'POST', '/bulkcat/v1/categories/actions/preview' );
		$bad->set_param( 'operations', self::add() );
		$bad->set_param( 'categories', [ 'kitchen' ] );
		$this->assertSame( 'bulkcat_invalid_selection', $controller->create_item( $bad )->get_error_code() );
	}
}
