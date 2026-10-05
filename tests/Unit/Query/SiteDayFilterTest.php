<?php
/**
 * SQR-2: a date filter's day is a day in the site's time zone, as the grid
 * shows dates, not a UTC day. On a UTC+2 shop a product modified at 01:30 on
 * 5 October (23:30 on the 4th in UTC) shows 5 October, so "after 4 October"
 * lists it and "before 5 October" does not. Run as real prepared SQL over the
 * seeded catalogue plus that product.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class SiteDayFilterTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['timezone'] = 'Africa/Johannesburg';
		SeededCatalogue::seed(
			[
				401 => [
					[
						'post_type'         => 'product',
						'post_title'        => 'Late Lamp',
						'post_status'       => 'publish',
						'post_parent'       => 0,
						'menu_order'        => 0,
						'comment_status'    => 'open',
						'post_date'         => '2026-10-05 00:30:00',
						'post_date_gmt'     => '2026-10-04 22:30:00',
						'post_modified'     => '2026-10-05 01:30:00',
						'post_modified_gmt' => '2026-10-04 23:30:00',
					],
					[ 'LATE', 5.0, 5.0, 'instock', null, '' ],
					[ 60 ],
					[],
				],
			]
		);
	}

	/**
	 * The ids one date filter lists, sorted.
	 *
	 * @param string $field    date_created or date_modified.
	 * @param string $operator before or after.
	 * @param string $value    The day or time.
	 * @return list<int>
	 */
	private static function ids( string $field, string $operator, string $value ): array {
		$query = ProductQuery::from_array(
			[
				'per_page' => 100,
				'filters'  => [
					[
						'field'    => $field,
						'operator' => $operator,
						'value'    => $value,
					],
				],
			]
		);
		$ids   = array_column( $query->rows(), 'id' );
		sort( $ids );
		self::assertSame( count( $ids ), $query->total() );
		return $ids;
	}

	public function test_a_day_is_the_site_s_day(): void {
		foreach ( [ 'date_created', 'date_modified' ] as $field ) {
			$this->assertSame( [ 401 ], self::ids( $field, 'after', '2026-10-04' ), $field . ': the 5th in the shop is after the 4th.' );
			$this->assertNotContains( 401, self::ids( $field, 'before', '2026-10-05' ), $field . ': the 5th in the shop is not before the 5th.' );
			$this->assertContains( 401, self::ids( $field, 'before', '2026-10-06' ), $field );
			$this->assertSame( [], self::ids( $field, 'after', '2026-10-05' ), $field );
		}
	}

	public function test_a_full_time_is_still_gmt(): void {
		$this->assertSame( [ 401 ], self::ids( 'date_modified', 'after', '2026-10-04 23:00:00' ) );
		$this->assertSame( [], self::ids( 'date_modified', 'after', '2026-10-04 23:30:00' ) );
	}

	public function test_the_day_s_bounds_reach_sql_as_gmt(): void {
		$sql = ProductQuery::from_array(
			[
				'filters' => [
					[
						'field'    => 'date_modified',
						'operator' => 'before',
						'value'    => '2026-10-05',
					],
				],
			]
		)->statement();
		$this->assertStringContainsString( "p.post_modified_gmt < '2026-10-04 22:00:00'", $sql );
	}
}
