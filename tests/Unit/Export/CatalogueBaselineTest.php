<?php
/**
 * The catalogue export, pinned byte for byte (T96, ticket 10's
 * byte-identical rule). The golden files were written once by the
 * catalogue `GET /export` as it stood before the slice's switch existed
 * (ticket 04's head ca86255, builder ruling B24; committed in becb5b8),
 * over a fixed row source (BaselineRows), with every non-image column in
 * registry order and with the default view's columns. That `GET` is retired
 * (T103, ruling R9); the switch-off `POST /export` over the whole catalogue
 * must still produce those bytes. The files are not rewritten: a difference
 * is a change to every export and is agreed first. The heading check: every
 * registry heading is 1.0.0's, except the listing control ticket 04
 * relabelled.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Routes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BaselineRows.php';

final class CatalogueBaselineTest extends TestCase {
	/**
	 * Every field's heading in 1.0.0 (tests/Unit/Fields/fields.snapshot.json, pinned since 73af0c3), in registry order.
	 */
	private const HEADINGS_1_0_0 = [
		'name'               => 'Name',
		'status'             => 'Status',
		'catalog_visibility' => 'Catalogue visibility',
		'visible_on_site'    => 'Visible on site',
		'featured'           => 'Featured',
		'category_ids'       => 'Categories',
		'tag_ids'            => 'Tags',
		'regular_price'      => 'Regular price',
		'sale_price'         => 'Sale price',
		'stock_status'       => 'Stock status',
		'manage_stock'       => 'Manage stock',
		'stock_quantity'     => 'Stock quantity',
		'sku'                => 'SKU',
		'weight'             => 'Weight',
		'length'             => 'Length',
		'width'              => 'Width',
		'height'             => 'Height',
		'shipping_class_id'  => 'Shipping class',
		'tax_status'         => 'Tax status',
		'tax_class'          => 'Tax class',
		'sold_individually'  => 'Sold individually',
		'reviews_allowed'    => 'Reviews',
		'id'                 => 'ID',
		'type'               => 'Type',
		'thumbnail'          => 'Image',
		'price_html'         => 'Price',
		'date_created'       => 'Date created',
		'date_modified'      => 'Last modified',
		'units_sold'         => 'Total sales',
		'variation_count'    => 'Variations',
	];

	/**
	 * The one heading ticket 04 (global Off/On, T93) changed, and its new text.
	 */
	private const RELABELLED = [ 'visible_on_site' => 'Shown in shop and search' ];

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['now']                        = '2026-09-23 10:00:00';
	}

	/**
	 * Today's switch-off export of the whole catalogue in a view's columns,
	 * with a separator setting.
	 *
	 * @param list<string> $columns   Columns.
	 * @param string       $separator comma or semicolon.
	 */
	private static function export_bytes( array $columns, string $separator ): string {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'csv_separator' => $separator ];
		$controller = new ExportController( new Registry(), BaselineRows::source() );
		( new Routes( [ $controller ] ) )->register();
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
		$request->set_body_params(
			[
				'columns'     => $columns,
				'confirm_all' => '1',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$stream   = fopen( 'php://memory', 'w+b' );
		$server   = new class() {
			public function send_header( string $name, string $value ): void {
				unset( $name, $value );
			}
		};
		if ( true !== $controller->serve( false, $response, null, $server, $stream ) ) {
			return '';
		}
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	/**
	 * Compare a view's bytes with its golden file.
	 *
	 * @param string $view      all or default.
	 * @param string $separator comma or semicolon.
	 */
	private function assert_golden( string $view, string $separator ): void {
		$bytes = self::export_bytes( BaselineRows::views()[ $view ], $separator );
		$file  = BaselineRows::golden( $view, $separator );
		$this->assertFileExists( $file, 'The golden files are recorded (B24) and not rewritten.' );
		$this->assertStringStartsWith( CsvWriter::BOM, $bytes );
		$this->assertSame( (string) file_get_contents( $file ), $bytes, "catalogue-$view-$separator.csv differs from today's export." );
	}

	public function test_the_catalogue_export_matches_the_recorded_bytes_with_commas(): void {
		$this->assert_golden( 'all', 'comma' );
		$this->assert_golden( 'default', 'comma' );
	}

	public function test_the_catalogue_export_matches_the_recorded_bytes_with_semicolons(): void {
		$this->assert_golden( 'all', 'semicolon' );
		$this->assert_golden( 'default', 'semicolon' );
	}

	public function test_the_views_are_the_ones_the_plan_names(): void {
		$views = BaselineRows::views();
		$this->assertCount( 29, $views['all'], 'Every field but the image.' );
		$this->assertNotContains( 'thumbnail', $views['all'] );
		$this->assertSame( [ 'name', 'sku', 'category_ids', 'regular_price', 'stock_status', 'status' ], $views['default'], 'The recorded default golden keeps the 1.0.0-era default columns, so the CSV bytes stay pinned (B24) whatever the screen\'s default columns become.' );
		$this->assertSame( [ 'name', 'sku', 'category_ids', 'regular_price', 'stock_status', 'status', 'catalog_visibility', 'visible_on_site' ], BaselineRows::screen_default(), 'Ticket 19: the screen\'s default columns add both listing visibility presentations after Status.' );
	}

	public function test_only_the_listing_control_heading_differs_from_1_0_0(): void {
		$now = array_map( static fn( Field $field ): string => $field->label(), ( new Registry() )->all() );
		$this->assertSame(
			array_keys( self::HEADINGS_1_0_0 ),
			array_keys( $now ),
			'A field was added, removed or moved. A new heading changes every export: agree it with ticket 04 (global Off/On) and ticket 10 (CSV export) first.'
		);
		$this->assertSame( 'Status', $now['status'], 'Off/On is the Status field itself: its heading stays Status (ticket 04, ruling 3).' );
		$this->assertSame( self::RELABELLED['visible_on_site'], $now['visible_on_site'] );
		$this->assertSame(
			array_merge( self::HEADINGS_1_0_0, self::RELABELLED ),
			$now,
			'Only the listing control relabelled by ticket 04 (global Off/On, T93) may differ from 1.0.0: raise any other heading change with ticket 04\'s owner and ticket 10.'
		);
		// The same headings reach the file: the "all" golden's header row.
		$golden = (string) file_get_contents( BaselineRows::golden( 'all', 'comma' ) );
		$header = str_getcsv( strtok( substr( $golden, strlen( CsvWriter::BOM ) ), "\r" ), ',', '"', '' );
		$want   = array_values( array_diff_key( array_merge( self::HEADINGS_1_0_0, self::RELABELLED ), [ 'thumbnail' => true ] ) );
		$this->assertSame( $want, $header );
	}
}
