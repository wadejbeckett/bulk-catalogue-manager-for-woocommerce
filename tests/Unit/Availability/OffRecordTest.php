<?php
/**
 * T88: the Off record (SPEC §5.5) — canonical JSON bound to its product,
 * everything else read as no record, written without saving.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Availability;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class OffRecordTest extends TestCase {
	private const JSON = '{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":418}';

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function product( int $id, mixed $meta = null ): SavedProduct {
		$product = new SavedProduct( 'simple', [ 'id' => $id ] );
		if ( null !== $meta ) {
			$product->meta[ OffRecord::META_KEY ] = $meta;
		}
		return $product;
	}

	public function test_the_key_is_protected_meta_named_once(): void {
		$this->assertSame( '_bulkcat_availability_off', OffRecord::META_KEY );
		$this->assertSame( [ 'publish', 'future', 'pending', 'private' ], OffRecord::STATUSES );
	}

	public function test_canonical_json_keeps_its_key_order_and_unescaped_slashes(): void {
		$record = OffRecord::create( 123, 'publish', '2026-09-28T14:03:11Z', 418 );
		$this->assertSame( self::JSON, $record->to_json() );
		$this->assertSame( 123, $record->id );
		$this->assertSame( 'publish', $record->status );
		$this->assertSame( '2026-09-28T14:03:11Z', $record->at );
		$this->assertSame( 418, $record->batch );
		$this->assertStringNotContainsString( '\\/', $record->to_json() );
	}

	public function test_a_record_round_trips_through_the_product(): void {
		foreach ( OffRecord::STATUSES as $status ) {
			$json    = OffRecord::create( 7, $status, '2026-01-02T03:04:05Z', 9 )->to_json();
			$product = self::product( 7, $json );
			$record  = OffRecord::for_product( $product );
			$this->assertTrue( null !== $record, $status );
			$this->assertSame( $status, $record->status );
			$this->assertSame( $json, OffRecord::raw( $product ) );
			$this->assertSame( $json, OffRecord::parse( $json, 7 )->to_json() );
		}
	}

	public function test_a_record_naming_another_product_is_no_record(): void {
		// D8: WooCommerce's Duplicate (or an import) copied the meta onto product 124.
		$copy = self::product( 124, self::JSON );
		$this->assertTrue( null === OffRecord::for_product( $copy ) );
		$this->assertSame( '', OffRecord::raw( $copy ) );
		$this->assertSame( self::JSON, OffRecord::stored( $copy ), 'The stored value is still there, only not a record.' );
		$this->assertTrue( null === OffRecord::parse( self::JSON, 124 ) );
		$this->assertSame( 123, OffRecord::read( self::JSON )->id, 'read() names whichever product the value names.' );
	}

	public function test_anything_outside_the_shape_is_unreadable(): void {
		$unreadable = [
			'missing'          => null,
			'empty'            => '',
			'not json'         => '{"id":123,',
			'array'            => [ 'id' => 123 ],
			'a list'           => '[123,"publish","2026-09-28T14:03:11Z",418]',
			'draft'            => '{"id":123,"status":"draft","at":"2026-09-28T14:03:11Z","batch":418}',
			'trash'            => '{"id":123,"status":"trash","at":"2026-09-28T14:03:11Z","batch":418}',
			'an extra key'     => '{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":418,"variations":[]}',
			'a missing key'    => '{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z"}',
			'another order'    => '{"status":"publish","id":123,"at":"2026-09-28T14:03:11Z","batch":418}',
			'spaces'           => '{"id": 123, "status": "publish", "at": "2026-09-28T14:03:11Z", "batch": 418}',
			'id as text'       => '{"id":"123","status":"publish","at":"2026-09-28T14:03:11Z","batch":418}',
			'batch as text'    => '{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":"418"}',
			'negative batch'   => '{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":-1}',
			'local time'       => '{"id":123,"status":"publish","at":"2026-09-28 14:03:11","batch":418}',
			'escaped slashes'  => '{"id":123,"status":"publish","at":"2026\/09\/28","batch":418}',
			'a trailing space' => self::JSON . ' ',
			'zero id'          => '{"id":0,"status":"publish","at":"2026-09-28T14:03:11Z","batch":418}',
		];
		foreach ( $unreadable as $case => $value ) {
			$product = self::product( 123, $value );
			$this->assertTrue( null === OffRecord::for_product( $product ), $case );
			$this->assertSame( '', OffRecord::raw( $product ), $case );
			$this->assertTrue( null === OffRecord::parse( $value, 123 ), $case );
		}
	}

	public function test_a_record_written_from_outside_is_bound_to_its_product_and_its_statuses(): void {
		// Should-fix (2): the leading underscore hides the key from the editor's Custom Fields box and
		// core's wp/v2 only; WooCommerce REST v3 returns it in meta_data and accepts it on write, and
		// WooCommerce's CSV exporter includes it with custom meta. The protection is the record itself.
		$from_rest = [
			'another product\'s id'             => '{"id":124,"status":"private","at":"2026-09-01T08:00:00Z","batch":1}',
			'a status outside the four'         => '{"id":123,"status":"trash","at":"2026-09-01T08:00:00Z","batch":1}',
			'a JSON object, stored as an array' => [
				'id'     => 123,
				'status' => 'private',
				'at'     => '2026-09-01T08:00:00Z',
				'batch'  => 1,
			],
		];
		foreach ( $from_rest as $case => $value ) {
			$product = self::product( 123, $value );
			$this->assertTrue( null === OffRecord::for_product( $product ), $case );
			$this->assertSame( '', OffRecord::raw( $product ), $case );
		}
		// A well-formed record for the product's own id is a record: On restores its status, which is
		// always one Status offers anyone who may edit the product (a scheduled one comes back through
		// Published and WordPress's date rule), so it grants no status the user could not set.
		$own = OffRecord::for_product( self::product( 123, '{"id":123,"status":"private","at":"2026-09-01T08:00:00Z","batch":1}' ) );
		$this->assertSame( 'private', $own->status );
		$offered = array_merge( ( new \BulkCatalogueManager\Fields\Registry() )->get( 'status' )->writable_choices(), [ 'future' ] );
		foreach ( OffRecord::STATUSES as $status ) {
			$this->assertContains( $status, $offered );
		}
	}

	public function test_create_refuses_what_a_record_cannot_hold(): void {
		foreach ( [ [ 0, 'publish', '2026-09-28T14:03:11Z', 1 ], [ 5, 'draft', '2026-09-28T14:03:11Z', 1 ], [ 5, 'publish', 'yesterday', 1 ], [ 5, 'publish', '2026-09-28T14:03:11Z', -3 ] ] as $args ) {
			try {
				OffRecord::create( ...$args );
				$this->fail( 'Accepted ' . json_encode( $args ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'Off record', $e->getMessage() );
			}
		}
	}

	public function test_write_sets_or_deletes_the_meta_and_never_saves(): void {
		$product = self::product( 123 );
		OffRecord::write( $product, self::JSON );
		$this->assertSame( self::JSON, $product->meta[ OffRecord::META_KEY ] );
		$this->assertSame( [ [ 'update', OffRecord::META_KEY, self::JSON ] ], $product->meta_calls );

		OffRecord::write( $product, '' );
		$this->assertFalse( isset( $product->meta[ OffRecord::META_KEY ] ) );
		$this->assertSame( [ 'delete', OffRecord::META_KEY, null ], $product->meta_calls[1] );
		$this->assertSame( [], $product->saves, 'The caller saves, once, with the status.' );
	}

	public function test_the_value_survives_the_slashing_of_stored_meta(): void {
		// add_metadata() unslashes what it stores; WooCommerce's data store slashes before it.
		$json = OffRecord::create( 99, 'private', '2026-12-31T23:59:59Z', 12345 )->to_json();
		$this->assertSame( $json, wp_unslash( $json ) );
		$this->assertSame( $json, wp_unslash( wp_slash( $json ) ) );
	}

	public function test_duplicate_leaves_the_record_out(): void {
		$this->assertSame( [ '_sku_copy', OffRecord::META_KEY ], OffRecord::exclude_from_duplicate( [ '_sku_copy' ] ) );
		$this->assertSame( [ OffRecord::META_KEY ], OffRecord::exclude_from_duplicate( [ OffRecord::META_KEY ] ) );
		$this->assertSame( [ OffRecord::META_KEY ], OffRecord::exclude_from_duplicate( null ) );
	}

	public function test_only_this_class_names_the_meta_key(): void {
		$root  = dirname( __DIR__, 3 );
		$files = (array) glob( $root . '/src/{,*/,*/*/}*.php', GLOB_BRACE );
		$found = [];
		foreach ( $files as $file ) {
			if ( str_contains( (string) file_get_contents( $file ), '_bulkcat_availability_off' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
				$found[] = substr( (string) $file, strlen( $root ) + 1 );
			}
		}
		$this->assertSame( [ 'src/Availability/OffRecord.php' ], $found );
	}
}
