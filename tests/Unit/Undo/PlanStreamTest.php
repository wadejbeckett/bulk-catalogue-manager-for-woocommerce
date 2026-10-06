<?php
/**
 * The streamed undo plan (T111, ticket 11 item 1; SPEC §9.4): the plan is
 * walked a page of objects at a time, so it never holds every item of the
 * batch, and it plans exactly what the whole plan held in memory planned.
 *
 * Equivalence: a fixture of 271 objects across three pages of the walk —
 * products and variations, several fields and several applied rows for one
 * field, rows of one object far apart in the journal (the start and the
 * end), failed, skipped and unsettled intent rows, a status with its Off
 * record, a term list emptied, conflicts in price, stock and status, a
 * missing product and a category item — is planned and compared, item for
 * item and hash for hash, with the golden file the whole-plan planner wrote
 * (`golden/stream-plan.json`, written with BULKCAT_WRITE_GOLDEN=1 by the
 * planner of 69e2533, the integration head whose restore_status also
 * carries the status and record expected when planned; first written at
 * f498629, where only those 13 status items and the hash differ). The undo
 * preview's pages, conflicts first, are the same slices the whole plan gave.
 *
 * Memory: walking 12,000 objects keeps what it holds bounded by the page,
 * not by the batch (the whole plan kept about 2.7 KB an item).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Rest\UndoController;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\PlanSummary;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class PlanStreamTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	private const NOW = 1790208000; // 2026-09-24 00:00:00 UTC.

	private const GOLDEN = __DIR__ . '/golden/stream-plan.json';

	private RunnerStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
		$GLOBALS['bulkcat_test']['terms']     = [
			'product_cat' => [ 5, 7 ],
			'product_tag' => [ 8 ],
		];
		$this->store                          = new RunnerStore( self::BATCH, [], [], self::USER );
		$this->store->batch['status']         = 'complete';
		$this->store->batch['finished_gmt']   = '2026-09-23 08:05:00';
		$this->store->batch['done_items']     = 271;
		$this->store->batch['total_items']    = 271;
	}

	private function planner(): Planner {
		return new Planner( null, null, new Registry( new Fields() ), new Settings( [] ), static fn(): int => self::NOW );
	}

	/**
	 * Append one journal row, as the writer records it.
	 */
	private function change( int $object_id, string $prop, mixed $old, mixed $new, string $result = 'applied', string $type = 'product' ): void {
		$this->store->changes[] = [
			'batch_id'     => self::BATCH,
			'object_id'    => $object_id,
			'object_type'  => $type,
			'prop'         => $prop,
			'old_value'    => $old,
			'new_value'    => $new,
			'modified_gmt' => '2026-09-23 07:00:00',
			'applied_gmt'  => '2026-09-23 08:01:00',
			'result'       => $result,
			'message'      => 'applied' === $result ? '' : 'A reason.',
		];
	}

	private static function product( int $id, array $props, string $type = 'simple' ): SavedProduct {
		$product                                    = new SavedProduct( $type, [ 'id' => $id ] + $props );
		$GLOBALS['bulkcat_test']['products'][ $id ] = $product;
		return $product;
	}

	private static function off( int $id, string $status ): string {
		return \BulkCatalogueManager\Availability\OffRecord::create( $id, $status, '2026-09-23T08:01:00Z', self::BATCH )->to_json();
	}

	/**
	 * The fixture: 270 objects journalled in id order, then a second row for
	 * the first ten, so their rows sit at both ends of the journal, then a
	 * category item.
	 */
	private function fixture(): void {
		self::product( 4999, [], 'variable' );
		for ( $i = 0; $i < 270; $i++ ) {
			$id        = 5000 + $i;
			$variation = 1 === $i % 3;
			$type      = $variation ? 'variation' : 'product';
			$props     = $variation ? [ 'parent_id' => 4999 ] : [];
			$kind      = $variation ? 'variation' : 'simple';
			if ( 0 === $i % 23 && $i > 0 ) {
				// A product removed since: no product to read.
				$this->change( $id, 'regular_price', '10.00', '11.00', 'applied', $type );
				continue;
			}
			if ( 0 === $i % 17 && $i > 0 ) {
				// Nothing applied: its row failed.
				$this->change( $id, 'regular_price', '10.00', '10.00', 'failed', $type );
				self::product( $id, $props + [ 'regular_price' => '10.00' ], $kind );
				continue;
			}
			if ( 0 === $i % 13 && ! $variation ) {
				// Switched Off: status and its record, one undo step; every other one changed to Private since.
				$this->change( $id, 'status', 'publish', 'draft' );
				$this->change( $id, 'availability_off', '', self::off( $id, 'publish' ) );
				$product                                    = self::product( $id, [ 'status' => 0 === $i % 26 ? 'private' : 'draft' ] );
				$product->meta['_bulkcat_availability_off'] = self::off( $id, 'publish' );
				continue;
			}
			if ( 0 === $i % 29 && ! $variation ) {
				// A tag list that was empty: the undo removes what the batch added.
				$this->change( $id, 'tag_ids', [], [ 8 ] );
				self::product( $id, [ 'tag_ids' => [ 8 ] ] );
				continue;
			}
			if ( 0 === $i % 31 ) {
				// Stock set to 0, unset since: a conflict, null is not 0.
				$this->change( $id, 'stock_quantity', 5, 0, 'applied', $type );
				self::product(
					$id,
					$props + [
						'stock_quantity' => null,
						'manage_stock'   => true,
					],
					$kind
				);
				continue;
			}
			if ( 0 === $i % 19 ) {
				// A run killed after the save: an unsettled intent row plans like an applied one.
				$this->change( $id, 'regular_price', '10.00', '12.00', 'pending', $type );
				self::product( $id, $props + [ 'regular_price' => '12.00' ], $kind );
				continue;
			}
			if ( 0 === $i % 7 ) {
				// Two steps on one field: back to the oldest value.
				$this->change( $id, 'regular_price', '10.00', '11.00', 'applied', $type );
				$this->change( $id, 'regular_price', '11.00', '12.10', 'applied', $type );
				self::product( $id, $props + [ 'regular_price' => '12.10' ], $kind );
				continue;
			}
			$new = sprintf( '%d.00', 20 + $i % 9 );
			$this->change( $id, 'regular_price', '10.00', $new, 'applied', $type );
			if ( ! $variation ) {
				$this->change( $id, 'category_ids', [ 5 ], [ 5, 7 ] );
			}
			// Every eleventh changed again since: a price conflict.
			$now = 0 === $i % 11 ? '99.00' : $new;
			self::product(
				$id,
				$props + [
					'regular_price' => $now,
					'category_ids'  => [ 5, 7 ],
				],
				$kind
			);
		}
		// The first ten again, much later: their rows are at both ends of the journal.
		for ( $i = 0; $i < 10; $i++ ) {
			$id = 5000 + $i;
			if ( isset( $GLOBALS['bulkcat_test']['products'][ $id ] ) ) {
				$type = 1 === $i % 3 ? 'variation' : 'product';
				$this->change( $id, 'sale_price', '', '5.00', 'applied', $type );
				$GLOBALS['bulkcat_test']['products'][ $id ]->props['sale_price'] = '5.00';
			}
		}
		// A category item, which undo leaves alone.
		$this->change( 9000, 'name', 'Old', 'New', 'applied', 'term' );
	}

	/**
	 * A plan item as the golden file keeps it.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>
	 */
	private static function kept( array $item ): array {
		return json_decode( (string) wp_json_encode( $item ), true );
	}

	public function test_the_plan_is_the_one_the_whole_plan_made(): void {
		$this->fixture();
		$plan = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( Plan::class, $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		$actual = [
			'hash'   => $plan->hash(),
			'counts' => $plan->counts(),
			'items'  => array_map( [ self::class, 'kept' ], $plan->items() ),
		];
		if ( '1' === getenv( 'BULKCAT_WRITE_GOLDEN' ) ) {
			if ( ! is_dir( dirname( self::GOLDEN ) ) ) {
				mkdir( dirname( self::GOLDEN ), 0777, true );
			}
			file_put_contents( self::GOLDEN, json_encode( $actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		}
		$golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );

		$this->assertSame( 271, array_sum( $golden['counts'] ), 'Every object journalled is an item.' );
		$this->assertGreaterThan( 0, $golden['counts']['conflicted'] );
		$this->assertGreaterThan( 0, $golden['counts']['skipped'] );
		$this->assertSame( $golden['counts'], $actual['counts'] );
		$this->assertSame( array_column( $golden['items'], 'object_id' ), array_column( $actual['items'], 'object_id' ), 'The same objects in the same order: the one journalled last first.' );
		foreach ( $golden['items'] as $index => $item ) {
			$this->assertSame( $item, $actual['items'][ $index ], 'Item ' . $item['object_id'] );
		}
		$this->assertSame( $golden['hash'], $actual['hash'], 'The same plan hash, so a preview made before the walk still applies.' );
	}

	public function test_the_walk_streams_the_same_items_in_the_same_order_with_the_same_totals(): void {
		$this->fixture();
		$golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
		$seen   = [];
		$result = $this->planner()->walk(
			self::BATCH,
			self::USER,
			static function ( array $item ) use ( &$seen ): void {
				$seen[] = self::kept( $item );
			}
		);
		$this->assertInstanceOf( PlanSummary::class, $result );
		$this->assertSame( $golden['items'], $seen );
		$this->assertSame( $golden['counts'], $result->counts() );
		$this->assertSame( $golden['hash'], $result->hash() );
		$this->assertSame( 271, $result->total() );
		$this->assertSame( self::BATCH, $result->batch_id() );
	}

	public function test_the_walk_reads_the_journal_a_page_of_objects_at_a_time(): void {
		$this->fixture();
		$GLOBALS['wpdb']->queries = [];
		$this->planner()->walk( self::BATCH, self::USER );
		$pages = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => 1 === preg_match( '/FROM `wp_bulkcat_changes` WHERE batch_id = 12 AND object_id IN \(/', $sql ) ) );
		$this->assertCount( 3, $pages, '271 objects, 100 a page.' );
		foreach ( $pages as $sql ) {
			preg_match( '/object_id IN \(([\d, ]+)\)/', $sql, $m );
			$this->assertLessThan( 101, count( explode( ',', $m[1] ) ) );
		}
		$this->assertSame( 0, count( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => 1 === preg_match( '/SELECT change_id, batch_id, object_id, object_type, prop, old_value/', $sql ) ) ), 'No read of the whole journal with its values.' );
	}

	public function test_items_plans_the_named_objects_now(): void {
		$this->fixture();
		$golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
		$byid   = array_column( $golden['items'], null, 'object_id' );
		$ids    = [ 5011, 5007, 5026, 5000, 5023, 9000 ];
		$items  = $this->planner()->items( self::BATCH, $ids, self::USER );
		$this->assertSame( $ids, array_keys( $items ) );
		foreach ( $ids as $id ) {
			$this->assertSame( $byid[ $id ], self::kept( $items[ $id ] ), "Object $id." );
		}
	}

	public function test_the_preview_pages_are_the_slices_the_whole_plan_gave_conflicts_first(): void {
		$this->fixture();
		$whole      = new Plan( self::BATCH, json_decode( (string) file_get_contents( self::GOLDEN ), true )['items'], 'x' );
		$controller = new UndoController( null, null, null, $this->planner() );
		$ordered    = array_map( [ $controller, 'item' ], $whole->conflicts_first() );
		foreach ( [ [ 1, 100 ], [ 2, 100 ], [ 3, 100 ], [ 2, 25 ], [ 11, 25 ], [ 1, 1 ] ] as [ $page, $per_page ] ) {
			$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/batches/12/undo' );
			$request->set_param( 'id', self::BATCH );
			$request->set_param( 'preview', true );
			$request->set_param( 'page', $page );
			$request->set_param( 'per_page', $per_page );
			$data = $controller->undo( $request )->get_data();
			$this->assertSame( 271, $data['total'] );
			$this->assertSame( (int) ceil( 271 / $per_page ), $data['pagination']['totalPages'] );
			$this->assertSame( array_slice( $ordered, ( $page - 1 ) * $per_page, $per_page ), $data['items'], "Page $page of $per_page." );
		}
	}

	public function test_walking_a_large_batch_holds_a_page_not_the_batch(): void {
		// The whole plan kept every item, about 2.7 KB each (T85). The walk keeps the objects' order and one page.
		$small = $this->walk_memory( 3000 );
		$large = $this->walk_memory( 15000 );
		$per   = ( $large - $small ) / 12000;
		$this->assertLessThan( 200, $per, sprintf( 'Each object journalled added %d bytes.', (int) $per ) );
		$this->assertLessThan( 6 * 1048576, $large, sprintf( 'The walk of 15,000 objects held %.1f MB.', $large / 1048576 ) );
	}

	public function test_the_undo_of_a_100000_object_change_is_recorded_whole_while_it_is_walked(): void {
		// T112 (ticket 11 item 8): no size limit remains, so the engine must take an undo of the
		// largest change a selection can hold, recording every item as the plan is walked.
		$count = 100000;
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
		$this->store                          = new RunnerStore( self::BATCH, [], [], self::USER );
		$this->store->batch['status']         = 'complete';
		$this->store->batch['done_items']     = $count;
		$this->store->batch['total_items']    = $count;
		// One product stands in for all: each id loads it, as it holds the price the batch left.
		$shared = new SavedProduct( 'simple', [ 'regular_price' => '11.00' ] );
		$rows   = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = [ $i + 1, 200000 + $i ];
			$GLOBALS['bulkcat_test']['products'][ 200000 + $i ] = $shared;
		}
		$this->serve_fast( $rows );
		unset( $rows );
		$recorded = 0;
		$inserts  = 0;
		global $wpdb;
		$wpdb->responses = [
			'/^INSERT INTO `wp_bulkcat_batch_items` /' => static function ( string $sql ) use ( &$recorded, &$inserts ): int {
				++$inserts;
				$rows      = substr_count( $sql, "'pending', 0, '" );
				$recorded += $rows;
				return $rows;
			},
		] + $wpdb->responses;
		$clear           = static function (): void {
			$GLOBALS['bulkcat_test']['calls']           = [];
			$GLOBALS['bulkcat_test']['gettext']         = [];
			$GLOBALS['bulkcat_test']['products_loaded'] = [];
			$GLOBALS['wpdb']->queries                   = [];
			$GLOBALS['wpdb']->prepared                  = [];
		};
		$clear();
		gc_collect_cycles();
		$before   = memory_get_usage();
		$peak     = 0;
		$seen     = 0;
		$recorder = ( new \BulkCatalogueManager\Engine\BatchFactory( null, new \BulkCatalogueManager\Data\BatchRepository() ) )->undo_recorder( '0b8f4d2e-3c1a-4e5b-9a7d-6f2e1c3b4a50', self::USER, self::BATCH, false );
		$this->assertInstanceOf( \BulkCatalogueManager\Engine\UndoRecorder::class, $recorder );
		$summary = $this->planner()->walk(
			self::BATCH,
			self::USER,
			static function ( array $item ) use ( $recorder, $clear, &$seen, &$peak ): void {
				$recorder->add( $item );
				++$seen;
				if ( 0 === $seen % 500 ) {
					$clear();
					$peak = max( $peak, memory_get_usage() );
				}
			}
		);
		$this->assertInstanceOf( PlanSummary::class, $summary );
		$result = $recorder->finish( $summary->counts(), $summary->hash() );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $count, $result['total_items'] );
		$this->assertSame( $count, $recorded, 'Every object is an item of the undo batch.' );
		$this->assertSame( $count / 500, $inserts, '500 items a statement, as the plan was walked.' );
		$header = $this->store->other( $result['batch_id'] );
		$this->assertSame( $count, (int) $header['total_items'], 'The header completed with the item count.' );
		$this->assertSame( $count, json_decode( (string) $header['selection'], true )['materialised'] );
		$this->assertSame( ',restore_value,', $header['actions'], 'Prices go back exactly as stored (1.4.2).' );
		$held = $peak - $before;
		$this->assertLessThan( 32 * 1048576, $held, sprintf( 'Recording 100,000 items held %.1f MB.', $held / 1048576 ) );
	}

	/**
	 * Walk a batch of $count objects, each with one applied price row, and
	 * answer the most memory the walk held above where it started. The
	 * harness's own records of every stubbed call are cleared as it goes:
	 * they are not the plugin's to hold.
	 */
	private function walk_memory( int $count ): int {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
		$this->store                          = new RunnerStore( self::BATCH, [], [], self::USER );
		$this->store->batch['status']         = 'complete';
		$this->store->batch['done_items']     = $count;
		$this->store->batch['total_items']    = $count;
		$rows                                 = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$id     = 100000 + $i;
			$rows[] = [ $i + 1, $id ];
			self::product( $id, [ 'regular_price' => '11.00' ] );
		}
		$this->serve_fast( $rows );
		unset( $rows );
		$clear = static function (): void {
			$GLOBALS['bulkcat_test']['calls']           = [];
			$GLOBALS['bulkcat_test']['gettext']         = [];
			$GLOBALS['bulkcat_test']['products_loaded'] = [];
			$GLOBALS['wpdb']->queries                   = [];
			$GLOBALS['wpdb']->prepared                  = [];
			$GLOBALS['wpdb']->writes                    = [];
		};
		$clear();
		gc_collect_cycles();
		$before = memory_get_usage();
		$peak   = 0;
		$seen   = 0;
		$result = $this->planner()->walk(
			self::BATCH,
			self::USER,
			static function () use ( &$peak, &$seen, $clear ): void {
				++$seen;
				$clear();
				$peak = max( $peak, memory_get_usage() );
			}
		);
		$this->assertInstanceOf( PlanSummary::class, $result );
		$this->assertSame( $count, $seen );
		$this->assertSame( $count, $result->counts()['revertible'] );
		return $peak - $before;
	}

	/**
	 * Answer the walk's two reads straight from a list of [ change_id, object_id ]
	 * rows (each an applied regular price 10.00 → 11.00), fast enough for
	 * thousands of objects; anything else as the store answers it.
	 *
	 * @param list<array{0: int, 1: int}> $rows Rows in journal order.
	 */
	private function serve_fast( array $rows ): void {
		global $wpdb;
		$by_object = [];
		foreach ( $rows as [ $change, $object ] ) {
			$by_object[ $object ][] = $change;
		}
		$wpdb->responses = [
			'/^SELECT change_id, object_id, object_type FROM `wp_bulkcat_changes` WHERE batch_id = 12 AND change_id < (\d+) ORDER BY change_id DESC LIMIT (\d+)$/' => static function ( string $sql ) use ( $rows ): array {
				preg_match( '/change_id < (\d+) ORDER BY change_id DESC LIMIT (\d+)$/', $sql, $m );
				$out  = [];
				$last = count( $rows ) - 1;
				for ( $i = $last; $i >= 0; $i-- ) {
					if ( count( $out ) >= (int) $m[2] ) {
						break;
					}
					if ( $rows[ $i ][0] < (int) $m[1] ) {
						$out[] = [
							'change_id'   => (string) $rows[ $i ][0],
							'object_id'   => (string) $rows[ $i ][1],
							'object_type' => 'product',
						];
					}
				}
				return $out;
			},
			'/^SELECT change_id, object_id, object_type, prop, old_value, new_value, result FROM `wp_bulkcat_changes` WHERE batch_id = 12 AND object_id IN \(([\d, ]+)\)/' => static function ( string $sql ) use ( $by_object ): array {
				preg_match( '/object_id IN \(([\d, ]+)\)/', $sql, $m );
				$out = [];
				foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $object ) {
					foreach ( $by_object[ $object ] ?? [] as $change ) {
						$out[] = [
							'change_id'   => (string) $change,
							'object_id'   => (string) $object,
							'object_type' => 'product',
							'prop'        => 'regular_price',
							'old_value'   => '"10.00"',
							'new_value'   => '"11.00"',
							'result'      => 'applied',
						];
					}
				}
				return $out;
			},
		] + $wpdb->responses;
	}
}
