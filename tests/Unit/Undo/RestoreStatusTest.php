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
		$GLOBALS['bulkcat_test']['caps'] = [
			'edit_post'        => true,
			'publish_products' => true,
		];
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

	public function test_the_undo_expects_the_status_and_record_it_was_planned_against(): void {
		// Should-fix (1): the planner takes these when it plans the undo (at preview and at Apply).
		$fields  = new Fields();
		$status  = $fields->get( 'status' );
		$product = self::product( 80, 'draft', self::off( 80, 'publish', 12 ) );
		$this->assertSame(
			[
				'expected_status' => 'draft',
				'expected_record' => self::off( 80, 'publish', 12 ),
			],
			\BulkCatalogueManager\Operations\RestoreStatus::expecting( $product, $status )
		);
		$variation = self::product( 83, 'publish', '', 'variation' );
		$this->assertSame(
			[
				'expected_status' => 'publish',
				'expected_record' => '',
			],
			\BulkCatalogueManager\Operations\RestoreStatus::expecting( $variation, $status )
		);
	}

	public function test_a_change_after_the_undo_was_applied_leaves_the_item_alone_when_it_is_written(): void {
		// Should-fix (1): the plan's changed-since guard held when the undo was applied; a change that
		// lands before its chunk runs is caught when the item is written: skipped, not written, not failed.
		$sentence = 'This product was changed since this batch ran, so it is left alone unless you choose Revert anyway.';
		$cases    = [
			'switched Off again by a newer batch' => [ 81, 'draft', self::off( 81, 'publish', 30 ) ],
			'status changed outside the screen'   => [ 82, 'private', self::off( 82, 'publish', 12 ) ],
			'record removed'                      => [ 84, 'draft', '' ],
		];
		foreach ( $cases as $case => [ $id, $status, $record ] ) {
			$product = self::product( $id, $status, $record );
			$outcome = $this->undo(
				$id,
				[
					'value'           => 'publish',
					'record'          => '',
					'expected_status' => 'draft',
					'expected_record' => self::off( $id, 'publish', 12 ),
				]
			);
			$this->assertSame( [ [ 'status', Result::SKIPPED, $status, $status ] ], self::summary( $outcome ), $case );
			$this->assertSame( \BulkCatalogueManager\Operations\RestoreStatus::CHANGED_SINCE, $outcome->results()[0]->code(), $case );
			$this->assertSame( $sentence, $outcome->results()[0]->reason(), $case );
			$this->assertFalse( $outcome->saved(), $case );
			$this->assertSame( [], $product->saves, $case . ': nothing written.' );
			$this->assertSame( [], $product->meta_calls, $case . ': the record untouched.' );
			$this->assertSame( [ 'skipped', $sentence ], \BulkCatalogueManager\Engine\ChunkRunner::item_state( $outcome ), $case . ': skipped, not failed.' );
		}

		$variation = self::product( 85, 'publish', '', 'variation' );
		$outcome   = $this->undo(
			85,
			[
				'value'           => 'publish',
				'expected_status' => 'private',
				'expected_record' => '',
			]
		);
		$this->assertSame( Result::SKIPPED, $outcome->results()[0]->state() );
		$this->assertSame( 'This variation was changed since this batch ran, so it is left alone unless you choose Revert anyway.', $outcome->results()[0]->reason() );
		$this->assertSame( [], $variation->saves );
	}

	public function test_an_item_unchanged_since_the_undo_was_applied_is_written_as_planned(): void {
		$product = self::product( 86, 'draft', self::off( 86, 'private', 12 ) );
		$outcome = $this->undo(
			86,
			[
				'value'           => 'private',
				'record'          => '',
				'expected_status' => 'draft',
				'expected_record' => self::off( 86, 'private', 12 ),
			]
		);
		$this->assertSame( [ [ 'status', Result::CHANGED, 'draft', 'private' ], [ 'availability_off', Result::CHANGED, self::off( 86, 'private', 12 ), '' ] ], self::summary( $outcome ) );
		$this->assertSame( 'private', $product->saves[0]['status'] );
	}

	public function test_an_unreadable_expected_record_is_refused_when_planned(): void {
		$list = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'restore_status',
					'args'   => [
						'field'               => 'status',
						'value'               => 'publish',
						'expected_status'     => 'draft',
						'expected_record'     => '{"id":1,"status":"trash"}',
						'apply_to_variations' => false,
					],
				],
			],
			true
		);
		$this->assertInstanceOf( \WP_Error::class, $list );
	}
	public function test_internal_restore_cannot_borrow_publication_rights_even_without_conflict_guards(): void {
		foreach ( [ null, 23, 0 ] as $actor ) {
			foreach ( [ false, true ] as $guarded ) {
				$this->setUp();
				$p                                        = self::product( 501, 'draft', self::off( 501, 'publish' ) );
				$p->props['post_password']                = 'current opaque password';
				$GLOBALS['bulkcat_test']['user_caps'][23] = [
					'edit_post'        => true,
					'publish_products' => false,
				];
				if ( null === $actor ) {
					$GLOBALS['bulkcat_test']['caps']['publish_products'] = false;
				}
				$actions = new Registry( new Fields() );
				$args    = [
					'field'    => 'status',
					'value'    => 'publish',
					'record'   => '',
					'password' => 'protected preimage',
				];
				if ( $guarded ) {
					$args += [
						'expected_status'   => 'draft',
						'expected_password' => $p->props['post_password'],
					];
				}
				$steps = $actions->validate_list(
					[
						[
							'action' => 'restore_status',
							'args'   => $args,
						],
					],
					true
				);
				$this->assertIsArray( $steps );
				$out = ( new Writer( $actions, null, $actor ) )->write( 501, $steps );
				$this->assertSame( Result::SKIPPED, $out->results()[0]->state() );
				$this->assertSame( [], $p->sets );
				$this->assertSame( [], $p->saves );
				$this->assertSame( 'current opaque password', $p->get_post_password( 'edit' ) );
				$this->assertSame( self::off( 501, 'publish' ), OffRecord::raw( $p ) );
			}
		}
	}
}
