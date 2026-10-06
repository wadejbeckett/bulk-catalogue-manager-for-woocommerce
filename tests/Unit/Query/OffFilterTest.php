<?php
/**
 * T92 (SPEC §4.1, §5.5): the Status filter's Off and Changed outside this
 * screen values, and a Draft that means a genuine draft, run as real
 * prepared SQL over the seeded catalogue: a record naming another product is
 * no record, and a variation never matches either value.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class OffFilterTest extends TestCase {
	private SqlEvaluator $db;

	protected function setUp(): void {
		bulkcat_test_reset();
		$this->db = SeededCatalogue::seed();
		// 102 is a draft with its own record: Off. 101 is published with its own record: changed outside.
		// 106 carries a record copied from 104: no record. Variation 202 carries one: never matched.
		$this->record( 102, OffRecord::create( 102, 'publish', '2026-09-01T08:00:00Z', 4 )->to_json() );
		$this->record( 101, OffRecord::create( 101, 'private', '2026-09-01T08:00:00Z', 4 )->to_json() );
		$this->record( 106, OffRecord::create( 104, 'publish', '2026-09-01T08:00:00Z', 4 )->to_json() );
		$this->record( 202, OffRecord::create( 202, 'publish', '2026-09-01T08:00:00Z', 4 )->to_json() );
	}

	private function record( int $id, string $value ): void {
		$this->db->tables['wp_postmeta'][] = [
			'meta_id'    => 7000 + $id,
			'post_id'    => $id,
			'meta_key'   => OffRecord::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture row.
			'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture row.
		];
	}

	/**
	 * The ids a Status filter lists, sorted.
	 *
	 * @param string   $operator isAny or isNone.
	 * @param string[] $values   Status filter values.
	 * @param string   $mode     Criteria mode.
	 * @return list<int>
	 */
	private static function ids( string $operator, array $values, string $mode = Criteria::MODE_PRODUCTS ): array {
		$query = ProductQuery::from_array(
			[
				'mode'     => $mode,
				'per_page' => 100,
				'filters'  => [
					[
						'field'    => 'status',
						'operator' => $operator,
						'value'    => $values,
					],
				],
			]
		);
		$ids   = array_column( $query->rows(), 'id' );
		sort( $ids );
		return $ids;
	}

	public function test_off_changed_outside_and_a_genuine_draft(): void {
		$this->assertSame( [ 102 ], self::ids( 'isAny', [ 'off' ] ) );
		$this->assertSame( [ 101 ], self::ids( 'isAny', [ 'changed_outside' ] ) );
		$this->assertSame( [], self::ids( 'isAny', [ 'draft' ] ), 'Draft means a genuine draft: the only draft is Off.' );
		$this->assertSame( [ 102 ], self::ids( 'isAny', [ 'draft', 'off' ] ) );
		$this->assertSame( [ 101, 102 ], self::ids( 'isAny', [ 'off', 'changed_outside' ] ) );
		$this->assertSame( [ 101, 103, 106, 107 ], self::ids( 'isAny', [ 'publish' ] ), 'A real status still lists by its status alone.' );
		$this->assertSame( [ 101, 102, 103, 106, 107 ], self::ids( 'isAny', [ 'publish', 'off' ] ) );
	}

	public function test_is_none_of_each(): void {
		$this->assertSame( [ 101, 103, 104, 105, 106, 107 ], self::ids( 'isNone', [ 'off' ] ) );
		$this->assertSame( [ 102, 103, 104, 105, 106, 107 ], self::ids( 'isNone', [ 'changed_outside' ] ) );
		$this->assertSame( [ 101, 102, 103, 104, 105, 106, 107 ], self::ids( 'isNone', [ 'draft' ] ), 'An Off product is not a genuine draft.' );
		$this->assertSame( [ 103, 104, 105, 106, 107 ], self::ids( 'isNone', [ 'off', 'changed_outside' ] ) );
	}

	public function test_a_copied_record_and_a_variation_never_match(): void {
		$this->assertNotContains( 106, self::ids( 'isAny', [ 'changed_outside' ] ), 'A record naming another product is no record.' );
		$this->assertSame( [ 101 ], self::ids( 'isAny', [ 'changed_outside' ], Criteria::MODE_PRODUCTS_AND_VARIATIONS ) );
		$this->assertSame( [], self::ids( 'isAny', [ 'changed_outside', 'off' ], Criteria::MODE_VARIATIONS ) );
	}

	public function test_the_condition_names_the_meta_table_as_an_identifier_and_binds_the_key(): void {
		$query = ProductQuery::from_array(
			[
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'isAny',
						'value'    => [ 'off' ],
					],
				],
			]
		);
		$sql   = $query->statement();
		$this->assertStringContainsString( "EXISTS (SELECT 1 FROM `wp_postmeta` AS m WHERE m.post_id = p.ID AND m.meta_key = '_bulkcat_availability_off' AND m.meta_value LIKE CONCAT(", $sql );
		$this->assertContains( 'postmeta', ProductQuery::TABLES );
	}
}
