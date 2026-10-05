<?php
/**
 * Personal display preferences (ticket 19): each user's visible columns and
 * their order, page size and density, stored per site for the signed-in
 * user only, separate from named saved views.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\DisplayPreferencesController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/user-option-stubs.php';

final class DisplayPreferencesRouteTest extends TestCase {
	private const A = 7;
	private const B = 8;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['caps']      = [ 'manage_woocommerce' => true ];
		self::as_user( self::A );
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	private static function as_user( int $id ): void {
		$GLOBALS['bulkcat_test']['user_id'] = $id;
	}

	/**
	 * Dispatch through the stub server.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function call( string $method, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bulkcat/v1/display-preferences' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private static function http_status( \WP_REST_Response|\WP_Error $result ): int {
		return $result instanceof \WP_REST_Response ? $result->get_status() : (int) $result->get_error_data()['status'];
	}

	private const DISPLAY = [
		'fields'  => [ 'sku', 'stock_status' ],
		'perPage' => 50,
		'density' => 'compact',
	];

	public function test_display_is_personal_per_site_and_leaves_named_views_alone(): void {
		$GLOBALS['bulkcat_test']['user_meta'][ self::A ]['bulkcat_views'] = '{"version":1,"default":null,"views":[]}';
		$this->assertNull( self::call( 'GET' )->get_data()['display'] );
		$put = self::call( 'PUT', [ 'display' => self::DISPLAY ] );
		$this->assertSame( 200, self::http_status( $put ) );
		$this->assertSame( self::DISPLAY, (array) $put->get_data()['display'] );
		$this->assertSame( '{"version":1,"default":null,"views":[]}', $GLOBALS['bulkcat_test']['user_meta'][ self::A ]['bulkcat_views'], 'named views untouched' );
		$this->assertArrayHasKey( 'wp_' . DisplayPreferencesController::OPTION, $GLOBALS['bulkcat_test']['user_meta'][ self::A ], 'stored per site' );
		$this->assertSame( self::DISPLAY, (array) self::call( 'GET' )->get_data()['display'] );
		self::as_user( self::B );
		$this->assertNull( self::call( 'GET' )->get_data()['display'], 'another user never sees it' );
		self::call( 'PUT', [ 'display' => [ 'perPage' => 100 ] ] );
		self::as_user( self::A );
		$this->assertSame( self::DISPLAY, (array) self::call( 'GET' )->get_data()['display'], 'and cannot change it' );
	}

	public function test_partial_and_empty_documents_are_kept_as_given(): void {
		$put = self::call( 'PUT', [ 'display' => [ 'fields' => [] ] ] );
		$this->assertSame( 200, self::http_status( $put ) );
		$this->assertSame( [ 'fields' => [] ], (array) self::call( 'GET' )->get_data()['display'] );
	}

	public function test_users_without_access_are_refused(): void {
		$GLOBALS['bulkcat_test']['caps'] = [];
		$this->assertSame( 403, self::http_status( self::call( 'GET' ) ) );
		$this->assertSame( 403, self::http_status( self::call( 'PUT', [ 'display' => self::DISPLAY ] ) ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_displays(): array {
		return [
			'not an object'     => [ 'compact' ],
			'list'              => [ [ 'sku' ] ],
			'unknown key'       => [ [ 'search' => 'shirt' ] ],
			'page size 26'      => [ [ 'perPage' => 26 ] ],
			'page size string'  => [ [ 'perPage' => '50' ] ],
			'density'           => [ [ 'density' => 'tiny' ] ],
			'duplicate column'  => [ [ 'fields' => [ 'sku', 'sku' ] ] ],
			'bad column id'     => [ [ 'fields' => [ 'sku; drop' ] ] ],
			'column not string' => [ [ 'fields' => [ 3 ] ] ],
			'too many columns'  => [ [ 'fields' => array_map( static fn( int $n ): string => 'f' . $n, range( 1, 101 ) ) ] ],
		];
	}

	/**
	 * @dataProvider bad_displays
	 * @param mixed $display A malformed display.
	 */
	public function test_malformed_displays_are_refused_and_nothing_is_stored( mixed $display ): void {
		$result = self::call( 'PUT', [ 'display' => $display ] );
		$this->assertSame( 400, self::http_status( $result ) );
		$this->assertNull( self::call( 'GET' )->get_data()['display'] );
	}

	public function test_a_user_id_is_never_accepted(): void {
		$result = self::call(
			'PUT',
			[
				'display' => self::DISPLAY,
				'user_id' => self::B,
			]
		);
		$this->assertSame( 400, self::http_status( $result ) );
		self::as_user( self::B );
		$this->assertNull( self::call( 'GET' )->get_data()['display'] );
	}

	public function test_a_failed_write_is_not_reported_as_saved(): void {
		$GLOBALS['bulkcat_test']['user_option_fails'] = true;
		$this->assertSame( 503, self::http_status( self::call( 'PUT', [ 'display' => self::DISPLAY ] ) ) );
	}

	public function test_corrupt_stored_data_reads_as_none(): void {
		$GLOBALS['bulkcat_test']['user_meta'][ self::A ][ 'wp_' . DisplayPreferencesController::OPTION ] = '{"version":9,"display":{"perPage":50}}';
		$this->assertNull( self::call( 'GET' )->get_data()['display'] );
		$GLOBALS['bulkcat_test']['user_meta'][ self::A ][ 'wp_' . DisplayPreferencesController::OPTION ] = '{"version":1,"display":{"perPage":13}}';
		$this->assertNull( self::call( 'GET' )->get_data()['display'] );
	}
}
