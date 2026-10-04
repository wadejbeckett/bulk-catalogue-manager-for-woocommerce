<?php
/** Native dispatch fencing on marked manager links. @package BulkCatalogueManager */
declare( strict_types = 1 );
// phpcs:disable WordPress.Security.NonceVerification, Squiz.PHP.DisallowMultipleAssignments -- Test-owned request fixtures are passed through the production nonce guard.
namespace BulkCatalogueManager\Tests\Unit\Admin;

use BulkCatalogueManager\Admin\{ProductRowActions, ProductRowActionGuard};
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 2 ) . '/Support/row-action-stubs.php';
require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ProductRowActionGuardTest extends TestCase {
	private array $old_request;
	private array $old_get;
	private array $old_post;
	protected function setUp(): void {
		$this->old_request = $_REQUEST;
		$this->old_get     = $_GET;
		$this->old_post    = $_POST;
		$_POST             = [];
		bulkcat_test_reset();
		SeededCatalogue::seed();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['caps']      = array_fill_keys( [ 'manage_woocommerce', 'edit_products', 'edit_post', 'delete_post', 'read_post' ], true );
		( new Plugin() )->boot();
		$native = new \WC_Admin_Duplicate_Product();
		add_action( 'admin_action_duplicate_product', [ $native, 'duplicate_product_action' ], 10, 0 );
		$GLOBALS['wp_filter']['admin_action_duplicate_product'] = (object) [ 'callbacks' => [ 10 => [ [ 'function' => [ $native, 'duplicate_product_action' ] ] ] ] ];
	}
	protected function tearDown(): void {
		$_REQUEST = $this->old_request;
		$_GET     = $this->old_get;
		$_POST    = $this->old_post;
		unset( $GLOBALS['wp_filter']['admin_action_duplicate_product'] );
	}
	private function request( string $action ): void {
		$dto = ProductRowActions::decorate( [ [ 'id' => 101 ] ] )[0]['native_actions'];
		parse_str( parse_url( $dto[ $action ]['url'], PHP_URL_QUERY ), $_GET );
		$_REQUEST = $_GET;
	}
	public function test_revoked_permission_stops_marked_native_duplicate_dispatch(): void {
		$this->request( 'duplicate' );
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		try {
			do_action( 'admin_action_duplicate_product' );
			$this->fail( 'Revoked actor reached native dispatch.' );
		} catch ( \BulkcatTestDied $error ) {
			$this->assertSame( 403, $error->args['response'] );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['native_duplicates'] ?? 0 );
		}
	}
	public function test_valid_link_enters_native_handler_once_and_unmarked_traffic_is_untouched(): void {
		$this->request( 'duplicate' );
		do_action( 'admin_action_duplicate_product' );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['native_duplicates'] );
		$_REQUEST = $_GET = [];
		ProductRowActionGuard::trash();
		ProductRowActionGuard::duplicate();
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['native_duplicates'] );
	}
	public function test_malformed_ids_nonces_and_mixed_native_requests_refuse_before_dispatch(): void {
		foreach ( [ [ 'post' => 0 ], [ 'post' => -101 ], [ 'post' => [ 101 ] ], [ 'post' => '101suffix' ], [ '_wpnonce' => 'wrong' ], [ 'action' => 'trash' ], [ 'bulkcat_row_action' => 'trash' ] ] as $bad ) {
			$this->request( 'duplicate' );
			$_GET = $_REQUEST = array_replace( $_REQUEST, $bad );
			try {
				do_action( 'admin_action_duplicate_product' );
				$this->fail( 'Malformed marked request reached native handler.' );
			} catch ( \BulkcatTestDied $error ) {
				$this->assertSame( 0, $GLOBALS['bulkcat_test']['native_duplicates'] ?? 0 );
			}
		}
	}
	public function test_trash_requires_the_warning_state_that_was_rendered_and_reviewed(): void {
		$GLOBALS['bulkcat_test']['meta'][101]['total_sales'] = 0;
		$this->request( 'trash' );
		$GLOBALS['bulkcat_test']['meta'][101]['total_sales'] = 4;
		try {
			ProductRowActionGuard::trash();
			$this->fail( 'A new sold warning was skipped.' );
		} catch ( \BulkcatTestDied $error ) {
			$this->assertSame( 409, $error->args['response'] );
		}
		$this->request( 'trash' );
		ProductRowActionGuard::trash();
		$_GET['action'] = $_REQUEST['action'] = 'delete';
		try {
			ProductRowActionGuard::trash();
			$this->fail( 'Delete permanently reached native handler.' );
		} catch ( \BulkcatTestDied $error ) {
			$this->assertSame( 400, $error->args['response'] );
		}
	}
	public function test_absent_native_duplicate_handler_refuses_instead_of_replacing_it(): void {
		$this->request( 'duplicate' );
		unset( $GLOBALS['wp_filter']['admin_action_duplicate_product'] );
		try {
			ProductRowActionGuard::duplicate();
			$this->fail( 'Missing native handler was accepted.' );
		} catch ( \BulkcatTestDied $error ) {
			$this->assertSame( 409, $error->args['response'] );
		}
	}
	public function test_trash_rejects_mismatched_native_get_target_and_post_overrides(): void {
		foreach ( [ 'different-target', 'post-delete' ] as $case ) {
			$this->request( 'trash' );
			if ( 'different-target' === $case ) {
				$_GET['post'] = '102';
			} else {
				$_POST['deletepost'] = '1';
			}
			try {
				ProductRowActionGuard::trash();
				$this->fail( 'Mixed native dispatch input was accepted.' );
			} catch ( \BulkcatTestDied $error ) {
				$this->assertSame( 400, $error->args['response'] );
			}
			$_POST = [];
		}
	}
}
