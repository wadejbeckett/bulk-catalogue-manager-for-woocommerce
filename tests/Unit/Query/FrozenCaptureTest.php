<?php
/**
 * Frozen capture uses one bounded identity query, without view paging or count.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

final class FrozenCaptureTest extends TestCase {
	public function test_capture_reads_types_and_ids_once_independently_of_view_page(): void {
		bulkcat_test_reset();
		$query = new ProductQuery(
			Criteria::from_array(
				[
					'search'   => 'blue',
					'page'     => 3,
					'per_page' => 25,
					'orderby'  => 'name',
				]
			)
		);
		$this->assertTrue( method_exists( $query, 'snapshot_members' ), 'A cached count and paged filter replay cannot freeze membership.' );
		$GLOBALS['wpdb']->responses['/^SELECT .*p.ID AS id, p.post_type AS object_type/'] = [
			[
				'id'          => '8',
				'object_type' => 'product',
			],
			[
				'id'          => '11',
				'object_type' => 'product_variation',
			],
		];
		$this->assertSame(
			[
				'products'   => [ 8 ],
				'variations' => [ 11 ],
			],
			$query->snapshot_members()
		);
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringContainsString( 'ORDER BY p.ID ASC LIMIT 100001', $sql );
		$this->assertStringNotContainsString( 'OFFSET', $sql );
		$this->assertStringNotContainsString( 'COUNT(', $sql );
	}
}
