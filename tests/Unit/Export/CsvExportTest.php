<?php
/**
 * A CSV export (SPEC §10): the header is the requested columns' registry
 * labels in the requested order, every matching row is written exactly once
 * in column order, and the row source is read 100 rows at a time,
 * ceil( total / 100 ) times, so memory stays flat.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Registry;
use PHPUnit\Framework\TestCase;

final class CsvExportTest extends TestCase {
	/**
	 * Calls the fake source received: [ params, page, per_page ].
	 *
	 * @var list<array{0: array<string, mixed>, 1: int, 2: int}>
	 */
	private array $calls = [];

	/**
	 * Runtime cache releases seen as each page was read.
	 *
	 * @var list<int>
	 */
	private array $releases = [];

	protected function setUp(): void {
		bcm_test_reset();
		$this->calls = [];
	}

	/**
	 * A source of $total synthetic rows, recording each call.
	 *
	 * @param int $total Rows that match.
	 */
	private function source( int $total ): CallableRowSource {
		return new CallableRowSource(
			function ( array $params, int $page, int $per_page ) use ( $total ): array {
				$this->calls[]    = [ $params, $page, $per_page ];
				$this->releases[] = count( array_filter( $GLOBALS['bcm_test']['calls'] ?? [], static fn( array $call ): bool => 'wp_cache_flush_runtime' === $call[0] ) );
				$items            = [];
				$first            = ( $page - 1 ) * $per_page + 1;
				$last             = min( $total, $first + $per_page - 1 );
				for ( $id = $first; $id <= $last; ++$id ) {
					$items[] = [
						'id'             => $id,
						'name'           => "Product $id",
						'sku'            => "SKU-$id",
						'stock_quantity' => 0 === $id % 7 ? null : $id % 5,
						'regular_price'  => '9.99',
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
	 * Run an export into memory and return its bytes.
	 *
	 * @param CsvExport $export Export.
	 */
	private static function bytes( CsvExport $export ): string {
		$stream = fopen( 'php://memory', 'w+b' );
		$export->stream( $stream );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	/**
	 * Parsed rows of CSV text.
	 *
	 * @param string $csv CSV with its byte-order mark.
	 * @return list<list<string>>
	 */
	private static function parse( string $csv ): array {
		$stream = fopen( 'php://memory', 'w+b' );
		fwrite( $stream, substr( $csv, strlen( CsvWriter::BOM ) ) );
		rewind( $stream );
		$rows = [];
		$row  = fgetcsv( $stream, null, ',', '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $stream, null, ',', '"', '' );
		}
		return $rows;
	}

	public function test_the_source_is_read_ceil_total_over_100_times_and_every_row_written_once_in_order(): void {
		$registry = new Registry();
		foreach ( [
			250  => 3,
			100  => 1,
			101  => 2,
			1    => 1,
			0    => 1,
			1000 => 10,
		] as $total => $calls ) {
			$this->calls = [];
			$export      = new CsvExport( $this->source( $total ), $registry, [ 'sku', 'name' ], [ 'search' => 'x' ], ',', 'f.csv' );
			$rows        = self::parse( self::bytes( $export ) );

			$this->assertCount( $calls, $this->calls, "total $total" );
			$this->assertSame( max( 1, (int) ceil( $total / 100 ) ), count( $this->calls ), "total $total" );
			$this->assertSame( range( 1, $calls ), array_column( $this->calls, 1 ), "Pages in order for total $total." );
			$this->assertSame( [ CsvExport::PER_PAGE ], array_values( array_unique( array_column( $this->calls, 2 ) ) ) );
			$this->assertSame( [ [ 'search' => 'x' ] ], array_values( array_unique( array_column( $this->calls, 0 ), SORT_REGULAR ) ), 'The read parameters reach the source unchanged.' );

			$this->assertCount( $total + 1, $rows, "total $total" );
			$expected = [];
			for ( $id = 1; $id <= $total; ++$id ) {
				$expected[] = [ "SKU-$id", "Product $id" ];
			}
			$this->assertSame( $expected, array_slice( $rows, 1 ), "Every row once, in order, for total $total." );
		}
	}

	public function test_the_runtime_cache_is_released_after_every_page(): void {
		// T76: what each page primed stayed cached; 20,000 rows outgrew 128 MB at about 12,800.
		$GLOBALS['bcm_test']['calls'] = [];
		$this->calls                  = [];
		$this->releases               = [];
		self::bytes( new CsvExport( $this->source( 250 ), new Registry(), [ 'sku' ], [], ',', 'f.csv' ) );

		$this->assertSame( [ 0, 1, 2 ], $this->releases, 'Each page is read after the previous one was released.' );
		$this->assertCount( 3, array_filter( $GLOBALS['bcm_test']['calls'], static fn( array $call ): bool => 'wp_cache_flush_runtime' === $call[0] ) );
	}

	public function test_the_header_is_the_requested_columns_labels_in_the_requested_order(): void {
		$registry = new Registry();
		$columns  = [ 'stock_quantity', 'name', 'id', 'regular_price' ];
		$export   = new CsvExport( $this->source( 3 ), $registry, $columns, [], ',', 'f.csv' );
		$labels   = array_map( static fn( string $key ): string => $registry->get( $key )->label(), $columns );
		$rows     = self::parse( self::bytes( $export ) );

		$this->assertSame( $labels, $export->header() );
		$this->assertSame( $labels, $rows[0] );
		$this->assertNotSame( $columns, $rows[0], 'Labels, never keys.' );
		$this->assertSame( [ '1', 'Product 1', '1', '9.99' ], $rows[1] );
		$this->assertSame( $columns, $export->columns() );
	}

	public function test_a_missing_value_is_an_empty_cell_and_zero_stays_zero(): void {
		$export = new CsvExport( $this->source( 10 ), new Registry(), [ 'id', 'stock_quantity', 'weight' ], [], ',', 'f.csv' );
		$csv    = self::bytes( $export );
		$this->assertStringContainsString( "\r\n\"5\",\"0\",\"\"\r\n", $csv );
		$this->assertStringContainsString( "\r\n\"7\",\"\",\"\"\r\n", $csv );
	}

	public function test_the_separator_is_the_one_given(): void {
		$csv = self::bytes( new CsvExport( $this->source( 1 ), new Registry(), [ 'id', 'sku' ], [], ';', 'f.csv' ) );
		$this->assertStringEndsWith( "\"1\";\"SKU-1\"\r\n", $csv );
	}

	public function test_reading_stops_at_an_empty_page(): void {
		$source = new CallableRowSource(
			function ( array $params, int $page, int $per_page ): array {
				$this->calls[] = [ $params, $page, $per_page ];
				return [
					'items' => 1 === $page ? [ [ 'id' => 1 ] ] : [],
					'total' => 500,
				];
			}
		);
		$this->assertCount( 2, self::parse( self::bytes( new CsvExport( $source, new Registry(), [ 'id' ], [], ',', 'f.csv' ) ) ) );
		$this->assertCount( 2, $this->calls );
	}

	public function test_bad_columns_and_a_malformed_source_are_refused(): void {
		foreach ( [ [], [ 'id', 'id' ], [ 'id', 'no_such_field' ] ] as $columns ) {
			try {
				new CsvExport( $this->source( 1 ), new Registry(), $columns, [], ',', 'f.csv' );
				$this->fail( json_encode( $columns ) . ' was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$broken = new CallableRowSource( static fn(): array => [ 'rows' => [] ] );
		try {
			$broken->page( [], 1, 100 );
			$this->fail( 'A malformed page was accepted.' );
		} catch ( \UnexpectedValueException $e ) {
			$this->assertStringContainsString( 'items, total', $e->getMessage() );
		}
	}

	public function test_the_filename_is_dated_and_the_headers_make_a_csv_download(): void {
		$this->assertSame( 'catalogue-export-2026-09-23.csv', CsvExport::filename( new \DateTimeImmutable( '2026-09-23 23:59:00' ) ) );
		$export = new CsvExport( $this->source( 1 ), new Registry(), [ 'id' ], [], ',', 'catalogue-export-2026-09-23.csv' );
		$this->assertSame(
			[
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="catalogue-export-2026-09-23.csv"',
				'Cache-Control'       => 'no-store, max-age=0',
			],
			$export->headers()
		);
	}
}
