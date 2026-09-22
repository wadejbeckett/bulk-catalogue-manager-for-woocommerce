<?php
/**
 * Capability resolution (SPEC §13, §11): read, product write, category write,
 * settings write and per-object edits, each honouring the administrators-only
 * setting and the bcm_capability filter where it applies.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Rest\Access;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in'] = true;
	}

	/**
	 * Give the current user these capabilities only.
	 *
	 * @param list<string> $caps Capabilities.
	 */
	private static function grant( array $caps ): void {
		$GLOBALS['bcm_test']['caps'] = array_fill_keys( $caps, true );
	}

	private static function administrators_only( bool $on ): void {
		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'administrators_only' => $on ];
	}

	private static function http_status( mixed $result ): int {
		return true === $result ? 200 : $result->get_error_data()['status'];
	}

	public function test_a_shop_manager_reads_and_writes_until_administrators_only_is_on(): void {
		self::grant( [ 'manage_woocommerce', 'edit_products', 'manage_product_terms' ] );
		$this->assertSame( [ 'manage_woocommerce', 'edit_products' ], Access::write_capabilities() );
		$this->assertSame( [ 'manage_woocommerce', 'manage_product_terms' ], Access::terms_capabilities() );
		$this->assertSame( 200, self::http_status( Access::can_read() ) );
		$this->assertSame( 200, self::http_status( Access::can_write() ) );
		$this->assertSame( 200, self::http_status( Access::can_manage_terms() ) );
		$this->assertSame( 403, self::http_status( Access::can_manage_settings() ), 'Settings writes need manage_options.' );

		self::administrators_only( true );
		$this->assertSame( [ 'manage_options', 'edit_products' ], Access::write_capabilities() );
		$this->assertSame( [ 'manage_options', 'manage_product_terms' ], Access::terms_capabilities() );
		$this->assertSame( 403, self::http_status( Access::can_read() ) );
		$this->assertSame( 403, self::http_status( Access::can_write() ) );
		$this->assertSame( 403, self::http_status( Access::can_manage_terms() ) );
		$this->assertStringContainsString( 'permission to use Bulk Catalogue Manager', Access::can_write()->get_error_message(), 'A write refused for want of the read capability says so.' );
	}

	public function test_an_administrator_passes_with_administrators_only_off_and_on(): void {
		self::grant( [ 'manage_options', 'manage_woocommerce', 'edit_products', 'edit_others_products', 'manage_product_terms' ] );
		foreach ( [ false, true ] as $on ) {
			self::administrators_only( $on );
			$this->assertSame( 200, self::http_status( Access::can_read() ) );
			$this->assertSame( 200, self::http_status( Access::can_write() ) );
			$this->assertSame( 200, self::http_status( Access::can_manage_terms() ) );
			$this->assertSame( 200, self::http_status( Access::can_manage_settings() ) );
		}
	}

	public function test_writes_need_edit_products_and_category_writes_manage_product_terms(): void {
		self::grant( [ 'manage_woocommerce' ] );
		$this->assertSame( 200, self::http_status( Access::can_read() ) );
		$refused = Access::can_write();
		$this->assertSame( 403, self::http_status( $refused ) );
		$this->assertSame( 'bcm_forbidden', $refused->get_error_code() );
		$this->assertStringContainsString( 'not change them', $refused->get_error_message() );
		$this->assertStringContainsString( 'categories', Access::can_manage_terms()->get_error_message() );

		self::grant( [ 'manage_woocommerce', 'edit_products' ] );
		$this->assertSame( 200, self::http_status( Access::can_write() ) );
		$this->assertSame( 403, self::http_status( Access::can_manage_terms() ) );
	}

	public function test_an_anonymous_visitor_gets_401_everywhere(): void {
		$GLOBALS['bcm_test']['logged_in'] = false;
		foreach ( [ Access::can_read(), Access::can_write(), Access::can_manage_terms(), Access::can_manage_settings() ] as $result ) {
			$this->assertSame( 401, self::http_status( $result ) );
			$this->assertSame( 'bcm_forbidden', $result->get_error_code() );
			$this->assertStringContainsString( 'log in', $result->get_error_message() );
		}
	}

	public function test_the_filter_moves_the_read_capability_for_writes_too(): void {
		add_filter( 'bcm_capability', static fn( string $capability, bool $administrators_only ) => $administrators_only ? $capability : 'edit_products', 10, 2 );
		self::grant( [ 'edit_products' ] );
		$this->assertSame( [ 'edit_products' ], Access::write_capabilities(), 'The pair collapses when the filter picks edit_products.' );
		$this->assertSame( 200, self::http_status( Access::can_read() ) );
		$this->assertSame( 200, self::http_status( Access::can_write() ) );

		self::administrators_only( true );
		$this->assertSame( 403, self::http_status( Access::can_write() ), 'The filter saw the setting and kept manage_options.' );
		$this->assertSame( 403, self::http_status( Access::can_manage_settings() ), 'The filter never opens settings writes.' );
	}

	public function test_each_object_is_checked_with_edit_post(): void {
		self::grant( [ 'manage_woocommerce', 'edit_products' ] );
		$GLOBALS['bcm_test']['caps']['edit_post:10'] = true;
		$GLOBALS['bcm_test']['caps']['edit_post:11'] = false;
		$this->assertTrue( Access::can_edit_object( 10 ) );
		$refused = Access::can_edit_object( 11 );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bcm_cannot_edit', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertStringContainsString( 'skipped', $refused->get_error_message() );
	}

	public function test_a_background_chunk_checks_the_batch_user_not_the_current_one(): void {
		self::grant( [ 'edit_post' ] );
		$GLOBALS['bcm_test']['user_caps'][4] = [ 'edit_post:10' => true ];
		$this->assertTrue( Access::user_can_edit_object( 4, 10 ) );
		$this->assertSame( 'bcm_cannot_edit', Access::user_can_edit_object( 4, 12 )->get_error_code() );
		$this->assertSame( 'bcm_cannot_edit', Access::user_can_edit_object( 5, 10 )->get_error_code(), 'Another user gets no rights from the current one.' );
		$this->assertSame( 'bcm_cannot_edit', Access::user_can_edit_object( 0, 10 )->get_error_code(), 'No recorded user, no write.' );
	}
}
