<?php
/**
 * SQR-3 and SQR-4: a filtered catalogue export walks the matching rows by
 * id (keyset: each page is the next rows after the last id written), so a
 * row whose sort key changes, or a product created, while the file is
 * being written can never be written twice or pushed off the end; and no
 * page reads the grid's row actions, which the file never holds. Run
 * through POST /export over the seeded catalogue plus 250 filler products.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';
require_once __DIR__ . '/AfterFirstPage.php';

final class FilterExportPagingTest extends TestCase {
	private ExportController $controller;

	private SqlEvaluator $db;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-29 10:00:00';
		$this->db         = SeededCatalogue::seed( SeededCatalogue::fillers( 250 ) );
		$this->controller = new ExportController( new Registry() );
		( new Routes( [ $this->controller ] ) )->register();
	}

	/**
	 * POST /export with a form body, streamed to a string.
	 *
	 * @param array<string, mixed> $body  Body.
	 * @param callable|null        $after Run once the first page is written, as a change made meanwhile.
	 */
	private function export( array $body, ?callable $after = null ): string {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params( $body );
		$result = rest_get_server()->dispatch( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$stream = fopen( 'php://memory', 'w+b' );
		$server = new class() {
			public function send_header( string $name, string $value ): void {
				unset( $name, $value );
			}
		};
		if ( null !== $after ) {
			// Once the first data row is written, the first page has been read.
			$done = false;
			stream_filter_register( 'bulkcat_after_first_page', AfterFirstPage::class );
			AfterFirstPage::$callback = static function ( string $data ) use ( &$done, $after ): void {
				if ( ! $done && str_contains( $data, 'Filler' ) ) {
					$done = true;
					$after();
				}
			};
			stream_filter_append( $stream, 'bulkcat_after_first_page', STREAM_FILTER_WRITE );
		}
		$this->assertTrue( $this->controller->serve( false, $result, null, $server, $stream ) );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	/**
	 * The file's data rows' first cells (ids).
	 *
	 * @return list<int>
	 */
	private static function ids( string $csv ): array {
		$lines = array_slice( explode( CsvWriter::EOL, substr( $csv, strlen( CsvWriter::BOM ) ) ), 1, -1 );
		return array_map( static fn( string $line ): int => (int) str_getcsv( $line, ',', '"', '' )[0], $lines );
	}

	public function test_a_row_that_moves_while_the_file_is_written_is_written_once_and_none_is_lost(): void {
		$body = [
			'columns' => 'id,name',
			'search'  => 'Filler',
			'orderby' => 'name',
			'order'   => 'asc',
		];
		// Meanwhile: the first filler written is renamed to sort last.
		$csv = $this->export(
			$body,
			function (): void {
				foreach ( $this->db->tables['wp_posts'] as &$post ) {
					if ( 1001 === $post['ID'] ) {
						$post['post_title'] = 'Filler zzz';
					}
				}
				unset( $post );
			}
		);
		$ids = self::ids( $csv );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'No row is written twice.' );
		$this->assertSame( [], array_diff( range( 1001, 1250 ), $ids ), 'No row that matched throughout is lost.' );
	}

	public function test_a_product_created_while_the_file_is_written_pushes_no_row_off_the_end(): void {
		$csv = $this->export(
			[
				'columns' => 'id,name',
				'search'  => 'Filler',
			],
			function (): void {
				// Newest first by default: a new product lands on the first page, already written.
				$this->db->tables['wp_posts'][] = [
					'ID'                => 1300,
					'post_type'         => 'product',
					'post_title'        => 'Filler new',
					'post_status'       => 'publish',
					'post_parent'       => 0,
					'menu_order'        => 0,
					'post_date_gmt'     => '2026-09-28 08:00:00',
					'post_modified_gmt' => '2026-09-28 08:00:00',
				];
			}
		);
		$ids = self::ids( $csv );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'No row is written twice.' );
		$this->assertSame( [], array_diff( range( 1001, 1250 ), $ids ), 'No row is pushed off the last page.' );
	}

	public function test_rows_come_in_id_order_whatever_the_view_s_sort(): void {
		$ids = self::ids(
			$this->export(
				[
					'columns' => 'id,name',
					'search'  => 'Filler',
					'orderby' => 'name',
					'order'   => 'desc',
				]
			)
		);
		$this->assertSame( range( 1001, 1250 ), $ids );
	}
}
