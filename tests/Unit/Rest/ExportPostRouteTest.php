<?php
/**
 * `POST /bulkcat/v1/export` (T101, SPEC §10, §12.1): registered beside the
 * GET behind the read permission with RouteSchema::export_args(); every
 * refusal is JSON before any CSV byte (anonymous, under-privileged, token,
 * whole catalogue, too many ids, a token in the address); a filter streams
 * what the GET streams for the same view; a selection or ids stream their
 * present members named `selected`; the identifier switch puts the block
 * first with "(for reference)" headings; the summary answers the counts and
 * the file name without streaming; the separator setting applies; serve()
 * lifts the time limit before the first byte; nothing is written.
 *
 * Pending T96 (ticket 04's T93 first): the byte comparison against the
 * recorded golden files, test_a_filter_post_with_the_switch_off_streams_the_recorded_bytes.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Rest\RouteSchema;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotService;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CountingStream.php';

final class ExportPostRouteTest extends TestCase {
	/**
	 * Calls the fake filter source received: [ params, page, per_page ].
	 *
	 * @var list<array{0: array<string, mixed>, 1: int, 2: int}>
	 */
	private array $calls = [];

	/**
	 * Tokens the selection resolver was asked for.
	 *
	 * @var list<string>
	 */
	private array $resolved = [];

	/**
	 * The controller under test.
	 *
	 * @var ExportController
	 */
	private ExportController $controller;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-23 10:00:00';
		$this->calls    = [];
		$this->resolved = [];
		$db             = SeededCatalogue::seed();
		// The guarded counts read _sku from postmeta: the seeded meta, as rows.
		$meta = [];
		foreach ( $GLOBALS['bulkcat_test']['meta'] as $id => $values ) {
			foreach ( $values as $key => $value ) {
				$meta[] = [
					'meta_id'    => count( $meta ) + 1,
					'post_id'    => $id,
					'meta_key'   => $key,
					'meta_value' => $value,
				];
			}
		}
		$db->tables['wp_postmeta'] = $meta;
		$this->controller          = new ExportController( new Registry(), $this->source( 250 ), null, null, null, $this->resolver() );
		( new Routes( [ $this->controller ] ) )->register();
	}

	/**
	 * A filter source of $total rows, recording each call.
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
						'id'        => $id,
						'parent_id' => 0,
						'name'      => 1 === $id ? '=cmd|x' : "Product $id; \"quoted\"",
						'sku'       => "SKU-$id",
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
	 * The selection resolver: a few named tokens, recording each ask.
	 */
	private function resolver(): \Closure {
		return function ( string $token, int $owner ): Selection|\WP_Error {
			unset( $owner );
			$this->resolved[] = $token;
			return match ( $token ) {
				'v2.ok'     => Selection::of_members(
					[
						'products'   => [ 103, 101, 108 ],
						'variations' => [ 202 ],
					]
				),
				'v2.lapsed' => Refusal::create( 'bulkcat_selection_renew_required', 'Your selected products are still saved. Restore your selection, then preview the change again.', 409 ),
				'v2.gone'   => SnapshotService::gone(),
				default     => SnapshotToken::invalid(),
			};
		};
	}

	/**
	 * Dispatch a request to /bulkcat/v1/export.
	 *
	 * @param string                    $method GET or POST.
	 * @param array<string, mixed>      $body   Form body (POST) or query (GET).
	 * @param array<string, mixed>      $query  Query string (POST).
	 * @param array<string, mixed>|null $json   A JSON body instead of the form.
	 */
	private static function send( string $method, array $body, array $query = [], ?array $json = null ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bulkcat/v1/export' );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body_params( $body );
			$request->set_query_params( $query );
		}
		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $json ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Serve a response into memory: the bytes and the headers sent.
	 *
	 * @param \WP_REST_Response|\WP_Error $response Response.
	 * @return array{0: string, 1: array<string, string>, 2: bool} Bytes, headers, whether served as CSV.
	 */
	private function serve( \WP_REST_Response|\WP_Error $response ): array {
		$server = new class() {
			/**
			 * Headers sent.
			 *
			 * @var array<string, string>
			 */
			public array $headers = [];

			public function send_header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
		$stream = fopen( 'php://memory', 'w+b' );
		$served = $this->controller->serve( false, $response, null, $server, $stream );
		rewind( $stream );
		return [ (string) stream_get_contents( $stream ), $server->headers, true === $served ];
	}

	/**
	 * The CSV's lines, without the byte-order mark or the last empty line.
	 *
	 * @param string $csv Bytes.
	 * @return list<string>
	 */
	private static function lines( string $csv ): array {
		return array_slice( explode( CsvWriter::EOL, substr( $csv, strlen( CsvWriter::BOM ) ) ), 0, -1 );
	}

	/**
	 * Assert a JSON refusal that nothing streams.
	 *
	 * @param mixed  $result Result.
	 * @param string $code   Code.
	 * @param int    $status Status.
	 */
	private function refused( mixed $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $result->get_error_message() );
		[ $bytes, , $served ] = $this->serve( $result );
		$this->assertFalse( $served, 'serve() leaves a refusal to core, which sends it as JSON.' );
		$this->assertSame( '', $bytes, 'No CSV byte.' );
	}

	public function test_post_registers_behind_the_read_permission(): void {
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/export'];
		$this->assertSame( 'GET', $route[0]['methods'], 'The GET stays for history exports (and the catalogue until the dialog ships).' );
		$this->assertSame( 'POST', $route[1]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $route[1]['permission_callback'] );
		$this->assertSame( [ $this->controller, 'create_item' ], $route[1]['callback'] );
		$this->assertSame( array_keys( ( new RouteSchema( new Registry() ) )->export_args() ), array_keys( $route[1]['args'] ) );
		$this->assertTrue( $route[1]['args']['columns']['required'] );
		$this->assertSame( ( new Registry() )->keys(), $route[1]['args']['columns']['items']['enum'], 'Only registry columns are posted.' );
		foreach ( [ 'page', 'per_page', 'fields' ] as $absent ) {
			$this->assertArrayNotHasKey( $absent, $route[1]['args'] );
		}
	}

	public function test_anonymous_and_under_privileged_posts_are_refused_before_any_row_is_read(): void {
		$bodies = [
			[
				'columns' => 'name',
				'search'  => 'x',
			],
			[
				'columns' => 'name',
				'ids'     => '101',
			],
			[
				'columns'   => 'name',
				'selection' => 'v2.ok',
			],
		];
		foreach ( $bodies as $body ) {
			$GLOBALS['bulkcat_test']['logged_in'] = false;
			$GLOBALS['bulkcat_test']['caps']      = [];
			$result                               = self::send( 'POST', $body );
			$this->assertSame( 'bulkcat_forbidden', $result->get_error_code() );
			$this->assertSame( 401, $result->get_error_data()['status'] );
			$GLOBALS['bulkcat_test']['logged_in'] = true;
			$result                               = self::send( 'POST', $body );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( [], $this->calls );
		$this->assertSame( [], $this->resolved );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'parent_id' ) ) );
	}

	public function test_a_filter_post_with_the_switch_off_streams_what_the_get_streams(): void {
		$view                    = [
			'columns'      => 'sku,name',
			'search'       => 'shoe',
			'orderby'      => 'sku',
			'order'        => 'desc',
			'stock_status' => [
				'operator' => 'isAny',
				'value'    => [ 'instock' ],
			],
		];
		[ $get, $get_headers ]   = $this->serve( self::send( 'GET', $view ) );
		$get_params              = $this->calls[0][0];
		$this->calls             = [];
		[ $post, $post_headers ] = $this->serve( self::send( 'POST', $view ) );
		$this->assertSame( $get, $post, 'Byte for byte what the GET export writes for the same view.' );
		$this->assertSame( 'attachment; filename="catalogue-export-2026-09-23.csv"', $get_headers['Content-Disposition'] );
		$this->assertSame( 'attachment; filename="catalogue-filtered-2026-09-23-1000.csv"', $post_headers['Content-Disposition'], 'Only the file name differs.' );
		$this->assertSame( $get_params, $this->calls[0][0], 'The same read arguments reach the /products reader.' );
		$this->assertCount( 251, self::lines( $post ) );
	}

	public function test_an_empty_filter_without_confirm_all_is_refused_and_no_csv_byte_is_written(): void {
		foreach ( [ [], [ 'search' => '  ' ], [ 'mode' => 'variations' ], [ 'confirm_all' => '0' ] ] as $params ) {
			$this->refused( self::send( 'POST', [ 'columns' => 'name' ] + $params ), 'bulkcat_export_confirm_all', 400 );
		}
		$this->assertSame( [], $this->calls );
	}

	public function test_a_confirmed_whole_catalogue_streams_every_row_named_all(): void {
		[ $csv, $headers, $served ] = $this->serve(
			self::send(
				'POST',
				[
					'columns'     => 'name',
					'confirm_all' => '1',
				]
			)
		);
		$this->assertTrue( $served );
		$this->assertSame( 'attachment; filename="catalogue-all-2026-09-23-1000.csv"', $headers['Content-Disposition'] );
		$this->assertCount( 251, self::lines( $csv ), 'The header and all 250 rows.' );
		$this->assertSame( [ 1, 2, 3 ], array_column( $this->calls, 1 ) );
	}

	public function test_a_selection_streams_its_members_named_selected(): void {
		[ $csv, $headers ] = $this->serve(
			self::send(
				'POST',
				[
					'columns'   => 'id,name',
					'selection' => 'v2.ok',
					'search'    => 'Shirt',
				]
			)
		);
		$this->assertSame( [ 'v2.ok' ], $this->resolved );
		$this->assertSame( 'attachment; filename="catalogue-selected-2026-09-23-1000.csv"', $headers['Content-Disposition'] );
		$this->assertSame( [ '"ID","Name"', '"101","Blue Shirt"', '"103","Rain Jacket"', '"202","Rain Jacket - Dark Green"' ], self::lines( $csv ), 'Members in ascending order; the binned 108 left out; the search ignored.' );
		$this->assertSame( [], $this->calls, 'The filter reader is never asked.' );
	}

	public function test_the_switch_on_streams_the_identifier_block_and_for_reference_headings(): void {
		[ $csv, $headers ] = $this->serve(
			self::send(
				'POST',
				[
					'columns'     => 'name,category_ids,regular_price,sku',
					'ids'         => '202,101',
					'identifiers' => '1',
				]
			)
		);
		$this->assertSame( 'attachment; filename="catalogue-selected-with-ids-2026-09-23-1000.csv"', $headers['Content-Disposition'] );
		$this->assertSame(
			[
				'ID,"SKU","Parent","Type","Name","Categories (for reference)","Regular price"',
				'"101","SHIRT-BLUE","","simple","Blue Shirt","11","20"',
				'"202","JACKET-DG","JACKET","variation","Rain Jacket - Dark Green","","80"',
			],
			self::lines( $csv )
		);
	}

	public function test_token_refusals_arrive_as_json_before_any_csv_byte(): void {
		$this->refused(
			self::send(
				'POST',
				[
					'columns'   => 'name',
					'selection' => 'v2.someone-else',
				]
			),
			'bulkcat_invalid_selection',
			403
		);
		$this->refused(
			self::send(
				'POST',
				[
					'columns'   => 'name',
					'selection' => 'v2.lapsed',
				]
			),
			'bulkcat_selection_renew_required',
			409
		);
		$this->refused(
			self::send(
				'POST',
				[
					'columns'   => 'name',
					'selection' => 'v2.gone',
				]
			),
			'bulkcat_selection_expired',
			410
		);
		$this->refused(
			self::send(
				'POST',
				[
					'columns'   => 'name',
					'selection' => 'abc.def',
				]
			),
			'bulkcat_invalid_selection',
			403
		);
		$this->assertSame( [ 'v2.someone-else', 'v2.lapsed', 'v2.gone' ], $this->resolved, 'A 1.0 token is never resolved.' );
		$this->assertSame( [], $this->calls );
	}

	public function test_a_catalogue_read_failure_before_streaming_is_503_export_unavailable(): void {
		// Main Build L2: a presence failure after the selection resolved is the export's, not selection storage's.
		global $wpdb;
		$page                          = $wpdb->responses['/^SELECT /'];
		$wpdb->responses['/^SELECT /'] = static function ( string $sql ) use ( $page ): mixed {
			if ( str_contains( $sql, 'p.post_parent AS parent_id' ) && str_contains( $sql, 'p.ID IN (' ) ) {
				$GLOBALS['wpdb']->last_error = 'Lost connection to the catalogue.';
				return [];
			}
			return $page( $sql );
		};
		foreach ( [ [ 'selection' => 'v2.ok' ], [ 'ids' => '101,202' ] ] as $rows ) {
			$GLOBALS['wpdb']->last_error = '';
			$this->refused( self::send( 'POST', [ 'columns' => 'name' ] + $rows ), 'bulkcat_export_unavailable', 503 );
		}
		$this->assertCount( 2, $GLOBALS['bulkcat_test']['logged'] ?? [], 'Each logged once, by the controller.' );
	}

	public function test_more_than_1000_ids_or_ids_with_a_selection_are_refused(): void {
		$this->refused(
			self::send(
				'POST',
				[
					'columns' => 'name',
					'ids'     => implode( ',', range( 1, 1001 ) ),
				]
			),
			'bulkcat_invalid_value',
			400
		);
		$this->refused(
			self::send(
				'POST',
				[],
				[],
				[
					'columns'   => [ 'name' ],
					'ids'       => [ 101 ],
					'selection' => 'v2.ok',
				]
			),
			'bulkcat_invalid_value',
			400
		);
		$this->assertSame( [], $this->resolved );
	}

	public function test_a_selection_in_the_query_string_is_refused(): void {
		$this->refused(
			self::send(
				'POST',
				[
					'columns' => 'name',
					'search'  => 'x',
				],
				[ 'selection' => 'v2.ok' ]
			),
			'bulkcat_invalid_value',
			400
		);
		$this->assertSame( [], $this->resolved );
		$this->assertSame( [], $this->calls );
	}

	public function test_summary_answers_rows_left_out_guarded_counts_whole_catalogue_and_filename_without_streaming(): void {
		$summary = function ( array $body, ?array $json = null ): array {
			$response = null === $json ? self::send( 'POST', $body + [ 'summary' => '1' ] ) : self::send( 'POST', [], [], $json + [ 'summary' => true ] );
			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$this->assertFalse( $response->get_data() instanceof CsvExport, 'A summary is data, not a file.' );
			[ $bytes, , $served ] = $this->serve( $response );
			$this->assertFalse( $served, 'Answered as JSON by core; nothing streams.' );
			$this->assertSame( '', $bytes );
			return $response->get_data();
		};
		$this->assertSame(
			[
				'rows'                => 2,
				'left_out'            => 0,
				'guarded_identifiers' => 0,
				'guarded_names'       => 0,
				'whole_catalogue'     => false,
				'filename'            => 'catalogue-filtered-2026-09-23-1000.csv',
			],
			$summary(
				[
					'columns' => 'name',
					'search'  => 'Shirt',
				]
			),
			'The grid\'s own count for the filter.'
		);
		$this->assertSame(
			[
				'rows'                => 7,
				'left_out'            => 0,
				'guarded_identifiers' => 0,
				'guarded_names'       => 0,
				'whole_catalogue'     => true,
				'filename'            => 'catalogue-all-2026-09-23-1000.csv',
			],
			$summary( [ 'columns' => 'name' ] ),
			'No confirmation is needed to ask; the dialog is told it is the whole catalogue.'
		);
		$this->assertSame(
			[
				'rows'                => 3,
				'left_out'            => 1,
				'guarded_identifiers' => 0,
				'guarded_names'       => 0,
				'whole_catalogue'     => false,
				'filename'            => 'catalogue-selected-with-ids-2026-09-23-1000.csv',
			],
			$summary(
				[],
				[
					'columns'     => [ 'name' ],
					'selection'   => 'v2.ok',
					'identifiers' => true,
				]
			)
		);
		// Guarded values, counted only with the switch on and only over the rows written.
		$GLOBALS['bulkcat_test']['meta'][101]['_sku']        = '-SHIRT';
		$GLOBALS['bulkcat_test']['posts'][104]['post_title'] = '@home Tote';
		$db                        = SeededCatalogue::seed();
		$db->tables['wp_postmeta'] = [
			[
				'meta_id'    => 1,
				'post_id'    => 101,
				'meta_key'   => '_sku',
				'meta_value' => '-SHIRT',
			],
		];
		foreach ( $db->tables['wp_posts'] as $index => $post ) {
			if ( 104 === $post['ID'] ) {
				$db->tables['wp_posts'][ $index ]['post_title'] = '@home Tote';
			}
		}
		$on = $summary(
			[
				'columns'     => 'name',
				'ids'         => '101,104,108',
				'identifiers' => '1',
			]
		);
		$this->assertSame( [ 2, 1, 1, 1 ], [ $on['rows'], $on['left_out'], $on['guarded_identifiers'], $on['guarded_names'] ] );
		$off = $summary(
			[
				'columns' => 'name',
				'ids'     => '101,104,108',
			]
		);
		$this->assertSame( [ 0, 0 ], [ $off['guarded_identifiers'], $off['guarded_names'] ] );
		$filter = $summary(
			[
				'columns'     => 'name',
				'confirm_all' => '1',
				'identifiers' => '1',
			]
		);
		$this->assertSame( [ 1, 1 ], [ $filter['guarded_identifiers'], $filter['guarded_names'] ] );
		$nameless = $summary(
			[
				'columns'     => 'regular_price',
				'ids'         => '101,104,108',
				'identifiers' => '1',
			]
		);
		$this->assertSame( [ 1, 0 ], [ $nameless['guarded_identifiers'], $nameless['guarded_names'] ], 'Without the Name column no name is written, so none is counted; the SKU always is.' );
		$this->assertSame( [], $this->calls, 'A summary never reads a page of rows.' );
	}

	public function test_the_semicolon_setting_applies_to_post_exports(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'csv_separator' => 'semicolon' ];
		[ $csv ] = $this->serve(
			self::send(
				'POST',
				[
					'columns'     => 'id,name',
					'ids'         => '101,103',
					'identifiers' => '1',
				]
			)
		);
		$this->assertSame( [ 'ID;"SKU";"Parent";"Type";"Name"', '"101";"SHIRT-BLUE";"";"simple";"Blue Shirt"', '"103";"JACKET";"";"variable";"Rain Jacket"' ], self::lines( $csv ) );
	}

	public function test_serve_raises_the_time_limit_before_streaming(): void {
		$this->assertTrue( stream_wrapper_register( 'bulkcat-count', CountingStream::class ) );
		try {
			$GLOBALS['bulkcat_test']['time_limit_bytes'] = 0;
			CountingStream::$all                         = '';
			$stream                                      = fopen( 'bulkcat-count://body', 'wb' );
			$this->assertTrue(
				$this->controller->serve(
					false,
					self::send(
						'POST',
						[
							'columns' => 'name',
							'ids'     => '101',
						]
					),
					null,
					null,
					$stream
				)
			);
			$this->assertSame( [ [ 0, 0 ] ], $GLOBALS['bulkcat_test']['time_limits'], 'wc_set_time_limit( 0 ) was asked before the first byte.' );
			$this->assertStringStartsWith( CsvWriter::BOM, CountingStream::$all );
		} finally {
			stream_wrapper_unregister( 'bulkcat-count' );
		}
	}

	public function test_a_failure_while_streaming_stops_the_file_and_is_logged(): void {
		// Main Build L1: an error escaping serve() would let WordPress append its critical-error page to a cut-off CSV.
		foreach ( [ new \RuntimeException( 'The database went away.' ), new \TypeError( 'A page was not a list.' ) ] as $failure ) {
			bulkcat_test_reset();
			$GLOBALS['bulkcat_test']['logged_in']                  = true;
			$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
			$source           = new CallableRowSource(
				static function ( array $params, int $page, int $per_page ) use ( $failure ): array {
					if ( 2 === $page ) {
						throw $failure;
					}
					$items = [];
					for ( $id = 1; $id <= $per_page; ++$id ) {
						$items[] = [
							'id'   => $id,
							'name' => "Product $id",
						];
					}
					return [
						'items' => $items,
						'total' => 250,
					];
				}
			);
			$this->controller = new ExportController( new Registry(), $source );
			( new Routes( [ $this->controller ] ) )->register();
			$response           = self::send(
				'POST',
				[
					'columns' => 'id,name',
					'search'  => 'Product',
				]
			);
			[ $csv, , $served ] = $this->serve( $response );
			$this->assertTrue( $served, 'Served: nothing reaches WordPress\'s fatal-error handler.' );
			$lines = self::lines( $csv );
			$this->assertCount( 101, $lines, 'The header and the first page, then nothing: ' . get_class( $failure ) );
			$this->assertStringEndsWith( CsvWriter::EOL, $csv, 'The file stops at the end of a line.' );
			$logged = $GLOBALS['bulkcat_test']['logged'] ?? [];
			$this->assertCount( 1, $logged );
			$this->assertSame( 'error', $logged[0][0] );
			$this->assertSame( [ 'source' => 'bulk-catalogue-manager-for-woocommerce' ], $logged[0][2] );
			$this->assertStringContainsString( get_class( $failure ), $logged[0][1] );
			$this->assertStringContainsString( $failure->getMessage(), $logged[0][1] );
		}
	}

	public function test_an_export_writes_no_grant_receipt_selection_option_product_or_term(): void {
		$options = $GLOBALS['bulkcat_test']['options'];
		$bodies  = [
			[
				'columns' => 'name',
				'search'  => 'Shirt',
			],
			[
				'columns'     => 'name',
				'confirm_all' => '1',
				'identifiers' => '1',
			],
			[
				'columns' => 'name',
				'ids'     => '101,202',
			],
			[
				'columns'     => 'name,sku',
				'ids'         => '101,202,108',
				'identifiers' => '1',
			],
			[
				'columns'   => 'name',
				'selection' => 'v2.ok',
			],
			[
				'columns'     => 'name',
				'selection'   => 'v2.ok',
				'identifiers' => '1',
			],
		];
		foreach ( $bodies as $body ) {
			$this->serve( self::send( 'POST', $body ) );
			self::send( 'POST', $body + [ 'summary' => '1' ] );
		}
		$this->assertSame( [], $GLOBALS['wpdb']->writes );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|START TRANSACTION)\b/i', $sql ) ) );
		$this->assertSame( $options, $GLOBALS['bulkcat_test']['options'], 'No option.' );
		foreach ( array_keys( $GLOBALS['bulkcat_test']['transients'] ?? [] ) as $transient ) {
			$this->assertStringStartsWith( 'bulkcat_count_', $transient, 'Only the grid\'s own count cache.' );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['set_object_terms'] ?? [] );
		$this->assertSame( [ 'v2.ok', 'v2.ok', 'v2.ok', 'v2.ok' ], $this->resolved, 'Resolved to read, never renewed or released.' );
	}
}
