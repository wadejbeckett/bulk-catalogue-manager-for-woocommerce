<?php
/**
 * T89: the Status switch decides every row of the SPEC §5.5 table, from the
 * fixture the grid's mirror runs too (tests/fixtures/status-switch.json).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Availability;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Availability\Outcome;
use BulkCatalogueManager\Availability\Stamp;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class StatusSwitchTest extends TestCase {
	/**
	 * A fixed clock: 2026-09-29 12:00:00 UTC.
	 */
	private const NOW = 1790683200;

	private const ID = 501;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_post' => true ];
	}

	/**
	 * The fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/status-switch.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a test fixture.
	}

	/**
	 * The product one fixture row describes.
	 *
	 * @param array<string, mixed> $row A fixture row.
	 */
	private static function product( array $row ): SavedProduct {
		$date = match ( $row['schedule'] ?? null ) {
			'ahead'  => self::NOW + 3 * 86400,
			'passed' => self::NOW - 3600,
			default  => self::NOW - 30 * 86400,
		};
		$product = new SavedProduct(
			'simple',
			[
				'id'           => self::ID,
				'status'       => $row['status'],
				'date_created' => new \DateTimeImmutable( '@' . $date ),
			]
		);
		$record  = $row['record'];
		if ( is_array( $record ) ) {
			$product->meta[ OffRecord::META_KEY ] = OffRecord::create( self::ID, $record['status'], '2026-09-01T08:00:00Z', 7 )->to_json();
		} elseif ( 'foreign' === $record ) {
			$product->meta[ OffRecord::META_KEY ] = OffRecord::create( self::ID + 1, 'private', '2026-09-01T08:00:00Z', 7 )->to_json();
		} elseif ( 'unreadable' === $record ) {
			$product->meta[ OffRecord::META_KEY ] = '{"id":' . self::ID . ',"status":"draft"}';
		}
		$GLOBALS['bulkcat_test']['products'][ self::ID ] = $product;
		return $product;
	}

	/**
	 * The set_value status step for a target.
	 *
	 * @param string $target The status chosen.
	 * @param bool   $keep   Keep current status.
	 * @return array<string, mixed>
	 */
	private static function step( string $target, bool $keep ): array {
		// Accepted stored work resolves SetValue directly, bypassing new-request aliases.
		$validation = ( new Registry( new Fields() ) )->get( 'set_value' )->validate(
			[
				'field'               => 'status',
				'value'               => $target,
				'keep_changed_status' => $keep,
			]
		);
		self::assertNull( $validation->error() );
		return $validation->args();
	}

	public function test_the_fixture_lists_every_outcome_and_the_confirm_list_is_the_servers(): void {
		$fixture = self::fixture();
		$this->assertSame( Outcome::CODES, $fixture['codes'] );
		$this->assertSame( Outcome::CONFIRMS, $fixture['confirms'] );
		$seen = array_unique( array_filter( array_column( $fixture['rows'], 'code' ) ) );
		sort( $seen );
		$codes = Outcome::CODES;
		sort( $codes );
		$this->assertSame( $codes, array_values( $seen ), 'Every outcome has a row.' );
	}

	public function test_every_row_of_the_table(): void {
		$set = ( new Registry( new Fields() ) )->get( 'set_value' );
		foreach ( self::fixture()['rows'] as $row ) {
			$case                = $row['case'];
			$product             = self::product( $row );
			$args                = self::step( $row['target'], $row['keep'] );
			[ $status, $follow ] = Stamp::within(
				77,
				static fn(): int => self::NOW,
				static fn(): array => [ $set->compute( $product, $args ), $set->follow( $product, $set->compute( $product, $args ), $args ) ]
			);

			$this->assertSame( 'status', $status->field(), $case );
			$this->assertSame( $row['result']['state'], $status->state(), $case );
			$this->assertSame( $row['result']['old'], $status->old(), $case );
			$this->assertSame( $row['result']['new'], $status->new(), $case );
			$this->assertSame( $row['result']['code'] ?? '', $status->code(), $case );
			$this->assertSame( $row['code'], $status->note(), $case );
			if ( Result::SKIPPED === $status->state() ) {
				$this->assertSame( Outcome::conflict_reason(), $status->reason(), $case );
			}

			$own = OffRecord::raw( $product );
			if ( 'none' === $row['record_result'] ) {
				$this->assertSame( [], $follow, $case );
				continue;
			}
			$this->assertCount( 1, $follow, $case );
			$record = $follow[0];
			$this->assertSame( 'availability_off', $record->field(), $case );
			$this->assertSame( Result::CHANGED, $record->state(), $case );
			$this->assertSame( $own, $record->old(), $case );
			if ( 'clear' === $row['record_result'] ) {
				$this->assertSame( '', $record->new(), $case );
			} else {
				$this->assertSame( OffRecord::create( self::ID, $row['record_result']['save'], gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), 77 )->to_json(), $record->new(), $case );
			}
		}
	}

	public function test_the_outcome_context_names_what_is_saved_restored_and_current(): void {
		$set   = ( new Registry( new Fields() ) )->get( 'set_value' );
		$cases = [
			[
				[
					'status' => 'publish',
					'record' => 'none',
				],
				'draft',
				false,
				[ 'saved' => 'publish' ],
			],
			[
				[
					'status' => 'private',
					'record' => [ 'status' => 'publish' ],
				],
				'draft',
				false,
				[
					'saved'   => 'private',
					'current' => 'private',
				],
			],
			[
				[
					'status' => 'draft',
					'record' => [ 'status' => 'pending' ],
				],
				'draft',
				false,
				[ 'saved' => 'pending' ],
			],
			[
				[
					'status' => 'draft',
					'record' => [ 'status' => 'private' ],
				],
				'publish',
				false,
				[
					'saved'    => 'private',
					'restores' => 'private',
				],
			],
			[
				[
					'status' => 'private',
					'record' => [ 'status' => 'publish' ],
				],
				'publish',
				false,
				[
					'saved'   => 'publish',
					'current' => 'private',
				],
			],
			[
				[
					'status' => 'draft',
					'record' => [ 'status' => 'publish' ],
				],
				'pending',
				false,
				[ 'saved' => 'publish' ],
			],
		];
		foreach ( $cases as [ $row, $target, $keep, $context ] ) {
			$product = self::product( $row );
			$result  = Stamp::within( 1, static fn(): int => self::NOW, static fn(): Result => $set->compute( $product, self::step( $target, $keep ) ) );
			$this->assertSame( $context, $result->context(), wp_json_encode( [ $row, $target ] ) );
		}
		// A scheduled restore and a never-Off scheduled product carry the scheduled time (GMT).
		$product = self::product(
			[
				'status'   => 'draft',
				'record'   => [ 'status' => 'future' ],
				'schedule' => 'ahead',
			]
		);
		$result  = Stamp::within( 1, static fn(): int => self::NOW, static fn(): Result => $set->compute( $product, self::step( 'publish', false ) ) );
		$this->assertSame(
			[
				'saved'         => 'future',
				'restores'      => 'future',
				'scheduled_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW + 3 * 86400 ),
			],
			$result->context()
		);
		$product = self::product(
			[
				'status'   => 'future',
				'record'   => 'none',
				'schedule' => 'ahead',
			]
		);
		$result  = Stamp::within( 1, static fn(): int => self::NOW, static fn(): Result => $set->compute( $product, self::step( 'publish', false ) ) );
		$this->assertSame( 'publishes_not_off', $result->note() );
		$this->assertSame(
			[
				'current'       => 'future',
				'scheduled_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW + 3 * 86400 ),
			],
			$result->context()
		);
	}

	public function test_a_schedule_less_than_a_minute_ahead_counts_as_passed(): void {
		// WordPress schedules a publish only when its date is at least a minute ahead (wp_insert_post()).
		$set = ( new Registry( new Fields() ) )->get( 'set_value' );
		foreach ( [
			59 => 'on_schedule_passed',
			60 => 'on_scheduled',
			61 => 'on_scheduled',
			-1 => 'on_schedule_passed',
		] as $ahead => $code ) {
			$product                              = new SavedProduct(
				'simple',
				[
					'id'           => self::ID,
					'status'       => 'draft',
					'date_created' => new \DateTimeImmutable( '@' . ( self::NOW + $ahead ) ),
				]
			);
			$product->meta[ OffRecord::META_KEY ] = OffRecord::create( self::ID, 'future', '2026-09-01T08:00:00Z', 7 )->to_json();
			$result                               = Stamp::within( 1, static fn(): int => self::NOW, static fn(): Result => $set->compute( $product, self::step( 'publish', false ) ) );
			$this->assertSame( $code, $result->note(), "$ahead seconds ahead." );
		}
	}

	public function test_a_variation_keeps_the_ordinary_field_rules_and_never_a_record(): void {
		$set                                    = ( new Registry( new Fields() ) )->get( 'set_value' );
		$variation                              = new SavedProduct(
			'variation',
			[
				'id'        => 601,
				'parent_id' => 600,
				'status'    => 'publish',
			]
		);
		$variation->meta[ OffRecord::META_KEY ] = OffRecord::create( 601, 'publish', '2026-09-01T08:00:00Z', 7 )->to_json();
		$private                                = $set->compute( $variation, self::step( 'private', false ) );
		$this->assertSame( [ Result::CHANGED, 'publish', 'private', '' ], [ $private->state(), $private->old(), $private->new(), $private->note() ] );
		$this->assertSame( [], $set->follow( $variation, $private, self::step( 'private', false ) ) );
		// Chosen on the variation itself (not pushed down by Also apply to variations), Draft fails.
		$own   = [ 'apply_to_variations' => false ] + self::step( 'draft', false );
		$draft = \BulkCatalogueManager\Operations\Validator::for_request( new Fields() )->check( $variation, $set->compute( $variation, $own ), $own )[0];
		$this->assertSame( Result::FAILED, $draft->state(), 'Draft chosen on a variation fails with the field\'s reason (D7).' );
		$this->assertSame( 'On a variation, Status can only be: Published, Private.', $draft->reason() );
		$this->assertSame( [], $set->follow( $variation, $draft, self::step( 'draft', false ) ) );
	}

	public function test_a_step_the_validator_refused_follows_with_nothing(): void {
		$set     = ( new Registry( new Fields() ) )->get( 'set_value' );
		$product = self::product(
			[
				'status' => 'publish',
				'record' => 'none',
			]
		);
		$args    = self::step( 'draft', false );
		foreach ( [ Result::skipped( 'status', 'publish', 'You may not edit this product.', 'bulkcat_cannot_edit' ), Result::failed( 'status', 'publish', 'No.', 'bulkcat_invalid_value' ) ] as $refused ) {
			$this->assertSame( [], $set->follow( $product, $refused, $args ) );
		}
	}
}
