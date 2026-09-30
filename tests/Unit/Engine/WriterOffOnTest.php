<?php
/**
 * T89: the writer switching products Off and On through Status (SPEC §5.5,
 * §9.3, §12.3): Off journals status and the record in one save stamped with
 * its batch; On restores and clears; intent rows cover both and a retry
 * replays them; an already-Off product is neither saved nor journalled; a
 * conflict is skipped and unsaved; Keep current status journals only the
 * record; and after every status save the writer reads back the status
 * WordPress stored, so a product WordPress keeps scheduled is journalled as
 * `future`, in write() and in replay().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Availability\Stamp;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class WriterOffOnTest extends TestCase {
	private const BATCH = 418;

	/**
	 * A fixed clock: 2026-09-29 12:00:00 UTC.
	 */
	private const NOW = 1790683200;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_post' => true ];
		$GLOBALS['wpdb']->responses['/^SELECT change_id FROM `wp_bulkcat_changes` WHERE batch_id = (\d+) AND object_id = (\d+) LIMIT 1$/']                                  = static function ( string $sql ): ?string {
			preg_match( '/batch_id = (\d+) AND object_id = (\d+)/', $sql, $m );
			foreach ( self::rows() as $row ) {
				if ( (int) $m[1] === $row['batch_id'] && (int) $m[2] === $row['object_id'] ) {
					return '1';
				}
			}
			return null;
		};
		$GLOBALS['wpdb']->responses["/^SELECT prop, old_value, new_value FROM `wp_bulkcat_changes` WHERE batch_id = (\\d+) AND object_id = (\\d+) AND result = 'pending'/"] = static function ( string $sql ): array {
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

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [], 'The writer called update_post_meta().' );
	}

	private static function writer( int $batch = self::BATCH ): Writer {
		return Writer::for_batch( new Registry( new Fields() ), new Journal( new ChangeRepository(), new Fields(), false ), $batch );
	}

	/**
	 * Write under the fixed clock.
	 *
	 * @param Writer                   $writer The writer.
	 * @param int                      $id     Object.
	 * @param list<array<string,mixed>> $list  Normalised steps.
	 */
	private static function write( Writer $writer, int $id, array $list ): WriteOutcome {
		return Stamp::within( 0, static fn(): int => self::NOW, static fn(): WriteOutcome => $writer->write( $id, $list ) );
	}

	/**
	 * One validated status step, through JSON as the batch stores it.
	 *
	 * @param string $status The status chosen.
	 * @param bool   $keep   Keep current status.
	 * @param bool   $skip   Skip rows already correct.
	 */
	private function status_step( string $status, bool $keep = false, bool $skip = true ): array {
		// These are accepted legacy operations, not new public requests.
		$validation = ( new Registry( new Fields() ) )->get( 'set_value' )->validate(
			[
				'field'               => 'status',
				'value'               => $status,
				'keep_changed_status' => $keep,
				'skip_already'        => $skip,
			]
		);
		$this->assertNull( $validation->error() );
		return json_decode(
			(string) wp_json_encode(
				[
					[
						'action' => 'set_value',
						'args'   => $validation->args(),
					],
				]
			),
			true
		);
	}

	/**
	 * A date object as WooCommerce's getters return one.
	 *
	 * @param int $time Unix time.
	 */
	private static function date( int $time ): object {
		return new class( $time ) {
			public function __construct( private int $time ) {}

			public function date( string $format ): string {
				return gmdate( $format, $this->time );
			}

			public function getTimestamp(): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WC_DateTime's own method name.
				return $this->time;
			}
		};
	}

	/**
	 * Register a fake product under its id.
	 *
	 * @param int         $id     Id.
	 * @param string      $status Its status.
	 * @param string|null $saved  The status its own Off record saved, or null for none.
	 * @param int         $date   Its date, as a Unix time.
	 */
	private static function product( int $id, string $status, ?string $saved = null, int $date = self::NOW - 86400 ): SavedProduct {
		$product = new SavedProduct(
			'simple',
			[
				'id'            => $id,
				'status'        => $status,
				'date_created'  => self::date( $date ),
				'date_modified' => self::date( self::NOW - 3600 ),
			]
		);
		if ( null !== $saved ) {
			$product->meta[ OffRecord::META_KEY ] = self::record( $id, $saved );
		}
		$GLOBALS['bulkcat_test']['products'][ $id ] = $product;
		return $product;
	}

	private static function record( int $id, string $status, int $batch = 7 ): string {
		return OffRecord::create( $id, $status, '2026-09-01T08:00:00Z', $batch )->to_json();
	}

	/**
	 * The journal rows appended so far, values decoded, settled rows applied.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		$rows = [];
		foreach ( $GLOBALS['wpdb']->writes as [ $method, $table, $data, , $where ] ) {
			if ( 'wp_bulkcat_changes' !== $table ) {
				continue;
			}
			if ( 'insert' === $method ) {
				$data['old_value'] = ValueCodec::decode( $data['old_value'] );
				$data['new_value'] = ValueCodec::decode( $data['new_value'] );
				$rows[]            = $data;
			} elseif ( 'update' === $method ) {
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
	 * Rows as [ prop, old, new, result ].
	 *
	 * @return list<array{0: string, 1: mixed, 2: mixed, 3: string}>
	 */
	private static function summary(): array {
		return array_map( static fn( array $r ): array => [ $r['prop'], $r['old_value'], $r['new_value'], $r['result'] ], self::rows() );
	}

	public function test_off_journals_status_and_the_record_in_one_save_stamped_with_its_batch(): void {
		$product = self::product( 10, 'private' );
		$outcome = self::write( self::writer(), 10, $this->status_step( 'draft' ) );

		$record = OffRecord::create( 10, 'private', gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), self::BATCH )->to_json();
		$this->assertTrue( $outcome->saved() );
		$this->assertSame( [ Result::CHANGED, Result::CHANGED ], array_map( static fn( Result $r ): string => $r->state(), $outcome->results() ) );
		$this->assertCount( 1, $product->saves, 'One save carries the status and the record.' );
		$this->assertSame( 'draft', $product->saves[0]['status'] );
		$this->assertSame( $record, $product->saved_meta[0][ OffRecord::META_KEY ] );
		$this->assertSame(
			[
				[ 'status', 'private', 'draft', 'applied' ],
				[ 'availability_off', '', $record, 'applied' ],
			],
			self::summary()
		);
	}

	public function test_on_restores_the_saved_status_and_clears_the_record(): void {
		$product = self::product( 11, 'draft', 'private' );
		$outcome = self::write( self::writer(), 11, $this->status_step( 'publish' ) );

		$this->assertTrue( $outcome->saved() );
		$this->assertSame( 'private', $product->saves[0]['status'], 'On is not a public grant: Private comes back Private.' );
		$this->assertArrayNotHasKey( OffRecord::META_KEY, $product->saved_meta[0] );
		$this->assertSame(
			[
				[ 'status', 'draft', 'private', 'applied' ],
				[ 'availability_off', self::record( 11, 'private' ), '', 'applied' ],
			],
			self::summary()
		);
		$this->assertSame( 'on_restores', $outcome->results()[0]->note() );
	}

	public function test_already_off_is_neither_saved_nor_journalled_with_skip_already(): void {
		$product = self::product( 12, 'draft', 'publish' );
		$outcome = self::write( self::writer(), 12, $this->status_step( 'draft' ) );
		$this->assertFalse( $outcome->saved() );
		$this->assertSame( [], $product->saves );
		$this->assertSame( [], self::summary() );
		$this->assertSame( [ Result::ALREADY, 'already_off' ], [ $outcome->results()[0]->state(), $outcome->results()[0]->note() ] );
		$this->assertSame( self::record( 12, 'publish' ), $product->meta[ OffRecord::META_KEY ], 'The record is kept as it was.' );

		// A genuine draft saves nothing either.
		$draft = self::product( 13, 'draft' );
		$this->assertSame( 'already_draft', self::write( self::writer(), 13, $this->status_step( 'draft' ) )->results()[0]->note() );
		$this->assertSame( [], $draft->saves );
		$this->assertSame( [], $draft->meta_calls );
	}

	public function test_a_conflict_is_skipped_and_unsaved(): void {
		$product = self::product( 14, 'publish', 'private' );
		$outcome = self::write( self::writer(), 14, $this->status_step( 'publish' ) );
		$this->assertFalse( $outcome->saved() );
		$this->assertSame( [], $product->saves );
		$this->assertSame( [ Result::SKIPPED, 'bulkcat_availability_conflict', 'conflict' ], [ $outcome->results()[0]->state(), $outcome->results()[0]->code(), $outcome->results()[0]->note() ] );
		$this->assertSame( [ [ 'status', 'publish', 'publish', 'skipped' ] ], self::summary() );
		$this->assertSame( self::record( 14, 'private' ), $product->meta[ OffRecord::META_KEY ] );
	}

	public function test_keep_current_status_journals_only_the_record(): void {
		$product = self::product( 15, 'publish', 'private' );
		$outcome = self::write( self::writer(), 15, $this->status_step( 'publish', true ) );
		$this->assertTrue( $outcome->saved() );
		$this->assertCount( 1, $product->saves );
		$this->assertSame( 'publish', $product->saves[0]['status'] );
		$this->assertSame( [ [ 'availability_off', self::record( 15, 'private' ), '', 'applied' ] ], self::summary() );
		$this->assertSame( [ Result::ALREADY, 'conflict_kept' ], [ $outcome->results()[0]->state(), $outcome->results()[0]->note() ] );
	}

	public function test_draft_again_after_a_change_elsewhere_saves_the_current_status(): void {
		$product = self::product( 16, 'pending', 'publish' );
		self::write( self::writer(), 16, $this->status_step( 'draft' ) );
		$record = OffRecord::create( 16, 'pending', gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), self::BATCH )->to_json();
		$this->assertSame(
			[
				[ 'status', 'pending', 'draft', 'applied' ],
				[ 'availability_off', self::record( 16, 'publish' ), $record, 'applied' ],
			],
			self::summary()
		);
		$this->assertSame( $record, $product->saved_meta[0][ OffRecord::META_KEY ] );
	}

	public function test_the_status_wordpress_stored_is_journalled_not_the_one_set(): void {
		// A product saved Off from scheduled: On writes publish, and WordPress keeps it scheduled
		// because its date is still ahead. WooCommerce does not read post_status back; the writer does.
		$product                                      = self::product( 17, 'draft', 'future', self::NOW + 7 * 86400 );
		$GLOBALS['bulkcat_test']['stored_status'][17] = 'future';
		$outcome                                      = self::write( self::writer(), 17, $this->status_step( 'publish' ) );

		$this->assertSame( 'future', $product->props['status'], 'The saved object holds what WordPress stored.' );
		$this->assertSame(
			[
				[ 'status', 'draft', 'future', 'applied' ],
				[ 'availability_off', self::record( 17, 'future' ), '', 'applied' ],
			],
			self::summary()
		);
		$this->assertSame( 'future', $outcome->results()[0]->new(), 'The outcome reports the stored status too.' );
		$this->assertSame( 'on_scheduled', $outcome->results()[0]->note() );
		$this->assertContains( 17, $GLOBALS['bulkcat_test']['cleaned_posts'] );

		// A never-Off scheduled product set to Published stays scheduled (a 1.0 bug: journalled as publish).
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [ 'edit_post' => true ];
		$this->setUp();
		$never                                        = self::product( 18, 'future', null, self::NOW + 7 * 86400 );
		$GLOBALS['bulkcat_test']['stored_status'][18] = 'future';
		$kept = self::write( self::writer(), 18, $this->status_step( 'publish' ) );
		$this->assertSame( 'future', $never->props['status'] );
		$this->assertSame( [ [ 'status', 'future', 'future', 'applied' ] ], self::summary() );
		// Should-fix (4): the outcome (the PATCH results) says what happened, as the journal does:
		// the status is as it was, not changed to publish.
		$result = $kept->results()[0];
		$this->assertSame( [ Result::ALREADY, 'future', 'future' ], [ $result->state(), $result->old(), $result->new() ] );
		$this->assertSame( [ 'publishes_not_off', 'future' ], [ $result->note(), $result->context()['current'] ?? null ] );
	}

	public function test_intent_rows_cover_both_props_and_a_retry_replays_them(): void {
		$stored                                  = self::product( 19, 'publish' );
		$dying                                   = new class( $stored ) {
			public function __construct( private SavedProduct $stored ) {}

			public function is_type( string $type ): bool {
				return $this->stored->is_type( $type );
			}

			public function get_meta( string $key = '', bool $single = true, string $context = 'view' ): mixed {
				return $this->stored->get_meta( $key, $single, $context );
			}

			public function update_meta_data( string $key, mixed $value, int $meta_id = 0 ): void {
				$this->stored->update_meta_data( $key, $value, $meta_id );
			}

			public function delete_meta_data( string $key ): void {
				$this->stored->delete_meta_data( $key );
			}

			public function save(): int {
				$this->stored->save();
				throw new \Error( 'Maximum execution time of 30 seconds exceeded' );
			}

			public function __call( string $method, array $args ): mixed {
				return $this->stored->$method( ...$args );
			}
		};
		$GLOBALS['bulkcat_test']['products'][19] = $dying;
		$list                                    = $this->status_step( 'draft' );
		try {
			self::write( self::writer(), 19, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertStringContainsString( 'execution time', $e->getMessage() );
		}
		$record = OffRecord::create( 19, 'publish', gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), self::BATCH )->to_json();
		$this->assertSame(
			[
				[ 'status', 'publish', 'draft', 'pending' ],
				[ 'availability_off', '', $record, 'pending' ],
			],
			self::summary(),
			'Both values are on record before save().'
		);

		// The retry writes nothing again: WooCommerce saved both before the request died.
		$GLOBALS['bulkcat_test']['products'][19] = $stored;
		$retried                                 = Stamp::within( 0, static fn(): int => self::NOW + 600, static fn(): WriteOutcome => self::writer()->write( 19, $list ) );
		$this->assertTrue( $retried->saved() );
		$this->assertCount( 1, $stored->saves, 'Nothing needed writing again.' );
		$this->assertSame(
			[
				[ 'status', 'publish', 'draft', 'applied' ],
				[ 'availability_off', '', $record, 'applied' ],
			],
			self::summary(),
			'The record keeps the time and batch the first run wrote.'
		);
	}

	public function test_a_retry_before_the_save_landed_writes_the_journalled_record_verbatim(): void {
		$lost                                    = new SavedProduct(
			'simple',
			[
				'id'            => 20,
				'status'        => 'private',
				'date_modified' => self::date( self::NOW ),
			]
		);
		$dying                                   = new class( $lost ) {
			public function __construct( private SavedProduct $lost ) {}

			public function is_type( string $type ): bool {
				return $this->lost->is_type( $type );
			}

			public function get_meta( string $key = '', bool $single = true, string $context = 'view' ): mixed {
				return $this->lost->get_meta( $key, $single, $context );
			}

			public function update_meta_data( string $key, mixed $value, int $meta_id = 0 ): void {
				$this->lost->update_meta_data( $key, $value, $meta_id );
			}

			public function delete_meta_data( string $key ): void {
				$this->lost->delete_meta_data( $key );
			}

			public function save(): int {
				throw new \Error( 'Allowed memory size exhausted' );
			}

			public function __call( string $method, array $args ): mixed {
				return $this->lost->$method( ...$args );
			}
		};
		$GLOBALS['bulkcat_test']['products'][20] = $dying;
		$list                                    = $this->status_step( 'draft' );
		try {
			self::write( self::writer(), 20, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		// Nothing landed: the reloaded product is still private with no record.
		$fresh                                        = self::product( 20, 'private' );
		$GLOBALS['bulkcat_test']['stored_status'][20] = 'draft';
		Stamp::within( 0, static fn(): int => self::NOW + 600, static fn(): WriteOutcome => self::writer()->write( 20, $list ) );
		$record = OffRecord::create( 20, 'private', gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), self::BATCH )->to_json();
		$this->assertCount( 1, $fresh->saves );
		$this->assertSame( 'draft', $fresh->saves[0]['status'] );
		$this->assertSame( $record, $fresh->saved_meta[0][ OffRecord::META_KEY ], 'Replay writes the journalled JSON verbatim, not a new stamp.' );
		$this->assertSame(
			[
				[ 'status', 'private', 'draft', 'applied' ],
				[ 'availability_off', '', $record, 'applied' ],
			],
			self::summary()
		);
	}

	public function test_a_replayed_status_wordpress_kept_is_already_and_not_saved(): void {
		// Should-fix 4's replay half (Writer::replay()'s `saved` recomputed through stored()): a retry
		// of a Published that WordPress keeps scheduled reports already and no save, as write() does.
		$lost                                    = self::product( 22, 'future', null, self::NOW + 7 * 86400 );
		$dying                                   = new class( $lost ) {
			public function __construct( private SavedProduct $lost ) {}

			public function is_type( string $type ): bool {
				return $this->lost->is_type( $type );
			}

			public function get_meta( string $key = '', bool $single = true, string $context = 'view' ): mixed {
				return $this->lost->get_meta( $key, $single, $context );
			}

			public function update_meta_data( string $key, mixed $value, int $meta_id = 0 ): void {
				$this->lost->update_meta_data( $key, $value, $meta_id );
			}

			public function delete_meta_data( string $key ): void {
				$this->lost->delete_meta_data( $key );
			}

			public function save(): int {
				throw new \Error( 'Maximum execution time of 30 seconds exceeded' );
			}

			public function __call( string $method, array $args ): mixed {
				return $this->lost->$method( ...$args );
			}
		};
		$GLOBALS['bulkcat_test']['products'][22] = $dying;
		$list                                    = $this->status_step( 'publish' );
		try {
			self::write( self::writer(), 22, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertSame( [ [ 'status', 'future', 'publish', 'pending' ] ], self::summary() );

		$fresh                                        = self::product( 22, 'future', null, self::NOW + 7 * 86400 );
		$GLOBALS['bulkcat_test']['stored_status'][22] = 'future';
		$replayed                                     = self::write( self::writer(), 22, $list );
		$this->assertSame( 'future', $fresh->props['status'] );
		$this->assertFalse( $replayed->saved(), 'WordPress kept it scheduled: no change to report.' );
		$result = $replayed->results()[0];
		$this->assertSame( [ Result::ALREADY, 'future', 'future' ], [ $result->state(), $result->old(), $result->new() ] );
		$this->assertSame( [ [ 'status', 'future', 'future', 'applied' ] ], self::summary(), 'The journal records what WordPress stored.' );
	}

	public function test_replay_reads_back_the_stored_status_too(): void {
		$lost                                    = self::product( 21, 'draft', 'future', self::NOW + 7 * 86400 );
		$dying                                   = new class( $lost ) {
			public function __construct( private SavedProduct $lost ) {}

			public function is_type( string $type ): bool {
				return $this->lost->is_type( $type );
			}

			public function get_meta( string $key = '', bool $single = true, string $context = 'view' ): mixed {
				return $this->lost->get_meta( $key, $single, $context );
			}

			public function update_meta_data( string $key, mixed $value, int $meta_id = 0 ): void {
				$this->lost->update_meta_data( $key, $value, $meta_id );
			}

			public function delete_meta_data( string $key ): void {
				$this->lost->delete_meta_data( $key );
			}

			public function save(): int {
				throw new \Error( 'Maximum execution time of 30 seconds exceeded' );
			}

			public function __call( string $method, array $args ): mixed {
				return $this->lost->$method( ...$args );
			}
		};
		$GLOBALS['bulkcat_test']['products'][21] = $dying;
		$list                                    = $this->status_step( 'publish' );
		try {
			self::write( self::writer(), 21, $list );
			$this->fail( 'The request was meant to die in save().' );
		} catch ( \Error $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertSame( [ [ 'status', 'draft', 'publish', 'pending' ], [ 'availability_off', self::record( 21, 'future' ), '', 'pending' ] ], self::summary() );

		$fresh                                        = self::product( 21, 'draft', 'future', self::NOW + 7 * 86400 );
		$GLOBALS['bulkcat_test']['stored_status'][21] = 'future';
		self::write( self::writer(), 21, $list );
		$this->assertSame( 'future', $fresh->props['status'] );
		$this->assertSame( [ [ 'status', 'draft', 'future', 'applied' ], [ 'availability_off', self::record( 21, 'future' ), '', 'applied' ] ], self::summary() );
	}
}
