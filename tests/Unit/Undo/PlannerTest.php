<?php
/**
 * The undo planner (T55; SPEC §9.4, §9.5, §12.6), over the chunk runner's
 * in-memory batch, items and journal: the plan runs the applied rows in
 * reverse, sets each touched field back to its old value as normalised
 * operations, compares values in their canonical form, classifies every
 * object (revertible, conflicted per field, skipped with each reason),
 * plans only the applied part of a partial batch, refuses an undone,
 * pruned, running or out-of-retention batch with its reason, counts, hashes
 * stably, and writes nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class PlannerTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	/**
	 * A day after the fixture batch was created.
	 */
	private const NOW = 1790208000; // 2026-09-24 00:00:00 UTC.

	private RunnerStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$GLOBALS['bulkcat_test']['user_id']   = self::USER; // The person undoing, unless a test says otherwise.
		$GLOBALS['bulkcat_test']['terms']     = [
			'product_cat' => [ 5, 7 ],
			'product_tag' => [ 8 ],
		];
		$this->store                          = new RunnerStore( self::BATCH, [], [], self::USER );
		$this->store->batch['status']         = 'complete';
		$this->store->batch['finished_gmt']   = '2026-09-23 08:05:00';
		$this->store->batch['done_items']     = 1;
		$this->store->batch['total_items']    = 1;
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'The planner wrote to the database.' );
		foreach ( $GLOBALS['wpdb']->queries as $sql ) {
			// The read stubs log a cache priming as a pseudo-statement: a read too.
			$this->assertSame( 1, preg_match( '#^(SELECT|/\* (_prime_post_caches|update_meta_cache) \*/)#', $sql ), 'The planner only reads: ' . $sql );
		}
		foreach ( $GLOBALS['bulkcat_test']['products'] ?? [] as $product ) {
			$this->assertSame( [], $product->sets, 'The planner set a value on a product.' );
			$this->assertSame( [], $product->saves, 'The planner saved a product.' );
		}
	}

	/**
	 * A planner over the fixture, the given settings and the fixed clock.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 */
	private function planner( array $settings = [], int $now = self::NOW ): Planner {
		return new Planner( null, null, new Registry( new Fields() ), new Settings( $settings ), static fn(): int => $now );
	}

	private function plan( array $settings = [] ): Plan {
		$plan = $this->planner( $settings )->plan( self::BATCH );
		$this->assertInstanceOf( Plan::class, $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		return $plan;
	}

	/**
	 * Append one journal row, as the writer records it.
	 */
	private function change( int $object_id, string $prop, mixed $old, mixed $new, string $result = 'applied', string $type = 'product', string $message = '' ): void {
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
			'message'      => $message,
		];
	}

	/**
	 * Register a product for wc_get_product().
	 *
	 * @param array<string, mixed> $props Props.
	 */
	private static function product( int $id, array $props, string $type = 'simple' ): SavedProduct {
		$product                                    = new SavedProduct( $type, [ 'id' => $id ] + $props );
		$GLOBALS['bulkcat_test']['products'][ $id ] = $product;
		return $product;
	}

	/**
	 * The normalised form of a raw operation list, as BatchFactory stores it.
	 */
	private static function normalised( array $operations ): array {
		$list = ( new Registry( new Fields() ) )->validate_list( $operations );
		self::assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return $list;
	}

	/**
	 * An item of a plan by object id.
	 *
	 * @return array<string, mixed>
	 */
	private static function item( Plan $plan, int $object_id ): array {
		foreach ( $plan->items() as $item ) {
			if ( $object_id === $item['object_id'] ) {
				return $item;
			}
		}
		throw new \LogicException( "No item for $object_id." );
	}

	/**
	 * Answer the lookup of undo batches naming the fixture batch with these rows,
	 * through the SQL evaluator beside the fixture's own batch.
	 *
	 * @param list<array<string, mixed>> $undos Undo batch rows.
	 */
	private function undo_batches( array $undos ): void {
		global $wpdb;
		$wpdb->responses = [
			'/^SELECT batch_id, status FROM `wp_bulkcat_batches` WHERE undo_of = /' => fn( string $sql ): array => ( new SqlEvaluator( [ 'wp_bulkcat_batches' => array_merge( [ $this->store->batch ], $undos ) ] ) )->rows( $sql ),
		] + $wpdb->responses;
	}

	private function assert_refused( string $reason, ?Planner $planner = null ): \WP_Error {
		$refusal = ( $planner ?? $this->planner() )->plan( self::BATCH );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'bulkcat_not_reversible', $refusal->get_error_code() );
		$this->assertSame( 409, $refusal->get_error_data()['status'] );
		$this->assertSame( $reason, $refusal->get_error_data()['reason'] );
		$this->assertStringNotContainsString( 'bulkcat_', $refusal->get_error_message() );
		return $refusal;
	}

	public function test_a_multi_operation_batch_reverts_in_reverse_order_to_the_old_values(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1001, 'sale_price', '', '9.00' );
		$this->change( 1002, 'regular_price', '20.00', '22.00' );
		$this->change( 1002, 'category_ids', [ 5 ], [ 5, 7 ] );
		$this->change( 1003, 'stock_quantity', null, 4 );
		$this->change( 1003, 'sku', '', 'SHOE-42' );
		self::product(
			1001,
			[
				'regular_price' => '11.00',
				'sale_price'    => '9.00',
			]
		);
		self::product(
			1002,
			[
				'regular_price' => '22.00',
				'category_ids'  => [ 7, 5 ],
			]
		);
		self::product(
			1003,
			[
				'stock_quantity' => 4,
				'sku'            => 'SHOE-42',
				'manage_stock'   => true,
			]
		);

		$plan = $this->plan();

		$this->assertSame( [ 1003, 1002, 1001 ], array_column( $plan->items(), 'object_id' ), 'The object journalled last is undone first.' );
		$this->assertSame(
			[
				'revertible' => 3,
				'conflicted' => 0,
				'skipped'    => 0,
			],
			$plan->counts()
		);
		$this->assertSame(
			self::normalised(
				[
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'sku',
							'value' => '',
						],
					],
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'stock_quantity',
							'value' => '',
						],
					],
				]
			),
			self::item( $plan, 1003 )['operations'],
			'Fields go back in reverse order: the SKU set back to empty (it takes no clear), the stock quantity to unset.'
		);
		$this->assertNull( self::item( $plan, 1003 )['operations'][1]['args']['value'], 'Empty stock is null, not 0.' );
		$this->assertSame(
			self::normalised(
				[
					[
						'action' => 'replace_terms',
						'args'   => [
							'field' => 'category_ids',
							'terms' => [ 5 ],
						],
					],
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'regular_price',
							'value' => '20.00',
						],
					],
				]
			),
			self::item( $plan, 1002 )['operations']
		);
		$this->assertSame( [ 'clear', 'set_value' ], array_column( self::item( $plan, 1001 )['operations'], 'action' ) );
		$this->assertSame( [ 'sale_price', 'regular_price' ], array_column( array_column( self::item( $plan, 1001 )['operations'], 'args' ), 'field' ) );
		foreach ( $plan->items() as $item ) {
			$this->assertSame( Plan::REVERTIBLE, $item['state'] );
			$this->assertSame( '', $item['reason'] );
			$this->assertSame( [], $item['conflicts'] );
			$this->assertSame( 'product', $item['object_type'] );
		}
		$this->assertSame( self::BATCH, $plan->to_array()['batch_id'] );
		$this->assertSame( [ 'batch_id', 'items', 'counts', 'hash' ], array_keys( $plan->to_array() ) );
	}

	public function test_several_applied_rows_for_one_field_go_back_to_the_oldest_value(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1001, 'regular_price', '11.00', '12.10' );
		self::product( 1001, [ 'regular_price' => '12.10' ] );

		$item = self::item( $this->plan(), 1001 );

		$this->assertSame( Plan::REVERTIBLE, $item['state'] );
		$this->assertCount( 1, $item['operations'] );
		$this->assertSame( '10.00', $item['operations'][0]['args']['value'] );
	}

	public function test_an_empty_term_list_goes_back_by_removing_what_the_batch_added(): void {
		$this->change( 1001, 'tag_ids', [], [ 8 ] );
		self::product( 1001, [ 'tag_ids' => [ 8 ] ] );

		$item = self::item( $this->plan(), 1001 );

		$this->assertSame(
			self::normalised(
				[
					[
						'action' => 'remove_terms',
						'args'   => [
							'field' => 'tag_ids',
							'terms' => [ 8 ],
						],
					],
				]
			),
			$item['operations']
		);
	}

	public function test_values_compare_in_canonical_form_by_field_type(): void {
		$fields = new Fields();
		$price  = $fields->get( 'regular_price' );
		$stock  = $fields->get( 'stock_quantity' );
		$sku    = $fields->get( 'sku' );
		$weight = $fields->get( 'weight' );
		$terms  = $fields->get( 'category_ids' );
		$switch = $fields->get( 'featured' );

		$this->assertTrue( Planner::same( $price, '10', '10.00' ), 'Prices compare at the shop precision.' );
		$this->assertTrue( Planner::same( $price, 0, '0.00' ) );
		$this->assertFalse( Planner::same( $price, '', '0.00' ), 'An empty price is not zero.' );
		$this->assertTrue( Planner::same( $price, null, '' ) );
		$this->assertFalse( Planner::same( $price, '10.01', '10.00' ) );
		$this->assertFalse( Planner::same( $stock, null, 0 ), 'Unset stock is not zero stock.' );
		$this->assertTrue( Planner::same( $stock, '4', 4 ) );
		$this->assertFalse( Planner::same( $sku, '', '0' ), 'Text compares exactly.' );
		$this->assertFalse( Planner::same( $sku, 'abc', 'ABC' ) );
		$this->assertTrue( Planner::same( $weight, '1.5', '1.50' ) );
		$this->assertFalse( Planner::same( $weight, '', '0' ) );
		$this->assertTrue( Planner::same( $terms, [ 7, 5 ], [ 5, 7 ] ), 'A term list is a set.' );
		$this->assertFalse( Planner::same( $terms, [ 5 ], [ 5, 7 ] ) );
		$this->assertTrue( Planner::same( $switch, true, 'yes' ) );
		$this->assertFalse( Planner::same( $switch, false, true ) );
	}

	public function test_the_plan_compares_what_the_product_holds_now_in_canonical_form(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1002, 'sale_price', '', '0.00' );
		$this->change( 1003, 'stock_quantity', 5, 0 );
		$this->change( 1004, 'sku', 'A', '0' );
		self::product( 1001, [ 'regular_price' => '11' ] );
		self::product( 1002, [ 'sale_price' => '' ] );
		self::product(
			1003,
			[
				'stock_quantity' => null,
				'manage_stock'   => true,
			]
		);
		self::product( 1004, [ 'sku' => '' ] );

		$plan = $this->plan();

		$this->assertSame( Plan::REVERTIBLE, self::item( $plan, 1001 )['state'], '11 is the 11.00 the batch wrote.' );
		foreach ( [ 1002, 1003, 1004 ] as $id ) {
			$this->assertSame( Plan::CONFLICTED, self::item( $plan, $id )['state'], "Object $id: empty, null, 0 and '0.00' are different values." );
		}
		$this->assertSame(
			[
				[
					'field'    => 'stock_quantity',
					'expected' => 0,
					'current'  => null,
				],
			],
			self::item( $plan, 1003 )['conflicts']
		);
	}

	public function test_one_conflicted_field_leaves_the_same_objects_other_fields_revertible(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1001, 'sale_price', '', '9.00' );
		self::product(
			1001,
			[
				'regular_price' => '11.00',
				'sale_price'    => '8.50',
			]
		);

		$plan = $this->plan();
		$item = self::item( $plan, 1001 );

		$this->assertSame( Plan::CONFLICTED, $item['state'] );
		$this->assertSame( 'changed_since', $item['code'] );
		$this->assertStringContainsString( 'changed since this batch ran', $item['reason'] );
		$this->assertSame(
			[
				[
					'field'    => 'sale_price',
					'expected' => '9.00',
					'current'  => '8.50',
				],
			],
			$item['conflicts'],
			'Only the field changed since is a conflict.'
		);
		$fields = array_column( array_column( $item['operations'], 'args' ), 'field' );
		$this->assertSame( [ 'sale_price', 'regular_price' ], $fields, 'The operations still cover every field, for Revert anyway.' );
		$this->assertSame( [ 'regular_price' ], array_values( array_diff( $fields, array_column( $item['conflicts'], 'field' ) ) ), 'The regular price is still revertible.' );
		$this->assertSame(
			[
				'revertible' => 0,
				'conflicted' => 1,
				'skipped'    => 0,
			],
			$plan->counts()
		);
	}

	public function test_a_variation_reverts_although_its_parent_was_edited_separately(): void {
		$this->change( 2001, 'regular_price', '5.00', '6.00', 'applied', 'variation' );
		self::product(
			1001,
			[
				'name' => 'Renamed since',
				'type' => 'variable',
			],
			'variable'
		);
		self::product(
			2001,
			[
				'regular_price' => '6.00',
				'parent_id'     => 1001,
			],
			'variation'
		);

		$plan = $this->plan();
		$item = self::item( $plan, 2001 );

		$this->assertSame( Plan::REVERTIBLE, $item['state'] );
		$this->assertSame( 'variation', $item['object_type'] );
		$this->assertCount( 1, $plan->items(), 'The parent, which this batch did not journal, is not in the plan.' );
		$this->assertSame( '5.00', $item['operations'][0]['args']['value'] );
	}

	public function test_each_skip_reason(): void {
		// 1001: every row of it was itself skipped or failed.
		$this->change( 1001, 'regular_price', '10.00', '10.00', 'skipped', 'product', 'A plain reason.' );
		$this->change( 1001, 'sku', 'A', 'A', 'failed', 'product', 'A plain reason.' );
		// 1002: deleted since.
		$this->change( 1002, 'regular_price', '10.00', '11.00' );
		// 2003: journalled as a variation, now something else.
		$this->change( 2003, 'regular_price', '10.00', '11.00', 'applied', 'variation' );
		self::product( 2003, [ 'regular_price' => '11.00' ] );
		// 1004: the batch's user may no longer edit it.
		$this->change( 1004, 'regular_price', '10.00', '11.00' );
		self::product( 1004, [ 'regular_price' => '11.00' ] );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_post:1004'] = false;
		// 1005: the category it was in has been deleted.
		$this->change( 1005, 'category_ids', [ 99 ], [ 5 ] );
		self::product( 1005, [ 'category_ids' => [ 5 ] ] );
		// 1006: a field no longer in the registry.
		$this->change( 1006, 'retired_field', 'a', 'b' );
		self::product( 1006, [] );

		$plan = $this->plan();

		$expected = [
			1001 => 'not_applied',
			1002 => 'missing',
			2003 => 'missing',
			1004 => 'cannot_edit',
			1005 => 'cannot_restore',
			1006 => 'cannot_restore',
		];
		foreach ( $expected as $id => $code ) {
			$item = self::item( $plan, $id );
			$this->assertSame( Plan::SKIPPED, $item['state'], "Object $id." );
			$this->assertSame( $code, $item['code'], "Object $id." );
			$this->assertSame( [], $item['operations'], "Object $id." );
			$this->assertNotSame( '', $item['reason'], "Object $id." );
			$this->assertStringNotContainsString( 'bulkcat_', $item['reason'], "Object $id." );
		}
		$this->assertStringContainsString( 'no longer exists', self::item( $plan, 1002 )['reason'] );
		$this->assertStringContainsString( 'can no longer edit', self::item( $plan, 1004 )['reason'] );
		$this->assertSame(
			[
				'revertible' => 0,
				'conflicted' => 0,
				'skipped'    => 6,
			],
			$plan->counts()
		);
	}

	public function test_edit_rights_are_those_of_the_person_undoing_not_the_batch_author(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );
		// The author has since been demoted; an administrator undoes the batch.
		$GLOBALS['bulkcat_test']['user_caps'] = [
			self::USER => [],
			9          => [ 'edit_post' => true ],
		];

		$plan = $this->planner()->plan( self::BATCH, 9 );
		$this->assertSame( Plan::REVERTIBLE, self::item( $plan, 1001 )['state'], 'The batch can still be undone by someone who may edit the product.' );

		$GLOBALS['bulkcat_test']['user_caps'] = [
			self::USER => [ 'edit_post' => true ],
			9          => [],
		];
		$item                                 = self::item( $this->planner()->plan( self::BATCH, 9 ), 1001 );
		$this->assertSame( [ Plan::SKIPPED, 'cannot_edit' ], [ $item['state'], $item['code'] ], 'The author\'s rights do not let someone else undo.' );

		$GLOBALS['bulkcat_test']['user_id'] = 9;
		$this->assertSame( Plan::SKIPPED, self::item( $this->planner()->plan( self::BATCH ), 1001 )['state'], 'Without a user, the current user\'s rights apply.' );
	}

	public function test_an_intent_row_a_killed_run_left_is_planned_like_an_applied_one(): void {
		// The run died between WooCommerce's save and the journal's settlement, and was never retried (cancelled).
		$this->change( 1001, 'regular_price', '10.00', '11.00', 'pending' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );

		$item = self::item( $this->plan(), 1001 );
		$this->assertSame( Plan::REVERTIBLE, $item['state'], 'The save landed, so the change can be put back.' );
		$this->assertSame( '10.00', $item['operations'][0]['args']['value'] ?? null );
	}

	public function test_a_partial_failed_or_cancelled_batch_plans_the_applied_part_only(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1001, 'sku', 'A', 'TAKEN', 'failed', 'product', 'A product with the SKU TAKEN already exists, so this SKU was not applied.' );
		$this->change( 1002, 'regular_price', '10.00', '10.00', 'failed', 'product', 'Could not save.' );
		self::product(
			1001,
			[
				'regular_price' => '11.00',
				'sku'           => 'A',
			]
		);
		self::product( 1002, [ 'regular_price' => '10.00' ] );

		foreach ( [ 'partial', 'failed', 'cancelled' ] as $status ) {
			$this->store->batch['status'] = $status;
			$plan                         = $this->plan();
			$this->assertSame( [ 1002, 1001 ], array_column( $plan->items(), 'object_id' ), $status );
			$this->assertSame( [ 'regular_price' ], array_column( array_column( self::item( $plan, 1001 )['operations'], 'args' ), 'field' ), "$status: the failed SKU is not put back." );
			$this->assertSame( Plan::REVERTIBLE, self::item( $plan, 1001 )['state'], $status );
			$this->assertSame( 'not_applied', self::item( $plan, 1002 )['code'], $status );
		}
	}

	public function test_the_plan_for_an_undo_of_an_undo_puts_the_first_batches_values_back(): void {
		$this->store->batch['source']  = 'undo';
		$this->store->batch['undo_of'] = 11;
		// The undo (this batch) set 11.00 back to 10.00; undoing it redoes 11.00.
		$this->change( 1001, 'regular_price', '11.00', '10.00' );
		self::product( 1001, [ 'regular_price' => '10.00' ] );

		$item = self::item( $this->plan(), 1001 );

		$this->assertSame( Plan::REVERTIBLE, $item['state'] );
		$this->assertSame( '11.00', $item['operations'][0]['args']['value'] );
	}

	public function test_an_unfinished_batch_is_refused(): void {
		foreach ( [ 'queued', 'running' ] as $status ) {
			$this->store->batch['status'] = $status;
			$this->assert_refused( 'in_progress' );
		}
		$this->store->batch['status'] = 'undoing';
		$this->assert_refused( 'undo_in_progress' );
	}

	public function test_an_already_undone_batch_is_refused(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );

		$this->store->batch['status'] = 'undone';
		$this->assert_refused( 'already_undone' );

		$this->store->batch['status'] = 'complete';
		$undo                         = [
			'batch_id' => 40,
			'status'   => 'complete',
			'source'   => 'undo',
			'undo_of'  => self::BATCH,
		];
		$this->undo_batches( [ $undo ] );
		$refusal = $this->assert_refused( 'already_undone' );
		$this->assertSame( 40, $refusal->get_error_data()['undo_batch_id'] );
		$this->assertStringContainsString( 'already been undone', $refusal->get_error_message() );

		$this->undo_batches( [ [ 'status' => 'running' ] + $undo ] );
		$this->assert_refused( 'undo_in_progress' );

		$this->undo_batches(
			[
				[ 'status' => 'failed' ] + $undo,
				[
					'batch_id' => 41,
					'undo_of'  => 99,
				] + $undo,
			]
		);
		$this->assertInstanceOf( Plan::class, $this->planner()->plan( self::BATCH ), 'A failed undo, or one of another batch, does not count.' );
	}

	public function test_a_pruned_batch_is_refused(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );
		$this->store->batch['pruned_gmt'] = '2026-09-23 09:00:00';
		$this->assert_refused( 'pruned' );

		$this->store->batch['pruned_gmt'] = null;
		$this->assertInstanceOf( Plan::class, $this->planner()->plan( self::BATCH ), 'With pruned_gmt unset, the batch is judged by its journal.' );

		$this->store->changes = [];
		$this->assert_refused( 'pruned' );

		$this->store->batch['done_items'] = 0;
		$plan                             = $this->planner()->plan( self::BATCH );
		$this->assertInstanceOf( Plan::class, $plan, 'A batch cancelled before any work has an empty plan, not a pruned one.' );
		$this->assertSame( [], $plan->items() );
	}

	public function test_a_batch_outside_the_retention_window_is_refused(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );
		$created = strtotime( '2026-09-23 08:00:00 UTC' );

		$refusal = $this->assert_refused( 'retention', $this->planner( [ 'history_days' => 30 ], $created + 31 * 86400 ) );
		$this->assertSame( 30, $refusal->get_error_data()['history_days'] );
		$this->assertStringContainsString( '30 days', $refusal->get_error_message() );

		$this->assertInstanceOf( Plan::class, $this->planner( [ 'history_days' => 30 ], $created + 29 * 86400 )->plan( self::BATCH ) );
		$this->assertInstanceOf( Plan::class, $this->planner( [ 'history_days' => 0 ], $created + 3000 * 86400 )->plan( self::BATCH ), 'History kept indefinitely never expires.' );
	}

	public function test_a_missing_batch_is_not_found(): void {
		$refusal = $this->planner()->plan( 999 );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'bulkcat_not_found', $refusal->get_error_code() );
		$this->assertSame( 404, $refusal->get_error_data()['status'] );
	}

	public function test_the_journal_is_read_past_one_page(): void {
		foreach ( range( 1, 501 ) as $n ) {
			$this->change( 5000 + $n, 'regular_price', '1.00', '1.00', 'skipped', 'product', 'Already.' );
		}

		$plan = $this->plan();

		$this->assertCount( 501, $plan->items() );
		$this->assertSame( 5501, $plan->items()[0]['object_id'] );
		$this->assertSame( 5001, $plan->items()[500]['object_id'] );
	}

	public function test_objects_are_read_a_page_at_a_time_and_each_page_released(): void {
		// T76: the single batch read plans the whole batch; at 5,000 products that outgrew 128 MB.
		foreach ( range( 1, 250 ) as $n ) {
			$this->change( 7000 + $n, 'regular_price', '10.00', '11.00' );
			self::product( 7000 + $n, [ 'regular_price' => '11.00' ] );
		}
		$GLOBALS['bulkcat_test']['calls'] = [];

		$plan = $this->plan();

		$this->assertSame( 250, $plan->counts()[ Plan::REVERTIBLE ] );
		$calls  = array_values( array_filter( $GLOBALS['bulkcat_test']['calls'], static fn( array $call ): bool => in_array( $call[0], [ '_prime_post_caches', 'wp_cache_flush_runtime' ], true ) ) );
		$primed = array_map( static fn( array $call ): int => count( $call[1][0] ), array_filter( $calls, static fn( array $call ): bool => '_prime_post_caches' === $call[0] ) );
		$this->assertSame( [ 100, 100, 50 ], array_values( $primed ), 'Newest first, a page of 100 at a time.' );
		$this->assertSame( [ '_prime_post_caches', 'wp_cache_flush_runtime', '_prime_post_caches', 'wp_cache_flush_runtime', '_prime_post_caches' ], array_column( $calls, 0 ) );
	}

	public function test_conflicts_come_first_for_the_preview(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1002, 'regular_price', '10.00', '11.00' );
		$this->change( 1003, 'regular_price', '10.00', '11.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );
		self::product( 1002, [ 'regular_price' => '15.00' ] );

		$plan = $this->plan();

		$this->assertSame( [ 1003, 1002, 1001 ], array_column( $plan->items(), 'object_id' ) );
		$this->assertSame( [ 1002, 1001, 1003 ], array_column( $plan->conflicts_first(), 'object_id' ) );
	}

	public function test_the_hash_is_stable_and_binds_the_batch_the_plan_and_the_settings(): void {
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$product = self::product( 1001, [ 'regular_price' => '11.00' ] );

		$hash = $this->plan()->hash();
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertSame( $hash, $this->plan()->hash(), 'The same plan gives the same hash.' );
		$this->assertNotSame( $hash, $this->plan( [ 'record_unchanged' => true ] )->hash(), 'A bound setting changes it.' );
		$this->assertSame( $hash, $this->plan( [ 'history_days' => 120 ] )->hash(), 'A setting outside the bound ones does not.' );

		$product->props['regular_price'] = '12.00';
		$this->assertNotSame( $hash, $this->plan()->hash(), 'A conflict appearing changes it.' );
		$product->props['regular_price'] = '11.00';
		$this->assertSame( $hash, $this->plan()->hash() );

		$this->store->batch['batch_id'] = 13;
		foreach ( $this->store->changes as $index => $row ) {
			$this->store->changes[ $index ]['batch_id'] = 13;
		}
		$other = ( $this->planner() )->plan( 13 );
		$this->assertInstanceOf( Plan::class, $other );
		$this->assertSame( self::item( $this->planner()->plan( 13 ), 1001 )['operations'], self::item( $other, 1001 )['operations'] );
		$this->assertNotSame( $hash, $other->hash(), 'The same contents for another batch hash differently.' );
	}

	public function test_the_planners_conflict_count_makes_the_history_state_partially_reversible(): void {
		global $wpdb;
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1002, 'regular_price', '20.00', '22.00' );
		self::product( 1001, [ 'regular_price' => '11.00' ] );
		$changed                           = self::product( 1002, [ 'regular_price' => '21.00' ] );
		$this->store->batch['total_items'] = 2;
		$this->store->batch['done_items']  = 2;
		$wpdb->responses                   = [
			'/^SELECT batch_id, undo_of FROM `wp_bulkcat_batches` WHERE undo_of IN /' => static fn(): array => [],
			'/^SELECT batch_id, COUNT\(\*\) AS applied FROM `wp_bulkcat_changes` /' => static fn(): array => [
				[
					'batch_id' => self::BATCH,
					'applied'  => 2,
				],
			],
		] + $wpdb->responses;
		$batch                             = ( new BatchRepository() )->find( self::BATCH );
		$this->assertNotNull( $batch );
		$clock = static fn(): int => self::NOW;

		$reversibility = new Reversibility( null, null, new Settings( [] ), $clock, Planner::counter( null, null, $clock ) );
		$this->assertSame(
			[
				'state'     => Reversibility::PARTIALLY_REVERSIBLE,
				'conflicts' => 1,
				'reason'    => null,
			],
			$reversibility->of( $batch ),
			'One product changed since the batch ran.'
		);
		$this->assertSame( 1, $this->planner()->conflicts( $batch ) );

		$progress = new Progress( null, null, $clock );
		$this->assertSame( Reversibility::PARTIALLY_REVERSIBLE, $progress->report_for( $batch, false )['reversibility']['state'], 'Progress, and so the single batch read, counts conflicts with the planner by default.' );
		$GLOBALS['bulkcat_test']['products_loaded'] = [];
		$this->assertSame(
			[
				'state'     => Reversibility::REVERSIBLE,
				'conflicts' => null,
				'reason'    => null,
			],
			$progress->reports( [ $batch ] )[0]['reversibility'],
			'The history list reports from the cheap signals, conflicts not counted (T56).'
		);
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'], 'The list reads no product.' );

		$changed->props['regular_price'] = '22.00';
		$this->assertSame( Reversibility::REVERSIBLE, ( new Reversibility( null, null, new Settings( [] ), $clock, Planner::counter( null, null, $clock ) ) )->of( $batch )['state'], 'Back to what the batch left: reversible again.' );

		$this->store->batch['status'] = 'running';
		$this->assertSame( 0, $this->planner()->conflicts( $this->store->batch ), 'A batch that cannot be planned counts none.' );
	}

	/**
	 * T85: the plan hash of a whole-catalogue batch is worked out without a
	 * second, sorted copy of every item and one encoding of them all (the
	 * undo of 20,013 products peaked at 101 MB in a web request), and it is
	 * the hash the whole-payload form gave.
	 */
	public function test_the_plan_hash_is_the_whole_payload_hash_worked_out_as_a_stream(): void {
		$planner   = $this->planner( [ 'variations_price' => true ] );
		$hash      = new \ReflectionMethod( Planner::class, 'hash' );
		$settings  = new \ReflectionProperty( Planner::class, 'settings' );
		$reference = static function ( int $batch_id, array $items ) use ( $planner, $settings ): string {
			$sorted = static function ( mixed $value ) use ( &$sorted ): mixed {
				if ( ! is_array( $value ) ) {
					return $value;
				}
				if ( ! array_is_list( $value ) ) {
					ksort( $value );
				}
				return array_map( $sorted, $value );
			};
			$bound  = array_intersect_key( $settings->getValue( $planner )->values(), array_flip( \BulkCatalogueManager\Engine\Preview::SETTINGS ) );
			ksort( $bound );
			$payload = [
				'batch'    => $batch_id,
				'items'    => $sorted( $items ),
				'settings' => $bound,
			];
			return hash_hmac( 'sha256', 'bulk-catalogue-manager-for-woocommerce/undo/1/' . (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
		};
		$item      = [
			'state'       => Plan::REVERTIBLE,
			'object_type' => 'product',
			'object_id'   => 7,
			'operations'  => [
				[
					'args'   => [
						'value'        => 'Café 12',
						'field'        => 'regular_price',
						'skip_already' => false,
					],
					'action' => 'set_value',
				],
			],
			'code'        => '',
			'reason'      => '',
			'conflicts'   => [
				[
					'field'    => 'regular_price',
					'expected' => '12.00',
					'current'  => '13.50',
				],
			],
		];
		foreach ( [
			'none'    => [],
			'one'     => [ $item ],
			'several' => [
				$item,
				array_merge(
					$item,
					[
						'object_id' => 9,
						'conflicts' => [],
					]
				),
			],
		] as $case => $items ) {
			$this->assertSame( $reference( self::BATCH, $items ), $hash->invoke( $planner, self::BATCH, $items ), $case );
		}
	}

	public function test_the_plan_hash_of_a_large_plan_adds_almost_no_memory(): void {
		$planner = $this->planner();
		$hash    = new \ReflectionMethod( Planner::class, 'hash' );
		$items   = [];
		for ( $id = 1; $id <= 20000; $id++ ) {
			$items[] = [
				'object_id'   => $id,
				'object_type' => 'product',
				'operations'  => [
					[
						'action' => 'set_value',
						'args'   => [
							'field'        => 'visible_on_site',
							'value'        => 'on',
							'skip_already' => false,
						],
					],
				],
				'state'       => Plan::REVERTIBLE,
				'code'        => '',
				'reason'      => '',
				'conflicts'   => [],
			];
		}
		gc_collect_cycles();
		memory_reset_peak_usage();
		$start = memory_get_usage();
		$hash->invoke( $planner, self::BATCH, $items );
		$added = memory_get_peak_usage() - $start;
		$this->assertLessThan( 2 * 1048576, $added, 'Hashing a 20,000-item plan added ' . round( $added / 1048576, 1 ) . ' MB.' );
	}
}
