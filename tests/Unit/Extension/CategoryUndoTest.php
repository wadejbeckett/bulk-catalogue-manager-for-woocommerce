<?php
/**
 * History and undo of a category change (docs/EXTENSIONS-v2.md §5.4;
 * acceptance test V7): History names each category by its path, or says it
 * no longer exists; undo plans each category again for the person undoing
 * (it exists, edit_term, the capability, authorise()), lists a category
 * changed since as a conflict left alone unless forced, skips a deleted one
 * as unavailable, writes back in undo mode through flush_terms(), and redo
 * puts the change back; a provider removed refuses the undo with its reason;
 * an audit search for a product never finds a category of the same number.
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
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\BatchChangesController;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesField;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesProvider;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class CategoryUndoTest extends TestCase {
	private const BATCH = 70;

	private const USER = 3;

	private const NOW = 1790600000;

	private const UUID = '2c4e6a80-1b3d-4f5a-8c7e-9d0f1a2b3c4d';

	private const IDS = [ 41, 42, 43 ];

	private MemoryStore $store;

	private float $clock = 1790000000.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		ShelvesProvider::reset();
		$GLOBALS['bulkcat_test']['scheduled']               = [];
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [
			'manage_product_terms' => true,
			'edit_term'            => true,
		];
		$GLOBALS['bulkcat_test']['term_list']               = [
			self::term( 41, 'Kitchen', 0 ),
			self::term( 42, 'Pots', 41 ),
			self::term( 43, 'Pans', 41 ),
		];
		// Product 42 shares a number with Pots: its own history is not the category's.
		$GLOBALS['bulkcat_test']['posts'][42] = [ 'post_title' => 'Product 42' ];
		$this->store                          = new MemoryStore();
		$this->store->data[41]                = [ ShelvesField::META => '["west"]' ];
	}

	protected function tearDown(): void {
		delete_option( ShelvesProvider::FAULTS );
	}

	private static function term( int $id, string $name, int $parent ): object {
		return (object) [
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => strtolower( $name ),
			'parent'   => $parent,
			'taxonomy' => 'product_cat',
		];
	}

	private function actions( bool $registered = true ): Actions {
		return new Actions( new Fields(), null, $registered ? Providers::of( [ new ShelvesProvider( $this->store ) ], [ 'category_target' ] ) : Providers::none() );
	}

	private function forward(): RunnerStore {
		$operations                   = RunContext::within(
			RunContext::apply( self::USER, 0 ),
			fn(): mixed => $this->actions()->validate_list(
				[
					[
						'action' => 'x_shelves_add',
						'args'   => [
							'field'   => ShelvesField::KEY,
							'shelves' => [ 'north' ],
						],
					],
				]
			)
		);
		$operations                   = json_decode( (string) wp_json_encode( $operations ), true );
		$runner                       = new RunnerStore( self::BATCH, array_map( static fn( int $id ): array => [ $id, 'product_cat' ], self::IDS ), $operations, self::USER );
		$runner->batch['extensions']  = ValueCodec::encode( Manifest::build( Providers::of( [ new ShelvesProvider( $this->store ) ], [ 'category_target' ] )->get( 'shelves' ), $operations ) );
		$runner->batch['actions']     = ',x_shelves_add,';
		$runner->batch['created_gmt'] = gmdate( 'Y-m-d H:i:s', self::NOW - 3600 );
		$this->run_batch( self::BATCH );
		return $runner;
	}

	private function run_batch( int $batch_id ): string {
		$runner = new ChunkRunner( null, $this->actions(), null, new Settings( [] ), fn(): float => $this->clock += 1.0, 30 );
		do {
			$result = $runner->run( $batch_id );
		} while ( 'continued' === $result );
		return $result;
	}

	private function planner( ?Actions $actions = null ): Planner {
		return new Planner( new BatchRepository(), new ChangeRepository(), $actions ?? $this->actions(), new Settings( [] ), static fn(): int => self::NOW );
	}

	private function undo( int $batch_id, bool $force = false, string $uuid = self::UUID ): int {
		$plan = $this->planner()->plan( $batch_id, self::USER );
		$this->assertInstanceOf( Plan::class, $plan, $plan instanceof \WP_Error ? $plan->get_error_code() . ' ' . $plan->get_error_message() : '' );
		$made = ( new BatchFactory( $this->actions(), new BatchRepository() ) )->create_undo( $uuid, self::USER, $plan, $force );
		$this->assertTrue( is_array( $made ), $made instanceof \WP_Error ? $made->get_error_message() : '' );
		$this->run_batch( $made['batch_id'] );
		return $made['batch_id'];
	}

	public function test_history_names_each_category_by_its_path(): void {
		$this->forward();
		$GLOBALS['bulkcat_test']['term_list'] = array_values( array_filter( $GLOBALS['bulkcat_test']['term_list'], static fn( object $term ): bool => 43 !== $term->term_id ) );
		\BulkCatalogueManager\Categories\HistoryText::reset();
		$rows = ( new BatchChangesController( new BatchRepository(), new ChangeRepository(), new Fields() ) )->rows( self::BATCH, null, 1, 25 )['items'];
		$this->assertSame( [ 'Kitchen', 'Kitchen › Pots', 'A category that no longer exists' ], array_column( $rows, 'name' ) );
		$this->assertSame( [ 'product_cat' ], array_values( array_unique( array_column( $rows, 'object_type' ) ) ) );
		$this->assertSame( 'Shelves', $rows[0]['field_label'] );
		$this->assertSame( [ 'West wall', 'North wall, West wall' ], [ $rows[0]['old_value'], $rows[0]['new_value'] ] );
	}

	public function test_undo_puts_each_category_back_and_redo_returns_the_change(): void {
		$before = $this->store->data;
		$this->forward();
		$after = $this->store->data;
		$plan  = $this->planner()->plan( self::BATCH, self::USER );
		$this->assertSame( [ 'product_cat' ], array_values( array_unique( array_column( $plan->items(), 'object_type' ) ) ) );
		$this->assertSame( 3, $plan->counts()[ Plan::REVERTIBLE ] );
		ShelvesProvider::reset();
		$undo = $this->undo( self::BATCH );
		$this->assertSame( $before, $this->store->data, 'Undo leaves the provider\'s storage as it was.' );
		$this->assertSame( 'undone', ( new BatchRepository() )->status( self::BATCH ) );
		$this->assertSame( [ [ 'product_cat', self::IDS, 'undo' ] ], ShelvesProvider::$calls['flush_terms'] );
		$this->assertArrayNotHasKey( 'flush', ShelvesProvider::$calls );
		$this->undo( $undo, false, '7d8e9f00-1a2b-4c3d-8e4f-5a6b7c8d9e0f' );
		$this->assertSame( $after, $this->store->data, 'Redo puts the change back.' );
	}

	public function test_a_category_changed_since_is_left_alone_unless_forced(): void {
		$this->forward();
		$this->store->data[42] = [ ShelvesField::META => '["east","north"]' ];
		$items                 = array_column( $this->planner()->plan( self::BATCH, self::USER )->items(), null, 'object_id' );
		$this->assertSame( Plan::CONFLICTED, $items[42]['state'] );
		$this->assertSame( 'North wall', $items[42]['conflicts'][0]['expected'] );
		$this->assertSame( 'East wall, North wall', $items[42]['conflicts'][0]['current'] );
		$this->assertStringContainsString( 'category', $items[42]['reason'] );
		$this->undo( self::BATCH );
		$this->assertSame( '["east","north"]', $this->store->data[42][ ShelvesField::META ], 'A later change is preserved.' );
		$this->assertArrayNotHasKey( 43, $this->store->data, 'The others are undone.' );
	}

	public function test_permission_deletion_and_a_hidden_category_are_planned_truthfully(): void {
		$this->forward();
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_term:41'] = false;
		$GLOBALS['bulkcat_test']['term_list']                               = array_values( array_filter( $GLOBALS['bulkcat_test']['term_list'], static fn( object $term ): bool => 43 !== $term->term_id ) );
		// The undo runs in the background, logged out, with 42 hidden from term queries.
		$GLOBALS['bulkcat_test']['user_id']      = 0;
		$GLOBALS['bulkcat_test']['hidden_terms'] = [ 42 ];
		$items                                   = array_column( $this->planner()->plan( self::BATCH, self::USER )->items(), null, 'object_id' );
		$this->assertSame( [ Plan::SKIPPED, Planner::CANNOT_EDIT ], [ $items[41]['state'], $items[41]['code'] ] );
		$this->assertSame( Plan::REVERTIBLE, $items[42]['state'], 'A hidden category still exists.' );
		$this->assertSame( [ Plan::SKIPPED, Planner::MISSING ], [ $items[43]['state'], $items[43]['code'] ] );
		$this->assertSame( 'This category no longer exists, so it was left alone.', $items[43]['reason'] );
	}

	public function test_a_removed_provider_refuses_the_undo_with_its_reason(): void {
		$this->forward();
		$refusal = $this->planner( $this->actions( false ) )->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'provider_missing', $refusal->get_error_data()['reason'] );
		$this->assertSame( 'This change was made with Sample shelves, which is not active now. Turn it on again to undo this change.', $refusal->get_error_message() );
	}

	public function test_an_audit_search_for_a_product_never_finds_a_category_of_the_same_number(): void {
		$this->forward();
		$changes = new ChangeRepository();
		$this->assertSame( [], $changes->audit( [ 'object_id' => 42 ], 1, 25 ) );
	}
}
