<?php
/**
 * Categories\Writer (T63, T64; SPEC §4.7, §7, §9.3, §13) over the seeded
 * four-level tree and the in-memory batch tables: a create, an edit and a
 * move each write through WordPress's term functions, are recorded as a
 * `tree` batch of one `term` item and journalled with the old and new
 * values; a duplicate name under the same parent, a slug in use, an empty
 * name, a missing parent and a parent inside the category itself are
 * refused with nothing written; a move renumbers the siblings compactly in
 * `bcm_order`, which the tree then honours; every write renews the tree cache.
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
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';
require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class WriterTest extends TestCase {
	private const USER = 12;

	private InlineStore $store;

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']               = true;
		$GLOBALS['bcm_test']['user_id']                 = self::USER;
		$GLOBALS['bcm_test']['caps']['unfiltered_html'] = true;
		$this->store                                    = new InlineStore();
		CatalogueFixture::seed();
		Cache::register_hooks();
		set_transient( Cache::VERSION, 'before', 0 );
	}

	private static function writer(): Writer {
		return new Writer();
	}

	private function assert_refused( mixed $result, string $code, int $status = 400 ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bcm_', $result->get_error_message(), 'No code in the sentence (SPEC §4.8).' );
		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'No batch was recorded.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['inserted_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bcm_test']['updated_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bcm_test']['term_meta_updates'] ?? [] );
		return $result;
	}

	/**
	 * The stored tree order of some terms.
	 *
	 * @param list<int> $ids Term ids.
	 * @return list<mixed>
	 */
	private static function orders( array $ids ): array {
		return array_map( static fn( int $id ): mixed => $GLOBALS['bcm_test']['term_meta'][ $id ][ CategoryTree::TREE_ORDER_META ] ?? null, $ids );
	}

	public function test_a_create_writes_the_term_and_journals_a_tree_batch_of_one(): void {
		$result = self::writer()->create(
			[
				'name'        => 'Hats',
				'parent'      => 1,
				'description' => 'Every hat',
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 'item', 'batch_id', 'status', 'results' ], array_keys( $result ) );
		$this->assertSame(
			[
				'id'          => 12,
				'name'        => 'Hats',
				'slug'        => 'hats',
				'parent'      => 1,
				'depth'       => 1,
				'order'       => 0,
				'description' => 'Every hat',
			],
			$result['item']
		);
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( [ 'changed', 'changed', 'changed', 'changed' ], array_column( $result['results'], 'state' ) );
		$this->assertSame(
			[
				[
					'Hats',
					'product_cat',
					[
						'description' => 'Every hat',
						'parent'      => 1,
					],
				],
			],
			$GLOBALS['bcm_test']['inserted_terms']
		);

		$batch = $this->store->batch( $result['batch_id'] );
		$this->assertSame( [ 'tree', 'complete', self::USER, 1 ], [ $batch['source'], $batch['status'], $batch['user_id'], $batch['total_items'] ] );
		$this->assertSame( Writer::CREATE, json_decode( $batch['operations'], true )[0]['action'] );
		$this->assertSame( [ [ 12, 'term', 'done' ] ], array_map( static fn( array $i ): array => [ $i['object_id'], $i['object_type'], $i['state'] ], array_values( $this->store->items ) ) );
		$this->assertSame(
			[
				[ 12, 'term_name', null, 'Hats', 'applied' ],
				[ 12, 'term_slug', null, 'hats', 'applied' ],
				[ 12, 'term_description', null, 'Every hat', 'applied' ],
				[ 12, 'term_parent', null, 1, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( [ 'term' ], array_values( array_unique( array_column( $this->store->changes, 'object_type' ) ) ) );
		$this->assertNotSame( 'before', get_transient( Cache::VERSION ), 'The tree cache was renewed.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['scheduled'] ?? [], 'Nothing is queued.' );
	}

	public function test_a_create_takes_a_slug_and_a_name_used_under_another_parent(): void {
		$result = self::writer()->create(
			[
				'name'   => 'Men',
				'slug'   => 'Mens Shoes',
				'parent' => 6,
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( [ 'Men', 'mens-shoes', 6 ], [ $result['item']['name'], $result['item']['slug'], $result['item']['parent'] ] );
	}

	public function test_without_unfiltered_html_the_name_and_description_pass_the_post_filter(): void {
		$GLOBALS['bcm_test']['caps']['unfiltered_html'] = false;
		$result = self::writer()->create(
			[
				'name'        => '<script>x</script>Hats',
				'description' => '<em>Warm</em><iframe></iframe>',
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( [ 'xHats', '<em>Warm</em>' ], [ $result['item']['name'], $result['item']['description'] ] );
	}

	public function test_a_create_refuses_an_empty_or_duplicate_name_a_taken_slug_and_a_missing_parent(): void {
		$this->assert_refused( self::writer()->create( [ 'name' => '   ' ], self::USER ), Writer::NAME_REQUIRED );
		$taken = $this->assert_refused(
			self::writer()->create(
				[
					'name'   => ' men ',
					'parent' => 1,
				],
				self::USER
			),
			Writer::NAME_TAKEN
		);
		$this->assertSame( 'A category called "men" already exists in "Clothing". Choose a different name, or put it somewhere else.', $taken->get_error_message() );
		$top = $this->assert_refused( self::writer()->create( [ 'name' => 'SHOES' ], self::USER ), Writer::NAME_TAKEN );
		$this->assertStringContainsString( 'the top level', $top->get_error_message() );
		$slug = $this->assert_refused(
			self::writer()->create(
				[
					'name' => 'Blouses',
					'slug' => 'shirts',
				],
				self::USER
			),
			Writer::SLUG_TAKEN
		);
		$this->assertSame( 'The slug "shirts" is already used by the category "Shirts". Choose a different slug.', $slug->get_error_message() );
		$this->assert_refused(
			self::writer()->create(
				[
					'name' => 'Blouses',
					'slug' => '!!!',
				],
				self::USER
			),
			Writer::SLUG_INVALID
		);
		$this->assert_refused(
			self::writer()->create(
				[
					'name'   => 'Blouses',
					'parent' => 99,
				],
				self::USER
			),
			Writer::PARENT_MISSING
		);
	}

	public function test_a_create_whose_batch_cannot_be_recorded_is_removed_again(): void {
		global $wpdb;
		$wpdb->responses = [ '/^INSERT INTO `wp_bcm_batch_items`/' => false ] + $wpdb->responses;

		$result = self::writer()->create( [ 'name' => 'Hats' ], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bcm_batch_not_recorded', $result->get_error_code() );
		$this->assertSame( [ [ 12, 'product_cat' ] ], $GLOBALS['bcm_test']['deleted_terms'] );
		$this->assertNull( get_term( 12, 'product_cat' ) );
		$this->assertSame( [], $this->store->journal() );
	}

	public function test_a_journal_that_cannot_be_written_is_logged_and_its_text_never_sent(): void {
		$GLOBALS['wpdb']->failing = [ 'insert:wp_bcm_changes' ];

		$result = self::writer()->create( [ 'name' => 'Hats' ], self::USER );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'details', $result->get_error_data(), 'The exception text is logged, never sent.' );
		$this->assertSame( 'error', $GLOBALS['bcm_test']['logged'][0][0] ?? null );
		$this->assertStringContainsString( 'The change could not be journalled.', $GLOBALS['bcm_test']['logged'][0][1] );
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', $GLOBALS['bcm_test']['logged'][0][2]['source'] );
	}

	public function test_an_edit_journals_the_old_and_new_name_slug_and_description(): void {
		$result = self::writer()->update(
			3,
			[
				'name'        => 'Tops',
				'slug'        => 'tops',
				'description' => 'Shirts and more',
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame(
			[
				'id'          => 3,
				'name'        => 'Tops',
				'slug'        => 'tops',
				'parent'      => 2,
				'depth'       => 2,
				'order'       => 0,
				'description' => 'Shirts and more',
			],
			$result['item']
		);
		$this->assertSame(
			[
				[
					3,
					'product_cat',
					[
						'name'        => 'Tops',
						'slug'        => 'tops',
						'description' => 'Shirts and more',
					],
				],
			],
			$GLOBALS['bcm_test']['updated_terms']
		);
		$this->assertSame(
			[
				[ 3, 'term_name', 'Shirts', 'Tops', 'applied' ],
				[ 3, 'term_slug', 'shirts', 'tops', 'applied' ],
				[ 3, 'term_description', '', 'Shirts and more', 'applied' ],
			],
			$this->store->journal()
		);
		$batch = $this->store->batch( $result['batch_id'] );
		$this->assertSame( [ 'tree', 'complete', 1 ], [ $batch['source'], $batch['status'], $batch['changed_items'] ] );
		$this->assertSame( [], $GLOBALS['bcm_test']['term_meta_updates'] ?? [], 'An edit that keeps the parent leaves the order alone.' );
		$this->assertNotSame( 'before', get_transient( Cache::VERSION ) );
	}

	public function test_an_edit_to_the_same_values_journals_nothing(): void {
		$result = self::writer()->update( 2, [ 'name' => 'Men' ], self::USER );
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( [ 'already' ], array_column( $result['results'], 'state' ) );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( [], $this->store->journal() );
		$this->assertSame( 0, $this->store->batch( $result['batch_id'] )['changed_items'] );
	}

	public function test_an_edit_refuses_a_sibling_name_and_a_taken_slug_but_not_its_own_name_in_another_case(): void {
		$this->assert_refused( self::writer()->update( 2, [ 'name' => 'women' ], self::USER ), Writer::NAME_TAKEN );
		$this->assert_refused( self::writer()->update( 2, [ 'name' => '' ], self::USER ), Writer::NAME_REQUIRED );
		$this->assert_refused( self::writer()->update( 2, [ 'slug' => 'women' ], self::USER ), Writer::SLUG_TAKEN );
		$this->assertTrue( is_array( self::writer()->update( 2, [ 'name' => 'MEN' ], self::USER ) ) );
		$this->assertSame( [ [ 2, 'term_name', 'Men', 'MEN', 'applied' ] ], $this->store->journal() );
	}

	public function test_an_edit_moving_into_itself_or_a_descendant_is_refused(): void {
		foreach ( [ 1, 2, 3, 4 ] as $target ) {
			$refused = $this->assert_refused( self::writer()->update( 1, [ 'parent' => $target ], self::USER ), Writer::CYCLE );
			$this->assertSame( 'A category cannot be moved inside itself.', $refused->get_error_message() );
		}
		$this->assert_refused( self::writer()->update( 2, [ 'parent' => 6 ] + [ 'name' => 'Boots' ], self::USER ), Writer::NAME_TAKEN );
	}

	public function test_an_edit_of_the_parent_places_the_category_last_among_its_new_siblings(): void {
		$GLOBALS['bcm_test']['term_list'][] = (object) [
			'term_id'  => 12,
			'name'     => 'Dresses',
			'slug'     => 'dresses',
			'parent'   => 5,
			'taxonomy' => 'product_cat',
		];
		$result                             = self::writer()->update( 3, [ 'parent' => 5 ], self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 5, 1 ], [ $result['item']['parent'], $result['item']['order'] ] );
		$this->assertSame( [ 0, 1 ], self::orders( [ 12, 3 ] ) );
		$this->assertSame(
			[
				[ 3, 'term_parent', 2, 5, 'applied' ],
				[ 3, 'term_order', null, 1, 'applied' ],
				[ 12, 'term_order', null, 0, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( [ 12, 3 ], CategoryTree::tree()->children( 5 ) );
	}

	public function test_an_edit_wordpress_refuses_is_journalled_as_failed(): void {
		$GLOBALS['bcm_test']['update_term_error'] = new \WP_Error( 'duplicate_term_slug', 'The slug is already in use.' );
		$result                                   = self::writer()->update( 3, [ 'name' => 'Tops' ], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( Writer::NOT_SAVED, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'duplicate_term_slug: The slug is already in use.', $result->get_error_data()['details'] );
		$batch = $this->store->batch( $result->get_error_data()['batch_id'] );
		$this->assertSame( [ 'failed', 1 ], [ $batch['status'], $batch['failed_items'] ] );
		$this->assertSame( [ [ 3, 'term_name', 'Shirts', 'Shirts', 'failed' ] ], $this->store->journal() );
	}

	public function test_a_reorder_renumbers_the_siblings_compactly_and_the_tree_follows(): void {
		// WooCommerce's own order puts Women (1) before Men (2).
		$this->assertSame( [ 5, 2 ], CategoryTree::tree()->children( 1 ) );

		$result = self::writer()->move( 2, 1, 0, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( [ 1, 0 ], [ $result['item']['parent'], $result['item']['order'] ] );
		$this->assertSame( [ 'already', 'changed' ], array_column( $result['results'], 'state' ) );
		$this->assertSame( [ 0, 1 ], self::orders( [ 2, 5 ] ) );
		$this->assertSame( [ 2, 5 ], CategoryTree::tree()->children( 1 ), 'The tree honours bcm_order before WooCommerce\'s order.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['updated_terms'] ?? [], 'A reorder under the same parent does not touch the term.' );
		$this->assertSame(
			[
				[ 2, 'term_order', null, 0, 'applied' ],
				[ 5, 'term_order', null, 1, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( [ 'tree', 'term' ], [ $this->store->batch( $result['batch_id'] )['source'], array_values( $this->store->items )[0]['object_type'] ] );
		$this->assertNotSame( 'before', get_transient( Cache::VERSION ), 'A meta-only write renews the cache itself.' );
	}

	public function test_a_move_to_another_parent_renumbers_both_groups(): void {
		// Clothing holds Women (0), Men (1) after a reorder; Shoes holds Boots only.
		$GLOBALS['bcm_test']['term_meta'][5][ CategoryTree::TREE_ORDER_META ] = 0;
		$GLOBALS['bcm_test']['term_meta'][2][ CategoryTree::TREE_ORDER_META ] = 1;

		$result = self::writer()->move( 7, 1, 1, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 1, 1, 1 ], [ $result['item']['parent'], $result['item']['depth'], $result['item']['order'] ] );
		$this->assertSame( [ [ 7, 'product_cat', [ 'parent' => 1 ] ] ], array_slice( $GLOBALS['bcm_test']['updated_terms'], -1 ) );
		$this->assertSame( [ 5, 7, 2 ], CategoryTree::tree()->children( 1 ) );
		$this->assertSame( [ 0, 1, 2 ], self::orders( [ 5, 7, 2 ] ), 'Contiguous from 0.' );
		$this->assertSame( [], CategoryTree::tree()->children( 6 ) );
		$this->assertSame(
			[
				[ 7, 'term_parent', 6, 1, 'applied' ],
				[ 7, 'term_order', null, 1, 'applied' ],
				[ 2, 'term_order', 1, 2, 'applied' ],
			],
			$this->store->journal()
		);
	}

	public function test_a_move_leaving_a_group_closes_the_gap(): void {
		foreach ( [
			3  => 0,
			12 => 1,
			13 => 2,
		] as $id => $order ) {
			if ( 3 !== $id ) {
				$GLOBALS['bcm_test']['term_list'][] = (object) [
					'term_id'  => $id,
					'name'     => 'Child ' . $id,
					'slug'     => 'child-' . $id,
					'parent'   => 2,
					'taxonomy' => 'product_cat',
				];
			}
			$GLOBALS['bcm_test']['term_meta'][ $id ][ CategoryTree::TREE_ORDER_META ] = $order;
		}
		$this->assertTrue( is_array( self::writer()->move( 3, 0, null, self::USER ) ) );
		$this->assertSame( [ 12, 13 ], CategoryTree::tree()->children( 2 ) );
		$this->assertSame( [ 0, 1 ], self::orders( [ 12, 13 ] ) );
		$top = CategoryTree::tree()->children( 0 );
		$this->assertSame( 3, end( $top ), 'Placed last at the top level.' );
		$this->assertSame( count( $top ) - 1, self::orders( [ 3 ] )[0] );
	}

	public function test_a_move_inside_itself_a_child_or_a_grandchild_is_refused(): void {
		foreach ( [
			'self'             => 1,
			'child'            => 2,
			'grandchild'       => 3,
			'great-grandchild' => 4,
		] as $label => $target ) {
			$refused = $this->assert_refused( self::writer()->move( 1, $target, 0, self::USER ), Writer::CYCLE );
			$this->assertSame( 'A category cannot be moved inside itself.', $refused->get_error_message(), $label );
		}
		$this->assert_refused( self::writer()->move( 1, 99, 0, self::USER ), Writer::PARENT_MISSING );
	}

	public function test_an_unknown_id_is_not_found(): void {
		$this->assert_refused( self::writer()->update( 999, [ 'name' => 'X' ], self::USER ), Writer::NOT_FOUND, 404 );
		$this->assert_refused( self::writer()->move( 999, 0, 0, self::USER ), Writer::NOT_FOUND, 404 );
	}

	public function test_tree_order_ranks_before_woocommerce_order_then_name(): void {
		$terms = CatalogueFixture::terms();
		$tree  = Tree::from_terms(
			$terms,
			[
				2 => '2',
				5 => '1',
			],
			[ 2 => '0' ]
		);
		$this->assertSame( [ 2, 5 ], $tree->children( 1 ), 'A ranked sibling comes before an unranked one.' );
		$this->assertSame( 0, $tree->node( 2 )['order'] );
		$this->assertSame( 1, $tree->node( 5 )['order'], 'An unranked node keeps WooCommerce\'s order.' );
		$tree = Tree::from_terms(
			$terms,
			[],
			[
				1 => '1',
				6 => '0',
			]
		);
		$this->assertSame( [ 6, 1, 10, 9 ], $tree->children( 0 ), 'Ranked by tree order, then the rest by name.' );
	}

	public function test_a_tree_batch_takes_term_items_only(): void {
		$thrown = null;
		try {
			( new BatchFactory() )->open(
				self::USER,
				[
					[
						'action' => Writer::EDIT,
						'args'   => [],
					],
				],
				[ [ 101, 'product' ] ],
				Writer::SOURCE
			);
		} catch ( \InvalidArgumentException $e ) {
			$thrown = $e;
		}
		$this->assertInstanceOf( \InvalidArgumentException::class, $thrown );
		$this->assertSame( [], $GLOBALS['wpdb']->writes );
	}
}
