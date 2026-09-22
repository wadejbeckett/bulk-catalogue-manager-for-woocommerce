<?php
/**
 * The one CSV writer (SPEC §10, §11, §13): byte-order mark, every field
 * quoted, CRLF, the comma or semicolon separator, the formula guard on each
 * dangerous leading character, empty distinct from zero, and a value with
 * quotes, the separator and line breaks surviving a round trip.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	/**
	 * Everything a writer produces for the given rows.
	 *
	 * @param list<list<mixed>> $rows      Rows.
	 * @param string            $separator Separator.
	 */
	private static function csv( array $rows, string $separator = ',' ): string {
		$stream = fopen( 'php://memory', 'w+b' );
		$writer = new CsvWriter( $stream, $separator );
		$writer->rows( $rows );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	/**
	 * Parse CSV text back into rows as a spreadsheet would (RFC 4180, no escape character).
	 *
	 * @param string $csv       CSV text, byte-order mark included.
	 * @param string $separator Separator.
	 * @return list<list<string>>
	 */
	private static function parse( string $csv, string $separator = ',' ): array {
		$stream = fopen( 'php://memory', 'w+b' );
		fwrite( $stream, substr( $csv, strlen( CsvWriter::BOM ) ) );
		rewind( $stream );
		$rows = [];
		$row  = fgetcsv( $stream, null, $separator, '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $stream, null, $separator, '"', '' );
		}
		return $rows;
	}

	public function test_output_starts_with_one_byte_order_mark_even_with_no_rows(): void {
		$this->assertSame( CsvWriter::BOM, self::csv( [] ) );
		$csv = self::csv( [ [ 'a' ], [ 'b' ] ] );
		$this->assertStringStartsWith( "\xEF\xBB\xBF\"a\"", $csv );
		$this->assertSame( 1, substr_count( $csv, CsvWriter::BOM ) );
	}

	public function test_every_field_is_quoted_and_lines_end_crlf(): void {
		$this->assertSame(
			CsvWriter::BOM . "\"Name\",\"SKU\",\"Stock\"\r\n\"Blue shoe\",\"SHOE-42\",\"12\"\r\n",
			self::csv( [ [ 'Name', 'SKU', 'Stock' ], [ 'Blue shoe', 'SHOE-42', 12 ] ] )
		);
	}

	public function test_a_quote_is_doubled_inside_its_quotes(): void {
		$this->assertSame( "\"a \"\"quoted\"\" word\"\r\n", CsvWriter::line( [ 'a "quoted" word' ], ',' ) );
	}

	public function test_the_separator_switches_between_comma_and_semicolon(): void {
		$this->assertSame( CsvWriter::BOM . "\"1,5\",\"x\"\r\n", self::csv( [ [ '1,5', 'x' ] ], ',' ) );
		$this->assertSame( CsvWriter::BOM . "\"1,5\";\"x\"\r\n", self::csv( [ [ '1,5', 'x' ] ], ';' ) );
		$this->assertSame( [ [ 'a;b', 'c' ] ], self::parse( self::csv( [ [ 'a;b', 'c' ] ], ';' ), ';' ) );
	}

	public function test_any_other_separator_is_refused(): void {
		foreach ( [ "\t", '|', '', ',,' ] as $separator ) {
			try {
				new CsvWriter( fopen( 'php://memory', 'w+b' ), $separator );
				$this->fail( 'Separator ' . json_encode( $separator ) . ' was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'comma or a semicolon', $e->getMessage() );
			}
		}
	}

	public function test_each_formula_start_gets_an_apostrophe(): void {
		$cases = [
			'=SUM(A1:A2)'     => "'=SUM(A1:A2)",
			'+27 21 555 0100' => "'+27 21 555 0100",
			'-10'             => "'-10",
			'@SUM(A1)'        => "'@SUM(A1)",
			"\t=1+1"          => "'\t=1+1",
			"\r=1+1"          => "'\r=1+1",
			'  =cmd|x'        => "'  =cmd|x",
			' @x'             => "' @x",
		];
		foreach ( $cases as $value => $guarded ) {
			$this->assertSame( $guarded, CsvWriter::guard( (string) $value ), json_encode( $value ) );
			$this->assertSame( '"' . $guarded . "\"\r\n", CsvWriter::line( [ $value ], ',' ), json_encode( $value ) );
		}
		$this->assertSame( "\"'-5\"\r\n", CsvWriter::line( [ -5 ], ',' ), 'A number starting with a minus is guarded too.' );
		foreach ( [ 'Shoe', 'a=b', '5-10', 'x@y.test', '', ' ', "'quoted" ] as $safe ) {
			$this->assertSame( $safe, CsvWriter::guard( $safe ), json_encode( $safe ) );
		}
	}

	public function test_an_empty_cell_is_distinct_from_zero(): void {
		$this->assertSame( "\"\",\"\",\"0\",\"0\",\"0.00\"\r\n", CsvWriter::line( [ null, '', 0, 0.0, '0.00' ], ',' ) );
	}

	public function test_cells_render_lists_switches_and_numbers_as_text(): void {
		$this->assertSame( "\"12, 14\",\"Yes\",\"No\",\"19.5\",\"7\"\r\n", CsvWriter::line( [ [ 12, 14 ], true, false, 19.5, 7 ], ',' ) );
		$this->assertSame( 'caf?', str_replace( "\u{FFFD}", '?', CsvWriter::text( "caf\xE9" ) ), 'Invalid UTF-8 is replaced, not passed through.' );
	}

	public function test_a_value_with_quotes_separators_and_line_breaks_round_trips(): void {
		$value = "He said \"hi\", then;\r\nleft\nagain \"\"";
		foreach ( [ ',', ';' ] as $separator ) {
			$csv = self::csv( [ [ 'Name', 'Note' ], [ $value, 'é ü 中' ] ], $separator );
			$this->assertSame( [ [ 'Name', 'Note' ], [ $value, 'é ü 中' ] ], self::parse( $csv, $separator ), "Separator $separator" );
		}
	}

	public function test_lines_are_counted_and_written_as_they_come(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$writer = new CsvWriter( $stream, ';' );
		$this->assertSame( ';', $writer->separator() );
		$writer->row( [ 'a' ] );
		$writer->flush();
		$this->assertSame( strlen( CsvWriter::BOM . "\"a\"\r\n" ), ftell( $stream ) );
		$writer->row( [ 'b' ] );
		$this->assertSame( 2, $writer->lines() );
	}
}
