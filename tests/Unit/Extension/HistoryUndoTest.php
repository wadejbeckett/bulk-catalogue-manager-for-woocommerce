<?php
/**
 * History, undo and reversibility of external changes (T108;
 * docs/EXTENSIONS.md §8.3, §8.4, §11; acceptance tests E9, E10 and the
 * unit half of E11): history rows render from the batch's manifest and the
 * journalled text, never a raw value, and stay readable without the
 * provider; the undo plan restores through `restore_external` with conflicts
 * listed as text, round trips the provider's storage byte for byte, redoes,
 * undoes the applied part of a partial batch, upgrades older formats and
 * refuses newer ones; a missing provider refuses the undo with a plain
 * reason that clears when it returns.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\BatchChangesController;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class HistoryUndoTest extends TestCase {
	private const BATCH = 40;

	private const USER = 3;

	private const IDS = [ 1001, 1002, 1003 ];

	private const NOW = 1790600000;

	private const UUID = '0b8f1b56-2f0a-4c49-8d2c-5f7e3c1a9e10';

	private MemoryStore $store;

	private float $clock = 1790000000.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		SampleProvider::reset();
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [
			'edit_post'     => true,
			'edit_products' => true,
		];
		$GLOBALS['bulkcat_test']['scheduled']               = [];
		foreach ( self::IDS as $id ) {
			$GLOBALS['bulkcat_test']['products'][ $id ] = new SavedProduct(
				'simple',
				[
					'id'   => $id,
					'name' => "Product $id",
				]
			);
			$GLOBALS['bulkcat_test']['posts'][ $id ]    = [ 'post_title' => "Product $id" ];
		}
		$this->store             = new MemoryStore();
		$this->store->data[1001] = [ '_sample_labels' => '["blue"]' ];
		$this->store->data[1003] = [ '_sample_note' => 'Kept' ];
	}

	private function actions( bool $registered = true ): Actions {
		return new Actions( new Fields(), null, $registered ? Providers::of( [ new SampleProvider( $this->store ) ] ) : Providers::none() );
	}

	private function steps( array $steps ): array {
		$list = RunContext::within( RunContext::apply( self::USER, 0 ), fn(): mixed => $this->actions()->validate_list( $steps ) );
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return json_decode( (string) wp_json_encode( $list ), true );
	}

	private static function add( string $label ): array {
		return [
			'action' => 'x_sample_add',
			'args'   => [
				'field'  => 'x_sample_labels',
				'labels' => [ $label ],
			],
		];
	}

	/**
	 * Run the forward batch: Add labels "red" on the three products.
	 */
	private function forward(): RunnerStore {
		$operations                   = $this->steps( [ self::add( 'red' ) ] );
		$runner                       = new RunnerStore( self::BATCH, self::IDS, $operations, self::USER );
		$runner->batch['extensions']  = ValueCodec::encode( Manifest::build( Providers::of( [ new SampleProvider( $this->store ) ] )->get( 'sample' ), $operations ) );
		$runner->batch['actions']     = ',x_sample_add,';
		$runner->batch['created_gmt'] = gmdate( 'Y-m-d H:i:s', self::NOW - 3600 );
		$this->run_batch( self::BATCH );
		return $runner;
	}

	private function run_batch( int $batch_id, ?Actions $actions = null ): string {
		$runner = new ChunkRunner( null, $actions ?? $this->actions(), null, new Settings( [] ), fn(): float => $this->clock += 1.0, 30 );
		do {
			$result = $runner->run( $batch_id );
		} while ( 'continued' === $result );
		return $result;
	}

	private function planner( ?Actions $actions = null ): Planner {
		return new Planner( new BatchRepository(), new ChangeRepository(), $actions ?? $this->actions(), new Settings( [] ), static fn(): int => self::NOW );
	}

	/**
	 * Plan, make and run the undo of a batch.
	 *
	 * @param int    $batch_id Batch.
	 * @param bool   $force    Revert anyway.
	 * @param string $uuid     Request id.
	 * @return int The undo batch.
	 */
	private function undo( int $batch_id, bool $force = false, string $uuid = self::UUID ): int {
		$plan = $this->planner()->plan( $batch_id, self::USER );
		$this->assertInstanceOf( Plan::class, $plan, $plan instanceof \WP_Error ? $plan->get_error_code() . ' ' . $plan->get_error_message() : '' );
		$made = ( new BatchFactory( $this->actions(), new BatchRepository() ) )->create_undo( $uuid, self::USER, $plan, $force );
		$this->assertTrue( is_array( $made ), $made instanceof \WP_Error ? $made->get_error_message() : '' );
		$this->run_batch( $made['batch_id'] );
		return $made['batch_id'];
	}

	public function test_the_changes_feed_renders_from_the_manifest_and_the_journalled_text(): void {
		$runner = $this->forward();
		$feed   = new BatchChangesController( new BatchRepository(), new ChangeRepository(), new Fields() );
		$rows   = $feed->rows( self::BATCH, null, 1, 25 )['items'];
		$this->assertCount( 3, $rows );
		$this->assertSame( 'x_sample_labels', $rows[0]['field'] );
		$this->assertSame( 'Sample labels', $rows[0]['field_label'] );
		$this->assertSame( 'Blue', $rows[0]['old_value'] );
		$this->assertSame( 'Blue, Red', $rows[0]['new_value'] );
		$this->assertSame( 'No labels', $rows[1]['old_value'] );
		$json = (string) wp_json_encode( $rows );
		$this->assertStringNotContainsString( '"external":1', $json, 'No envelope reaches a response.' );
		$this->assertStringNotContainsString( '["blue","red"]', $json, 'No raw value reaches a response.' );

		$runner->batch['extensions'] = null;
		$bare                        = ( new BatchChangesController( new BatchRepository(), new ChangeRepository(), new Fields() ) )->rows( self::BATCH, null, 1, 25 )['items'];
		$this->assertSame( 'A value from an add-on that is not active', $bare[0]['field_label'], 'Without a manifest: a plain phrase, no key.' );
		$this->assertSame( 'Blue, Red', $bare[0]['new_value'], 'The text stays readable.' );
	}

	public function test_undo_round_trips_the_providers_storage_byte_for_byte_and_redo_works(): void {
		$before = $this->store->data;
		$this->forward();
		$after = $this->store->data;
		$this->assertNotSame( $before, $after );
		$undo = $this->undo( self::BATCH );
		$this->assertSame( $before, $this->store->data, 'Undo leaves the provider\'s storage as it was.' );
		$this->assertSame( 'undone', ( new BatchRepository() )->status( self::BATCH ) );
		$undo_batch = ( new BatchRepository() )->find( $undo );
		$this->assertSame( 'Sample labels', $undo_batch['extensions']['sample']['label'], 'An undo batch copies its source\'s manifest.' );
		$this->assertSame( [ 'restore_external' ], $undo_batch['actions'] );
		$this->assertSame( 'undo', end( SampleProvider::$flushed )[1], 'The undo runs in undo mode.' );
		$this->undo( $undo, false, '1c9e2b77-3d1b-4e5a-9f6c-7a8b9c0d1e2f' );
		$this->assertSame( $after, $this->store->data, 'Redo (the undo of the undo) puts the change back.' );
	}

	public function test_external_undo_preserves_a_value_changed_after_confirmation(): void {
		$this->forward();
		$plan = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( Plan::class, $plan );
		$made = ( new BatchFactory( $this->actions(), new BatchRepository() ) )->create_undo( self::UUID, self::USER, $plan, false );
		$this->assertTrue( is_array( $made ) );
		$this->store->data[1002] = [ '_sample_labels' => '["green"]' ];
		$this->run_batch( $made['batch_id'] );
		$this->assertSame( [ '_sample_labels' => '["green"]' ], $this->store->data[1002] ?? [], 'The newer edit must survive a queued undo.' );
	}

	public function test_conflicts_are_listed_as_text_and_left_alone_unless_forced(): void {
		$this->forward();
		$this->store->data[1002] = [ '_sample_labels' => '["green"]' ];
		$plan                    = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( Plan::class, $plan );
		$items = array_column( $plan->items(), null, 'object_id' );
		$this->assertSame( Plan::CONFLICTED, $items[1002]['state'] );
		$this->assertSame(
			[
				[
					'field'    => 'x_sample_labels',
					'expected' => 'Red',
					'current'  => 'Green',
				],
			],
			$items[1002]['conflicts']
		);
		$this->assertSame( Plan::REVERTIBLE, $items[1001]['state'] );
		$this->assertSame(
			[
				[
					'action' => 'restore_external',
					'args'   => [
						'field' => 'x_sample_labels',
						'value' => [ 'blue' ],
					],
				],
			],
			$items[1001]['operations']
		);
		$this->undo( self::BATCH );
		$this->assertSame( '["green"]', $this->store->data[1002]['_sample_labels'], 'Left alone.' );
		$this->assertSame( '["blue"]', $this->store->data[1001]['_sample_labels'] );
	}

	public function test_revert_anyway_writes_over_a_conflict(): void {
		$this->forward();
		$this->store->data[1002] = [ '_sample_labels' => '["green"]' ];
		$this->undo( self::BATCH, true );
		$this->assertArrayNotHasKey( '_sample_labels', $this->store->data[1002] ?? [], 'Put back to no labels, over the conflict.' );
	}

	public function test_a_partial_batch_is_undone_for_the_part_applied(): void {
		update_option( 'sample_provider_faults', [ 'write_error' => [ 1002 ] ] );
		$this->forward();
		delete_option( 'sample_provider_faults' );
		$plan  = $this->planner()->plan( self::BATCH, self::USER );
		$items = array_column( $plan->items(), null, 'object_id' );
		$this->assertSame( Plan::SKIPPED, $items[1002]['state'] );
		$this->assertSame( Planner::NOT_APPLIED, $items[1002]['code'] );
		$this->undo( self::BATCH );
		$this->assertSame( '["blue"]', $this->store->data[1001]['_sample_labels'] );
		$this->assertArrayNotHasKey( '_sample_labels', $this->store->data[1003] ?? [] );
	}

	public function test_an_older_format_is_upgraded_and_a_newer_one_is_refused(): void {
		$this->forward();
		update_option( 'sample_provider_faults', [ 'format' => 2 ] );
		$this->undo( self::BATCH );
		$this->assertSame( '["blue"]', $this->store->data[1001]['_sample_labels'], 'Format 1 values upgraded to 2 and put back.' );

		bulkcat_test_reset();
		$this->setUp();
		update_option( 'sample_provider_faults', [ 'format' => 2 ] );
		$this->forward();
		update_option( 'sample_provider_faults', [ 'format' => 1 ] );
		$plan = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( Plan::class, $plan );
		foreach ( $plan->items() as $item ) {
			$this->assertSame( Plan::SKIPPED, $item['state'] );
			$this->assertSame( Planner::CANNOT_RESTORE, $item['code'], 'A value newer than the provider reads is never guessed at.' );
		}
	}

	public function test_unknown_values_and_the_providers_refusal_skip_their_object(): void {
		$this->forward();
		update_option(
			'sample_provider_faults',
			[
				'unknown' => [ 1001 ],
				'refuse'  => [ 1002 ],
			]
		);
		$items = array_column( $this->planner()->plan( self::BATCH, self::USER )->items(), null, 'object_id' );
		$this->assertSame( Planner::CANNOT_RESTORE, $items[1001]['code'] );
		$this->assertSame( 'The sample labels of this product could not be read.', $items[1001]['reason'] );
		$this->assertSame( Planner::CANNOT_EDIT, $items[1002]['code'] );
		$this->assertSame( 'The sample add-on does not allow changes to this product.', $items[1002]['reason'] );
		$this->assertSame( Plan::REVERTIBLE, $items[1003]['state'] );
	}

	public function test_a_missing_provider_refuses_the_undo_until_it_returns(): void {
		$runner  = $this->forward();
		$refusal = $this->planner( $this->actions( false ) )->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'bulkcat_not_reversible', $refusal->get_error_code() );
		$this->assertSame( 'provider_missing', $refusal->get_error_data()['reason'] );
		$this->assertSame( 409, $refusal->get_error_data()['status'] );
		$this->assertSame( 'This change was made with Sample labels, which is not active now. Turn it on again to undo this change.', $refusal->get_error_message() );

		// D-1: registered but unavailable carries its own reason, under another data.reason.
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$unavailable = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $unavailable );
		$this->assertSame( 'bulkcat_not_reversible', $unavailable->get_error_code() );
		$this->assertSame( 'provider_unavailable', $unavailable->get_error_data()['reason'] );
		$this->assertSame( 'This change was made with Sample labels, which cannot run now, so it cannot be undone yet. Sample labels needs its settings saved before it can be used.', $unavailable->get_error_message() );
		$batch = ( new BatchRepository() )->find( self::BATCH );
		$held  = ( new Reversibility( null, null, new Settings( [] ), static fn(): int => self::NOW, null, $this->actions()->providers() ) )->of( $batch );
		$this->assertSame( Reversibility::PROVIDER_UNAVAILABLE, $held['reason'] );
		$this->assertSame( 'Sample labels', $held['provider'] );
		$this->assertSame( 'Sample labels needs its settings saved before it can be used.', $held['provider_reason'] );
		delete_option( 'sample_provider_faults' );

		$gone = ( new Reversibility( null, null, new Settings( [] ), static fn(): int => self::NOW, null, Providers::none() ) )->of( $batch );
		$this->assertSame( Reversibility::NOT_REVERSIBLE, $gone['state'] );
		$this->assertSame( Reversibility::PROVIDER_MISSING, $gone['reason'] );
		$this->assertSame( 'Sample labels', $gone['provider'] );
		$this->assertArrayNotHasKey( 'provider_reason', $gone );
		$back = ( new Reversibility( null, null, new Settings( [] ), static fn(): int => self::NOW, null, $this->actions()->providers() ) )->of( $batch );
		$this->assertSame( Reversibility::REVERSIBLE, $back['state'], 'It clears when the provider returns.' );
		$this->assertArrayNotHasKey( 'provider', $back );
		$this->assertArrayNotHasKey( 'provider_reason', $back );
		$this->assertInstanceOf( Plan::class, $this->planner()->plan( self::BATCH, self::USER ) );
		unset( $runner );
	}

	public function test_a_batch_report_shows_an_external_steps_arguments_only_to_who_may_use_the_action(): void {
		// Coordinator's point (b): the add-on's own argument values (which may
		// name the site's data) reach only users holding the action's capability.
		$this->forward();
		$GLOBALS['bulkcat_test']['user_id'] = self::USER;
		$batch                              = ( new BatchRepository() )->find( self::BATCH );
		$report                             = static fn( Providers $providers ): array => ( new Progress( null, null, static fn(): int => self::NOW, new Reversibility( null, null, new Settings( [] ), static fn(): int => self::NOW, null, $providers ), $providers ) )->report_for( $batch, false );
		$this->assertSame( [ 'red' ], $report( $this->actions()->providers() )['operations'][0]['args']['labels'], 'Shown to a user who may use the action.' );

		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_post' => true ];
		$this->assertSame(
			[
				'field'        => 'x_sample_labels',
				'skip_already' => true,
			],
			$report( $this->actions()->providers() )['operations'][0]['args'],
			'Withheld from one who may not: only the core\'s own keys remain.'
		);

		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
		$this->assertArrayNotHasKey( 'labels', $report( Providers::none() )['operations'][0]['args'], 'Withheld when the add-on is missing, since its capability is unknown.' );
		$GLOBALS['bulkcat_test']['user_id'] = 0;
		$this->assertArrayNotHasKey( 'labels', $report( $this->actions()->providers() )['operations'][0]['args'], 'Withheld without a user.' );
	}

	public function test_restore_external_is_internal(): void {
		$step    = [
			'action' => 'restore_external',
			'args'   => [
				'field' => 'x_sample_labels',
				'value' => [ 'blue' ],
			],
		];
		$refused = $this->actions()->validate_list( [ $step ] );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_unknown_action', $refused->get_error_code(), 'No client may send it.' );
		$this->assertNotContains( 'restore_external', $this->actions()->ids() );
		$this->assertTrue( is_array( $this->actions()->validate_list( [ $step ], true ) ), 'The undo planner may.' );
		foreach ( [
			[ 'field' => 'x_sample_labels' ],
			[
				'field' => 'sale_price',
				'value' => '1',
			],
			[
				'field' => 'x_sample_labels',
				'value' => [ 'blue' ],
				'extra' => 1,
			],
		] as $args ) {
			$this->assertInstanceOf(
				\WP_Error::class,
				$this->actions()->validate_list(
					[
						[
							'action' => 'restore_external',
							'args'   => $args,
						],
					],
					true
				)
			);
		}
	}
}
