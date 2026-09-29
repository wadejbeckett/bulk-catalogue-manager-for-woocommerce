<?php
/**
 * The saved status of a product switched Off (ticket 04's Off record,
 * `_bulkcat_availability_off`) never reaches the catalogue CSV, whatever
 * columns are chosen (T103, from ticket 04's review). The record is internal
 * and bound to its product: a copy imported onto another product is ignored
 * by design, so the file carries Status (Draft) and nothing of the record.
 * The export's columns are the registry's public keys; the record's internal
 * field is not one, and asking for it, or for its meta key, is refused
 * before any row is read.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class OffRecordExportTest extends TestCase {
	/**
	 * The product switched Off.
	 */
	private const OFF = 301;

	/**
	 * Its name, which a search finds.
	 */
	private const NAME = 'Switched Off Tee';

	private ExportController $controller;

	/**
	 * The product's own Off record, as ticket 04 stores it.
	 */
	private static function record(): string {
		return OffRecord::create( self::OFF, 'publish', '2026-09-28T14:03:11Z', 418 )->to_json();
	}

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-29 10:00:00';
		SeededCatalogue::seed(
			[
				self::OFF => [
					[
						'post_type'         => 'product',
						'post_title'        => self::NAME,
						'post_status'       => 'draft',
						'post_parent'       => 0,
						'menu_order'        => 0,
						'comment_status'    => 'open',
						'post_date_gmt'     => '2026-09-20 08:00:00',
						'post_modified_gmt' => '2026-09-28 14:03:11',
					],
					[ 'OFF-1', 9.0, 9.0, 'instock', 4, '' ],
					[ 60 ],
					[ OffRecord::META_KEY => self::record() ],
				],
			]
		);
		$this->controller = new ExportController( new Registry() );
		( new Routes( [ $this->controller ] ) )->register();
	}

	/**
	 * POST /export with a form body, streamed to a string.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array{0: mixed, 1: string} The result and the bytes streamed.
	 */
	private function export( array $body ): array {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params( $body );
		$result = rest_get_server()->dispatch( $request );
		$stream = fopen( 'php://memory', 'w+b' );
		$server = new class() {
			public function send_header( string $name, string $value ): void {
				unset( $name, $value );
			}
		};
		if ( true !== $this->controller->serve( false, $result, null, $server, $stream ) ) {
			return [ $result, '' ];
		}
		rewind( $stream );
		return [ $result, (string) stream_get_contents( $stream ) ];
	}

	/**
	 * The file's rows, header first.
	 *
	 * @param string $csv File.
	 * @return list<list<string>>
	 */
	private static function rows( string $csv ): array {
		$lines = array_slice( explode( CsvWriter::EOL, substr( $csv, strlen( CsvWriter::BOM ) ) ), 0, -1 );
		return array_map( static fn( string $line ): array => str_getcsv( $line, ',', '"', '' ), $lines );
	}

	/**
	 * Assert the file holds nothing of the record, and Status reads Draft.
	 *
	 * @param string $csv  File.
	 * @param string $leg  For the message.
	 */
	private function assert_no_record( string $csv, string $leg ): void {
		$this->assertStringStartsWith( CsvWriter::BOM, $csv, $leg );
		foreach ( [ OffRecord::META_KEY, OffRecord::FIELD, 'Status before Off', '2026-09-28T14:03:11Z', '"batch"', self::record(), str_replace( '"', '""', self::record() ) ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $csv, "$leg: $absent" );
		}
		$rows = self::rows( $csv );
		$this->assertCount( 2, $rows, "$leg: the header and the Off product" );
		$status = array_search( 'Status', $rows[0], true );
		$this->assertTrue( is_int( $status ), "$leg: the Status column" );
		$this->assertSame( 'draft', $rows[1][ $status ], "$leg: the round trip carries Status (Draft)" );
		$labels = array_map( static fn( $field ): string => $field->label(), ( new Registry() )->all() );
		foreach ( $rows[0] as $heading ) {
			$plain = trim( str_replace( '(for reference)', '', $heading ) );
			$this->assertContains( $plain, [ ...array_values( $labels ), 'ID', 'SKU', 'Parent', 'Type' ], "$leg: every heading is a public column's" );
		}
	}

	public function test_an_off_product_exported_with_every_column_carries_no_saved_status(): void {
		$every = ( new Registry() )->keys();
		$this->assertNotContains( OffRecord::FIELD, $every, 'The record is an internal field, never a column.' );
		foreach ( [ '0', '1' ] as $identifiers ) {
			foreach ( [
				'ids'    => [ 'ids' => (string) self::OFF ],
				'filter' => [ 'search' => self::NAME ],
			] as $source => $rows ) {
				[ $result, $csv ] = $this->export(
					[
						'columns'     => implode( ',', $every ),
						'identifiers' => $identifiers,
					] + $rows
				);
				$this->assertInstanceOf( \WP_REST_Response::class, $result, "$source/$identifiers" );
				$this->assert_no_record( $csv, "$source, identifiers $identifiers" );
			}
		}
		$this->assertSame( self::record(), $GLOBALS['bulkcat_test']['meta'][ self::OFF ][ OffRecord::META_KEY ], 'The record is there to be left out.' );
	}

	public function test_asking_for_the_record_as_a_column_is_refused_before_any_row_is_read(): void {
		foreach ( [ OffRecord::FIELD, OffRecord::META_KEY, 'name,' . OffRecord::FIELD ] as $columns ) {
			[ $result, $csv ] = $this->export(
				[
					'columns' => $columns,
					'ids'     => (string) self::OFF,
				]
			);
			$this->assertInstanceOf( \WP_Error::class, $result, $columns );
			$this->assertSame( 'rest_invalid_param', $result->get_error_code(), $columns );
			$this->assertSame( 400, $result->get_error_data()['status'], $columns );
			$this->assertSame( '', $csv, "$columns: no CSV byte" );
		}
	}
}
