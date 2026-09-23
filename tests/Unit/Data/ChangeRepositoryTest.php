<?php
/**
 * The change journal's statements against the recording $wpdb, and values
 * that come back exactly as they were written (SPEC §9.2, §9.3, §5.4, §13).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\ChangeRepository;
use PHPUnit\Framework\TestCase;

final class ChangeRepositoryTest extends TestCase {
	private const MODIFIED = '2026-09-22 08:15:00';

	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function db(): \BulkCatalogueManager\Tests\Support\WpdbStub {
		return $GLOBALS['wpdb'];
	}

	public function test_append_inserts_one_encoded_row(): void {
		self::db()->insert_id = 99;
		$id                   = ( new ChangeRepository() )->append( 6, 101, 'product', 'sale_price', '0.00', '', self::MODIFIED, 'applied' );

		$this->assertSame( 100, $id );
		[ $method, $table, $data, $format ] = self::db()->writes[0];
		$this->assertSame( [ 'insert', 'wp_bcm_changes' ], [ $method, $table ] );
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['applied_gmt'] ) );
		unset( $data['applied_gmt'] );
		$this->assertSame(
			[
				'batch_id'     => 6,
				'object_id'    => 101,
				'object_type'  => 'product',
				'prop'         => 'sale_price',
				'old_value'    => '"0.00"',
				'new_value'    => '""',
				'modified_gmt' => self::MODIFIED,
				'result'       => 'applied',
				'message'      => '',
			],
			$data
		);
		$this->assertSame( [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ], $format );
		$this->assertCount( 10, self::db()->writes[0][2] );
	}

	public function test_the_message_is_cut_to_the_column_by_characters(): void {
		( new ChangeRepository() )->append( 6, 101, 'variation', 'stock_quantity', 3, -1, self::MODIFIED, 'skipped', str_repeat( 'é', 300 ) );
		$this->assertSame( str_repeat( 'é', 255 ), self::db()->writes[0][2]['message'] );
	}

	public function test_an_unknown_modified_time_is_stored_as_the_zero_date(): void {
		( new ChangeRepository() )->append( 6, 404, 'product', 'regular_price', null, null, ChangeRepository::UNKNOWN_TIME, 'failed', 'Not found.' );
		$this->assertSame( '0000-00-00 00:00:00', self::db()->writes[0][2]['modified_gmt'] );
	}

	public function test_append_refuses_bad_input_before_writing(): void {
		$repo = new ChangeRepository();
		$bad  = [
			'zero batch'     => [ 0, 101, 'product', 'name', self::MODIFIED, 'applied' ],
			'zero object'    => [ 6, 0, 'product', 'name', self::MODIFIED, 'applied' ],
			'unknown type'   => [ 6, 101, 'order', 'name', self::MODIFIED, 'applied' ],
			'raw meta key'   => [ 6, 101, 'product', '_price', self::MODIFIED, 'applied' ],
			'long prop'      => [ 6, 101, 'product', str_repeat( 'a', 65 ), self::MODIFIED, 'applied' ],
			'unknown result' => [ 6, 101, 'product', 'name', self::MODIFIED, 'done' ],
			'bad time'       => [ 6, 101, 'product', 'name', '2026-02-30 10:00:00', 'applied' ],
			'no seconds'     => [ 6, 101, 'product', 'name', '2026-09-22 08:15', 'applied' ],
		];
		foreach ( $bad as $label => [ $batch, $object, $type, $prop, $modified, $result ] ) {
			try {
				$repo->append( $batch, $object, $type, $prop, 'a', 'b', $modified, $result );
				$this->fail( "$label was accepted." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( [], self::db()->writes, $label );
			}
		}
		try {
			$repo->append( 6, 101, 'product', 'name', new \stdClass(), 'b', self::MODIFIED, 'applied' );
			$this->fail( 'An object was journalled.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [], self::db()->writes );
		}
	}

	public function test_a_failed_insert_is_an_error(): void {
		self::db()->failing = [ 'insert' ];
		try {
			( new ChangeRepository() )->append( 6, 101, 'product', 'name', 'a', 'b', self::MODIFIED, 'applied' );
			$this->fail( 'A failed insert returned an id.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The change could not be journalled.', $e->getMessage() );
		}
	}

	public function test_values_written_come_back_as_themselves(): void {
		$repo   = new ChangeRepository();
		$values = [ null, '', 0, '0', '0.00', false, 12.0, [ 3, 7 ] ];
		foreach ( $values as $i => $old ) {
			$repo->append( 6, 100 + $i, 'product', 'prop_' . $i, $old, $values[ ( $i + 1 ) % count( $values ) ], self::MODIFIED, 'applied' );
		}
		// Feed the written rows back as MySQL returns them: every column a string.
		$rows = [];
		foreach ( self::db()->writes as $i => [ , , $data ] ) {
			$rows[] = array_map( 'strval', [ 'change_id' => $i + 1 ] + $data );
		}
		self::db()->responses['/^SELECT \* FROM `wp_bcm_changes`/'] = $rows;

		$read = $repo->for_batch( 6 );

		$this->assertCount( count( $values ), $read );
		foreach ( $values as $i => $old ) {
			$this->assertSame( $old, $read[ $i ]['old_value'], "old_value $i" );
			$this->assertSame( $values[ ( $i + 1 ) % count( $values ) ], $read[ $i ]['new_value'], "new_value $i" );
			$this->assertSame( $i + 1, $read[ $i ]['change_id'] );
			$this->assertSame( 100 + $i, $read[ $i ]['object_id'] );
			$this->assertSame( 6, $read[ $i ]['batch_id'] );
			$this->assertSame( 'prop_' . $i, $read[ $i ]['prop'] );
			$this->assertSame( self::MODIFIED, $read[ $i ]['modified_gmt'] );
		}
	}

	public function test_for_batch_pages_forward_and_backward_by_change_id(): void {
		$repo = new ChangeRepository();
		$repo->for_batch( 6 );
		$repo->for_batch( 6, 250, 5000 );
		$repo->for_batch( 6, 0, 0, true );
		$repo->for_batch( 6, 250, 20, true );

		$this->assertSame(
			[
				'SELECT * FROM `wp_bcm_changes` WHERE batch_id = 6 AND change_id > 0 ORDER BY change_id ASC LIMIT 100',
				'SELECT * FROM `wp_bcm_changes` WHERE batch_id = 6 AND change_id > 250 ORDER BY change_id ASC LIMIT 500',
				'SELECT * FROM `wp_bcm_changes` WHERE batch_id = 6 AND change_id < ' . PHP_INT_MAX . ' ORDER BY change_id DESC LIMIT 1',
				'SELECT * FROM `wp_bcm_changes` WHERE batch_id = 6 AND change_id < 250 ORDER BY change_id DESC LIMIT 20',
			],
			self::db()->queries
		);
		$this->assertSame( [ 'SELECT * FROM %i WHERE batch_id = %d AND change_id > %d ORDER BY change_id ASC LIMIT %d', [ 'wp_bcm_changes', 6, 0, 100 ] ], self::db()->prepared[0] );
		$this->assert_all_prepared();
	}

	public function test_has_object_and_counts(): void {
		self::db()->responses['SELECT change_id FROM `wp_bcm_changes` WHERE batch_id = 6 AND object_id = 101 LIMIT 1'] = '55';
		self::db()->responses['SELECT COUNT(*) FROM `wp_bcm_changes` WHERE batch_id = 6']                              = '12';
		self::db()->responses["SELECT COUNT(*) FROM `wp_bcm_changes` WHERE batch_id = 6 AND result = 'failed'"]        = '2';
		$repo = new ChangeRepository();

		$this->assertTrue( $repo->has_object( 6, 101 ) );
		$this->assertFalse( $repo->has_object( 6, 102 ) );
		$this->assertSame( 12, $repo->count_for_batch( 6 ) );
		$this->assertSame( 2, $repo->count_for_batch( 6, 'failed' ) );
		$this->assertSame( 0, $repo->count_for_batch( 7, 'applied' ) );
		$this->assertSame( [ 'wp_bcm_changes', 6, 'failed' ], self::db()->prepared[3][1] );
		$this->assert_all_prepared();

		try {
			$repo->count_for_batch( 6, 'done' );
			$this->fail( 'An unknown result was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertCount( 5, self::db()->queries );
		}
	}

	public function test_the_feed_pages_counts_and_groups_reasons(): void {
		global $wpdb;
		$repo = new ChangeRepository();
		$this->assertSame( [], $repo->page( 9, 3, 25, [ 'failed', 'skipped', 'failed' ] ) );
		$this->assertSame( "SELECT change_id, batch_id, object_id, object_type, prop, old_value, new_value, modified_gmt, applied_gmt, result, message FROM `wp_bcm_changes` WHERE batch_id = 9 AND result IN ('failed', 'skipped') ORDER BY change_id ASC LIMIT 25 OFFSET 50", end( $wpdb->queries ) );
		$repo->page( 9, 1, 5000 );
		$this->assertStringEndsWith( 'WHERE batch_id = 9 ORDER BY change_id ASC LIMIT ' . ChangeRepository::MAX_PAGE . ' OFFSET 0', end( $wpdb->queries ) );

		$wpdb->responses = [
			"SELECT COUNT(*) FROM `wp_bcm_changes` WHERE batch_id = 9 AND result IN ('failed')" => '4',
			"/^SELECT message, COUNT\\(DISTINCT object_id\\) AS objects FROM `wp_bcm_changes` WHERE batch_id = 9 AND result IN \\('failed', 'skipped'\\) AND message <> '' GROUP BY message ORDER BY objects DESC LIMIT 20$/" => [
				[
					'message' => 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.',
					'objects' => '2',
				],
			],
		];
		$this->assertSame( 4, $repo->page_count( 9, [ 'failed' ] ) );
		$this->assertSame(
			[
				[
					'message' => 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.',
					'objects' => 2,
				],
			],
			$repo->reasons( 9 )
		);
		foreach ( $wpdb->prepared as [ $template ] ) {
			$this->assertStringContainsString( '%i', $template );
			$this->assertStringNotContainsString( 'wp_', $template );
		}

		foreach ( [ [], [ 'applied', 'lost' ] ] as $bad ) {
			try {
				$repo->page( 9, 1, 10, $bad );
				$this->fail( 'Accepted ' . wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'applied, skipped and failed', $e->getMessage() );
			}
		}
	}

	public function test_the_site_prefix_is_used(): void {
		self::db()->prefix = 'wp_3_';
		( new ChangeRepository() )->append( 6, 101, 'term', 'name', 'Shoes', 'Footwear', self::MODIFIED, 'applied' );
		( new ChangeRepository() )->has_object( 6, 101 );
		$this->assertSame( 'wp_3_bcm_changes', self::db()->writes[0][1] );
		$this->assertStringContainsString( 'FROM `wp_3_bcm_changes`', self::db()->queries[0] );
	}

	/**
	 * Every executed statement came from a prepare() naming its table only through %i.
	 */
	private function assert_all_prepared(): void {
		$this->assertNotEmpty( self::db()->prepared );
		foreach ( self::db()->prepared as [ $template ] ) {
			$this->assertStringContainsString( ' %i ', $template );
			$this->assertStringNotContainsString( 'wp_', $template );
			$this->assertStringNotContainsString( 'bcm_', $template );
		}
		$this->assertCount( count( self::db()->prepared ), self::db()->queries, 'One prepare() per statement.' );
	}

	public function test_the_audit_log_joins_the_batch_for_its_user_and_filters(): void {
		global $wpdb;
		$repo            = new ChangeRepository();
		$filters         = [
			'object_id' => 1001,
			'prop'      => 'regular_price',
			'user_id'   => 4,
			'after'     => '2026-09-01 00:00:00',
			'before'    => '2026-10-01 00:00:00',
		];
		$wpdb->responses = [
			'/^SELECT c\.change_id/' => [
				[
					'change_id'    => '5',
					'batch_id'     => '2',
					'object_id'    => '1001',
					'object_type'  => 'product',
					'prop'         => 'regular_price',
					'old_value'    => '"10"',
					'new_value'    => '"11"',
					'modified_gmt' => self::MODIFIED,
					'applied_gmt'  => self::MODIFIED,
					'result'       => 'applied',
					'message'      => '',
					'user_id'      => '4',
				],
			],
			'/^SELECT COUNT/'        => '41',
		];
		$rows            = $repo->audit( $filters, 3, 20 );
		$this->assertSame( "SELECT c.change_id, c.batch_id, c.object_id, c.object_type, c.prop, c.old_value, c.new_value, c.modified_gmt, c.applied_gmt, c.result, c.message, b.user_id FROM `wp_bcm_changes` AS c INNER JOIN `wp_bcm_batches` AS b ON b.batch_id = c.batch_id WHERE c.change_id > 0 AND c.object_id = 1001 AND b.user_id = 4 AND c.prop = 'regular_price' AND c.applied_gmt >= '2026-09-01 00:00:00' AND c.applied_gmt < '2026-10-01 00:00:00' ORDER BY c.change_id DESC LIMIT 20 OFFSET 40", end( $wpdb->queries ) );
		$this->assertSame( [ 5, 2, '10', '11', 4 ], [ $rows[0]['change_id'], $rows[0]['batch_id'], $rows[0]['old_value'], $rows[0]['new_value'], $rows[0]['user_id'] ] );
		$this->assertSame( 41, $repo->audit_count( [] ) );
		$this->assertSame( 'SELECT COUNT(*) FROM `wp_bcm_changes` AS c INNER JOIN `wp_bcm_batches` AS b ON b.batch_id = c.batch_id WHERE c.change_id > 0', end( $wpdb->queries ) );
		$repo->audit( [], 1, 5000 );
		$this->assertStringEndsWith( 'LIMIT ' . ChangeRepository::MAX_PAGE . ' OFFSET 0', end( $wpdb->queries ) );

		$repo->applied_counts( [ 3, 1, 3 ] );
		$this->assertSame( "SELECT batch_id, COUNT(*) AS applied FROM `wp_bcm_changes` WHERE batch_id IN (3, 1) AND result = 'applied' GROUP BY batch_id", end( $wpdb->queries ) );
		$count = count( $wpdb->queries );
		$this->assertSame( [], $repo->applied_counts( [] ) );
		$this->assertCount( $count, $wpdb->queries );

		foreach ( [ [ 'orderby' => 'x' ], [ 'object_id' => 0 ], [ 'object_id' => '5' ], [ 'user_id' => -1 ], [ 'prop' => '_price' ], [ 'prop' => "x' OR 1" ], [ 'after' => '2026-09-01' ], [ 'before' => "1' OR 1=1" ] ] as $bad ) {
			try {
				$repo->audit( $bad, 1, 10 );
				$this->fail( 'Accepted ' . wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		try {
			$repo->applied_counts( [ 0 ] );
			$this->fail( 'Accepted a zero id.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		foreach ( $wpdb->prepared as [ $template ] ) {
			$this->assertStringContainsString( '%i', $template );
		}
	}
}
