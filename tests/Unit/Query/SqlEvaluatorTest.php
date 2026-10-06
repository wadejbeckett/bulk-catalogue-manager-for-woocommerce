<?php
/**
 * The test evaluator itself (tests/Support/SqlEvaluator.php): the MySQL
 * behaviours the seeded-catalogue tests rely on, so a wrong answer there
 * cannot hide a wrong query.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

final class SqlEvaluatorTest extends TestCase {
	private static function db(): SqlEvaluator {
		return new SqlEvaluator(
			[
				'wp_a' => [
					[
						'id'   => 1,
						'name' => 'Blue_Shirt',
						'n'    => 5,
					],
					[
						'id'   => 2,
						'name' => 'Bluexshirt',
						'n'    => null,
					],
					[
						'id'   => 3,
						'name' => '50% off',
						'n'    => 10,
					],
				],
				'wp_b' => [
					[
						'a_id' => 1,
						'tag'  => 'x',
					],
					[
						'a_id' => 1,
						'tag'  => 'y',
					],
				],
			]
		);
	}

	private static function ids( string $where, string $order = 'a.id ASC' ): array {
		return array_map( 'intval', array_column( self::db()->rows( "SELECT a.id AS id FROM `wp_a` AS a WHERE $where ORDER BY $order LIMIT 10 OFFSET 0" ), 'id' ) );
	}

	public function test_null_is_neither_in_nor_out_of_a_list(): void {
		$this->assertSame( [ 1 ], self::ids( 'a.n IN (5)' ) );
		$this->assertSame( [ 3 ], self::ids( 'a.n NOT IN (5)' ) );
		$this->assertSame( [ 2 ], self::ids( 'a.n IS NULL' ) );
		$this->assertSame( [], self::ids( 'NOT (a.n >= 0)' ) );
	}

	public function test_like_escapes_and_ignores_case(): void {
		$this->assertSame( [ 1, 2 ], self::ids( "a.name LIKE 'blue_shirt'" ) );
		$this->assertSame( [ 1 ], self::ids( "a.name LIKE 'blue\\\\_shirt'" ) );
		$this->assertSame( [ 3 ], self::ids( "a.name LIKE '%\\\\%%'" ) );
	}

	public function test_null_sorts_first_ascending_and_last_descending(): void {
		$this->assertSame( [ 2, 1, 3 ], self::ids( 'a.id > 0', 'a.n ASC, a.id ASC' ) );
		$this->assertSame( [ 3, 1, 2 ], self::ids( 'a.id > 0', 'a.n DESC, a.id DESC' ) );
	}

	public function test_correlated_sub_queries_counts_and_joins(): void {
		$this->assertSame( [ 1 ], self::ids( "EXISTS (SELECT 1 FROM `wp_b` AS b WHERE b.a_id = a.id AND b.tag IN ('y'))" ) );
		$this->assertSame( [ 1 ], self::ids( '(SELECT COUNT(DISTINCT b.tag) FROM `wp_b` AS b WHERE b.a_id = a.id) = 2' ) );
		$this->assertSame( '3', self::db()->value( 'SELECT COUNT(*) FROM `wp_a` AS a LEFT JOIN `wp_b` AS b ON b.a_id = a.id WHERE b.tag IS NULL OR b.tag = \'x\' LIMIT 1' ) );
		$this->assertSame(
			[
				[
					'a_id' => '1',
					'tags' => '2',
				],
			],
			self::db()->rows( 'SELECT a_id, COUNT(*) AS tags FROM `wp_b` WHERE a_id IN (1, 2) GROUP BY a_id LIMIT 5' )
		);
	}

	public function test_concat_joins_text_and_a_column_and_null_makes_it_null(): void {
		// T92: the Off filter matches a meta value by the prefix CONCAT( '{"id":', p.ID, ',%' ).
		$this->assertSame( [ 1 ], self::ids( "CONCAT('Blue', '_', 'Shirt') = a.name" ) );
		// As a LIKE pattern the joined text keeps its wildcards: '_' is any one character.
		$this->assertSame( [ 1, 2 ], self::ids( "a.name LIKE CONCAT('Blue', '_', 'Shirt')" ) );
		$this->assertSame( [ 3 ], self::ids( "'id10' = CONCAT('id', a.n)" ) );
		$this->assertSame( [], self::ids( "CONCAT('x', a.n) IS NULL AND a.id = 1" ) );
		$this->assertSame( [ 2 ], self::ids( "CONCAT('x', a.n) IS NULL" ) );
	}

	public function test_an_optimiser_hint_changes_nothing(): void {
		$plain = 'SELECT COUNT(*) FROM `wp_a` AS a LEFT JOIN `wp_b` AS b ON b.a_id = a.id WHERE b.tag IS NULL OR b.tag = \'x\' LIMIT 1';
		$this->assertSame( self::db()->value( $plain ), self::db()->value( str_replace( 'SELECT ', 'SELECT /*+ JOIN_PREFIX(a) */ ', $plain ) ) );
		try {
			self::db()->value( 'SELECT /*+ JOIN_PREFIX(a) COUNT(*) FROM `wp_a` AS a LIMIT 1' );
			$this->fail( 'An unclosed hint was accepted.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'Unclosed optimiser hint', $e->getMessage() );
		}
	}

	public function test_between_nullif_cast_and_ordering_by_an_alias(): void {
		$this->assertSame( [ 1, 3 ], self::ids( 'a.n BETWEEN 5 AND 10' ) );
		$this->assertSame( [ 1 ], self::ids( 'a.n BETWEEN 1 AND 7' ) );
		$this->assertSame( [], self::ids( 'NULLIF(a.n, 5) = 5' ) );
		$this->assertSame( [ 3 ], self::ids( 'NULLIF(a.n, 5) IS NOT NULL' ) );
		$this->assertSame( [ 2, 3 ], self::ids( 'NULLIF(a.n, 10) IS NULL' ) );
		$this->assertSame( [ 3 ], self::ids( 'CAST(a.name AS DECIMAL(19, 4)) = 50' ), 'The leading number of a text.' );
		$this->assertSame( [ 1, 2 ], self::ids( 'CAST(a.name AS DECIMAL(19, 4)) = 0' ), 'No leading number reads 0.' );
		$this->assertSame( [ 2 ], self::ids( "CAST(NULLIF(a.name, 'Bluexshirt') AS DECIMAL(19, 4)) IS NULL" ) );
		$this->assertSame( '2026-09-30 08:00:00', self::db()->value( "SELECT DATE_SUB('2026-09-30 10:00:00', INTERVAL 7200 SECOND) AS d FROM `wp_a` AS a LIMIT 1" ) );
		$this->assertSame( '2026-09-30 12:00:00', self::db()->value( "SELECT DATE_SUB('2026-09-30 10:00:00', INTERVAL -7200 SECOND) AS d FROM `wp_a` AS a LIMIT 1" ) );
		$rows = self::db()->rows( 'SELECT a.id AS id, (CASE WHEN a.n IS NULL THEN 7 ELSE a.n END) AS k FROM `wp_a` AS a ORDER BY k DESC, a.id ASC LIMIT 10' );
		$this->assertSame( [ '3', '2', '1' ], array_column( $rows, 'id' ), 'An alias orders by its column.' );
	}

	public function test_anything_outside_the_dialect_throws(): void {
		foreach ( [ 'SELECT a.id FROM `wp_a` AS a WHERE a.id + 1 = 2', 'SELECT a.id FROM `wp_missing` AS a', 'SELECT a.nope AS x FROM `wp_a` AS a' ] as $sql ) {
			try {
				self::db()->rows( $sql );
				$this->fail( "Accepted: $sql" );
			} catch ( \LogicException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
