<?php
/**
 * Variations end to end through the engine (T69–T71; SPEC §8, §9.2, §9.4,
 * §12.3, §12.4, §13): a batch queued with "Also apply to variations" on
 * materialises each parent's variations as items, runs them through the
 * chunk runner, writer and validator, and its undo leaves the catalogue as
 * it was; stock quantity on a variation that does not manage its own stock
 * is skipped with its reason, a SKU clash across products and variations
 * fails that item while the rest apply, a parent resyncs once per chunk, a
 * variation takes only published or private; a chunk holds products up to
 * the product size, then variations up to the variation size, both through
 * `bcm_batch_size` with the kind, halved together after a slow chunk; and
 * every variation is checked against the batch's user, its parent too.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RunnerStore.php';

final class VariationRunTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	private const UUID = '9b2d8a4e-1c3f-4e5a-8b7c-6d5e4f3a2b10';

	/**
	 * Variable parents and their variations, in menu order.
	 */
	private const FAMILIES = [
		500 => [ 501, 502, 503 ],
	];

	private float $now = 1790000000.0;

	private float $step = 1.0;

	protected function setUp(): void {
		bcm_test_reset();
		$this->now                            = 1790000000.0;
		$this->step                           = 1.0;
		$GLOBALS['bcm_test']['user_caps']     = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bcm_test']['scheduled']     = [];
		$GLOBALS['bcm_test']['term_counting'] = [];
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bcm_test']['post_meta_updates'] ?? [], 'The engine wrote meta.' );
	}

	private function runner( array $settings = [], int $limit = 30 ): ChunkRunner {
		return new ChunkRunner( null, new Registry( new Fields() ), null, new Settings( $settings ), fn(): float => $this->now += $this->step, $limit );
	}

	/**
	 * Run a batch's chain to its end.
	 */
	private function run_all( int $batch_id ): string {
		$runner = $this->runner();
		do {
			$result = $runner->run( $batch_id );
		} while ( 'continued' === $result );
		return $result;
	}

	/**
	 * Normalise and send through JSON, as a batch stores its list.
	 *
	 * @param list<array{0: string, 1: array<string, mixed>}> $steps [ action, args ] pairs.
	 * @return list<array<string, mixed>>
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

	private static function object( int $id, string $type, array $props ): SavedProduct {
		$product                                = new SavedProduct( $type, [ 'id' => $id ] + $props );
		$GLOBALS['bcm_test']['products'][ $id ] = $product;
		return $product;
	}

	/**
	 * Variable product 500 with variations 501–503 at 10, 20 and 30, and simple product 600 at 50.
	 */
	private static function catalogue(): void {
		self::object(
			500,
			'variable',
			[
				'status'        => 'publish',
				'regular_price' => '',
				'sku'           => 'COAT',
			]
		);
		foreach ( self::FAMILIES[500] as $index => $id ) {
			self::object(
				$id,
				'variation',
				[
					'parent_id'      => 500,
					'status'         => 'publish',
					'regular_price'  => sprintf( '%d.00', 10 * ( $index + 1 ) ),
					'sale_price'     => '',
					'sku'            => 'COAT-' . $id,
					'manage_stock'   => 502 === $id,
					'stock_quantity' => 502 === $id ? 4 : null,
				]
			);
		}
		self::object(
			600,
			'simple',
			[
				'status'        => 'publish',
				'regular_price' => '50.00',
				'sale_price'    => '',
				'sku'           => 'HAT',
			]
		);
	}

	private static function prop( int $id, string $prop ): mixed {
		return $GLOBALS['bcm_test']['products'][ $id ]->props[ $prop ] ?? null;
	}

	/**
	 * The claim statements run so far.
	 *
	 * @return list<string>
	 */
	private static function claims(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE `wp_bcm_batch_items` SET claim_token = \'' ) ) );
	}

	/**
	 * Answer BatchFactory's two catalogue reads from the fixture products:
	 * the id list's types and each variable parent's listed variations.
	 */
	private static function answer_catalogue_reads(): void {
		global $wpdb;
		$wpdb->responses = [
			'/^SELECT p\.ID AS id, p\.post_type AS post_type FROM `wp_posts` AS p WHERE p\.ID IN \(([\d, ]+)\)/' => static function ( string $sql ): array {
				preg_match( '/p\.ID IN \(([\d, ]+)\)/', $sql, $m );
				$rows = [];
				foreach ( array_map( 'intval', explode( ', ', $m[1] ) ) as $id ) {
					$product = $GLOBALS['bcm_test']['products'][ $id ] ?? null;
					if ( null !== $product ) {
						$rows[] = [
							'id'        => (string) $id,
							'post_type' => $product->is_type( 'variation' ) ? 'product_variation' : 'product',
						];
					}
				}
				return $rows;
			},
			'/^SELECT v\.ID AS id, v\.post_parent AS parent_id FROM `wp_posts` AS v WHERE v\.post_parent IN \(([\d, ]+)\)/' => static function ( string $sql ): array {
				preg_match( '/v\.post_parent IN \(([\d, ]+)\)/', $sql, $m );
				$rows = [];
				foreach ( array_map( 'intval', explode( ', ', $m[1] ) ) as $parent ) {
					foreach ( self::FAMILIES[ $parent ] ?? [] as $child ) {
						$rows[] = [
							'id'        => (string) $child,
							'parent_id' => (string) $parent,
						];
					}
				}
				return $rows;
			},
		] + $wpdb->responses;
	}

	public function test_a_batch_with_the_switch_on_materialises_runs_and_undoes_exactly(): void {
		self::catalogue();
		$store = new RunnerStore( self::BATCH, [], [], self::USER );
		self::answer_catalogue_reads();
		$before = [];
		foreach ( [ 500, 501, 502, 503, 600 ] as $id ) {
			$before[ $id ] = $GLOBALS['bcm_test']['products'][ $id ]->props;
		}

		$increase = $this->steps(
			[
				[
					'adjust',
					[
						'field'               => 'regular_price',
						'direction'           => 'increase',
						'by'                  => 'percent',
						'amount'              => '10',
						'apply_to_variations' => true,
					],
				],
			]
		);
		$created  = ( new BatchFactory( new Registry( new Fields() ), null, fn(): int => (int) $this->now ) )->create( self::UUID, self::USER, $increase, Selection::of_ids( [ 600, 500 ] ) );
		$this->assertTrue( is_array( $created ), $created instanceof \WP_Error ? $created->get_error_message() : '' );
		$batch_id = $created['batch_id'];
		$this->assertSame( 4, $created['total_items'] );
		$this->assertSame(
			[ [ 600, 'product' ], [ 501, 'variation' ], [ 502, 'variation' ], [ 503, 'variation' ] ],
			array_map( static fn( array $item ): array => [ $item['object_id'], $item['object_type'] ], $store->items_of( $batch_id ) ),
			'Materialised at creation, before any work: the product, then each variation of the parent (which has no price of its own).'
		);

		$this->assertSame( 'complete', $this->run_all( $batch_id ) );
		$this->assertSame( [ '55.00', '11.00', '22.00', '33.00' ], [ self::prop( 600, 'regular_price' ), self::prop( 501, 'regular_price' ), self::prop( 502, 'regular_price' ), self::prop( 503, 'regular_price' ) ] );
		$this->assertSame( '', self::prop( 500, 'regular_price' ), 'The parent is never written.' );
		$this->assertSame( [ 500 ], $GLOBALS['bcm_test']['variable_syncs'], 'The parent is resynchronised once, through WooCommerce, after its variations.' );
		$journal = array_values( array_filter( $store->changes, static fn( array $row ): bool => $batch_id === $row['batch_id'] ) );
		$this->assertSame( [ 'product', 'variation', 'variation', 'variation' ], array_column( $journal, 'object_type' ) );

		$plan = ( new Planner( null, null, new Registry( new Fields() ), new Settings( [] ), fn(): int => (int) $this->now ) )->plan( $batch_id, self::USER );
		$this->assertFalse( $plan instanceof \WP_Error, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		$undo = ( new BatchFactory( new Registry( new Fields() ), null, fn(): int => (int) $this->now ) )->create_undo( '9b2d8a4e-1c3f-4e5a-8b7c-6d5e4f3a2b11', self::USER, $plan, false );
		$this->assertTrue( is_array( $undo ), $undo instanceof \WP_Error ? $undo->get_error_message() : '' );
		$this->assertSame( 4, $undo['total_items'], 'The undo names exactly the objects the batch touched.' );
		$this->assertSame( 'complete', $this->run_all( $undo['batch_id'] ) );

		foreach ( $before as $id => $props ) {
			$this->assertSame( $props['regular_price'], self::prop( $id, 'regular_price' ), "Object $id is back as it was." );
		}
		$this->assertSame( [ 500, 500 ], $GLOBALS['bcm_test']['variable_syncs'], 'The undo resynchronises the parent again, once.' );
	}

	public function test_stock_quantity_on_a_variation_not_managing_its_stock_is_skipped_with_its_reason(): void {
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 501, 'variation' ], [ 502, 'variation' ] ],
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'stock_quantity',
							'value' => 9,
						],
					],
				]
			),
			self::USER
		);

		$this->assertSame( 'partial', $this->run_all( self::BATCH ), 'Completed with errors, not failed.' );
		$reason = 'This variation does not manage its own stock, so its stock quantity was not changed. Turn on Manage stock for the variation first.';
		$this->assertSame( $reason, Validator::unmanaged_stock_reason() );
		$this->assertSame( [ 'skipped', $reason ], [ $store->item( 501 )['state'], $store->item( 501 )['message'] ] );
		$this->assertSame( 'done', $store->item( 502 )['state'] );
		$this->assertNull( self::prop( 501, 'stock_quantity' ) );
		$this->assertSame( 9, self::prop( 502, 'stock_quantity' ) );
		$rows = array_values( array_filter( $store->changes, static fn( array $row ): bool => 501 === $row['object_id'] ) );
		$this->assertSame( [ [ 'stock_quantity', 'skipped', $reason ] ], array_map( static fn( array $row ): array => [ $row['prop'], $row['result'], $row['message'] ], $rows ), 'Recorded skipped with its reason, not ignored.' );

		// An increase says the same.
		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 501, 'variation' ] ],
			$this->steps(
				[
					[
						'adjust',
						[
							'field'     => 'stock_quantity',
							'direction' => 'increase',
							'by'        => 'amount',
							'amount'    => '2',
						],
					],
				]
			),
			self::USER
		);
		$this->run_all( self::BATCH );
		$this->assertSame( [ 'skipped', $reason ], [ $store->item( 501 )['state'], $store->item( 501 )['message'] ] );
	}

	public function test_a_sku_another_product_holds_fails_that_item_and_the_rest_apply(): void {
		self::catalogue();
		$GLOBALS['bcm_test']['skus'] = [ 'X-COAT-501' => 900 ];
		$store                       = new RunnerStore(
			self::BATCH,
			[ [ 501, 'variation' ], [ 502, 'variation' ], [ 600, 'product' ] ],
			$this->steps(
				[
					[
						'affix_sku',
						[
							'field'    => 'sku',
							'position' => 'prefix',
							'text'     => 'X-',
						],
					],
				]
			),
			self::USER
		);

		$this->assertSame( 'partial', $this->run_all( self::BATCH ) );
		$clash = 'A product with the SKU X-COAT-501 already exists, so this SKU was not applied.';
		$this->assertSame( [ 'failed', $clash ], [ $store->item( 501 )['state'], $store->item( 501 )['message'] ], 'The §4.8 sentence, checked across products and variations.' );
		$this->assertSame( [ 'COAT-501', 'X-COAT-502', 'X-HAT' ], [ self::prop( 501, 'sku' ), self::prop( 502, 'sku' ), self::prop( 600, 'sku' ) ], 'The rest of the chunk applies.' );
		$failed = array_values( array_filter( $store->changes, static fn( array $row ): bool => 'failed' === $row['result'] ) );
		$this->assertSame( [ [ 501, 'variation', 'sku', $clash ] ], array_map( static fn( array $row ): array => [ $row['object_id'], $row['object_type'], $row['prop'], $row['message'] ], $failed ) );
		$this->assertSame( [ 1, 2 ], [ $store->batch()['failed_items'], $store->batch()['changed_items'] ] );
		$this->assertSame( [ 500 ], $GLOBALS['bcm_test']['variable_syncs'], 'Two variations of one parent in one chunk: one resync.' );
	}

	public function test_a_variation_takes_only_published_or_private(): void {
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 500, 'product' ], [ 501, 'variation' ], [ 502, 'variation' ] ],
			$this->steps(
				[
					[
						'set_value',
						[
							'field'               => 'status',
							'value'               => 'draft',
							'apply_to_variations' => true,
						],
					],
				]
			),
			self::USER
		);
		$this->assertSame( 'partial', $this->run_all( self::BATCH ) );
		$sentence = 'On a variation, Status can only be: Published, Private.';
		$this->assertSame( 'draft', self::prop( 500, 'status' ) );
		foreach ( [ 501, 502 ] as $id ) {
			$this->assertSame( [ 'skipped', $sentence ], [ $store->item( $id )['state'], $store->item( $id )['message'] ], 'Pushed down by the switch, the product carries the draft: skipped with the reason.' );
			$this->assertSame( 'publish', self::prop( $id, 'status' ) );
		}

		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 501, 'variation' ] ],
			$this->steps(
				[
					[
						'set_value',
						[
							'field'               => 'status',
							'value'               => 'draft',
							'apply_to_variations' => false,
						],
					],
				]
			),
			self::USER
		);
		$this->assertSame( 'failed', $this->run_all( self::BATCH ), 'Chosen on the variation itself, a draft fails.' );
		$this->assertSame( [ 'failed', $sentence ], [ $store->item( 501 )['state'], $store->item( 501 )['message'] ] );

		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 500, 'product' ], [ 501, 'variation' ] ],
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'status',
							'value' => 'private',
						],
					],
				]
			),
			self::USER
		);
		$this->assertSame( 'complete', $this->run_all( self::BATCH ) );
		$this->assertSame( [ 'private', 'private' ], [ self::prop( 500, 'status' ), self::prop( 501, 'status' ) ] );
	}

	public function test_a_variable_product_s_own_price_is_skipped_when_the_switch_is_on(): void {
		self::catalogue();
		$store = new RunnerStore(
			self::BATCH,
			[ [ 500, 'product' ] ],
			$this->steps(
				[
					[
						'set_value',
						[
							'field'               => 'regular_price',
							'value'               => '12',
							'apply_to_variations' => true,
						],
					],
				]
			),
			self::USER
		);
		$this->run_all( self::BATCH );
		$this->assertSame( 'skipped', $store->item( 500 )['state'], 'Its variations carry the price: skipped, not failed.' );
		$this->assertSame( 0, $store->batch()['failed_items'] );
	}

	/**
	 * $count simple products from $first at a regular price of 10.
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private static function products( int $count, int $first ): array {
		$objects = [];
		for ( $id = $first; $id < $first + $count; $id++ ) {
			self::object(
				$id,
				'simple',
				[
					'regular_price' => '10',
				]
			);
			$objects[] = [ $id, 'product' ];
		}
		return $objects;
	}

	/**
	 * $count variations of parent 9000 from $first at a regular price of 10.
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private static function variations( int $count, int $first ): array {
		self::object( 9000, 'variable', [] );
		$objects = [];
		for ( $id = $first; $id < $first + $count; $id++ ) {
			self::object(
				$id,
				'variation',
				[
					'parent_id'     => 9000,
					'regular_price' => '10',
				]
			);
			$objects[] = [ $id, 'variation' ];
		}
		return $objects;
	}

	private function increase(): array {
		return $this->steps(
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
	}

	public function test_a_chunk_claims_products_up_to_their_size_then_variations_up_to_theirs(): void {
		// Products only.
		$store = new RunnerStore( self::BATCH, self::products( 60, 1001 ), $this->increase(), self::USER );
		$this->runner()->run( self::BATCH );
		$this->assertCount( 1, self::claims() );
		$this->assertStringContainsString( "AND object_type <> 'variation' ORDER BY item_id ASC LIMIT 50", self::claims()[0] );
		$this->assertSame( 50, array_count_values( $store->states() )['done'] );

		// Variations only: the product claim finds nothing, the variation claim takes 100.
		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$store                            = new RunnerStore( self::BATCH, self::variations( 150, 2001 ), $this->increase(), self::USER );
		$this->runner()->run( self::BATCH );
		$this->assertCount( 2, self::claims() );
		$this->assertStringContainsString( "AND object_type = 'variation' ORDER BY item_id ASC LIMIT 100", self::claims()[1] );
		$this->assertSame(
			[
				'done'    => 100,
				'pending' => 50,
			],
			array_count_values( $store->states() )
		);
		$this->assertSame( [ 9000 ], $GLOBALS['bcm_test']['variable_syncs'], 'A hundred variations of one parent: one resync for the chunk.' );

		// Mixed and interleaved: products first, each kind against its own size.
		bcm_test_reset();
		$GLOBALS['bcm_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$variations                       = self::variations( 150, 2001 );
		$products                         = self::products( 60, 1001 );
		$mixed                            = [];
		foreach ( $variations as $index => $variation ) {
			$mixed[] = $variation;
			if ( isset( $products[ $index ] ) ) {
				$mixed[] = $products[ $index ];
			}
		}
		$store  = new RunnerStore( self::BATCH, $mixed, $this->increase(), self::USER );
		$chunks = [];
		$runner = $this->runner();
		do {
			$before = array_count_values( array_map( static fn( array $item ): string => $item['object_type'] . ':' . $item['state'], $store->items ) );
			$result = $runner->run( self::BATCH );
			$after  = array_count_values( array_map( static fn( array $item ): string => $item['object_type'] . ':' . $item['state'], $store->items ) );
			foreach ( [ 'product', 'variation' ] as $kind ) {
				$gained = ( $after[ "$kind:done" ] ?? 0 ) - ( $before[ "$kind:done" ] ?? 0 );
				if ( $gained > 0 ) {
					$chunks[] = "$kind:$gained";
				}
			}
		} while ( 'continued' === $result );
		$this->assertSame( [ 'product:50', 'product:10', 'variation:100', 'variation:50' ], $chunks, 'A mixed batch counts its variations against the variation size.' );
		$this->assertSame( 'complete', $result );
	}

	public function test_both_sizes_come_from_their_setting_through_the_filter_with_the_kind(): void {
		$seen = [];
		add_filter(
			'bcm_batch_size',
			static function ( int $size, int $batch_id, string $kind ) use ( &$seen ): int {
				$seen[] = [ $size, $batch_id, $kind ];
				return 'variation' === $kind ? 30 : $size;
			},
			10,
			3
		);
		new RunnerStore( self::BATCH, self::variations( 50, 2001 ), $this->increase(), self::USER );
		$this->runner( [ 'variation_batch_size' => 70 ] )->run( self::BATCH );
		$this->assertSame( [ [ 50, self::BATCH, 'product' ], [ 70, self::BATCH, 'variation' ] ], $seen );
		$this->assertStringContainsString( "AND object_type = 'variation' ORDER BY item_id ASC LIMIT 30", self::claims()[1] );

		$this->assertSame( 100, ( new Settings( [] ) )->variation_batch_size(), 'The variation size defaults to 100.' );
		$this->assertSame( 50, ( new Settings( [] ) )->batch_size() );
	}

	public function test_a_slow_variation_chunk_halves_both_sizes_and_says_why(): void {
		$store      = new RunnerStore( self::BATCH, self::variations( 400, 2001 ), $this->increase(), self::USER );
		$this->step = 25.0; // 25 of 30 seconds: over 70 per cent.
		$this->runner()->run( self::BATCH );
		$this->assertSame( 25, $store->batch()['chunk_size'], 'The product size is recorded halved.' );
		$this->assertSame( 'The last group of 100 variations took 25 of the 30 seconds the server allows, so this change now works on 25 products or 50 variations at a time.', $store->batch()['note'] );
		$this->runner()->run( self::BATCH );
		$this->assertStringContainsString( "AND object_type = 'variation' ORDER BY item_id ASC LIMIT 50", self::claims()[3], 'The variation size follows, halved.' );
		$this->assertSame( 50, ChunkRunner::variation_size( 25, 50, 100 ) );
		$this->assertSame( 20, ChunkRunner::variation_size( 10, 50, 100 ), 'Each within the floor and ceiling.' );
		$this->assertSame( 10, ChunkRunner::variation_size( 10, 50, 40 ) );
		$this->assertSame( 100, ChunkRunner::variation_size( 50, 50, 100 ) );
	}

	public function test_background_work_rechecks_each_variation_and_its_parent_against_the_batch_user(): void {
		self::object( 700, 'variable', [] );
		foreach ( [ 701, 702 ] as $id ) {
			self::object(
				$id,
				'variation',
				[
					'parent_id'     => 700,
					'regular_price' => '10',
				]
			);
		}
		$objects = [ ...self::variations( 3, 2001 ), [ 701, 'variation' ], [ 702, 'variation' ] ];
		// Revoked since the batch was queued: one variation of its own, and every variation of 700 through the parent.
		$GLOBALS['bcm_test']['user_caps'][ self::USER ]['edit_post:2002'] = false;
		$GLOBALS['bcm_test']['user_caps'][ self::USER ]['edit_post:700']  = false;
		$store = new RunnerStore( self::BATCH, $objects, $this->increase(), self::USER );

		$this->assertSame( 'partial', $this->run_all( self::BATCH ) );
		foreach ( [ 2002, 701, 702 ] as $id ) {
			$this->assertSame( 'skipped', $store->item( $id )['state'], "$id is skipped." );
			$this->assertStringContainsString( 'permission', $store->item( $id )['message'] );
			$this->assertSame( '10', self::prop( $id, 'regular_price' ), "$id is not written." );
		}
		foreach ( [ 2001, 2003 ] as $id ) {
			$this->assertSame( [ 'done', '11.00' ], [ $store->item( $id )['state'], self::prop( $id, 'regular_price' ) ] );
		}
		$skipped = array_values( array_filter( $store->changes, static fn( array $row ): bool => 'skipped' === $row['result'] ) );
		$this->assertSame( [ 2002, 701, 702 ], array_column( $skipped, 'object_id' ), 'Each refusal is recorded against the variation.' );
		$this->assertSame( [ 9000 ], $GLOBALS['bcm_test']['variable_syncs'], 'Only a parent with a saved variation is resynchronised.' );
	}
}
