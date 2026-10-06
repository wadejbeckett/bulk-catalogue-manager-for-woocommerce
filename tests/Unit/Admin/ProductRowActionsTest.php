<?php
/** Per-actor native row actions; no simulated business mutations. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Admin;

use BulkCatalogueManager\Admin\ProductRowActions;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use BulkCatalogueManager\Rest\ProductsController;
use BulkCatalogueManager\Fields\Registry;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 2 ) . '/Support/row-action-stubs.php';
require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ProductRowActionsTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		SeededCatalogue::seed();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['caps']      = array_fill_keys( [ 'manage_woocommerce', 'edit_products', 'edit_post', 'delete_post', 'read_post' ], true );
	}
	public function test_duplicate_rechecks_filtered_capability_create_and_object(): void {
		foreach ( [ 'edit_post:101', 'edit_products', 'manage_woocommerce' ] as $cap ) {
			$GLOBALS['bulkcat_test']['caps'][ $cap ] = false;
			$answer                                  = ProductRowActions::authorise( 'duplicate', 101 );
			$this->assertInstanceOf( \WP_Error::class, $answer );
			$this->assertSame( 403, $answer->get_error_data()['status'] );
			$GLOBALS['bulkcat_test']['caps'][ $cap ] = true;
		}
		$this->assertTrue( ProductRowActions::authorise( 'duplicate', 101 ) );
		add_filter( 'woocommerce_duplicate_product_capability', static fn() => 'custom_duplicate' );
		$this->assertInstanceOf( \WP_Error::class, ProductRowActions::authorise( 'duplicate', 101 ) );
	}
	public function test_drafts_have_no_live_view_variations_have_only_details_and_missing_refuses(): void {
		foreach ( [ 'draft', 'pending', 'future', 'trash' ] as $status ) {
			$GLOBALS['bulkcat_test']['posts'][101]['post_status'] = $status;
			$this->assertNull( ProductRowActions::decorate( [ [ 'id' => 101 ] ] )[0]['native_actions']['view'] );
		}
		$row = ProductRowActions::decorate( [ [ 'id' => 201 ] ] )[0]['native_actions'];
		$this->assertTrue( $row['details'] );
		foreach ( [ 'editor', 'view', 'duplicate', 'trash' ] as $action ) {
			$this->assertNull( $row[ $action ] );
		}
		$this->assertNotSame( '', $row['unavailable'] );
		$GLOBALS['bulkcat_test']['caps']['edit_post:103'] = false;
		$this->assertFalse( ProductRowActions::decorate( [ [ 'id' => 201 ] ] )[0]['native_actions']['details'] );
		$this->assertSame( 404, ProductRowActions::authorise( 'editor', 999 )->get_error_data()['status'] );
	}
	public function test_trash_cannot_be_filtered_into_delete_or_another_destination(): void {
		foreach ( [ 'https://example.test/wp-admin/post.php?post=101&action=delete', 'https://other.test/wp-admin/post.php?post=101&action=trash', 'https://example.test/wp-admin/post.php?post=102&action=trash' ] as $url ) {
			$filter = static fn() => $url;
			add_filter( 'get_delete_post_link', $filter );
			$this->assertNull( ProductRowActions::decorate( [ [ 'id' => 101 ] ] )[0]['native_actions']['trash'] );
			remove_filter( 'get_delete_post_link', $filter );
		}
	}
	public function test_private_view_delete_denial_lock_and_sold_warning_are_independent(): void {
		$GLOBALS['bulkcat_test']['posts'][101]['post_status'] = 'private';
		$GLOBALS['bulkcat_test']['caps']['read_post:101']     = false;
		$this->assertInstanceOf( \WP_Error::class, ProductRowActions::authorise( 'view', 101 ) );
		$GLOBALS['bulkcat_test']['posts'][101]['post_status'] = 'publish';
		$GLOBALS['bulkcat_test']['meta'][101]['total_sales']  = 3;
		$row = ProductRowActions::decorate( [ [ 'id' => 101 ] ] )[0]['native_actions'];
		$this->assertStringContainsString( 'has produced sales', $row['trash']['confirm'] );
		$this->assertStringContainsString( 'bulkcat_row_action=trash', $row['trash']['url'] );
		$this->assertStringNotContainsString( '&amp;', $row['trash']['url'] );
		$GLOBALS['bulkcat_test']['caps']['delete_post:101'] = false;
		$this->assertNull( ProductRowActions::decorate( [ [ 'id' => 101 ] ] )[0]['native_actions']['trash'] );
		$GLOBALS['bulkcat_test']['caps']['delete_post:101'] = true;
		$GLOBALS['bulkcat_test']['row_locks'][101]          = 8;
		$this->assertInstanceOf( \WP_Error::class, ProductRowActions::authorise( 'trash', 101 ) );
	}
	public function test_cached_page_gets_fresh_actor_projection_without_mutating_cached_rows(): void {
		$controller = new ProductsController( new Registry() );
		$request    = new \WP_REST_Request( 'GET', '/bulkcat/v1/products' );
		$request->set_param( 'per_page', 25 );
		$first = $controller->get_items( $request )->get_data()['items'];
		$this->assertNotSame( null, $first[0]['native_actions']['duplicate'] );
		$this->assertStringNotContainsString( 'native_actions', json_encode( $GLOBALS['bulkcat_test']['cache'], JSON_THROW_ON_ERROR ) );
		$GLOBALS['bulkcat_test']['caps']['edit_products'] = false;
		$second = $controller->get_items( $request )->get_data()['items'];
		$this->assertSame( array_column( $first, 'id' ), array_column( $second, 'id' ) );
		$this->assertNull( $second[0]['native_actions']['duplicate'] );
		$this->assertNotSame( null, $first[0]['native_actions']['duplicate'] );
	}
	public function test_projection_primes_once_for_each_page_size_without_product_hydration(): void {
		foreach ( [ 25, 100 ] as $count ) {
			$rows = [];
			for ( $offset = 0; $offset < $count; ++$offset ) {
				$id                                      = 1000 + $offset;
				$GLOBALS['bulkcat_test']['posts'][ $id ] = $GLOBALS['bulkcat_test']['posts'][101];
				$rows[]                                  = [ 'id' => $id ];
			}
			$GLOBALS['bulkcat_test']['primed_posts'] = [];
			$GLOBALS['bulkcat_test']['primed_meta']  = [];
			$before                                  = bulkcat_test_query_count();
			$answer                                  = ProductRowActions::decorate( $rows );
			$this->assertCount( $count, $answer );
			$this->assertSame( 2, bulkcat_test_query_count() - $before, 'One post prime and one metadata prime, independent of row count.' );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['products_built'] ?? 0 );
		}
	}
	public function test_anonymous_and_administrator_only_screen_rights_are_not_bypassed(): void {
		$GLOBALS['bulkcat_test']['caps']      = [];
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$this->assertSame( 401, ProductRowActions::authorise( 'editor', 101 )->get_error_data()['status'] );
		$GLOBALS['bulkcat_test']['logged_in']                   = true;
		$GLOBALS['bulkcat_test']['caps']                        = [
			'manage_woocommerce' => true,
			'edit_products'      => true,
			'edit_post'          => true,
		];
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$this->assertSame( 403, ProductRowActions::authorise( 'editor', 101 )->get_error_data()['status'] );
	}
}
