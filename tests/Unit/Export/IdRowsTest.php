<?php
/**
 * Explicit rows (T99, SPEC §10): a selection's or an id list's members are
 * written once each in ascending id order; members that are gone, binned,
 * unlisted or not products are left out, counted and never read; a variable
 * product brings none of its variations; pages follow the present members,
 * so a run of absent ids never ends the file; filter arguments never narrow
 * or widen the rows (R10, R12).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Export\ExportRows;
use BulkCatalogueManager\Export\IdRows;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

final class IdRowsTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		SeededCatalogue::seed();
	}

	/**
	 * The ids a file holds, streamed through CsvExport with the id column.
	 *
	 * @param IdRows               $rows   Rows.
	 * @param array<string, mixed> $params Parameters besides fields.
	 * @return list<int>
	 */
	private static function written( IdRows $rows, array $params = [] ): array {
		$stream = fopen( 'php://memory', 'w+b' );
		( new CsvExport( $rows, new Registry(), [ 'id', 'name' ], $params + [ 'fields' => [ 'id', 'name' ] ], ',', 'x.csv' ) )->stream( $stream );
		rewind( $stream );
		$lines = array_slice( explode( CsvWriter::EOL, substr( (string) stream_get_contents( $stream ), strlen( CsvWriter::BOM ) ) ), 1, -1 );
		return array_map( static fn( string $line ): int => (int) str_getcsv( $line, ',', '"', '' )[0], $lines );
	}

	public function test_members_are_written_once_each_in_ascending_order(): void {
		$rows = ExportRows::ids( [ 202, 101, 201, 101, 103, 106 ], new Registry() );
		$this->assertSame( 5, $rows->rows() );
		$this->assertSame( 0, $rows->left_out() );
		$this->assertSame( [ 101, 103, 106, 201, 202 ], self::written( $rows ) );
		$this->assertSame( [ 101, 103, 106, 201, 202 ], $rows->ids() );
	}

	public function test_left_out_members_are_counted_and_never_read(): void {
		$GLOBALS['bulkcat_test']['calls'] = [];
		$rows                             = ExportRows::ids( [ 101, 108, 99999, 5000, 203 ], new Registry() );
		$this->assertSame( 1, $rows->rows() );
		$this->assertSame( 4, $rows->left_out(), 'Binned 108, missing 99999, the attachment and the binned variation 203.' );
		$this->assertSame( [ 101 ], self::written( $rows ) );
		foreach ( $GLOBALS['bulkcat_test']['calls'] as [ $name, $args ] ) {
			if ( in_array( $name, [ '_prime_post_caches', 'update_meta_cache', 'get_post', 'get_post_meta', 'wp_get_object_terms' ], true ) ) {
				$ids = 'update_meta_cache' === $name ? $args[1] : (array) $args[0];
				$this->assertSame( [], array_intersect( [ 108, 99999, 5000, 203 ], $ids ), "$name read a left-out member." );
			}
		}
	}

	public function test_a_variable_product_brings_no_variations(): void {
		$this->assertSame( [ 103 ], self::written( ExportRows::ids( [ 103 ], new Registry() ) ), 'Its variations 201 and 202 come only when selected themselves.' );
		$this->assertSame( [ 103, 202 ], self::written( ExportRows::ids( [ 202, 103 ], new Registry() ) ) );
	}

	public function test_pages_follow_the_present_members_so_a_page_of_absent_ones_never_ends_the_file(): void {
		SeededCatalogue::seed( SeededCatalogue::fillers( 150 ) );
		// 1..100, a whole page by raw id, are not posts at all; 1001..1150 are listed products.
		$ids  = [ ...range( 1, 100 ), ...range( 1001, 1150 ) ];
		$rows = ExportRows::ids( $ids, new Registry() );
		$this->assertSame( 150, $rows->rows() );
		$this->assertSame( 100, $rows->left_out() );
		$this->assertSame( range( 1001, 1150 ), self::written( $rows ), 'Two pages of present members, not a first page of absent ids that would end the file.' );
		$this->assertSame( 150, $rows->page( [ 'fields' => [ 'id' ] ], 1, 100 )['total'] );
		$this->assertCount( 50, $rows->page( [ 'fields' => [ 'id' ] ], 2, 100 )['items'] );
		$this->assertSame( 1001, $rows->page( [ 'fields' => [ 'id' ] ], 1, 100 )['items'][0]['id'], 'A page asked out of order is still right.' );
		$this->assertSame( [], $rows->page( [ 'fields' => [ 'id' ] ], 3, 100 )['items'] );
	}

	public function test_filter_arguments_never_narrow_or_widen_the_rows(): void {
		$rows   = ExportRows::ids( [ 101, 104, 202 ], new Registry() );
		$params = [
			'search'       => 'Shirt',
			'mode'         => 'variations',
			'stock_status' => [
				'operator' => 'isAny',
				'value'    => [ 'instock' ],
			],
			'orderby'      => [ 'name' ],
			'order'        => [ 'desc' ],
		];
		$this->assertSame( [ 101, 104, 202 ], self::written( $rows, $params ), 'Not narrowed to shirts or in stock, not widened to other variations, and still ascending.' );
	}

	public function test_the_present_members_are_kept_as_an_integer_map(): void {
		$rows = ExportRows::ids( [ 202, 101 ], new Registry() );
		$this->assertSame(
			[
				101 => 0,
				202 => 103,
			],
			$rows->present()
		);
	}
}
