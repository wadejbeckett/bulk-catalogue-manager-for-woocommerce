<?php
/**
 * Batch creation and selection materialisation (T31; SPEC §9.2, §3, §12.1,
 * §12.6) over the seeded catalogue and the in-memory batch tables: the batch
 * row is written first, then its pending items, then the created action
 * fires; counts match; an id list keeps its order; a filter is re-resolved
 * at that moment with variations as its mode decides, a thousand ids per
 * statement; every refusal writes nothing; a request id replayed within
 * sixty seconds answers the same batch and writes nothing, and after that
 * creates a new one; a submission that raced past the replay check removes
 * its own row and answers the earlier batch.
 *
 * The concurrent-request integration test (two real requests with one
 * `uuid`, exactly one batch row and one set of items) waits on wp-env.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';
require_once __DIR__ . '/BatchStore.php';

final class BatchFactoryTest extends TestCase {
	private const UUID = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

	private const USER = 12;

	private SqlEvaluator $db;

	private BatchStore $store;

	private int $now;

	protected function setUp(): void {
		bulkcat_test_reset();
		$this->db    = SeededCatalogue::seed();
		$this->store = new BatchStore( $this->db );
		$this->now   = time();
	}

	private function factory(): BatchFactory {
		return new BatchFactory( new Actions( new Fields() ), null, fn(): int => $this->now );
	}

	/**
	 * One valid step.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function operations(): array {
		return [
			[
				'action' => 'clear',
				'args'   => [ 'field' => 'sale_price' ],
			],
		];
	}

	private static function filter( string $mode ): Selection {
		return Selection::of_filter( Criteria::from_array( [ 'mode' => $mode ] ), 0 );
	}

	/**
	 * Every statement or write that changes a table.
	 *
	 * @return list<string>
	 */
	private static function writes(): array {
		global $wpdb;
		$found = array_map( static fn( array $write ): string => $write[0] . ' ' . $write[1], $wpdb->writes );
		foreach ( $wpdb->queries as $sql ) {
			if ( 1 === preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
				$found[] = $sql;
			}
		}
		return $found;
	}

	/**
	 * Assert a refusal and that nothing was written.
	 *
	 * @param mixed  $result What create() returned.
	 * @param string $code   Expected code.
	 * @param int    $status Expected status.
	 */
	private function assert_refused_with_nothing_written( mixed $result, string $code, int $status = 400 ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertSame( [], self::writes(), 'A refusal writes nothing.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['transients'] ?? [], 'A refusal leaves no replay record.' );
		$this->assertSame( 0, did_action( BatchFactory::CREATED ) );
		return $result;
	}

	public function test_an_id_list_writes_the_batch_then_its_items_in_the_order_given(): void {
		$seen = [];
		add_action(
			BatchFactory::CREATED,
			function ( int $batch_id ) use ( &$seen ): void {
				$seen[] = [ $batch_id, $this->store->object_ids( $batch_id ) ];
			}
		);

		$result = $this->factory()->create( strtoupper( self::UUID ), self::USER, self::operations(), Selection::of_ids( [ 106, 201, 101, '106' ] ) );

		$this->assertSame(
			[
				'batch_id'        => 1,
				'status'          => 'queued',
				'total_items'     => 3,
				'previewed_items' => null,
				'replayed'        => false,
			],
			$result
		);
		$this->assertSame( [ 'batch:1', 'items:1:3' ], $this->store->log, 'The batch row, then its items in one statement.' );
		$this->assertSame( [ [ 1, [ 106, 201, 101 ] ] ], $seen, 'The created action fires once, after the items are written.' );
		$this->assertSame( [ 'product', 'variation', 'product' ], array_column( $this->store->items, 'object_type' ) );
		$this->assertSame( [ 'pending' ], array_values( array_unique( array_column( $this->store->items, 'state' ) ) ) );

		$batch = ( new BatchRepository() )->find( 1 );
		$this->assertSame( self::UUID, $batch['uuid'] );
		$this->assertSame( self::USER, $batch['user_id'] );
		$this->assertSame( 'queued', $batch['status'] );
		$this->assertSame( 'bulk', $batch['source'] );
		$this->assertSame( 3, $batch['total_items'] );
		$this->assertSame( 0, $batch['done_items'] );
		$this->assertSame( ( new Actions( new Fields() ) )->validate_list( self::operations() ), $batch['operations'], 'The operations are stored normalised.' );
		$this->assertSame(
			[
				'kind'         => 'ids',
				'ids'          => [ 106, 201, 101 ],
				'count'        => 3,
				'materialised' => 3,
			],
			$batch['selection']
		);
		$this->assertSame( 60, array_values( $GLOBALS['bulkcat_test']['transient_ttl'] )[0] );
		$this->assertStringStartsWith( BatchFactory::PREFIX, array_key_first( $GLOBALS['bulkcat_test']['transients'] ) );
	}

	public function test_ids_that_are_no_longer_a_listed_product_or_variation_are_left_out(): void {
		// 108 and 203 are trashed, 5000 is an attachment, 999 does not exist.
		$result = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 108, 102, 5000, 203, 999, 202 ] ) );
		$this->assertSame( 2, $result['total_items'] );
		$this->assertSame( [ 102, 202 ], $this->store->object_ids( 1 ) );
		$this->assertSame( 6, ( new BatchRepository() )->find( 1 )['selection']['count'] );
	}

	public function test_a_filter_is_resolved_with_variations_as_its_mode_decides(): void {
		$expected = [
			Criteria::MODE_PRODUCTS                => [ SeededCatalogue::PRODUCTS, [] ],
			Criteria::MODE_PRODUCTS_AND_VARIATIONS => [ SeededCatalogue::PRODUCTS, SeededCatalogue::VARIATIONS ],
			Criteria::MODE_VARIATIONS              => [ [], SeededCatalogue::VARIATIONS ],
		];
		$batch    = 0;
		foreach ( $expected as $mode => [ $products, $variations ] ) {
			$result = $this->factory()->create( sprintf( '3f2504e0-4f89-41d3-9a0c-0305e82c330%d', ++$batch ), self::USER, self::operations(), self::filter( $mode ) );
			$this->assertSame( count( $products ) + count( $variations ), $result['total_items'], $mode );
			$items = array_values( array_filter( $this->store->items, static fn( array $item ): bool => $item['batch_id'] === $result['batch_id'] ) );
			$this->assertSame( array_merge( $products, $variations ), array_column( $items, 'object_id' ), "$mode: every matching row, in id order." );
			$this->assertSame( array_merge( array_fill( 0, count( $products ), 'product' ), array_fill( 0, count( $variations ), 'variation' ) ), array_column( $items, 'object_type' ), $mode );
			$this->assertSame( Criteria::from_array( [ 'mode' => $mode ] )->conditions(), ( new BatchRepository() )->find( $result['batch_id'] )['selection']['conditions'] );
		}
	}

	public function test_a_filter_is_materialised_at_that_moment_and_not_after(): void {
		$selection                      = Selection::of_filter( Criteria::from_array( [ 'search' => 'Shirt' ] ), 2 );
		$this->db->tables['wp_posts'][] = [
			'ID'                => 109,
			'post_type'         => 'product',
			'post_title'        => 'Linen Shirt',
			'post_status'       => 'publish',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_date_gmt'     => '2026-09-23 08:00:00',
			'post_modified_gmt' => '2026-09-23 08:00:00',
		];

		$result = $this->factory()->create( self::UUID, self::USER, self::operations(), $selection );
		$this->assertSame( [ 101, 102, 109 ], $this->store->object_ids( 1 ), 'A product created after the selection was made but before the batch is included.' );
		$this->assertSame( 3, $result['total_items'] );
		$this->assertSame( 2, ( new BatchRepository() )->find( 1 )['selection']['count'], 'The count the owner saw is kept beside it.' );

		$this->db->tables['wp_posts'][] = [ 'ID' => 110 ] + $this->db->tables['wp_posts'][ array_key_last( $this->db->tables['wp_posts'] ) ];
		$this->assertSame( [ 101, 102, 109 ], $this->store->object_ids( 1 ), 'A product created after materialisation is not in the batch.' );
	}

	public function test_a_filter_is_read_a_thousand_ids_at_a_time(): void {
		global $wpdb;
		$this->db       = SeededCatalogue::seed( SeededCatalogue::fillers( 1500 ) );
		$this->store    = new BatchStore( $this->db );
		$wpdb->prepared = [];

		$result = $this->factory()->create( self::UUID, self::USER, self::operations(), self::filter( Criteria::MODE_PRODUCTS ) );
		$this->assertSame( 1507, $result['total_items'] );
		$this->assertSame( 1507, count( $this->store->items ) );
		$this->assertSame( [ 'items:1:500', 'items:1:500', 'items:1:500', 'items:1:7' ], array_slice( $this->store->log, 1 ) );

		$pages = array_values( array_filter( $wpdb->prepared, static fn( array $call ): bool => str_starts_with( $call[0], 'SELECT p.ID AS id, p.post_type AS post_type FROM' ) ) );
		$this->assertCount( 2, $pages );
		$this->assertStringEndsWith( ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d', $pages[0][0] );
		$this->assertSame( [ 0, 1000 ], array_slice( $pages[0][1], -2 ) );
		$this->assertSame( [ 1993, 1000 ], array_slice( $pages[1][1], -2 ), 'The second page starts after the last id read.' );
	}

	public function test_too_many_operations_are_refused_before_anything_is_read_or_written(): void {
		global $wpdb;
		$result = $this->factory()->create( self::UUID, self::USER, array_fill( 0, 21, self::operations()[0] ), Selection::of_ids( [ 101 ] ) );
		$this->assert_refused_with_nothing_written( $result, 'bulkcat_too_many_operations' );
		$this->assertSame( [], $wpdb->queries, 'Nothing was even read.' );

		$this->assert_refused_with_nothing_written( $this->factory()->create( self::UUID, self::USER, [], Selection::of_ids( [ 101 ] ) ), 'bulkcat_no_operations' );
		$this->assert_refused_with_nothing_written(
			$this->factory()->create(
				self::UUID,
				self::USER,
				[
					[
						'action' => 'melt',
						'args'   => [],
					],
				],
				Selection::of_ids( [ 101 ] )
			),
			'bulkcat_unknown_action'
		);
	}

	public function test_a_selection_above_one_hundred_thousand_is_refused_before_anything_is_read_or_written(): void {
		global $wpdb;
		$ids    = range( 1, Selection::MAX_OBJECTS + 1 );
		$result = $this->assert_refused_with_nothing_written( $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( $ids ) ), 'bulkcat_selection_too_large' );
		$this->assertSame( Selection::MAX_OBJECTS + 1, $result->get_error_data()['count'] );
		$this->assertSame( [], $wpdb->queries );

		$this->assert_refused_with_nothing_written(
			$this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_filter( Criteria::from_array( [] ), Selection::MAX_OBJECTS + 1 ) ),
			'bulkcat_selection_too_large'
		);
		$this->assertSame( [], $wpdb->queries );

		$this->assertCount( Selection::MAX_OBJECTS, Selection::of_ids( range( 1, Selection::MAX_OBJECTS ) )->ids, 'Exactly the limit is a valid selection.' );
	}

	public function test_a_selection_with_nothing_left_is_refused_with_nothing_written(): void {
		$this->assert_refused_with_nothing_written( $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 108, 5000 ] ) ), 'bulkcat_selection_empty' );
		$this->assert_refused_with_nothing_written(
			$this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_filter( Criteria::from_array( [ 'search' => 'nothing matches this' ] ), 0 ) ),
			'bulkcat_selection_empty'
		);
	}

	public function test_a_repeated_request_id_answers_the_same_batch_and_writes_nothing(): void {
		global $wpdb;
		$first  = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 101, 102 ] ) );
		$writes = self::writes();

		$this->now += 59;
		$again      = $this->factory()->create( strtoupper( self::UUID ), self::USER, self::operations(), Selection::of_ids( [ 103, 104, 105 ] ) );
		$this->assertSame(
			[
				'batch_id'        => $first['batch_id'],
				'status'          => 'queued',
				'total_items'     => 2,
				'previewed_items' => null,
				'replayed'        => true,
			],
			$again
		);
		$this->assertSame( $writes, self::writes(), 'The repeat inserts nothing.' );
		$this->assertCount( 1, $this->store->batches );
		$this->assertCount( 2, $this->store->items );
		$this->assertSame( 1, did_action( BatchFactory::CREATED ), 'Nothing is queued twice.' );

		$other = $this->factory()->create( self::UUID, 13, self::operations(), Selection::of_ids( [ 101 ] ) );
		$this->assertFalse( $other['replayed'], 'Another user\'s request id is their own.' );
		$this->assertNotSame( $first['batch_id'], $other['batch_id'] );
	}

	public function test_a_request_id_older_than_sixty_seconds_creates_a_new_batch(): void {
		$first = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 101 ] ) );

		// A minute passes: the clock moves on, and the first row is as old as that.
		$this->now += 60;
		$this->store->batches[ $first['batch_id'] ]['created_gmt'] = gmdate( 'Y-m-d H:i:s', $this->now - 61 );
		$later = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 102 ] ) );
		$this->assertFalse( $later['replayed'] );
		$this->assertNotSame( $first['batch_id'], $later['batch_id'] );
		$this->assertCount( 2, $this->store->batches );
		$this->assertSame( [ 102 ], $this->store->object_ids( $later['batch_id'] ) );
	}

	public function test_a_submission_that_raced_past_the_replay_check_answers_the_earlier_batch(): void {
		// The earlier request's row is written but its replay record is not yet.
		$earlier = ( new BatchRepository() )->create( self::UUID, self::USER, 'bulk', self::operations(), [ 'kind' => 'ids' ], 1 );
		$this->store->sync();

		$result = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 101, 102 ] ) );
		$this->assertSame( $earlier, $result['batch_id'] );
		$this->assertTrue( $result['replayed'] );
		$this->assertSame( [ $earlier ], array_keys( $this->store->batches ), 'Exactly one batch row is left.' );
		$this->assertSame( [], $this->store->items, 'The later submission wrote no items.' );
		$this->assertSame( [ 'batch:1', 'batch:2', 'discard:wp_bulkcat_batch_items:2', 'discard:wp_bulkcat_batches:2' ], $this->store->log );
		$this->assertSame( 0, did_action( BatchFactory::CREATED ) );
	}

	public function test_items_that_cannot_be_written_leave_no_batch_behind(): void {
		global $wpdb;
		$wpdb->responses = [ '/^INSERT INTO `wp_bulkcat_batch_items`/' => false ] + $wpdb->responses;

		$result = $this->factory()->create( self::UUID, self::USER, self::operations(), Selection::of_ids( [ 101 ] ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( BatchFactory::NOT_RECORDED, $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'details', $result->get_error_data(), 'The exception text is logged, never sent.' );
		$this->assertSame( 'error', $GLOBALS['bulkcat_test']['logged'][0][0] ?? null );
		$this->assertStringContainsString( 'The batch items could not be recorded.', $GLOBALS['bulkcat_test']['logged'][0][1] );
		$this->assertSame( [ 'source' => 'bulk-catalogue-manager-for-woocommerce' ], $GLOBALS['bulkcat_test']['logged'][0][2] );
		$this->assertSame( [], $this->store->batches, 'The batch row was removed again.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['transients'] ?? [] );
		$this->assertSame( 0, did_action( BatchFactory::CREATED ) );
	}

	public function test_every_source_is_recorded_and_an_undo_names_its_batch(): void {
		$uuid = 0;
		foreach ( [ 'bulk', 'inline', 'tree' ] as $source ) {
			$result = $this->factory()->create( sprintf( '3f2504e0-4f89-41d3-9a0c-0305e82c33%02d', ++$uuid ), self::USER, self::operations(), Selection::of_ids( [ 101 ] ), $source );
			$this->assertSame( $source, ( new BatchRepository() )->find( $result['batch_id'] )['source'] );
		}
		$undo  = $this->factory()->create( sprintf( '3f2504e0-4f89-41d3-9a0c-0305e82c33%02d', ++$uuid ), self::USER, self::operations(), Selection::of_ids( [ 101 ] ), 'undo', 1 );
		$batch = ( new BatchRepository() )->find( $undo['batch_id'] );
		$this->assertSame( [ 'undo', 1 ], [ $batch['source'], $this->store->batches[ $undo['batch_id'] ]['undo_of'] ] );
	}

	public function test_nothing_is_enqueued_here(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/BatchFactory.php' );
		$this->assertStringNotContainsString( 'as_enqueue', $source );
		$this->assertStringNotContainsString( 'as_schedule', $source );
	}

	/**
	 * T69: a 10 per cent increase on the regular price, with "Also apply to variations" as given.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function increase( ?bool $variations ): array {
		$args = [
			'field'     => 'regular_price',
			'direction' => 'increase',
			'by'        => 'percent',
			'amount'    => '10',
		];
		if ( null !== $variations ) {
			$args['apply_to_variations'] = $variations;
		}
		return [
			[
				'action' => 'adjust',
				'args'   => $args,
			],
		];
	}

	/**
	 * The items of batch 1 as [ object id, type, the step fields of its own list or null ].
	 *
	 * @return list<array{0: int, 1: string, 2: list<string>|null}>
	 */
	private function items(): array {
		$this->store->sync();
		return array_values(
			array_map(
				static fn( array $item ): array => [ $item['object_id'], $item['object_type'], null === $item['operations'] ? null : array_map( static fn( array $step ): string => $step['args']['field'], $item['operations'] ) ],
				array_filter( $this->store->items, static fn( array $item ): bool => 1 === $item['batch_id'] )
			)
		);
	}

	public function test_apply_to_variations_adds_each_selected_parents_listed_variations_as_items(): void {
		// 103 is variable with 201 (published), 202 (private) and 203 (trashed); 107 is variable with none.
		$operations = [
			...self::increase( true ),
			[
				'action' => 'set_value',
				'args'   => [
					'field'               => 'status',
					'value'               => 'publish',
					'apply_to_variations' => true,
				],
			],
		];
		$result     = $this->factory()->create( self::UUID, self::USER, $operations, Selection::of_ids( [ 101, 103, 107 ] ) );

		$this->assertSame( 5, $result['total_items'], 'total_items counts the products and the variations added.' );
		$this->assertSame(
			[
				[ 101, 'product', null ],
				[ 103, 'product', null ],
				[ 107, 'product', null ],
				[ 202, 'variation', null ],
				[ 201, 'variation', null ],
			],
			$this->items(),
			'The parent keeps its row for the status it takes; its listed variations follow in menu order, the trashed one left out; a parent with no variations adds nothing.'
		);
		$batch = ( new BatchRepository() )->find( 1 );
		$this->assertSame( 5, $batch['total_items'] );
		$this->assertSame( [ 3, 2, 0, 5 ], [ $batch['selection']['selected'], $batch['selection']['variations_added'], $batch['selection']['parents_left_out'], $batch['selection']['materialised'] ] );
		$this->assertSame( [ 'batch:1', 'items:1:5' ], $this->store->log, 'Every item is written before any work is queued.' );
	}

	public function test_a_parent_with_nothing_of_its_own_to_change_is_left_out_for_its_variations(): void {
		$result = $this->factory()->create( self::UUID, self::USER, self::increase( true ), Selection::of_ids( [ 101, 103, 107 ] ) );

		$this->assertSame( 4, $result['total_items'] );
		$this->assertSame(
			[
				[ 101, 'product', null ],
				[ 107, 'product', null ],
				[ 202, 'variation', null ],
				[ 201, 'variation', null ],
			],
			$this->items(),
			'A price reaching the variations leaves out the variable product, which has no price of its own; 107, which reached nothing, keeps its row and reports why.'
		);
		$batch = ( new BatchRepository() )->find( 1 );
		$this->assertSame( [ 3, 2, 1 ], [ $batch['selection']['selected'], $batch['selection']['variations_added'], $batch['selection']['parents_left_out'] ] );
	}

	public function test_selecting_a_parent_alone_never_reaches_its_variations(): void {
		foreach ( [ false, null ] as $index => $switch ) {
			bulkcat_test_reset();
			$this->db    = SeededCatalogue::seed();
			$this->store = new BatchStore( $this->db );
			$result      = $this->factory()->create( sprintf( '3f2504e0-4f89-41d3-9a0c-0305e82c33%02d', $index ), self::USER, self::increase( $switch ), Selection::of_ids( [ 103 ] ) );
			$this->assertSame( 1, $result['total_items'], 'Off, or absent with the price default off.' );
			$this->assertSame( [ [ 103, 'product', null ] ], $this->items() );
			$this->assertArrayNotHasKey( 'selected', ( new BatchRepository() )->find( 1 )['selection'] );
		}
	}

	public function test_the_default_comes_from_the_settings_per_field_group(): void {
		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'variations_price' => true ];
		$result = $this->factory()->create( self::UUID, self::USER, self::increase( null ), Selection::of_ids( [ 103 ] ) );
		$this->assertSame( 2, $result['total_items'], 'With the price switch on, a price step reaches the variations by default.' );
		$this->assertSame( [ [ 202, 'variation', null ], [ 201, 'variation', null ] ], $this->items() );
	}

	public function test_a_variation_selected_directly_is_not_added_twice_and_keeps_the_whole_list(): void {
		$operations = [
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'status',
					'value' => 'publish',
				],
			],
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'featured',
					'value' => true,
				],
			],
		];
		$result     = $this->factory()->create( self::UUID, self::USER, $operations, Selection::of_ids( [ 202, 103 ] ) );
		$this->assertSame( 3, $result['total_items'] );
		$this->assertSame(
			[
				[ 202, 'variation', null ],
				[ 103, 'product', null ],
				[ 201, 'variation', [ 'status' ] ],
			],
			$this->items(),
			'202 keeps its own row and the whole list; the added 201 carries only the step that reaches variations (status, on by default), never the parent-only featured step.'
		);
		$this->assertSame( [ 'batch:1', 'items:1:2', 'items:1:1' ], $this->store->log, 'Rows taking the batch list, then rows with their own, in two statements before any work.' );
	}

	public function test_variations_only_mode_is_unaffected(): void {
		$result = $this->factory()->create( self::UUID, self::USER, self::increase( true ), self::filter( Criteria::MODE_VARIATIONS ) );
		$this->assertSame( 2, $result['total_items'] );
		$this->assertSame( [ [ 201, 'variation', null ], [ 202, 'variation', null ] ], $this->items() );
	}

	public function test_a_filter_of_products_and_variations_adds_nothing_it_already_holds(): void {
		$result = $this->factory()->create( self::UUID, self::USER, self::increase( true ), self::filter( Criteria::MODE_PRODUCTS_AND_VARIATIONS ) );
		$this->assertSame( count( SeededCatalogue::PRODUCTS ) + count( SeededCatalogue::VARIATIONS ) - 1, $result['total_items'], 'Nothing is added twice; the variable product 103 has no price of its own and is left out.' );
		$selection = ( new BatchRepository() )->find( 1 )['selection'];
		$this->assertSame( [ 9, 0, 1 ], [ $selection['selected'], $selection['variations_added'], $selection['parents_left_out'] ] );
	}

	public function test_the_variations_added_count_against_the_object_limit(): void {
		$factory = new BatchFactory( new Actions( new Fields() ), null, fn(): int => $this->now, 3 );
		$result  = $this->assert_refused_with_nothing_written( $factory->create( self::UUID, self::USER, self::increase( true ), Selection::of_ids( [ 101, 103, 104 ] ) ), 'bulkcat_selection_too_large' );
		$this->assertSame( [ 4, 3 ], [ $result->get_error_data()['count'], $result->get_error_data()['limit'] ], 'Two products and two variations pass a limit of three.' );
		$this->assertStringContainsString( 'more than the 3 one change can include', $result->get_error_message() );

		$this->assertSame( 3, $factory->create( self::UUID, self::USER, self::increase( true ), Selection::of_ids( [ 101, 103 ] ) )['total_items'], 'Exactly the limit is a valid batch.' );
	}
}
