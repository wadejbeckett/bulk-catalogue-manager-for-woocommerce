<?php
/**
 * `GET /bulkcat/v1/export` for the history sources (T58; SPEC §4.6, §10): a
 * batch's changes (`source=changes&batch=ID`), the audit log (`source=audit`
 * with its filters) and the history list (`source=batches` with its
 * filters), through the one CsvExport and CsvWriter, read page by page from
 * the history routes' own row methods and stored nowhere.
 *
 * The integration case exports 5,000 seeded journal rows through the
 * dispatcher and the real repositories, answered by the SQL evaluator: an
 * event log shared by the `$wpdb` stub and a spying output stream shows the
 * first CSV bytes written before the last page of rows is read, the rows are
 * read 100 to a statement with one count, and memory measured at every write
 * stays flat. A product name beginning `=cmd` comes back apostrophe-prefixed,
 * and a visitor and an under-privileged user are refused before any row is
 * read.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Export\HistoryColumns;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SpyStream.php';

final class HistoryExportTest extends TestCase {
	/**
	 * Journal rows in the seed.
	 */
	private const ROWS = 5000;

	/**
	 * Seeded batch rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $batches = [];

	/**
	 * Seeded journal rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $changes = [];

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-23 10:00:00';
		$GLOBALS['bulkcat_test']['users']                      = [
			3 => 'Sam Shop',
			4 => '=Rosa',
		];
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		if ( ! in_array( 'bulkcatspy', stream_get_wrappers(), true ) ) {
			stream_wrapper_register( 'bulkcatspy', SpyStream::class );
		}
		SpyStream::$log  = [];
		SpyStream::$head = '';
		SpyStream::$all  = '';
		SpyStream::$keep = false;
		$this->seed();
	}

	/**
	 * Batch 1 with 5,000 journal rows on products 10001–15000, the first
	 * named `=cmd|' /C calc'!A0`; batch 2 by user 4 with two rows, one
	 * failed; batch 3 undone by batch 4.
	 */
	private function seed(): void {
		global $wpdb;
		$batch         = fn( int $id, string $status, int $user, array $extra = [] ): array => $extra + [
			'batch_id'        => $id,
			'uuid'            => sprintf( '00000000-0000-4000-8000-%012d', $id ),
			'user_id'         => $user,
			'created_gmt'     => sprintf( '2026-09-2%d 10:00:00', $id ),
			'finished_gmt'    => sprintf( '2026-09-2%d 10:05:00', $id ),
			'status'          => $status,
			'source'          => 'bulk',
			'undo_of'         => null,
			'operations'      => ValueCodec::encode(
				[
					[
						'action' => 'adjust',
						'args'   => [ 'field' => 'regular_price' ],
					],
				]
			),
			'selection'       => ValueCodec::encode( [ 'ids' => [] ] ),
			'total_items'     => 2,
			'previewed_items' => null,
			'done_items'      => 2,
			'changed_items'   => 2,
			'failed_items'    => 0,
			'chunk_size'      => 50,
			'note'            => '',
			'actions'         => ',adjust,',
			'pruned_gmt'      => null,
		];
		$this->batches = [
			$batch( 1, 'complete', 3, [ 'total_items' => self::ROWS ] ),
			$batch( 2, 'partial', 4, [ 'failed_items' => 1 ] ),
			$batch( 3, 'undone', 3 ),
			$batch(
				4,
				'complete',
				4,
				[
					'source'  => 'undo',
					'undo_of' => 3,
					'actions' => ',set_value,',
				]
			),
		];
		$this->changes = [];
		$change        = function ( int $batch_id, int $object_id, string $prop, mixed $old, mixed $new, string $result, string $object_type = 'product' ): void {
			$this->changes[] = [
				'change_id'    => count( $this->changes ) + 1,
				'batch_id'     => $batch_id,
				'object_id'    => $object_id,
				'object_type'  => $object_type,
				'prop'         => $prop,
				'old_value'    => ValueCodec::encode( $old ),
				'new_value'    => ValueCodec::encode( $new ),
				'modified_gmt' => '2026-09-01 00:00:00',
				'applied_gmt'  => '2026-09-21 10:01:00',
				'result'       => $result,
				'message'      => 'applied' === $result ? '' : 'The sale price has to be below the regular price, so this product was skipped.',
			];
		};
		for ( $i = 1; $i <= self::ROWS; ++$i ) {
			$change( 1, 10000 + $i, 'regular_price', '10', '11', 'applied' );
		}
		$change( 2, 20001, 'sale_price', '', '8', 'applied', 'variation' );
		$change( 2, 20002, 'sale_price', '', '99', 'failed' );

		$GLOBALS['bulkcat_test']['posts'][10001] = [
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => "=cmd|' /C calc'!A0",
		];
		$GLOBALS['bulkcat_test']['posts'][20001] = [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_title'  => 'Shoe - Blue',
		];

		$tables          = '`wp_bulkcat_(batches|batch_items|changes)`';
		$wpdb->responses = [
			'/^SELECT \* FROM `wp_bulkcat_batches` WHERE batch_id = (\d+)$/' => function ( string $sql ): ?array {
				preg_match( '/(\d+)$/', $sql, $m );
				foreach ( $this->batches as $row ) {
					if ( (int) $m[1] === $row['batch_id'] ) {
						return array_map( static fn( mixed $v ): ?string => null === $v ? null : (string) $v, $row );
					}
				}
				return null;
			},
			"/^SELECT .* FROM $tables/s" => function ( string $sql ): mixed {
				if ( 1 === preg_match( '/FROM `wp_bulkcat_changes` .*LIMIT (\d+) OFFSET (\d+)$/s', $sql, $m ) ) {
					SpyStream::$log[] = [ 'read', intdiv( (int) $m[2], max( 1, (int) $m[1] ) ) + 1 ];
				}
				$db = new SqlEvaluator(
					[
						'wp_bulkcat_batches'     => $this->batches,
						'wp_bulkcat_batch_items' => [],
						'wp_bulkcat_changes'     => $this->changes,
					]
				);
				return 1 === preg_match( '/^SELECT (COUNT\(\*\)|status|claimed_gmt) FROM/', $sql ) ? $db->value( $sql ) : $db->rows( $sql );
			},
		];
	}

	private static function get( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/export' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A history export as the screen's form posts it (SECURITY-1): every
	 * value in the body, lists as one comma-separated field.
	 *
	 * @param array<string, mixed> $body  Form body.
	 * @param array<string, mixed> $query Query string.
	 */
	private static function post( array $body, array $query = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params( $body );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The plugin's export controller, as registered.
	 */
	private static function controller(): ExportController {
		foreach ( $GLOBALS['bulkcat_test']['filters']['rest_pre_serve_request'] as [ $callback ] ) {
			if ( is_array( $callback ) && $callback[0] instanceof ExportController ) {
				return $callback[0];
			}
		}
		throw new \LogicException( 'The export route is not registered.' );
	}

	/**
	 * Serve an export into the spy stream; the headers sent.
	 *
	 * @return array<string, string>
	 */
	private function serve( \WP_REST_Response|\WP_Error $response, bool $keep = true ): array {
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$this->assertInstanceOf( CsvExport::class, $response->get_data() );
		SpyStream::$keep = $keep;
		SpyStream::$all  = '';
		$server          = new class() {
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
		$stream          = fopen( 'bulkcatspy://out', 'wb' );
		$this->assertTrue( self::controller()->serve( false, $response, null, $server, $stream ) );
		fclose( $stream );
		return $server->headers;
	}

	/**
	 * The CSV lines written, without the byte-order mark.
	 *
	 * @return list<string>
	 */
	private static function lines(): array {
		$lines = explode( "\r\n", substr( SpyStream::$all, strlen( CsvWriter::BOM ) ) );
		array_pop( $lines );
		return $lines;
	}

	public function test_a_batch_of_5000_changes_streams_in_pages_with_flat_memory(): void {
		$before   = count( $GLOBALS['wpdb']->queries );
		$response = self::get(
			[
				'source'  => 'changes',
				'batch'   => 1,
				'columns' => [ 'name', 'object_id', 'field_label', 'old_value', 'new_value', 'result' ],
			]
		);
		$this->assertSame( [], SpyStream::$log, 'Nothing is read until the body is served.' );
		$headers = $this->serve( $response, false );

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="change-1-2026-09-23.csv"', $headers['Content-Disposition'] );

		$reads  = array_keys( array_filter( SpyStream::$log, static fn( array $event ): bool => 'read' === $event[0] ) );
		$writes = array_keys( array_filter( SpyStream::$log, static fn( array $event ): bool => 'write' === $event[0] ) );
		$this->assertCount( 50, $reads, 'Read 100 rows at a time: 5,000 / 100 pages.' );
		$this->assertSame( range( 1, 50 ), array_map( static fn( int $at ): int => SpyStream::$log[ $at ][1], $reads ) );
		$this->assertLessThan( $reads[1], $writes[0], 'The header goes out before the second page is read.' );
		$first_rows = min( array_filter( $writes, static fn( int $at ): bool => $at > $reads[0] ) );
		$this->assertLessThan( end( $reads ), $first_rows, 'The first rows are written before the last page is read.' );

		$queries = array_slice( $GLOBALS['wpdb']->queries, $before );
		$this->assertCount( 1, preg_grep( '/^SELECT COUNT\(\*\) FROM `wp_bulkcat_changes`/', $queries ), 'Counted once, on the first page.' );
		$this->assertCount( 50, preg_grep( '/FROM `wp_bulkcat_changes` .*LIMIT 100 OFFSET/s', $queries ) );
		$this->assertCount( 50, preg_grep( '/_prime_post_caches/', $queries ), 'One name prime per page, none per row.' );

		// Memory at the first write of each page after the first: no growth with the rows written. What
		// remains (under 0.5 MB here) is the stubbed post cache remembering each primed id, as WordPress's
		// runtime object cache does.
		$memory = array_map( static fn( int $at ): int => SpyStream::$log[ $at ][2], array_filter( $writes, static fn( int $at ): bool => $at > $reads[1] ) );
		$this->assertLessThan( 1024 * 1024, max( $memory ) - min( $memory ), 'Memory stays flat while 5,000 rows stream.' );

		$bytes = array_sum( array_map( static fn( array $event ): int => 'write' === $event[0] ? $event[1] : 0, SpyStream::$log ) );
		$this->assertGreaterThan( self::ROWS * 20, $bytes );
		$lines = explode( "\r\n", substr( SpyStream::$head, strlen( CsvWriter::BOM ) ) );
		$this->assertSame( '"Product","ID","Field","Old value","New value","Result"', $lines[0] );
		$this->assertSame( "\"'=cmd|' /C calc'!A0\",\"10001\",\"Regular price\",\"10\",\"11\",\"Applied\"", $lines[1], 'A name starting a formula comes back apostrophe-prefixed.' );
		$this->assertSame( '"Product 10002","10002","Regular price","10","11","Applied"', $lines[2] );
	}

	public function test_a_visitor_and_an_under_privileged_user_are_refused_before_any_row_is_read(): void {
		$params                               = [
			'source'  => 'changes',
			'batch'   => 1,
			'columns' => [ 'name' ],
		];
		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$result                               = self::get( $params );
		$this->assertSame( [ 'bulkcat_forbidden', 401 ], [ $result->get_error_code(), $result->get_error_data()['status'] ] );

		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$result                               = self::get( $params );
		$this->assertSame( [ 'bulkcat_forbidden', 403 ], [ $result->get_error_code(), $result->get_error_data()['status'] ] );

		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'administrators_only' => true ];
		$GLOBALS['bulkcat_test']['caps']                        = [ 'manage_woocommerce' => true ];
		$result = self::get( $params );
		$this->assertSame( 403, $result->get_error_data()['status'], 'A shop manager under administrators only.' );
		$this->assertSame( [], preg_grep( '/wp_bulkcat_changes/', $GLOBALS['wpdb']->queries ) );
	}

	public function test_a_bad_history_export_is_refused_before_any_row_is_read(): void {
		$refused = function ( array $params, string $code, int $status, ?string $param = null ): void {
			$result = self::get( $params );
			$this->assertInstanceOf( \WP_Error::class, $result, (string) wp_json_encode( $params ) );
			$this->assertSame( [ $code, $status ], [ $result->get_error_code(), $result->get_error_data()['status'] ], (string) wp_json_encode( $params ) );
			if ( null !== $param ) {
				$this->assertSame( $param, $result->get_error_data()['param'] ?? array_key_first( $result->get_error_data()['params'] ?? [] ) );
			}
		};
		$refused(
			[
				'source'  => 'changes',
				'columns' => [ 'name' ],
			],
			'bulkcat_invalid_value',
			400,
			'batch'
		);
		$refused(
			[
				'source'  => 'changes',
				'batch'   => 99,
				'columns' => [ 'name' ],
			],
			'bulkcat_not_found',
			404
		);
		$refused(
			[
				'source'  => 'changes',
				'batch'   => 1,
				'columns' => [ 'sku' ],
			],
			'bulkcat_invalid_value',
			400,
			'columns'
		);
		$refused(
			[
				'source'  => 'audit',
				'columns' => [ 'name', 'name' ],
			],
			'bulkcat_invalid_value',
			400,
			'columns'
		);
		$refused(
			[
				'source'  => 'batches',
				'columns' => [ 'no_such_column' ],
			],
			'rest_invalid_param',
			400,
			'columns'
		);
		$refused(
			[
				'source'  => 'everything',
				'columns' => [ 'name' ],
			],
			'rest_invalid_param',
			400,
			'source'
		);
		$refused(
			[
				'source'  => 'batches',
				'status'  => 'finished',
				'columns' => [ 'batch_id' ],
			],
			'rest_invalid_param',
			400,
			'status'
		);
		$refused(
			[
				'source'  => 'audit',
				'field'   => '_regular_price',
				'columns' => [ 'name' ],
			],
			'rest_invalid_param',
			400,
			'field'
		);
		$this->assertSame( [], preg_grep( '/wp_bulkcat_changes/', $GLOBALS['wpdb']->queries ) );

		// The catalogue keeps its own status filter.
		$catalogue = self::get(
			[
				'status'  => 'complete',
				'columns' => [ 'name' ],
			]
		);
		$this->assertSame( 'rest_invalid_param', $catalogue->get_error_code(), 'For products, status is the field filter.' );
	}

	public function test_a_batch_export_narrows_by_result_in_the_requested_column_order(): void {
		$this->serve(
			self::get(
				[
					'source'  => 'changes',
					'batch'   => 2,
					'result'  => [ 'failed' ],
					'columns' => 'result,name,message,object_type',
				]
			)
		);
		$this->assertSame(
			[
				'"Result","Product","Reason","Kind"',
				'"Failed","Product 20002","The sale price has to be below the regular price, so this product was skipped.","Product"',
			],
			self::lines()
		);
	}

	public function test_the_audit_log_exports_every_matching_row_with_names(): void {
		$headers = $this->serve(
			self::get(
				[
					'source'  => 'audit',
					'user'    => 4,
					'columns' => HistoryColumns::KEYS[ HistoryColumns::AUDIT ],
				]
			)
		);
		$this->assertSame( 'attachment; filename="audit-log-2026-09-23.csv"', $headers['Content-Disposition'] );
		$this->assertSame(
			[
				'"When (UTC)","Change number","Who","Product","ID","Kind","Field","Old value","New value","Result","Reason"',
				'"2026-09-21 10:01:00","2","\'=Rosa","Product 20002","20002","Product","Sale price","","99","Failed","The sale price has to be below the regular price, so this product was skipped."',
				'"2026-09-21 10:01:00","2","\'=Rosa","Shoe - Blue","20001","Variation","Sale price","","8","Applied",""',
			],
			self::lines(),
			'Newest first; a user name starting a formula is guarded too.'
		);

		$this->serve(
			self::get(
				[
					'source'  => 'audit',
					'product' => 20001,
					'columns' => [ 'object_id', 'name' ],
				]
			)
		);
		$this->assertSame( [ '"ID","Product"', '"20001","Shoe - Blue"' ], self::lines(), 'A variation id filters to that variation.' );
	}

	public function test_the_history_list_exports_with_its_filters_and_labels(): void {
		$headers = $this->serve(
			self::get(
				[
					'source'  => 'batches',
					'columns' => [ 'batch_id', 'user_name', 'actions', 'status', 'reversibility', 'undo', 'undone_by', 'variations_included' ],
				]
			)
		);
		$this->assertSame( 'attachment; filename="change-history-2026-09-23.csv"', $headers['Content-Disposition'] );
		$lines = self::lines();
		$this->assertSame( '"Change number","Who","What","Outcome","Can be undone","Undo of","Put back by","Variations included"', $lines[0] );
		$this->assertCount( 5, $lines );
		$this->assertStringStartsWith( '"4","\'=Rosa","', $lines[1] );
		$this->assertStringContainsString( '"Undo of #3"', $lines[1] );
		$this->assertSame( 1, preg_match( '/^"3","Sam Shop","[^"]+","Undone","No","","#4 by =Rosa on 2026-09-24 10:05:00 UTC","No"$/', $lines[2] ), $lines[2] );
		$this->assertSame( 1, preg_match( '/^"2","\'=Rosa","[^"]+","Completed with errors","Yes, with errors","","","No"$/', $lines[3] ), $lines[3] );

		$this->serve(
			self::get(
				[
					'source'  => 'batches',
					'status'  => 'partial',
					'columns' => [ 'batch_id' ],
				]
			)
		);
		$this->assertSame( [ '"Change number"', '"2"' ], self::lines(), 'status is the outcome for the history list.' );

		$this->serve(
			self::get(
				[
					'source'  => 'batches',
					'user'    => 3,
					'action'  => 'adjust',
					'columns' => [ 'batch_id' ],
				]
			)
		);
		$this->assertSame( [ '"Change number"', '"3"', '"1"' ], self::lines() );
	}

	public function test_a_history_export_says_who_redid_an_undone_change_and_when(): void {
		// Batch 3 was undone by 4; 4 was undone by 5 (a Redo), so batch 3's change is in effect again.
		foreach ( $this->batches as $index => $row ) {
			if ( 4 === $row['batch_id'] ) {
				$this->batches[ $index ]['status'] = 'undone';
			}
		}
		$this->batches[] = array_merge(
			$this->batches[3],
			[
				'batch_id'     => 5,
				'uuid'         => '00000000-0000-4000-8000-000000000005',
				'user_id'      => 3,
				'status'       => 'complete',
				'undo_of'      => 4,
				'created_gmt'  => '2026-09-25 10:00:00',
				'finished_gmt' => '2026-09-25 10:05:00',
			]
		);
		$this->serve(
			self::get(
				[
					'source'  => 'batches',
					'columns' => [ 'batch_id', 'status', 'undone_by', 'redone_by' ],
				]
			)
		);
		$this->assertSame(
			[
				'"Change number","Outcome","Put back by","Done again by"',
				'"5","Complete","",""',
				'"4","Undone","#5 by Sam Shop on 2026-09-25 10:05:00 UTC",""',
				'"3","Redone","#4 by =Rosa on 2026-09-24 10:05:00 UTC","#5 by Sam Shop on 2026-09-25 10:05:00 UTC"',
				'"2","Completed with errors","",""',
				'"1","Complete","",""',
			],
			self::lines(),
			'The original reads Redone, with the undo that put it back and the redo that made it again.'
		);
	}

	public function test_a_history_export_does_not_call_skipped_rows_errors(): void {
		// Batch 2 finished partial with nothing failed: its only problems were rows skipped as previewed.
		$this->batches[1]['failed_items'] = 0;
		$this->serve(
			self::get(
				[
					'source'  => 'batches',
					'status'  => 'partial',
					'columns' => [ 'batch_id', 'status', 'failed_items', 'reversibility' ],
				]
			)
		);
		$this->assertSame( [ '"Change number","Outcome","Failed","Can be undone"', '"2","Completed, some rows skipped","0","Yes"' ], self::lines() );
	}

	public function test_a_history_export_is_posted_with_its_filters_in_the_body(): void {
		$headers = $this->serve(
			self::post(
				[
					'source'  => 'batches',
					'status'  => 'partial',
					'columns' => 'batch_id,status',
				]
			)
		);
		$this->assertSame( 'attachment; filename="change-history-2026-09-23.csv"', $headers['Content-Disposition'] );
		$this->assertSame( [ '"Change number","Outcome"', '"2","Completed with errors"' ], self::lines(), 'status is the outcome for a posted history list too.' );

		$this->serve(
			self::post(
				[
					'source'  => 'changes',
					'batch'   => 2,
					'result'  => 'failed',
					'columns' => 'result,name',
				]
			)
		);
		$this->assertSame( [ '"Result","Product"', '"Failed","Product 20002"' ], self::lines() );

		$this->serve(
			self::post(
				[
					'source'  => 'audit',
					'product' => 20001,
					'columns' => 'object_id,name',
				]
			)
		);
		$this->assertSame( [ '"ID","Product"', '"20001","Shoe - Blue"' ], self::lines() );

		$refused = self::post(
			[
				'source'  => 'audit',
				'field'   => '_regular_price',
				'columns' => 'name',
			]
		);
		$this->assertSame( [ 'rest_invalid_param', 400 ], [ $refused->get_error_code(), $refused->get_error_data()['status'] ], 'A posted history export is validated as the history.' );
	}

	public function test_a_nonce_in_the_export_address_is_refused_before_any_row_is_read(): void {
		$get = new \WP_REST_Request( 'GET', '/bulkcat/v1/export' );
		$get->set_query_params(
			[
				'source'   => 'audit',
				'columns'  => [ 'name' ],
				'_wpnonce' => 'abc123def0',
			]
		);
		$result = rest_get_server()->dispatch( $get );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'bulkcat_invalid_value', 400, '_wpnonce' ], [ $result->get_error_code(), $result->get_error_data()['status'], $result->get_error_data()['param'] ] );

		$result = self::post(
			[
				'source'  => 'audit',
				'columns' => 'name',
			],
			[ '_wpnonce' => 'abc123def0' ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'bulkcat_invalid_value', 400 ], [ $result->get_error_code(), $result->get_error_data()['status'] ] );

		$result = self::post(
			[
				'columns'     => 'name',
				'confirm_all' => '1',
			],
			[ '_wpnonce' => 'abc123def0' ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result, 'The catalogue export refuses it too.' );
		$this->assertSame( 'bulkcat_invalid_value', $result->get_error_code() );
		$this->assertSame( [], preg_grep( '/wp_bulkcat_changes/', $GLOBALS['wpdb']->queries ) );

		// In the body, where the form sends it, it is fine.
		$this->serve(
			self::post(
				[
					'source'   => 'audit',
					'product'  => 20001,
					'columns'  => 'object_id',
					'_wpnonce' => 'abc123def0',
				]
			)
		);
		$this->assertSame( [ '"ID"', '"20001"' ], self::lines() );
	}
}
