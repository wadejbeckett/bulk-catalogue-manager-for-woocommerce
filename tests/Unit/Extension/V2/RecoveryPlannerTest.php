<?php
/** Typed recovery dispatch uses full identities and never legacy whole-value operations. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\External\V2\HistoryCodec;
use BulkCatalogueManager\Data\{BatchRepository, ValueCodec};
use BulkCatalogueManager\Undo\{Planner, Plan};
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/ProvenanceTest.php';
final class RecoveryPlannerTest extends TestCase {
	private function fixture(): array {
		$s       = ProvenanceTest::scenario();
		$steps   = [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'a', 'remove', [ 'v1' ] ), $s->step( 'b', 'add', [ 'v2' ] ) ];
		$c       = $s->compute( ProvenanceTest::targets(), $steps );
		$preview = $s->preview( ProvenanceTest::targets(), $steps );
		$this->assertTrue( is_array( $preview ) );
		$accepted = $s->accept( $preview );
		$this->assertTrue( is_array( $accepted ) );
		$id = $accepted['batch_id'];
		( new BatchRepository() )->find( $id );
		$s->database->batches[ $id ]['status']       = 'complete';
		$s->database->batches[ $id ]['done_items']   = 1;
		$s->database->batches[ $id ]['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$record                                      = HistoryCodec::journal( $c['history'], $c['before'], $c['after'], 'apply', 'stored', true, true );
		$s->store->seed( 'product::41', array_replace( $c['after'], [ 'a' => [ 'later' ] ] ) );
		return [ $s, $c, $id, $record ];
	}
	private function rows( int $id, array $record, string $state = 'applied' ): void {
		$GLOBALS['wpdb']->responses = [
			'/^SELECT \* FROM `wp_bulkcat_changes` WHERE batch_id = .* AND object_type = /' => [
				[
					'change_id'    => 1,
					'batch_id'     => $id,
					'object_id'    => 41,
					'object_type'  => 'product',
					'prop'         => 'x_sample_composite',
					'old_value'    => ValueCodec::encode( null ),
					'new_value'    => ValueCodec::encode( $record ),
					'modified_gmt' => '',
					'applied_gmt'  => '',
					'result'       => $state,
					'message'      => '',
				],
			],
		] + $GLOBALS['wpdb']->responses;
	}
	public function test_walk_dispatches_typed_history_without_legacy_payload_or_any_write(): void {
		[$s, $c, $id, $record] = $this->fixture();
		$this->rows( $id, $record );
		$writes = $GLOBALS['wpdb']->writes;
		$plan   = ( new Planner() )->plan( $id );
		$this->assertInstanceOf( Plan::class, $plan );
		$item = $plan->items()[0];
		$this->assertSame( 'revertible', $item['state'] );
		$this->assertSame( ProvenanceTest::targets()[0], $item['target'] );
		$this->assertSame( 'restore_external_v2', $item['operations'][0]['action'] );
		$this->assertSame( [ 'field' => 'x_sample_composite' ], $item['operations'][0]['args'] );
		$this->assertStringNotContainsString( 'contributions', json_encode( $plan->to_array() ) );
		$this->assertSame( $writes, $GLOBALS['wpdb']->writes );
		$this->assertSame( [ 'later' ], $s->value( 'product::41' )['a'] );
		$this->assertSame( [], ( new Planner() )->items( $id, [ 41 ] ), 'Legacy numeric adapter must not accept typed work.' );
	}
	public function test_unsettled_row_and_mismatched_original_refuse_even_when_value_matches(): void {
		[$s, $c, $id, $record] = $this->fixture();
		foreach ( [ 'intent', 'unclosed', 'identity', 'step' ] as $bad ) {
			$copy = $record;
			if ( 'unclosed' === $bad ) {
				$copy['closed'] = false; }
			if ( 'identity' === $bad ) {
				$copy['original']['target']['id'] = 99; }
			if ( 'step' === $bad ) {
				$copy['original']['contributions'][0]['step']['args']['part'] = 'b'; }
			$this->rows( $id, $copy, 'intent' === $bad ? 'intent' : 'applied' );
			$plan = ( new Planner() )->plan( $id );
			$this->assertInstanceOf( Plan::class, $plan );
			$this->assertSame( 'skipped', $plan->items()[0]['state'], $bad );
		}
	}
	public function test_conflict_changes_plan_hash_and_read_requires_current_permission(): void {
		[$s, $c, $id, $record] = $this->fixture();
		$this->rows( $id, $record );
		$first = ( new Planner() )->plan( $id );
		$s->store->seed( 'product::41', array_replace( $c['after'], [ 'b' => [ 'external' ] ] ) );
		$second = ( new Planner() )->plan( $id );
		$this->assertInstanceOf( Plan::class, $first );
		$this->assertInstanceOf( Plan::class, $second );
		$this->assertSame( 'conflicted', $second->items()[0]['state'] );
		$this->assertNotSame( $first->hash(), $second->hash() );
		$reads = $s->store->reads;
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] = false;
		$denied = ( new Planner() )->plan( $id );
		$this->assertSame( 'skipped', $denied->items()[0]['state'] );
		$this->assertSame( $reads, $s->store->reads );
	}
	public function test_full_target_tuples_are_never_collapsed_to_the_numeric_id(): void {
		[ $s, $c, $id, $record ] = $this->fixture();
		$this->rows( $id, $record );
		$s->database->items[999] = [
			'batch_id'        => $id,
			'object_id'       => 41,
			'object_type'     => 'variation',
			'target_taxonomy' => '',
		];
		$plan                    = ( new Planner() )->plan( $id );
		$this->assertInstanceOf( Plan::class, $plan );
		$this->assertCount( 2, $plan->items() );
		$this->assertSame( [ 'product', 'variation' ], array_column( $plan->items(), 'object_type' ) );
		$this->assertSame( [ 'revertible', 'skipped' ], array_column( $plan->items(), 'state' ) );
		$this->assertSame(
			[
				'kind'     => 'variation',
				'taxonomy' => null,
				'id'       => 41,
			],
			$plan->items()[1]['target']
		);
	}
}
