<?php
/**
 * The journal inside the writer (T33, T29; SPEC §9.3, §12.6, §5.4): one
 * bcm_changes row per touched field with its before and after value through
 * ValueCodec; no row for a field no step touched; a failed row for a refusal,
 * a failed save and an object that is not there, with the caller carrying
 * on; the object's post_modified_gmt captured before the write; the retry
 * guard skipping an object the batch already journalled, so a percentage
 * increase applies once; the skip_already switch crossed with *Record
 * unchanged rows in the audit log*; and a one-item inline batch journalling
 * exactly as a bulk batch does.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class JournalTest extends TestCase {
	private const BATCH = 7;

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['caps']  = [
			'edit_post'            => true,
			'assign_product_terms' => true,
		];
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat' => [ 11, 12 ],
			'product_tag' => [ 21, 22 ],
		];
		// has_object() answers from the rows appended so far, as the table would.
		$GLOBALS['wpdb']->responses['/^SELECT change_id FROM `wp_bcm_changes` WHERE batch_id = (\d+) AND object_id = (\d+) LIMIT 1$/'] = static function ( string $sql ): ?string {
			preg_match( '/batch_id = (\d+) AND object_id = (\d+)/', $sql, $m );
			foreach ( self::rows() as $row ) {
				if ( (int) $m[1] === $row['batch_id'] && (int) $m[2] === $row['object_id'] ) {
					return '1';
				}
			}
			return null;
		};
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bcm_test']['post_meta_updates'] ?? [], 'The writer called update_post_meta().' );
	}

	private static function journal( bool $record_unchanged = false ): Journal {
		return new Journal( new ChangeRepository(), new Fields(), $record_unchanged );
	}

	private static function writer( bool $record_unchanged = false, int $batch = self::BATCH ): Writer {
		return Writer::for_batch( new Registry( new Fields() ), self::journal( $record_unchanged ), $batch );
	}

	/**
	 * A validated, normalised list, sent through JSON as the batch's operations column stores it.
	 *
	 * @param list<array{0: string, 1: array<string, mixed>}> $steps [ action, args ].
	 */
	private function steps( array $steps ): array {
		$list = ( new Registry( new Fields() ) )->validate_list(
			array_map(
				static fn( array $s ): array => [
					'action' => $s[0],
					'args'   => $s[1],
				],
				$steps
			)
		);
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return json_decode( (string) wp_json_encode( $list ), true );
	}

	/**
	 * A date object as WooCommerce's getter returns it: date() formats in the site's time zone.
	 *
	 * @param string $iso Time with its offset.
	 */
	private static function date( string $iso ): object {
		return new class( $iso ) {
			public function __construct( private string $iso ) {}

			public function date( string $format ): string {
				return ( new \DateTimeImmutable( $this->iso ) )->format( $format );
			}
		};
	}

	/**
	 * Register a fake product under its id for wc_get_product().
	 *
	 * @param array<string, mixed> $props Props with `id`.
	 */
	private static function product( array $props, string $type = 'simple' ): SavedProduct {
		$product = new SavedProduct( $type, $props + [ 'date_modified' => self::date( '2026-09-20T10:15:00+02:00' ) ] );
		$GLOBALS['bcm_test']['products'][ $props['id'] ] = $product;
		return $product;
	}

	/**
	 * The journal rows appended so far, values decoded.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		$rows = [];
		foreach ( $GLOBALS['wpdb']->writes as [ $method, $table, $data, , $where ] ) {
			if ( 'wp_bcm_changes' !== $table ) {
				continue;
			}
			if ( 'insert' === $method && ! in_array( 'insert', $GLOBALS['wpdb']->failing, true ) ) {
				$data['old_value'] = ValueCodec::decode( $data['old_value'] );
				$data['new_value'] = ValueCodec::decode( $data['new_value'] );
				$rows[]            = $data;
			} elseif ( 'update' === $method ) {
				// An intent row settled (§12.3), as the table would apply it.
				foreach ( $rows as $index => $row ) {
					if ( [] === array_diff_assoc( $where, array_intersect_key( $row, $where ) ) ) {
						$data['new_value'] = ValueCodec::decode( $data['new_value'] );
						$rows[ $index ]    = $data + $row;
					}
				}
			}
		}
		return $rows;
	}

	/**
	 * Rows reduced to [ prop, old, new, result ].
	 *
	 * @return list<array{0: string, 1: mixed, 2: mixed, 3: string}>
	 */
	private static function summary(): array {
		return array_map( static fn( array $r ): array => [ $r['prop'], $r['old_value'], $r['new_value'], $r['result'] ], self::rows() );
	}

	/**
	 * Result states in list order.
	 *
	 * @return list<string>
	 */
	private static function states( WriteOutcome $outcome ): array {
		return array_map( static fn( Result $r ): string => $r->state(), $outcome->results() );
	}

	public function test_one_row_per_touched_field_and_none_for_untouched_fields(): void {
		$product = self::product(
			[
				'id'            => 81,
				'name'          => 'Blue shirt',
				'regular_price' => '10',
				'sale_price'    => '',
				'weight'        => '2',
			]
		);
		$outcome = self::writer()->write(
			81,
			$this->steps(
				[
					[
						'adjust',
						[
							'field'     => 'regular_price',
							'direction' => 'increase',
							'by'        => 'percent',
							'amount'    => '10',
						],
					],
					[
						'round',
						[
							'field'  => 'regular_price',
							'to'     => 'ending',
							'ending' => '0.95',
						],
					],
					[
						'set_value',
						[
							'field' => 'name',
							'value' => 'Blue shirt',
						],
					],
				]
			)
		);

		$this->assertSame( [ Result::CHANGED, Result::CHANGED, Result::ALREADY ], self::states( $outcome ) );
		$this->assertCount( 1, $product->saves );
		$this->assertSame( [ [ 'regular_price', '10', '10.95', 'applied' ] ], self::summary(), 'Two steps on one field write one row, before the first to after the last; the already-correct name and the untouched sale price and weight write none.' );
		$row = self::rows()[0];
		$this->assertSame( [ self::BATCH, 81, 'product', '' ], [ $row['batch_id'], $row['object_id'], $row['object_type'], $row['message'] ] );
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['applied_gmt'] ) );
	}

	public function test_old_and_new_values_round_trip_through_the_codec_keeping_empty_apart_from_zero(): void {
		self::product(
			[
				'id'             => 82,
				'regular_price'  => '10',
				'sale_price'     => '0.00',
				'manage_stock'   => true,
				'stock_quantity' => null,
				'featured'       => false,
				'category_ids'   => [ 11 ],
			],
			'simple'
		);
		self::writer()->write(
			82,
			$this->steps(
				[
					[ 'clear', [ 'field' => 'sale_price' ] ],
					[
						'set_value',
						[
							'field' => 'stock_quantity',
							'value' => 0,
						],
					],
					[
						'set_value',
						[
							'field' => 'featured',
							'value' => true,
						],
					],
					[
						'add_terms',
						[
							'field' => 'category_ids',
							'terms' => [ 12 ],
						],
					],
				]
			)
		);

		$this->assertSame(
			[
				[ 'sale_price', '0.00', '', 'applied' ],
				[ 'stock_quantity', null, 0, 'applied' ],
				[ 'featured', false, true, 'applied' ],
				[ 'category_ids', [ 11 ], [ 11, 12 ], 'applied' ],
			],
			self::summary(),
			'Zero, empty, null, a boolean and a term list each come back as themselves.'
		);
		$this->assertSame( '"0.00"', $GLOBALS['wpdb']->writes[0][2]['old_value'] );
		$this->assertSame( '""', $GLOBALS['wpdb']->writes[0][2]['new_value'] );
		$this->assertSame( 'null', $GLOBALS['wpdb']->writes[1][2]['old_value'] );
		$this->assertSame( '0', $GLOBALS['wpdb']->writes[1][2]['new_value'] );
	}

	public function test_a_refusal_writes_a_failed_row_and_the_caller_carries_on(): void {
		$first                    = self::product(
			[
				'id'            => 83,
				'regular_price' => '10',
				'sku'           => 'A-1',
			]
		);
		$first->refuse['set_sku'] = 'Invalid or duplicated SKU.';
		$second                   = self::product(
			[
				'id'            => 84,
				'regular_price' => '10',
				'sale_price'    => '',
				'sku'           => 'B-1',
			]
		);
		$list                     = $this->steps(
			[
				[
					'set_value',
					[
						'field' => 'regular_price',
						'value' => '12',
					],
				],
				[
					'affix_sku',
					[
						'field'    => 'sku',
						'position' => 'suffix',
						'text'     => '-X',
					],
				],
			]
		);
		$outcomes                 = self::writer()->run( static fn( Writer $w ): array => [ $w->write( 83, $list ), $w->write( 84, $list ) ] );

		$this->assertSame( [ Result::CHANGED, Result::FAILED ], self::states( $outcomes[0] ) );
		$this->assertSame( [ Result::CHANGED, Result::CHANGED ], self::states( $outcomes[1] ), 'The next object is written.' );
		$this->assertCount( 1, $first->saves );
		$this->assertCount( 1, $second->saves );
		$rows = self::rows();
		$this->assertSame(
			[
				[ 'regular_price', '10', '12.00', 'applied' ],
				[ 'sku', 'A-1', 'A-1', 'failed' ],
				[ 'regular_price', '10', '12.00', 'applied' ],
				[ 'sku', 'B-1', 'B-1-X', 'applied' ],
			],
			self::summary()
		);
		$this->assertSame( 'Invalid or duplicated SKU.', $rows[1]['message'] );
		$this->assertSame( [ 83, 83, 84, 84 ], array_column( $rows, 'object_id' ) );
	}

	public function test_a_row_the_validator_skips_is_journalled_with_its_reason_and_not_saved(): void {
		$product = self::product(
			[
				'id'            => 85,
				'regular_price' => '10',
				'sale_price'    => '',
			]
		);
		$outcome = self::writer()->write(
			85,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'sale_price',
							'value' => '15',
						],
					],
				]
			)
		);

		$this->assertSame( [ Result::SKIPPED ], self::states( $outcome ) );
		$this->assertSame( [], $product->saves );
		$this->assertSame( [ [ 'sale_price', '', '', 'skipped' ] ], self::summary() );
		$this->assertSame( $outcome->results()[0]->reason(), self::rows()[0]['message'] );
		$this->assertStringNotContainsString( 'bcm_', self::rows()[0]['message'] );
	}

	public function test_a_failed_save_writes_failed_rows_and_does_not_stop_the_caller(): void {
		$broken                              = new class() {
			/**
			 * Props.
			 *
			 * @var array<string, mixed>
			 */
			public array $props = [
				'id'            => 86,
				'regular_price' => '10',
				'parent_id'     => 30,
			];

			public function is_type( string $type ): bool {
				return 'variation' === $type;
			}

			public function save(): int {
				throw new \RuntimeException( 'Deadlock found when trying to get lock.' );
			}

			public function __call( string $method, array $args ): mixed {
				if ( str_starts_with( $method, 'set_' ) ) {
					$this->props[ substr( $method, 4 ) ] = $args[0];
					return null;
				}
				return $this->props[ substr( $method, 4 ) ] ?? null;
			}
		};
		$GLOBALS['bcm_test']['products'][86] = $broken;
		$next                                = self::product(
			[
				'id'            => 87,
				'regular_price' => '10',
			]
		);
		$list                                = $this->steps(
			[
				[
					'set_value',
					[
						'field' => 'regular_price',
						'value' => '12',
					],
				],
			]
		);
		$outcomes                            = self::writer()->run( static fn( Writer $w ): array => [ $w->write( 86, $list ), $w->write( 87, $list ) ] );

		$this->assertSame( [ Result::FAILED ], self::states( $outcomes[0] ) );
		$this->assertFalse( $outcomes[0]->saved() );
		$this->assertSame( Writer::SAVE_FAILED, $outcomes[0]->results()[0]->code() );
		$this->assertStringNotContainsString( 'Deadlock', $outcomes[0]->results()[0]->reason(), 'The reason is plain English, not the database error.' );
		$this->assertSame( [ Result::CHANGED ], self::states( $outcomes[1] ) );
		$this->assertCount( 1, $next->saves );
		$this->assertSame( [], $GLOBALS['bcm_test']['variable_syncs'] ?? [], 'A variation that was not saved does not resynchronise its parent.' );
		$this->assertSame(
			[
				[ 'regular_price', '10', '10', 'failed' ],
				[ 'regular_price', '10', '12.00', 'applied' ],
			],
			self::summary()
		);
		$this->assertSame( 'variation', self::rows()[0]['object_type'] );
	}

	public function test_an_object_that_is_not_there_writes_a_failed_row_with_an_unknown_time(): void {
		$outcome = self::writer()->write(
			404,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '12',
						],
					],
				]
			)
		);
		$this->assertSame( [ Result::FAILED ], self::states( $outcome ) );
		$row = self::rows()[0];
		$this->assertSame( [ 404, 'product', 'regular_price', null, null, 'failed', ChangeRepository::UNKNOWN_TIME ], [ $row['object_id'], $row['object_type'], $row['prop'], $row['old_value'], $row['new_value'], $row['result'], $row['modified_gmt'] ] );
		$this->assertSame( $outcome->results()[0]->reason(), $row['message'] );
	}

	public function test_post_modified_gmt_is_captured_before_the_write(): void {
		$product = self::product(
			[
				'id'            => 88,
				'regular_price' => '10',
			]
		);
		$writer  = new Writer(
			new Registry( new Fields() ),
			static function ( object $subject ): void {
				// What saving does to the object's own modified time.
				$subject->props['date_modified'] = self::date( '2026-09-23T12:00:00+00:00' );
			},
			null,
			self::journal(),
			self::BATCH
		);
		$writer->write(
			88,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '12',
						],
					],
				]
			)
		);
		$this->assertCount( 1, $product->saves );
		$this->assertSame( '2026-09-20 08:15:00', self::rows()[0]['modified_gmt'], 'The time before the write, in GMT, not the time the save gave it.' );
	}

	public function test_a_retried_object_already_journalled_by_the_batch_is_not_applied_again(): void {
		$product = self::product(
			[
				'id'            => 89,
				'regular_price' => '10',
			]
		);
		$other   = self::product(
			[
				'id'            => 90,
				'regular_price' => '20',
			]
		);
		$list    = $this->steps(
			[
				[
					'adjust',
					[
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
			]
		);
		$writer  = self::writer();
		$writer->write( 89, $list );
		// The chunk is killed before 90, then retried from the start.
		$GLOBALS['bcm_test']['products_loaded'] = [];
		$retried                                = $writer->run( static fn( Writer $w ): array => [ $w->write( 89, $list ), $w->write( 90, $list ) ] );

		$this->assertSame( [ Result::SKIPPED ], self::states( $retried[0] ) );
		$this->assertSame( Journal::ALREADY_DONE, $retried[0]->results()[0]->code() );
		$this->assertStringNotContainsString( 'bcm_', $retried[0]->results()[0]->reason() );
		$this->assertSame( [ 90 ], $GLOBALS['bcm_test']['products_loaded'], 'The done object is not even loaded.' );
		$this->assertSame( '11.00', $product->props['regular_price'], 'The percentage increase applied once.' );
		$this->assertCount( 1, $product->saves );
		$this->assertSame( '22.00', $other->props['regular_price'] );
		$this->assertSame(
			[
				[ 'regular_price', '10', '11.00', 'applied' ],
				[ 'regular_price', '20', '22.00', 'applied' ],
			],
			self::summary(),
			'Exactly one applied row per object and prop.'
		);

		// Another batch is not blocked by this one's rows.
		self::writer( false, self::BATCH + 1 )->write( 89, $list );
		$this->assertSame( '12.10', $product->props['regular_price'] );
	}

	/**
	 * Answer the intent-row read from the rows written so far, as the table would.
	 */
	private static function answer_intents(): void {
		$GLOBALS['wpdb']->responses["/^SELECT prop, old_value, new_value FROM `wp_bcm_changes` WHERE batch_id = (\\d+) AND object_id = (\\d+) AND result = 'pending'/"] = static function ( string $sql ): array {
			preg_match( '/batch_id = (\d+) AND object_id = (\d+)/', $sql, $m );
			$found = [];
			foreach ( self::rows() as $row ) {
				if ( (int) $m[1] === $row['batch_id'] && (int) $m[2] === $row['object_id'] && 'pending' === $row['result'] ) {
					$found[] = [
						'prop'      => $row['prop'],
						'old_value' => ValueCodec::encode( $row['old_value'] ),
						'new_value' => ValueCodec::encode( $row['new_value'] ),
					];
				}
			}
			return $found;
		};
	}

	/**
	 * A stored product whose save() is the last thing the request does: it
	 * dies there, after WooCommerce wrote the row ($landed) or before.
	 */
	private static function dying( SavedProduct $stored, bool $landed ): object {
		return new class( $stored, $landed ) {
			public function __construct( private SavedProduct $stored, private bool $landed ) {}

			public function is_type( string $type ): bool {
				return $this->stored->is_type( $type );
			}

			public function save(): int {
				if ( $this->landed ) {
					$this->stored->save();
				}
				throw new \Error( 'Maximum execution time of 30 seconds exceeded' );
			}

			public function __call( string $method, array $args ): mixed {
				return $this->stored->$method( ...$args );
			}
		};
	}

	public function test_a_run_killed_after_the_save_does_not_apply_a_percentage_twice_on_retry(): void {
		self::answer_intents();
		$list = $this->steps(
			[
				[
					'adjust',
					[
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
			]
		);

		// WooCommerce saves the product, then the request dies before the journal catches up.
		$stored                              = self::product(
			[
				'id'            => 91,
				'regular_price' => '10',
			]
		);
		$GLOBALS['bcm_test']['products'][91] = self::dying( $stored, true );
		try {
			self::writer()->write( 91, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertStringContainsString( 'execution time', $e->getMessage() );
		}
		$this->assertSame( '11.00', $stored->props['regular_price'] );
		$this->assertSame( [ [ 'regular_price', '10', '11.00', 'pending' ] ], self::summary(), 'The value being written is on record before save().' );

		// The retry reloads the product: the recorded value is already there, so nothing is written again.
		$GLOBALS['bcm_test']['products'][91] = $stored;
		$retried                             = self::writer()->write( 91, $list );
		$this->assertSame( '11.00', $stored->props['regular_price'], 'The increase applied once, not 12.10.' );
		$this->assertCount( 1, $stored->saves );
		$this->assertTrue( $retried->saved() );
		$this->assertSame( [ Result::CHANGED ], self::states( $retried ) );
		$this->assertSame( [ [ 'regular_price', '10', '11.00', 'applied' ] ], self::summary(), 'The intent row is settled, not duplicated.' );

		// Another retry finds a settled row and skips the object.
		$this->assertSame( Journal::ALREADY_DONE, self::writer()->write( 91, $list )->results()[0]->code() );
	}

	public function test_a_run_killed_before_the_save_landed_writes_the_recorded_value_on_retry(): void {
		self::answer_intents();
		$list                                = $this->steps(
			[
				[
					'adjust',
					[
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
			]
		);
		$GLOBALS['bcm_test']['products'][92] = self::dying(
			new SavedProduct(
				'simple',
				[
					'id'            => 92,
					'regular_price' => '10',
				]
			),
			false
		);
		try {
			self::writer()->write( 92, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		// The row never changed, so the reloaded product still holds 10: the recorded absolute value is written.
		$fresh = self::product(
			[
				'id'            => 92,
				'regular_price' => '10',
			]
		);
		self::writer()->write( 92, $list );
		$this->assertSame( '11.00', $fresh->props['regular_price'] );
		$this->assertCount( 1, $fresh->saves );
		$this->assertSame( [ [ 'regular_price', '10', '11.00', 'applied' ] ], self::summary() );
	}

	public function test_skip_already_crossed_with_record_unchanged_rows(): void {
		$cases = [
			'skip on, record off'  => [ true, false, false ],
			'skip on, record on'   => [ true, true, false ],
			'skip off, record off' => [ false, false, false ],
			'skip off, record on'  => [ false, true, true ],
		];
		foreach ( $cases as $label => [ $skip_already, $record_unchanged, $expect_row ] ) {
			bcm_test_reset();
			$this->setUp();
			$date    = self::date( '2026-09-20T10:15:00+02:00' );
			$product = self::product(
				[
					'id'            => 91,
					'name'          => 'Blue shirt',
					'regular_price' => '10',
					'date_modified' => $date,
				]
			);
			$list    = $this->steps(
				[
					[
						'set_value',
						[
							'field'        => 'name',
							'value'        => 'Blue shirt',
							'skip_already' => $skip_already,
						],
					],
					[
						'set_value',
						[
							'field'        => 'regular_price',
							'value'        => '10',
							'skip_already' => $skip_already,
						],
					],
				]
			);
			$this->assertSame( $skip_already, $list[0]['args']['skip_already'], "$label: the switch travels in the stored operations." );
			$outcome = self::writer( $record_unchanged )->write( 91, $list );

			$this->assertSame( [ Result::ALREADY, Result::ALREADY ], self::states( $outcome ), "$label: counted as already at the target value." );
			$this->assertFalse( $outcome->saved(), $label );
			$this->assertSame( [], $product->saves, "$label: an unchanged object is never re-saved." );
			$this->assertSame( [], $product->sets, "$label: nothing is set." );
			$this->assertSame( $date, $product->props['date_modified'], "$label: its modified time is untouched." );
			if ( $expect_row ) {
				$this->assertSame(
					[
						[ 'name', 'Blue shirt', 'Blue shirt', 'skipped' ],
						[ 'regular_price', '10', '10', 'skipped' ],
					],
					self::summary(),
					$label
				);
				$this->assertNotSame( '', self::rows()[0]['message'], "$label: the skipped row says why." );
				$this->assertStringNotContainsString( 'bcm_', self::rows()[0]['message'] );
			} else {
				$this->assertSame( [], self::rows(), "$label: nothing journalled." );
			}
		}
	}

	public function test_a_one_item_inline_batch_journals_as_a_bulk_batch_does(): void {
		$list  = $this->steps(
			[
				[
					'set_value',
					[
						'field' => 'regular_price',
						'value' => '12',
					],
				],
				[
					'set_value',
					[
						'field' => 'name',
						'value' => 'Red shirt',
					],
				],
			]
		);
		$props = [
			'regular_price' => '10',
			'name'          => 'Blue shirt',
		];
		self::product( [ 'id' => 92 ] + $props );
		self::product( [ 'id' => 93 ] + $props );
		self::product( [ 'id' => 94 ] + $props );

		// A bulk chunk of two, then an inline edit of one as its own batch.
		self::writer( false, 20 )->run( static fn( Writer $w ): array => [ $w->write( 92, $list ), $w->write( 93, $list ) ] );
		self::writer( false, 21 )->write( 94, $list );

		$strip = static function ( array $row ): array {
			unset( $row['batch_id'], $row['object_id'], $row['applied_gmt'] );
			return $row;
		};
		$rows  = self::rows();
		$this->assertCount( 6, $rows );
		$this->assertSame( [ 20, 20, 20, 20, 21, 21 ], array_column( $rows, 'batch_id' ) );
		$this->assertSame( array_map( $strip, array_slice( $rows, 0, 2 ) ), array_map( $strip, array_slice( $rows, 4, 2 ) ) );
	}

	public function test_a_journalling_writer_needs_its_batch(): void {
		try {
			new Writer( new Registry( new Fields() ), null, null, self::journal(), 0 );
			$this->fail( 'A journal without a batch was accepted.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'batch', $e->getMessage() );
		}
	}

	public function test_from_settings_follows_the_record_unchanged_setting(): void {
		self::product(
			[
				'id'   => 95,
				'name' => 'Blue shirt',
			]
		);
		$list = $this->steps(
			[
				[
					'set_value',
					[
						'field'        => 'name',
						'value'        => 'Blue shirt',
						'skip_already' => false,
					],
				],
			]
		);
		$on   = new Settings( [ 'record_unchanged' => true ] );
		Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), $on ), self::BATCH )->write( 95, $list );
		$this->assertCount( 1, self::rows() );
		Writer::for_batch( new Registry( new Fields() ), Journal::from_settings( new Fields(), new Settings( [] ) ), self::BATCH + 1 )->write( 95, $list );
		$this->assertCount( 1, self::rows(), 'Off by default.' );
	}
}
