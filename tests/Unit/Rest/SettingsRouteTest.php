<?php
/**
 * `GET` and `PUT /bcm/v1/settings` (SPEC §11, §12.1, §13; T73): both
 * registered through Routes with their own permission callbacks — the read
 * with the screen capability, the write with `manage_options` alone — the
 * GET describing every §11 setting in plain words, and the PUT saving only
 * values Schema::validate() accepts, refusing the rest by setting name.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\SettingsController;
use BulkCatalogueManager\Settings\Schema;
use BulkCatalogueManager\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsRouteTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in'] = true;
	}

	/**
	 * Boot the plugin, fire rest_api_init and return the settings endpoints keyed by method.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function endpoints(): array {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$routes = $GLOBALS['bcm_test']['routes'];
		self::assertArrayHasKey( '/bcm/v1/settings', $routes );
		$out = [];
		foreach ( $routes['/bcm/v1/settings'] as $name => $endpoint ) {
			if ( is_int( $name ) ) {
				$out[ $endpoint['methods'] ] = $endpoint;
			}
		}
		return $out;
	}

	private static function grant( string ...$caps ): void {
		$GLOBALS['bcm_test']['caps'] = array_fill_keys( $caps, true );
	}

	private static function http_status( mixed $result ): int {
		if ( true === $result ) {
			return 200;
		}
		return $result instanceof \WP_REST_Response ? $result->get_status() : $result->get_error_data()['status'];
	}

	/**
	 * Run the PUT endpoint with a body of values.
	 *
	 * @param mixed $values The `values` body parameter.
	 */
	private static function put( mixed $values ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'PUT', '/bcm/v1/settings' );
		$request->set_param( 'values', $values );
		return call_user_func( self::endpoints()['PUT, POST']['callback'], $request );
	}

	public function test_both_endpoints_register_with_their_own_permission_callbacks(): void {
		$endpoints = self::endpoints();
		$this->assertSame( [ 'GET', 'PUT, POST' ], array_keys( $endpoints ) );
		$this->assertSame( [ Access::class, 'can_read' ], $endpoints['GET']['permission_callback'] );
		$this->assertSame( [ Access::class, 'can_manage_settings' ], $endpoints['PUT, POST']['permission_callback'] );
		foreach ( $endpoints as $endpoint ) {
			$this->assertTrue( is_callable( $endpoint['callback'] ) );
		}
		$this->assertTrue( is_callable( $GLOBALS['bcm_test']['routes']['/bcm/v1/settings']['schema'] ) );
		$this->assertSame( [], $GLOBALS['bcm_test']['wrong'], 'The registrar refused nothing.' );
	}

	public function test_a_shop_manager_reads_but_may_not_write(): void {
		self::grant( 'manage_woocommerce', 'edit_products' );
		$endpoints = self::endpoints();
		$request   = new \WP_REST_Request( 'PUT', '/bcm/v1/settings' );
		$this->assertSame( 200, self::http_status( call_user_func( $endpoints['GET']['permission_callback'], $request ) ) );
		$refused = call_user_func( $endpoints['PUT, POST']['permission_callback'], $request );
		$this->assertSame( 403, self::http_status( $refused ) );
		$this->assertSame( 'bcm_forbidden', $refused->get_error_code() );
		$this->assertStringContainsString( 'Only an administrator can change', $refused->get_error_message() );

		$answer = call_user_func( $endpoints['GET']['callback'], $request );
		$this->assertFalse( $answer->get_data()['canEdit'], 'The tab renders read-only for a shop manager.' );
	}

	public function test_under_administrators_only_a_shop_manager_is_refused_both(): void {
		$GLOBALS['bcm_test']['options'][ Settings::OPTION ] = [ Schema::ADMINISTRATORS_ONLY => true ];
		self::grant( 'manage_woocommerce' );
		$endpoints = self::endpoints();
		$this->assertSame( 403, self::http_status( call_user_func( $endpoints['GET']['permission_callback'], new \WP_REST_Request() ) ) );
		$this->assertSame( 403, self::http_status( call_user_func( $endpoints['PUT, POST']['permission_callback'], new \WP_REST_Request() ) ) );

		self::grant( 'manage_options' );
		$this->assertSame( 200, self::http_status( call_user_func( $endpoints['GET']['permission_callback'], new \WP_REST_Request() ) ) );
		$this->assertSame( 200, self::http_status( call_user_func( $endpoints['PUT, POST']['permission_callback'], new \WP_REST_Request() ) ) );
	}

	public function test_an_anonymous_visitor_gets_401_on_both(): void {
		$GLOBALS['bcm_test']['logged_in'] = false;
		foreach ( self::endpoints() as $endpoint ) {
			$this->assertSame( 401, self::http_status( call_user_func( $endpoint['permission_callback'], new \WP_REST_Request() ) ) );
		}
	}

	public function test_get_describes_every_setting_in_plain_words(): void {
		self::grant( 'manage_options', 'manage_woocommerce' );
		$GLOBALS['bcm_test']['options'][ Settings::OPTION ] = [ Schema::ROWS_PER_PAGE => 50 ];
		$answer = call_user_func( self::endpoints()['GET']['callback'], new \WP_REST_Request() );
		$this->assertSame( 200, $answer->get_status() );
		$data = $answer->get_data();

		$this->assertSame( [ 'values', 'settings', 'groups', 'canEdit' ], array_keys( $data ) );
		$this->assertTrue( $data['canEdit'] );
		$this->assertSame( Schema::keys(), array_keys( $data['values'] ) );
		$this->assertSame( 50, $data['values'][ Schema::ROWS_PER_PAGE ] );
		$this->assertSame( 90, $data['values'][ Schema::HISTORY_DAYS ] );

		$keys   = array_column( $data['settings'], 'key' );
		$sorted = $keys;
		$schema = Schema::keys();
		sort( $sorted );
		sort( $schema );
		$this->assertSame( $schema, $sorted, 'Every §11 setting is described once.' );
		$this->assertSame( $keys, SettingsController::keys() );
		$groups = array_column( $data['groups'], 'id' );
		foreach ( $data['settings'] as $setting ) {
			$this->assertContains( $setting['group'], $groups );
			$this->assertNotSame( '', $setting['label'] );
			$this->assertNotSame( '', $setting['help'], $setting['key'] . ' has help text.' );
			$this->assertStringNotContainsString( '_', $setting['label'], 'No raw key shows as a label.' );
			$this->assertStringNotContainsString( $setting['key'], $setting['help'] );
			$this->assertSame( Schema::defaults()[ $setting['key'] ], $setting['default'] );
			$this->assertContains( $setting['type'], [ 'boolean', 'integer', 'choice' ] );
		}

		$by_key = array_column( $data['settings'], null, 'key' );
		$this->assertSame( 'Who can use this screen', $by_key[ Schema::ADMINISTRATORS_ONLY ]['label'] );
		$this->assertSame( [ 'Shop managers and administrators', 'Administrators only' ], array_column( $by_key[ Schema::ADMINISTRATORS_ONLY ]['choices'], 'label' ) );
		$this->assertSame( [ 25, 50, 100 ], array_column( $by_key[ Schema::ROWS_PER_PAGE ]['choices'], 'value' ) );
		$this->assertSame( [ 10, 100 ], [ $by_key[ Schema::BATCH_SIZE ]['min'], $by_key[ Schema::BATCH_SIZE ]['max'] ] );
		$this->assertSame( [ 7, 730 ], [ $by_key[ Schema::HISTORY_DAYS ]['min'], $by_key[ Schema::HISTORY_DAYS ]['max'] ] );
		$this->assertSame(
			[
				[
					'value' => 0,
					'label' => 'Indefinitely',
				],
			],
			$by_key[ Schema::HISTORY_DAYS ]['special']
		);
		$this->assertSame( 0, $by_key[ Schema::CONFIRM_THRESHOLD ]['min'] );
		$this->assertArrayNotHasKey( 'max', $by_key[ Schema::CONFIRM_THRESHOLD ] );
		$this->assertSame( [ 'comma', 'semicolon' ], array_column( $by_key[ Schema::CSV_SEPARATOR ]['choices'], 'value' ) );
		$this->assertSame( [ 'Comma', 'Semicolon' ], array_column( $by_key[ Schema::CSV_SEPARATOR ]['choices'], 'label' ) );
		$this->assertStringContainsString( 'cannot be brought back', $by_key[ Schema::REMOVE_DATA ]['warning'] );
	}

	public function test_put_saves_accepted_values_through_the_sanitiser_and_answers_them(): void {
		self::grant( 'manage_options' );
		$GLOBALS['bcm_test']['options'][ Settings::OPTION ] = [ Schema::CSV_SEPARATOR => 'semicolon' ];
		$answer = self::put(
			[
				Schema::ROWS_PER_PAGE     => '100',
				Schema::HISTORY_DAYS      => 0,
				Schema::CATEGORY_PANEL    => 'false',
				Schema::CONFIRM_THRESHOLD => 500,
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $answer );
		$this->assertSame( 200, $answer->get_status() );
		$values = $answer->get_data()['values'];
		$this->assertSame( 100, $values[ Schema::ROWS_PER_PAGE ], 'Sanitised to the stored type.' );
		$this->assertSame( 0, $values[ Schema::HISTORY_DAYS ] );
		$this->assertFalse( $values[ Schema::CATEGORY_PANEL ] );
		$this->assertSame( 'semicolon', $values[ Schema::CSV_SEPARATOR ], 'A setting not sent keeps its stored value.' );

		$stored = $GLOBALS['bcm_test']['options'][ Settings::OPTION ];
		$this->assertSame( Schema::keys(), array_keys( $stored ) );
		$this->assertSame( 500, Settings::load()->confirm_threshold() );
		$this->assertTrue( Settings::load()->keeps_history_indefinitely() );
	}

	public function test_put_refuses_by_setting_name_and_saves_nothing(): void {
		self::grant( 'manage_options' );
		$before = [ Schema::ROWS_PER_PAGE => 50 ];
		$GLOBALS['bcm_test']['options'][ Settings::OPTION ] = $before;

		$refused = self::put(
			[
				Schema::ROWS_PER_PAGE => 25,
				Schema::BATCH_SIZE    => 101,
				Schema::HISTORY_DAYS  => 6,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bcm_invalid_setting', $refused->get_error_code() );
		$data = $refused->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( [ Schema::BATCH_SIZE, Schema::HISTORY_DAYS ], array_keys( $data['errors'] ) );
		$this->assertSame( 'Products per background batch was not saved: enter a whole number from 10 to 100.', $data['errors'][ Schema::BATCH_SIZE ] );
		$this->assertSame( Schema::rejection( Schema::HISTORY_DAYS ), $data['errors'][ Schema::HISTORY_DAYS ] );
		$this->assertStringContainsString( 'Products per background batch', $refused->get_error_message() );
		$this->assertStringContainsString( 'Keep history for', $refused->get_error_message() );
		$this->assertStringNotContainsString( 'bcm_', $refused->get_error_message() );
		$this->assertSame( $before, $GLOBALS['bcm_test']['options'][ Settings::OPTION ], 'Nothing is saved, not even the valid value.' );

		$unknown = self::put( [ 'favourite_colour' => 'blue' ] );
		$this->assertSame( [ 'favourite_colour' ], array_keys( $unknown->get_error_data()['errors'] ) );
		$this->assertStringContainsString( 'is not a setting', $unknown->get_error_message() );

		$malformed = self::put( 'rows_per_page=100' );
		$this->assertSame( 'bcm_invalid_setting', $malformed->get_error_code() );
		$this->assertSame( [ Settings::OPTION ], array_keys( $malformed->get_error_data()['errors'] ) );
		$this->assertSame( $before, $GLOBALS['bcm_test']['options'][ Settings::OPTION ] );
	}

	public function test_the_administrators_only_setting_moves_the_screen_capability(): void {
		self::grant( 'manage_options' );
		self::put( [ Schema::ADMINISTRATORS_ONLY => true ] );
		$this->assertSame( 'manage_options', Access::capability() );
		self::grant( 'manage_woocommerce' );
		$this->assertSame( 403, self::http_status( Access::can_read() ) );
	}
}
