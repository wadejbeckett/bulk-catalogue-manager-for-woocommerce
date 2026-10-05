<?php
/**
 * SQR-6: the Stock quantity cell and filter agree when stock is not
 * managed. WooCommerce's lookup holds a quantity only when stock is managed
 * (`_manage_stock` "yes"), and the filter reads the lookup; the cell read
 * `_stock`, which a product that once managed stock can still hold. The
 * cell now shows no quantity unless stock is managed, as the lookup does.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Query\Snapshot;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class UnmanagedStockTest extends TestCase {
	public function test_a_left_over_quantity_is_neither_shown_nor_filtered(): void {
		bulkcat_test_reset();
		SeededCatalogue::seed(
			[
				403 => [
					[
						'post_type'         => 'product',
						'post_title'        => 'Once Counted',
						'post_status'       => 'publish',
						'post_parent'       => 0,
						'menu_order'        => 0,
						'comment_status'    => 'open',
						'post_date_gmt'     => '2026-09-10 08:00:00',
						'post_modified_gmt' => '2026-09-10 08:00:00',
					],
					[ 'ONCE', 5.0, 5.0, 'instock', null, '' ],
					[ 60 ],
					[ '_stock' => '5' ],
				],
			]
		);
		$this->assertSame( 'no', $GLOBALS['bulkcat_test']['meta'][403]['_manage_stock'] );

		$query = ProductQuery::from_array(
			[
				'per_page' => 100,
				'filters'  => [
					[
						'field'    => 'stock_quantity',
						'operator' => 'greaterThanOrEqual',
						'value'    => 0,
					],
				],
			]
		);
		$this->assertNotContains( 403, array_column( $query->rows(), 'id' ), 'The filter reads the lookup, which holds no quantity.' );

		$cell = new Snapshot( (object) [ 'ID' => 403 ], [], 0, '' );
		$this->assertNull( $cell->get_stock_quantity(), 'The cell shows no quantity either.' );

		$managed = new Snapshot( (object) [ 'ID' => 101 ], [], 0, '' );
		$this->assertSame( 5, $managed->get_stock_quantity(), 'A managed quantity still shows.' );
	}
}
