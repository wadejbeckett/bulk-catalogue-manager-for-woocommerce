<?php
/**
 * The catalogue export (SPEC §10, §12.1, §13). The GET route stays
 * registered behind the read permission with the `/products` arguments plus
 * `columns[]` for History's exports, but refuses the catalogue since the
 * export dialog (T103, ruling R9): a stale screen's GET gets a sentence
 * asking for a reload. The catalogue cases that were GET's now run through
 * `POST /export` with their assertions unchanged (ruling R4; `confirm_all`
 * added where the view has no filter, and the file name is the POST's):
 * anonymous and under-privileged requests refused before a row is read; an
 * unknown, repeated or missing column refused with 400; a CSV streamed
 * through `rest_pre_serve_request` with its attachment name, the separator
 * setting, the header in the requested order and every matching row read
 * 100 at a time; every other response passed through untouched.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Export\HistoryColumns;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\ReadController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Rest\RouteSchema;
use PHPUnit\Framework\TestCase;

final class ExportRouteTest extends TestCase {
	/**
	 * Calls the fake source received: [ params, page, per_page ].
	 *
	 * @var list<array{0: array<string, mixed>, 1: int, 2: int}>
	 */
	private array $calls = [];

	/**
	 * The controller under test, over the fake source.
	 *
	 * @var ExportController
	 */
	private ExportController $controller;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-23 10:00:00';
		$this->calls      = [];
		$this->controller = new ExportController( new Registry(), $this->source( 250 ) );
		( new Routes( [ $this->controller ] ) )->register();
	}

	/**
	 * A source of $total rows whose name carries a formula, recording each call.
	 *
	 * @param int $total Rows that match.
	 */
	private function source( int $total ): CallableRowSource {
		return new CallableRowSource(
			function ( array $params, int $page, int $per_page ) use ( $total ): array {
				$this->calls[] = [ $params, $page, $per_page ];
				$items         = [];
				$first         = ( $page - 1 ) * $per_page + 1;
				$last          = min( $total, $first + $per_page - 1 );
				for ( $id = $first; $id <= $last; ++$id ) {
					$items[] = [
						'id'   => $id,
						'name' => 1 === $id ? '=cmd|x' : "Product $id",
						'sku'  => "SKU-$id",
					];
				}
				return [
					'items' => $items,
					'total' => $total,
				];
			}
		);
	}

	/**
	 * Dispatch a GET to /bulkcat/v1/export.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private static function get( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/export' );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Dispatch a POST to /bulkcat/v1/export, as the export dialog's form posts it.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private static function post( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A REST server double recording the headers serve() sends.
	 */
	private static function server(): object {
		return new class() {
			/**
			 * Headers sent, by name.
			 *
			 * @var array<string, string>
			 */
			public array $headers = [];

			public function send_header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
	}

	/**
	 * Serve a response into memory; the bytes written and the headers sent.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return array{0: string, 1: array<string, string>}
	 */
	private function serve( \WP_REST_Response $response ): array {
		$server = self::server();
		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertTrue( $this->controller->serve( false, $response, null, $server, $stream ) );
		rewind( $stream );
		return [ (string) stream_get_contents( $stream ), $server->headers ];
	}

	public function test_the_route_registers_behind_the_read_permission_with_products_arguments_and_columns(): void {
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/export'] ?? null;
		$this->assertNotNull( $route );
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[0]['permission_callback'] );
		$query = array_keys( ( new RouteSchema( new Registry() ) )->query_args() );
		$this->assertSame( [ ...$query, 'columns', 'source', ...ExportController::HISTORY_ARGS ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['columns']['required'] );
		$this->assertSame( array_values( array_unique( [ ...( new Registry() )->keys(), ...HistoryColumns::all_keys() ] ) ), $route[0]['args']['columns']['items']['enum'], 'Field keys, then the history columns.' );
		$this->assertSame( 'products', $route[0]['args']['source']['default'] );
		$this->assertSame( [ 'products', 'changes', 'audit', 'batches' ], $route[0]['args']['source']['enum'] );
		$this->assertInstanceOf( ReadController::class, $route['schema'][0] );
		$this->assertSame( 'bulkcat-export-row', call_user_func( $route['schema'] )['title'] );
		$this->assertContains( [ [ $this->controller, 'serve' ], 10, 4 ], $GLOBALS['bulkcat_test']['filters']['rest_pre_serve_request'] );
	}

	public function test_a_catalogue_get_is_refused_with_a_reload_sentence(): void {
		// R9: the screen exports through the dialog's POST; a stale screen's GET is told to reload.
		foreach ( [
			[ 'columns' => [ 'name' ] ],
			[
				'columns' => [ 'name' ],
				'source'  => 'products',
				'search'  => 'shoe',
			],
			[
				'columns'     => [ 'id' ],
				'confirm_all' => '1',
			],
		] as $params ) {
			$result = self::get( $params );
			$this->assertInstanceOf( \WP_Error::class, $result, (string) wp_json_encode( $params ) );
			$this->assertSame( 'bulkcat_invalid_value', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
			$this->assertSame( 'The catalogue is now exported from the Export dialog. Reload the page and export again.', $result->get_error_message() );
			$this->assertFalse( $this->controller->serve( false, $result, null, self::server() ), 'Sent as JSON by core: no CSV byte.' );
		}
		$this->assertSame( [], $this->calls, 'No row is read.' );
		// History's exports stay on GET (HistoryExportTest covers them in full).
		$this->assertSame(
			'rest_invalid_param',
			self::get(
				[
					'columns' => [ 'no_such_column' ],
					'source'  => 'audit',
				]
			)->get_error_code()
		);
	}

	public function test_the_plugin_registers_the_route(): void {
		bulkcat_test_reset();
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->assertArrayHasKey( '/bulkcat/v1/export', $GLOBALS['bulkcat_test']['routes'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['wrong'] );
	}

	public function test_an_anonymous_visitor_and_a_user_without_the_capability_are_refused_before_any_row_is_read(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$result                               = self::post( [ 'columns' => [ 'name' ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$result                               = self::post( [ 'columns' => [ 'name' ] ] );
		$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$GLOBALS['bulkcat_test']['caps']                        = [ 'manage_woocommerce' => true ];
		$result = self::post( [ 'columns' => [ 'name' ] ] );
		$this->assertSame( 403, $result->get_error_data()['status'], 'A shop manager is refused under administrators only.' );
		$this->assertSame( [], $this->calls );
	}

	public function test_an_unknown_repeated_or_missing_column_is_refused_400(): void {
		$result = self::post( [ 'columns' => [ 'name', 'no_such_field' ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [ 'columns' ], array_keys( $result->get_error_data()['params'] ) );

		$result = self::post( [ 'columns' => '_regular_price' ] );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code(), 'A raw meta key is not a column.' );

		$result = self::post( [] );
		$this->assertSame( 'rest_missing_callback_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		foreach ( [ [ 'name', 'name' ], [] ] as $columns ) {
			$result = $this->controller->create_item( self::request( [ 'columns' => $columns ] ) );
			$this->assertInstanceOf( \WP_Error::class, $result, json_encode( $columns ) );
			$this->assertSame( 'bulkcat_invalid_value', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
			$this->assertSame( 'columns', $result->get_error_data()['param'] );
		}
		$this->assertSame( [], $this->calls );
	}

	public function test_a_malformed_filter_is_refused_400_before_any_row_is_read(): void {
		$result = self::post(
			[
				'columns'      => [ 'name' ],
				'stock_status' => [
					'operator' => 'like',
					'value'    => [ 'instock' ],
				],
			]
		);
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( [], $this->calls );
	}

	public function test_an_export_streams_every_matching_row_paged_by_100_with_the_csv_headers(): void {
		$response = self::post(
			[
				'columns'      => 'sku,name',
				'page'         => '3',
				'per_page'     => '25',
				'search'       => 'shoe',
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [ 'instock' ],
				],
			]
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertInstanceOf( CsvExport::class, $response->get_data() );
		$this->assertSame( [], $this->calls, 'Nothing is read until the body is served.' );

		[ $csv, $headers ] = $this->serve( $response );

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="catalogue-filtered-2026-09-23-1000.csv"', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( CsvWriter::BOM, $csv );

		$this->assertSame( [ 1, 2, 3 ], array_column( $this->calls, 1 ), 'ceil( 250 / 100 ) pages, not the requested page.' );
		$this->assertSame( [ 100, 100, 100 ], array_column( $this->calls, 2 ) );
		$params = $this->calls[0][0];
		$this->assertArrayNotHasKey( 'page', $params );
		$this->assertArrayNotHasKey( 'per_page', $params );
		$this->assertArrayNotHasKey( 'columns', $params );
		$this->assertSame( [ 'sku', 'name' ], $params['fields'], 'Rows carry just the exported columns.' );
		$this->assertSame( 'shoe', $params['search'] );
		$this->assertSame(
			[
				'operator' => 'isAny',
				'value'    => [ 'instock' ],
			],
			$params['stock_status']
		);

		$lines = explode( "\r\n", substr( $csv, strlen( CsvWriter::BOM ) ) );
		$this->assertSame( '', array_pop( $lines ) );
		$this->assertCount( 251, $lines );
		$registry = new Registry();
		$this->assertSame( '"' . $registry->get( 'sku' )->label() . '","' . $registry->get( 'name' )->label() . '"', $lines[0] );
		$this->assertSame( "\"SKU-1\",\"'=cmd|x\"", $lines[1], 'A name starting a formula comes back apostrophe-prefixed.' );
		$this->assertSame( '"SKU-250","Product 250"', $lines[250] );
	}

	public function test_the_separator_setting_switches_the_export_to_semicolons(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'csv_separator' => 'semicolon' ];
		[ $csv ] = $this->serve(
			self::post(
				[
					'columns'     => [ 'id', 'sku' ],
					'confirm_all' => '1',
				]
			)
		);
		$this->assertStringContainsString( "\r\n\"2\";\"SKU-2\"\r\n", $csv );
	}

	public function test_other_responses_pass_through_and_the_body_goes_to_the_output(): void {
		$json = new \WP_REST_Response( [ 'items' => [] ], 200 );
		$this->assertFalse( $this->controller->serve( false, $json, null, self::server() ) );
		$this->assertTrue( $this->controller->serve( true, $json, null, self::server() ) );
		$this->assertFalse( $this->controller->serve( false, new \WP_Error( 'x', 'y' ) ) );

		$export = self::post(
			[
				'columns'     => [ 'id' ],
				'confirm_all' => '1',
			]
		);
		$this->assertTrue( $this->controller->serve( true, $export, null, self::server() ), 'An already served request is left alone.' );
		$this->assertSame( [], $this->calls );

		ob_start();
		$served = $this->controller->serve( false, $export, null, self::server() );
		$output = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringStartsWith( CsvWriter::BOM . '"', $output );
		$this->assertSame( 251, substr_count( $output, "\r\n" ) );
	}

	public function test_the_default_source_counts_the_filter_first_and_reads_no_page_when_nothing_matches(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT ] = '0';
		$controller = new ExportController( new Registry() );
		( new Routes( [ $controller ] ) )->register();

		$response = self::post(
			[
				'columns'      => [ 'name' ],
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [ 'outofstock' ],
				],
			]
		);
		$stream   = fopen( 'php://memory', 'w+b' );
		$this->assertTrue( $controller->serve( false, $response, null, self::server(), $stream ) );
		rewind( $stream );
		$this->assertSame( CsvWriter::BOM . '"' . ( new Registry() )->get( 'name' )->label() . "\"\r\n", stream_get_contents( $stream ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertSame( 1, preg_match( \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT, $GLOBALS['wpdb']->queries[0] ) );
		$this->assertStringContainsString( "l.stock_status IN ('outofstock')", $GLOBALS['wpdb']->queries[0] );
	}

	public function test_a_parent_exports_one_product_s_variations_and_needs_the_variations_mode(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['wpdb']->responses[ \BulkCatalogueManager\Tests\Support\SqlEvaluator::COUNT ] = '0';
		$controller = new ExportController( new Registry() );
		( new Routes( [ $controller ] ) )->register();

		$refused = self::post(
			[
				'columns' => [ 'name' ],
				'parent'  => 103,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_invalid_value', $refused->get_error_code() );

		$response = self::post(
			[
				'columns' => [ 'name' ],
				'mode'    => 'variations',
				'parent'  => 103,
			]
		);
		$stream   = fopen( 'php://memory', 'w+b' );
		$this->assertTrue( $controller->serve( false, $response, null, self::server(), $stream ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'p.post_parent = 103', $GLOBALS['wpdb']->queries[0] );
	}

	/**
	 * A request with parameters, as the handler receives it after validation.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params( $params );
		return $request;
	}
}
