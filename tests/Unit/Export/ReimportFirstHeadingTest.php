<?php
/**
 * The first heading of a file meant for WooCommerce's product importer (T102
 * finding, ticket 10): PHP's CSV reader, which the importer uses, sees a
 * field as quoted only when a quote is its first byte, so after the file's
 * byte-order mark a quoted first heading is read with its quotes ("ID"
 * arrives as `"ID"`), is not mapped, and every row is then matched by SKU
 * alone. With the identifier switch on, the first heading, always the
 * literal `ID`, is therefore written unquoted; every other field stays
 * quoted and a switch-off file is byte for byte as before.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\ColumnPlan;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Registry;
use PHPUnit\Framework\TestCase;

final class ReimportFirstHeadingTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * What a writer wrote.
	 *
	 * @param callable $write Writes to the writer.
	 * @param string   $separator Separator.
	 */
	private static function written( callable $write, string $separator = ',' ): string {
		$stream = fopen( 'php://memory', 'w+b' );
		$write( new CsvWriter( $stream, $separator ) );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	public function test_a_plain_first_heading_follows_the_byte_order_mark_unquoted(): void {
		$this->assertSame(
			CsvWriter::BOM . 'ID,"SKU","Parent","Type","Name"' . CsvWriter::EOL,
			self::written( static fn( CsvWriter $w ) => $w->row_bare_first( [ 'ID', 'SKU', 'Parent', 'Type', 'Name' ] ) )
		);
		$this->assertSame(
			CsvWriter::BOM . 'ID;"SKU";"Name"' . CsvWriter::EOL,
			self::written( static fn( CsvWriter $w ) => $w->row_bare_first( [ 'ID', 'SKU', 'Name' ] ), ';' )
		);
		$this->assertSame( CsvWriter::BOM . 'ID' . CsvWriter::EOL, self::written( static fn( CsvWriter $w ) => $w->row_bare_first( [ 'ID' ] ) ) );
	}

	public function test_a_first_cell_that_is_not_plain_letters_stays_quoted_and_guarded(): void {
		foreach ( [ 'My ID', '=ID', 'Ärger', 'I"D', '', 'ID,SKU', "ID\r\n" ] as $first ) {
			$this->assertSame(
				CsvWriter::BOM . CsvWriter::line( [ $first, 'SKU' ], ',' ),
				self::written( static fn( CsvWriter $w ) => $w->row_bare_first( [ $first, 'SKU' ] ) ),
				(string) wp_json_encode( $first )
			);
		}
		$this->assertSame(
			1,
			( static function (): int {
				$stream = fopen( 'php://memory', 'w+b' );
				$writer = new CsvWriter( $stream );
				$writer->row_bare_first( [ 'ID', 'SKU' ] );
				return $writer->lines();
			} )(),
			'It counts as one line.'
		);
	}

	public function test_php_reads_the_bare_first_heading_as_woocommerce_needs_it(): void {
		$quoted = self::written( static fn( CsvWriter $w ) => $w->row( [ 'ID', 'SKU' ] ) );
		$bare   = self::written( static fn( CsvWriter $w ) => $w->row_bare_first( [ 'ID', 'SKU' ] ) );
		// WooCommerce's importer: fgetcsv with the escape disabled, then the byte-order mark stripped from the first key.
		$read = static function ( string $bytes ): array {
			$stream = fopen( 'php://memory', 'w+b' );
			fwrite( $stream, $bytes );
			rewind( $stream );
			$keys = fgetcsv( $stream, 0, ',', '"', "\0" );
			if ( str_starts_with( (string) $keys[0], CsvWriter::BOM ) ) {
				$keys[0] = substr( (string) $keys[0], 3 );
			}
			return $keys;
		};
		$this->assertSame( [ '"ID"', 'SKU' ], $read( $quoted ), 'The quoted form arrives with its quotes: the reason for this rule.' );
		$this->assertSame( [ 'ID', 'SKU' ], $read( $bare ) );
	}

	public function test_only_a_switch_on_export_writes_its_first_heading_bare(): void {
		$registry = new Registry();
		$rows     = [
			[
				'id'         => 7,
				'parent_id'  => 0,
				'name'       => 'Mug',
				'sku'        => 'M-7',
				'type'       => 'simple',
				'parent_sku' => '',
			],
		];
		$source   = new CallableRowSource(
			static fn( array $params, int $page, int $per_page ): array => [
				'items' => 1 === $page ? $rows : [],
				'total' => 1,
			]
		);
		$bytes    = static function ( bool $identifiers ) use ( $registry, $source ): string {
			$plan   = new ColumnPlan( $registry, [ 'name', 'sku' ], $identifiers );
			$stream = fopen( 'php://memory', 'w+b' );
			( new CsvExport( $source, $registry, $plan->columns(), [], ',', 'x.csv', $plan->labels(), $plan->identifiers() ) )->stream( $stream );
			rewind( $stream );
			return (string) stream_get_contents( $stream );
		};
		$this->assertStringStartsWith( CsvWriter::BOM . 'ID,"SKU","Parent","Type","Name"' . CsvWriter::EOL . '"7","M-7",', $bytes( true ) );
		$this->assertSame( CsvWriter::BOM . '"Name","SKU"' . CsvWriter::EOL . '"Mug","M-7"' . CsvWriter::EOL, $bytes( false ), 'Switch off: every field quoted, as before.' );
		$default = fopen( 'php://memory', 'w+b' );
		( new CsvExport( $source, $registry, [ 'id', 'name' ], [], ',', 'x.csv' ) )->stream( $default );
		rewind( $default );
		$this->assertStringStartsWith( CsvWriter::BOM . '"ID","Name"', (string) stream_get_contents( $default ), 'CsvExport quotes every heading unless asked.' );
	}
}
