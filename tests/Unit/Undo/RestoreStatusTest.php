<?php
/**
 * T90: the internal undo step for a status and its Off record (SPEC §9.4),
 * written through the writer as an undo item: the status only from the
 * journal (a scheduled one as publish, for WordPress's date rule), the
 * journalled record verbatim, and the status-only rule for a product that
 * now carries a record.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class RestoreStatusTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_post' => true ];
	}

	private static function off( int $id, string $status, int $batch = 7 ): string {
		return OffRecord::create( $id, $status, '2026-09-01T08:00:00Z', $batch )->to_json();
	}

	/**
	 * Register a product, with its own Off record when given.
	 */
	private static function product( int $id, string $status, string $record = '', string $type = 'simple' ): SavedProduct {
		$product = new SavedProduct(
			$type,
			[
				'id'        => $id,
				'status'    => $status,
				'parent_id' => 'variation' === $type ? 1 : 0,
			]
		);
		if ( '' !== $record ) {
			$product->meta[ OffRecord::META_KEY ] = $record;
		}
		$GLOBALS['bulkcat_test']['products'][ $id ] = $product;
		return $product;
	}

	/**
	 * Write the internal step as an undo item does.
	 *
	 * @param array<string, mixed> $args value and/or record.
	 */
	private function undo( int $id, array $args ): WriteOutcome {
		$registry = new Registry( new Fields() );
		$list     = $registry->validate_list(
			[
				[
					'action' => 'restore_status',
					'args'   => [ 'field' => 'status' ] + $args + [ 'apply_to_variations' => false ],
				],
			],
			true
		);
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return ( new Writer( $registry ) )->write( $id, json_decode( (string) wp_json_encode( $list ), true ) );
	}

	/**
	 * Results as [ field, state, old, new ].
	 *
	 * @return list<array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	private static function summary( WriteOutcome $outcome ): array {
		return array_map( static fn( Result $r ): array => [ $r->field(), $r->state(), $r->old(), $r->new() ], $outcome->results() );
	}

	public function test_undoing_an_off_puts_the_status_back_and_removes_the_record(): void {
		$product = self::product( 71, 'draft', self::off( 71, 'private', 12 ) );
		$outcome = $this->undo(
			71,
			[
				'value'  => 'private',
				'record' => '',
			]
		);
		$this->assertSame( [ [ 'status', Result::CHANGED, 'draft', 'private' ], [ 'availability_off', Result::CHANGED, self::off( 71, 'private', 12 ), '' ] ], self::summary( $outcome ) );
		$this->assertCount( 1, $product->saves );
		$this->assertSame( 'private', $product->saves[0]['status'] );
		$this->assertArrayNotHasKey( OffRecord::META_KEY, $product->saved_meta[0] );
	}

	public function test_a_scheduled_status_is_written_as_publish_for_wordpress_to_schedule(): void {
		$product                                      = self::product( 72, 'draft', self::off( 72, 'future', 12 ) );
		$GLOBALS['bulkcat_test']['stored_status'][72] = 'future';
		$outcome                                      = $this->undo(
			72,
			[
				'value'  => 'future',
				'record' => '',
			]
		);
		$this->assertSame( 'publish', $product->saves[0]['status'], 'The manager never writes future.' );
		$this->assertSame( 'future', $product->props['status'], 'WordPress kept it scheduled; the writer read that back.' );
		$this->assertSame( [ 'restores_scheduled', [ 'restores' => 'future' ] ], [ $outcome->results()[0]->note(), $outcome->results()[0]->context() ] );
	}

	public function test_undoing_an_on_puts_draft_and_the_original_record_back_byte_for_byte(): void {
		$original = self::off( 73, 'private', 7 );
		$product  = self::product( 73, 'private' );
		$outcome  = $this->undo(
			73,
			[
				'value'  => 'draft',
				'record' => $original,
			]
		);
		$this->assertSame( [ [ 'status', Result::CHANGED, 'private', 'draft' ], [ 'availability_off', Result::CHANGED, '', $original ] ], self::summary( $outcome ) );
		$this->assertSame( 'draft', $product->saves[0]['status'] );
		$this->assertSame( $original, $product->saved_meta[0][ OffRecord::META_KEY ], 'Its id, status, time and batch unchanged: not a new Off.' );
	}

	public function test_a_record_only_undo_leaves_the_status(): void {
		$original = self::off( 74, 'publish', 7 );
		$product  = self::product( 74, 'pending' );
		$outcome  = $this->undo( 74, [ 'record' => $original ] );
		$this->assertSame( [ [ 'status', Result::ALREADY, 'pending', 'pending' ], [ 'availability_off', Result::CHANGED, '', $original ] ], self::summary( $outcome ) );
		$this->assertSame( 'pending', $product->saves[0]['status'] );
		$this->assertSame( $original, $product->saved_meta[0][ OffRecord::META_KEY ] );
	}

	public function test_a_status_only_undo_clears_a_record_unless_it_restores_draft(): void {
		// Ruling 2: a status chosen by undoing is an ordinary status, as Pending or Private is.
		$cleared = self::product( 75, 'draft', self::off( 75, 'private', 20 ) );
		$outcome = $this->undo( 75, [ 'value' => 'publish' ] );
		$this->assertSame( [ [ 'status', Result::CHANGED, 'draft', 'publish' ], [ 'availability_off', Result::CHANGED, self::off( 75, 'private', 20 ), '' ] ], self::summary( $outcome ) );
		$this->assertSame( 'publish', $cleared->saves[0]['status'], 'Never the saved Private: the status comes from the journal.' );
		$this->assertArrayNotHasKey( OffRecord::META_KEY, $cleared->saved_meta[0] );

		$kept    = self::product( 76, 'pending', self::off( 76, 'publish', 20 ) );
		$outcome = $this->undo( 76, [ 'value' => 'draft' ] );
		$this->assertSame( [ [ 'status', Result::CHANGED, 'pending', 'draft' ] ], self::summary( $outcome ) );
		$this->assertSame( self::off( 76, 'publish', 20 ), $kept->saved_meta[0][ OffRecord::META_KEY ], 'Restored to draft: the product is Off again with its record.' );
	}

	public function test_a_variation_status_undoes_with_no_record(): void {
		$variation = self::product( 77, 'publish', '', 'variation' );
		$outcome   = $this->undo( 77, [ 'value' => 'private' ] );
		$this->assertSame( [ [ 'status', Result::CHANGED, 'publish', 'private' ] ], self::summary( $outcome ) );
		$this->assertSame( [], $variation->meta_calls );
	}

	public function test_a_record_naming_another_product_is_refused_when_written(): void {
		$product = self::product( 78, 'draft' );
		$outcome = $this->undo( 78, [ 'record' => self::off( 99, 'publish' ) ] );
		$this->assertSame( Result::FAILED, $outcome->results()[1]->state() );
		$this->assertSame( [], $product->saves );
		$this->assertSame( [], $product->meta_calls );
	}
}
