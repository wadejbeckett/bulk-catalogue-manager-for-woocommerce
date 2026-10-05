<?php
/**
 * SQR-4: a catalogue export never works out the grid's row actions. The
 * file holds only the chosen columns, so reading each row's actions (its
 * edit lock, permalink and nonces, and the meta they need) would be reads
 * for nothing: with Name and Categories alone, the export reads no meta.
 * In 1.3.0 every export page went through the products route, which
 * decorates its rows; the export now reads through Export\FilterRows and
 * the Hydrator alone (SQR-3), and this test keeps it that way.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ExportRowActionsTest extends TestCase {
	public function test_a_filter_export_reads_no_row_actions(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products']      = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-29 10:00:00';
		SeededCatalogue::seed( SeededCatalogue::fillers( 150 ) );
		$controller = new ExportController( new Registry() );
		( new Routes( [ $controller ] ) )->register();

		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params(
			[
				'columns' => 'name,category_ids',
				'search'  => 'Filler',
			]
		);
		$result = rest_get_server()->dispatch( $request );
		$stream = fopen( 'php://memory', 'w+b' );
		$server = new class() {
			public function send_header( string $name, string $value ): void {
				unset( $name, $value );
			}
		};
		$this->assertTrue( $controller->serve( false, $result, null, $server, $stream ) );
		rewind( $stream );
		$this->assertSame( 151, substr_count( (string) stream_get_contents( $stream ), "\r\n" ), 'The header and 150 rows.' );

		$meta = array_filter( $GLOBALS['bulkcat_test']['calls'] ?? [], static fn( array $call ): bool => 'update_meta_cache' === $call[0] );
		$this->assertSame( [], array_values( $meta ), 'No page reads meta for row actions.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['nonces'] ?? [], 'No row action link is signed.' );
	}
}
