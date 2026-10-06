<?php
/**
 * Category order is WooCommerce's own (ticket 23, owner's ruling of 5
 * October): the tree reads and a move writes the `order` term meta that
 * WooCommerce's drag-reorder writes (wc_set_term_order()) and its storefront
 * sorts siblings by, so a drag here changes the shop and WooCommerce's
 * screen, and a drag there shows here. The plugin's old `bulkcat_order` is
 * retired: never read, never written, never copied into the shop's order.
 * The default product category (WooCommerce's `default_product_cat`
 * option) is shown on its node and can be chosen, journalled and undoable.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Cache;
use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\Tree;
use BulkCatalogueManager\Categories\Writer;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';
require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class NativeOrderTest extends TestCase {
	private const USER = 12;

	private InlineStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = true;
		$this->store                                        = new InlineStore();
		CatalogueFixture::seed();
		Cache::register_hooks();
	}

	/**
	 * WooCommerce's stored order of some terms.
	 *
	 * @param list<int> $ids Term ids.
	 * @return list<mixed>
	 */
	private static function orders( array $ids ): array {
		return array_map( static fn( int $id ): mixed => $GLOBALS['bulkcat_test']['term_meta'][ $id ]['order'] ?? null, $ids );
	}

	/**
	 * Add children of a parent with WooCommerce orders.
	 *
	 * @param int             $parent Parent id.
	 * @param array<int, int> $orders Child id => stored order.
	 */
	private static function children( int $parent, array $orders ): void {
		foreach ( $orders as $id => $order ) {
			$GLOBALS['bulkcat_test']['term_list'][]               = (object) [
				'term_id'  => $id,
				'name'     => 'Child ' . $id,
				'slug'     => 'child-' . $id,
				'parent'   => $parent,
				'taxonomy' => 'product_cat',
			];
			$GLOBALS['bulkcat_test']['term_meta'][ $id ]['order'] = (string) $order;
		}
	}

	public function test_a_reorder_writes_woocommerce_order_and_never_the_old_tree_order(): void {
		$this->assertSame( [ 5, 2 ], CategoryTree::tree()->children( 1 ), 'WooCommerce\'s order: Women (1) before Men (2).' );

		$result = ( new Writer() )->move( 2, 1, 0, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 2, 5 ], CategoryTree::tree()->children( 1 ), 'The shop\'s order now puts Men first.' );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertLessThan( (int) self::orders( [ 5 ] )[0], (int) self::orders( [ 2 ] )[0] );
		foreach ( $GLOBALS['bulkcat_test']['term_meta_updates'] as [ , $key ] ) {
			$this->assertSame( 'order', $key, 'Only WooCommerce\'s own order is written.' );
		}
		$this->assertSame( [ Writer::ORDER_PROP ], array_values( array_unique( array_column( $this->store->changes, 'prop' ) ) ) );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['did_actions']['woocommerce_after_set_term_order'] ?? 0, 'WooCommerce\'s own hook runs for each order written.' );
	}

	public function test_a_drag_in_woocommerce_shows_here_and_the_retired_order_is_ignored(): void {
		// An old plugin-only order says Men first; WooCommerce (and the shop) say Women first.
		$GLOBALS['bulkcat_test']['term_meta'][2][ CategoryTree::TREE_ORDER_META ] = '0';
		$GLOBALS['bulkcat_test']['term_meta'][5][ CategoryTree::TREE_ORDER_META ] = '1';
		$this->assertSame( [ 5, 2 ], CategoryTree::tree()->children( 1 ), 'The retired order is not read.' );

		// WooCommerce's drag renumbers its order: Men first.
		$GLOBALS['bulkcat_test']['term_meta'][2]['order'] = '3';
		$GLOBALS['bulkcat_test']['term_meta'][5]['order'] = '4';
		$this->assertSame( [ 2, 5 ], CategoryTree::tree()->children( 1 ) );
		$this->assertSame( 3, CategoryTree::tree()->node( 2 )['order'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['term_meta_updates'] ?? [], 'Reading writes nothing: nothing is copied into the shop\'s order.' );

		// WooCommerce's drag writes the meta with no term hook: the stored tree is renewed.
		( new Cache() )->get( 'any' );
		set_transient( Cache::VERSION, 'before', 0 );
		do_action( 'updated_term_meta', 77, 2, 'order', '3' );
		$this->assertNotSame( 'before', get_transient( Cache::VERSION ) );
		set_transient( Cache::VERSION, 'again', 0 );
		do_action( 'updated_term_meta', 78, 5, 'order', '4' );
		$this->assertSame( 'again', get_transient( Cache::VERSION ), 'The rest of the same run need not renew it again.' );
		( new Cache() )->get( 'any' );
		do_action( 'updated_term_meta', 79, 5, '_some_other_key', 'x' );
		$this->assertSame( 'again', get_transient( Cache::VERSION ), 'Other meta leaves it alone.' );
	}

	public function test_siblings_without_an_order_come_first_by_name_as_woocommerce_sorts_them(): void {
		$tree = Tree::from_terms(
			CatalogueFixture::terms(),
			[
				1 => '0',
				6 => '5',
			]
		);
		// WooCommerce sorts meta_value+0 ascending: a missing order (NULL) first, then by name.
		$this->assertSame( [ 10, 9, 1, 6 ], $tree->children( 0 ) );
	}

	public function test_a_reorder_where_there_is_room_writes_only_the_category_moved(): void {
		// WooCommerce numbers the whole tree, so siblings have gaps between them.
		self::children(
			3,
			[
				20 => 10,
				21 => 20,
				22 => 30,
			]
		);
		$result = ( new Writer() )->move( 22, 3, 2, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 4, 20, 22, 21 ], CategoryTree::tree()->children( 3 ) );
		$this->assertSame( [ [ 22, 'order', 11 ] ], $GLOBALS['bulkcat_test']['term_meta_updates'] );
		$this->assertSame( [ [ 22, Writer::ORDER_PROP, 30, 11, 'applied' ] ], $this->store->journal() );
	}

	public function test_a_reorder_without_room_renumbers_the_siblings_from_one(): void {
		self::children(
			3,
			[
				20 => 1,
				21 => 2,
				22 => 3,
			]
		);
		$GLOBALS['bulkcat_test']['term_meta'][4]['order'] = '0';
		$result = ( new Writer() )->move( 22, 3, 0, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 22, 4, 20, 21 ], CategoryTree::tree()->children( 3 ) );
		$this->assertSame( [ 1, 2, 3, 4 ], array_map( 'intval', self::orders( [ 22, 4, 20, 21 ] ) ) );
		$this->assertSame(
			[
				[ 22, Writer::ORDER_PROP, 3, 1, 'applied' ],
				[ 4, Writer::ORDER_PROP, 0, 2, 'applied' ],
				[ 20, Writer::ORDER_PROP, 1, 3, 'applied' ],
				[ 21, Writer::ORDER_PROP, 2, 4, 'applied' ],
			],
			$this->store->journal()
		);
	}

	public function test_a_move_to_another_parent_leaves_the_group_it_left_alone(): void {
		self::children(
			2,
			[
				20 => 10,
				21 => 20,
			]
		);
		$GLOBALS['bulkcat_test']['term_meta'][3]['order'] = '30';
		$result = ( new Writer() )->move( 20, 1, null, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 5, 2, 20 ], CategoryTree::tree()->children( 1 ), 'Placed last.' );
		$this->assertSame( [ 21, 3 ], CategoryTree::tree()->children( 2 ) );
		$this->assertSame( [ '20', '30' ], self::orders( [ 21, 3 ] ), 'The siblings it left keep their order.' );
		$this->assertSame( [ '10' ], self::orders( [ 20 ] ), 'Its own order already places it last there.' );
		$this->assertSame( [ [ 20, 'term_parent', 2, 1, 'applied' ] ], $this->store->journal() );

		// Last under Shoes, after Boots (5): its 10 already fits; first under Shoes it does not.
		$GLOBALS['bulkcat_test']['term_meta'][7]['order'] = '5';
		$this->assertTrue( is_array( ( new Writer() )->move( 20, 6, 0, self::USER ) ) );
		$this->assertSame( [ 20, 7 ], CategoryTree::tree()->children( 6 ) );
		$this->assertSame( [ 4 ], array_map( 'intval', self::orders( [ 20 ] ) ) );
	}

	public function test_a_new_category_takes_woocommerce_order_zero_as_its_own_screen_gives_one(): void {
		$result = ( new Writer() )->create( [ 'name' => 'Hats' ], self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 0 ], self::orders( [ $result['item']['id'] ] ) );
	}

	public function test_the_default_category_is_marked_and_can_be_chosen(): void {
		$GLOBALS['bulkcat_test']['options']['default_product_cat'] = 9;
		$tree = CategoryTree::tree();
		$this->assertSame( [ 9 ], array_values( array_filter( range( 1, 11 ), static fn( int $id ): bool => $tree->node( $id )['default'] ) ) );
		$key = ( new Cache() )->key( [] );

		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_term:5'] = true;
		$result = ( new Writer() )->make_default( 5, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 5, $GLOBALS['bulkcat_test']['options']['default_product_cat'] );
		$this->assertTrue( $result['item']['default'] );
		$this->assertSame( [ [ 5, Writer::DEFAULT_PROP, 9, 5, 'applied' ] ], $this->store->journal() );
		$this->assertSame( 'tree', $this->store->batch( $result['batch_id'] )['source'] );
		$this->assertSame( Writer::MAKE_DEFAULT, json_decode( $this->store->batch( $result['batch_id'] )['operations'], true )[0]['action'] );

		$this->assertTrue( CategoryTree::tree()->node( 5 )['default'] );
		$this->assertNotSame( $key, ( new Cache() )->key( [] ), 'A stored tree with the old default is never served.' );
	}

	public function test_choosing_the_default_needs_permission_to_edit_that_category(): void {
		$GLOBALS['bulkcat_test']['options']['default_product_cat'] = 9;
		$refused = ( new Writer() )->make_default( 5, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertSame( 9, $GLOBALS['bulkcat_test']['options']['default_product_cat'] );
		$this->assertSame( [], $this->store->changes );
		$missing = ( new Writer() )->make_default( 999, self::USER );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}
}
