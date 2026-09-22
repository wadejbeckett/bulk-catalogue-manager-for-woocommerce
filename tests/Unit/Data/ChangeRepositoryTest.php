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
}
