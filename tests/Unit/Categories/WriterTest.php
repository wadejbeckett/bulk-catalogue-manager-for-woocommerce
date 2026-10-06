<?php
/**
 * Categories\Writer (T63, T64; SPEC §4.7, §7, §9.3, §13) over the seeded
 * four-level tree and the in-memory batch tables: a create, an edit and a
 * move each write through WordPress's term functions, are recorded as a
 * `tree` batch of one `term` item and journalled with the old and new
 * values; a duplicate name under the same parent, a slug in use, an empty
 * name, a missing parent and a parent inside the category itself are
 * refused with nothing written; a move writes WooCommerce's own order
 * (NativeOrderTest covers it), which the tree then honours; every write
 * renews the tree cache.
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
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = true;
		$this->store                                        = new InlineStore();
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
		$this->assertStringNotContainsString( 'bulkcat_', $result->get_error_message(), 'No code in the sentence (SPEC §4.8).' );
		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'No batch was recorded.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['inserted_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['updated_terms'] ?? [] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['term_meta_updates'] ?? [] );
		return $result;
	}

	/**
	 * WooCommerce's stored order of some terms.
	 *
	 * @param list<int> $ids Term ids.
	 * @return list<mixed>
	 */
	private static function orders( array $ids ): array {
		return array_map( static fn( int $id ): mixed => $GLOBALS['bulkcat_test']['term_meta'][ $id ][ CategoryTree::ORDER_META ] ?? null, $ids );
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
				'id'            => 12,
				'name'          => 'Hats',
				'slug'          => 'hats',
				'description'   => 'Every hat',
				'parent'        => 1,
				'depth'         => 1,
				'order'         => 0,
				'default'       => false,
				'display_type'  => '',
				'thumbnail_id'  => 0,
				'thumbnail_url' => null,
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
			$GLOBALS['bulkcat_test']['inserted_terms']
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
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] ?? [], 'Nothing is queued.' );
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
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = false;
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

	public function test_the_html_filter_follows_the_user_the_batch_is_recorded_for(): void {
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = true;
		$GLOBALS['bulkcat_test']['user_caps']               = [
			self::USER => [ 'unfiltered_html' => false ],
			13         => [ 'unfiltered_html' => true ],
		];
		$input = [
			'name'        => '<script>x</script>Hats',
			'description' => '<em>Warm</em><iframe></iframe>',
		];

		$filtered = self::writer()->create( $input, self::USER );
		$this->assertSame( [ 'xHats', '<em>Warm</em>' ], [ $filtered['item']['name'], $filtered['item']['description'] ] );

		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = false;
		$kept = self::writer()->create( [ 'name' => '<b>Caps</b>' ] + $input, 13 );
		$this->assertSame( [ '<b>Caps</b>', '<em>Warm</em><iframe></iframe>' ], [ $kept['item']['name'], $kept['item']['description'] ] );
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
		$wpdb->responses = [ '/^INSERT INTO `wp_bulkcat_batch_items`/' => false ] + $wpdb->responses;

		$result = self::writer()->create( [ 'name' => 'Hats' ], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_batch_not_recorded', $result->get_error_code() );
		$this->assertSame( [ [ 12, 'product_cat' ] ], $GLOBALS['bulkcat_test']['deleted_terms'] );
		$this->assertNull( get_term( 12, 'product_cat' ) );
		$this->assertSame( [], $this->store->journal() );
	}

	public function test_a_journal_that_cannot_be_written_is_logged_and_its_text_never_sent(): void {
		$GLOBALS['wpdb']->failing = [ 'insert:wp_bulkcat_changes' ];

		$result = self::writer()->create( [ 'name' => 'Hats' ], self::USER );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'details', $result->get_error_data(), 'The exception text is logged, never sent.' );
		$this->assertSame( 'error', $GLOBALS['bulkcat_test']['logged'][0][0] ?? null );
		$this->assertStringContainsString( 'The change could not be journalled.', $GLOBALS['bulkcat_test']['logged'][0][1] );
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', $GLOBALS['bulkcat_test']['logged'][0][2]['source'] );
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
				'id'            => 3,
				'name'          => 'Tops',
				'slug'          => 'tops',
				'description'   => 'Shirts and more',
				'parent'        => 2,
				'depth'         => 2,
				'order'         => 0,
				'default'       => false,
				'display_type'  => '',
				'thumbnail_id'  => 0,
				'thumbnail_url' => null,
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
			$GLOBALS['bulkcat_test']['updated_terms']
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
		$this->assertSame( [], $GLOBALS['bulkcat_test']['term_meta_updates'] ?? [], 'An edit that keeps the parent leaves the order alone.' );
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
		$GLOBALS['bulkcat_test']['term_list'][] = (object) [
			'term_id'  => 12,
			'name'     => 'Dresses',
			'slug'     => 'dresses',
			'parent'   => 5,
			'taxonomy' => 'product_cat',
		];
		$result                                 = self::writer()->update( 3, [ 'parent' => 5 ], self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( [ 5, 0 ], [ $result['item']['parent'], $result['item']['order'] ] );
		$this->assertSame( [ null, 0 ], self::orders( [ 12, 3 ] ), 'Dresses has no order, so WooCommerce sorts it first; Shirts takes 0 after it.' );
		$this->assertSame(
			[
				[ 3, 'term_parent', 2, 5, 'applied' ],
				[ 3, 'term_menu_order', null, 0, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( [ 12, 3 ], CategoryTree::tree()->children( 5 ) );
	}

	public function test_an_edit_wordpress_refuses_is_journalled_as_failed(): void {
		$GLOBALS['bulkcat_test']['update_term_error'] = new \WP_Error( 'duplicate_term_slug', 'The slug is already in use.' );
		$result                                       = self::writer()->update( 3, [ 'name' => 'Tops' ], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( Writer::NOT_SAVED, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'duplicate_term_slug: The slug is already in use.', $result->get_error_data()['details'] );
		$batch = $this->store->batch( $result->get_error_data()['batch_id'] );
		$this->assertSame( [ 'failed', 1 ], [ $batch['status'], $batch['failed_items'] ] );
		$this->assertSame( [ [ 3, 'term_name', 'Shirts', 'Shirts', 'failed' ] ], $this->store->journal() );
	}

	public function test_a_move_whose_journal_fails_says_it_was_made_and_is_not_called_failed(): void {
		// SILENT-FAILURES-9: WordPress moved the category, then the History row could not be written.
		$GLOBALS['wpdb']->failing = [ 'insert:wp_bulkcat_changes' ];
		$result                   = self::writer()->move( 2, 1, 0, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_save_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['applied'], 'The answer says the change was made, so the screen reloads the tree.' );
		$this->assertSame( 'The category was changed, but the change could not be recorded in History, so it cannot be undone there. Reload the categories to see it.', $result->get_error_message() );
		$this->assertSame( 1, CategoryTree::tree()->node( 2 )['parent'], 'The move stands.' );
		$batch = $this->store->batch( $result->get_error_data()['batch_id'] );
		$this->assertSame( [ 'partial', 0 ], [ $batch['status'], $batch['failed_items'] ], 'Made but not recorded: not "failed".' );
	}

	public function test_a_create_whose_journal_fails_leaves_no_category_behind(): void {
		$GLOBALS['wpdb']->failing = [ 'insert:wp_bulkcat_changes' ];
		$result                   = self::writer()->create( [ 'name' => 'Hats' ], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_save_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['applied'] );
		$this->assertSame( 'The category could not be created, because its History record could not be written. Nothing was changed; try again.', $result->get_error_message() );
		$this->assertContains( [ 12, 'product_cat' ], $GLOBALS['bulkcat_test']['deleted_terms'] ?? [], 'Never a category the journal does not know about.' );
		$this->assertNull( CategoryTree::tree()->node( 12 ) );
		$this->assertSame( 'failed', $this->store->batch( $result->get_error_data()['batch_id'] )['status'] );
	}

	public function test_an_order_wordpress_did_not_store_is_not_journalled_as_applied(): void {
		// SILENT-FAILURES-9: update_term_meta() can fail; the order journalled is the one read back.
		$GLOBALS['bulkcat_test']['term_meta_fail'] = [ CategoryTree::ORDER_META ];
		$before                                    = self::orders( [ 2, 5 ] );
		$result                                    = self::writer()->move( 2, 1, 0, self::USER );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $before, self::orders( [ 2, 5 ] ) );
		$this->assertSame( [], array_filter( $this->store->journal(), static fn( array $row ): bool => 'term_menu_order' === $row[1] && 'applied' === $row[4] ), 'No order row reads applied.' );
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

	/**
	 * An image attachment the user may use.
	 */
	private static function image( int $id, string $mime = 'image/jpeg' ): void {
		$GLOBALS['bulkcat_test']['posts'][ $id ]                                  = [
			'post_type'      => 'attachment',
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		];
		$GLOBALS['bulkcat_test']['meta'][ $id ]['_wp_attached_file']              = '2026/10/img-' . $id . '.jpg';
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['upload_files']       = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ][ 'read_post:' . $id ] = true;
	}

	public function test_an_edit_sets_the_native_display_type_and_image_and_journals_them(): void {
		self::image( 501 );
		$result = self::writer()->update(
			3,
			[
				'display_type' => 'subcategories',
				'thumbnail_id' => 501,
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( 'subcategories', $GLOBALS['bulkcat_test']['term_meta'][3]['display_type'] );
		$this->assertSame( 501, $GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['updated_terms'] ?? [], 'Only native term meta changes; the term itself is untouched.' );
		$this->assertSame(
			[
				[ 3, 'term_display_type', '', 'subcategories', 'applied' ],
				[ 3, 'term_thumbnail_id', 0, 501, 'applied' ],
			],
			$this->store->journal()
		);
		$this->assertSame( 'subcategories', $result['item']['display_type'] );
		$this->assertSame( 501, $result['item']['thumbnail_id'] );
		$this->assertStringContainsString( 'img-501', (string) $result['item']['thumbnail_url'] );

		// Removing the image stores 0, as the native category form does; Default display stores ''.
		$result = self::writer()->update(
			3,
			[
				'display_type' => '',
				'thumbnail_id' => 0,
			],
			self::USER
		);
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( 0, $GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id'] );
		$this->assertSame( '', $GLOBALS['bulkcat_test']['term_meta'][3]['display_type'] );
		$this->assertNull( $result['item']['thumbnail_url'] );
	}

	public function test_a_display_type_or_image_that_is_not_allowed_is_refused_with_nothing_written(): void {
		$this->assert_refused( self::writer()->update( 3, [ 'display_type' => 'grid' ], self::USER ), Writer::INVALID_DETAIL );
		self::image( 502, 'application/pdf' );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['read_post:999'] = true;
		$this->assert_refused( self::writer()->update( 3, [ 'thumbnail_id' => 999 ], self::USER ), Writer::INVALID_IMAGE );
		$this->assert_refused( self::writer()->update( 3, [ 'thumbnail_id' => 502 ], self::USER ), Writer::INVALID_IMAGE );
		self::image( 503 );
		unset( $GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['upload_files'] );
		$this->assert_refused( self::writer()->update( 3, [ 'thumbnail_id' => 503 ], self::USER ), Writer::INVALID_IMAGE, 403 );
		$this->assert_refused( self::writer()->update( 3, [ 'thumbnail_id' => -4 ], self::USER ), Writer::INVALID_IMAGE );
	}

	public function test_a_create_can_set_the_display_type_and_image(): void {
		self::image( 504 );
		$result = self::writer()->create(
			[
				'name'         => 'Hats',
				'display_type' => 'both',
				'thumbnail_id' => 504,
			],
			self::USER
		);
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'both', $GLOBALS['bulkcat_test']['term_meta'][12]['display_type'] );
		$this->assertSame( 504, $GLOBALS['bulkcat_test']['term_meta'][12]['thumbnail_id'] );
		$this->assertContains( [ 12, 'term_display_type', null, 'both', 'applied' ], $this->store->journal() );
		$this->assertContains( [ 12, 'term_thumbnail_id', null, 504, 'applied' ], $this->store->journal() );
	}

	public function test_history_names_every_category_property_in_plain_words(): void {
		$this->assertSame(
			[ 'Name', 'Slug', 'Description', 'Parent category', 'Display type', 'Image', 'Position', 'Old position in this screen only', 'Default category' ],
			array_map( [ Writer::class, 'label' ], [ 'term_name', 'term_slug', 'term_description', 'term_parent', 'term_display_type', 'term_thumbnail_id', 'term_menu_order', 'term_order', 'term_default' ] )
		);
		$this->assertNull( Writer::label( 'regular_price' ), 'Product fields keep their own labels.' );
	}

	public function test_a_create_records_its_details_in_the_batch_step(): void {
		self::image( 505 );
		$result = self::writer()->create(
			[
				'name'         => 'Caps',
				'display_type' => 'products',
				'thumbnail_id' => 505,
			],
			self::USER
		);
		$args   = json_decode( $this->store->batch( $result['batch_id'] )['operations'], true )[0]['args'];
		$this->assertSame( [ 'products', 505 ], [ $args['display_type'] ?? null, $args['thumbnail_id'] ?? null ] );
	}
}
