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

	public function test_anything_outside_the_dialect_throws(): void {
		foreach ( [ 'SELECT a.id FROM `wp_a` AS a WHERE a.id BETWEEN 1 AND 2', 'SELECT a.id FROM `wp_missing` AS a', 'SELECT a.nope AS x FROM `wp_a` AS a' ] as $sql ) {
			try {
				self::db()->rows( $sql );
				$this->fail( "Accepted: $sql" );
			} catch ( \LogicException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
