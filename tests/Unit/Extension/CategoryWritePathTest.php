<?php
/**
 * The category target's write path (docs/EXTENSIONS-v2.md §5.2, §5.4;
 * acceptance test V7): a queued category change writes each term through
 * its provider with a typed CategorySubject, never a product of the same
 * number; per term, in order, the term still exists in product_cat (read
 * unfiltered, so a background run sees categories term queries hide), the
 * recorded user may edit_term, holds the action's capability, and the
 * provider authorises it; the journal records `product_cat` items and rows;
 * prime_terms() and flush_terms() replace prime() and flush(); and a retry
 * with intent rows replays without applying twice.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesField;
use BulkCatalogueManager\Tests\Support\Extension\ShelvesProvider;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class CategoryWritePathTest extends TestCase {
	private const BATCH = 60;

	private const USER = 3;

	private MemoryStore $store;

	private float $now = 1790000000.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		ShelvesProvider::reset();
		ShelvesField::$written                              = [];
		$GLOBALS['bulkcat_test']['scheduled']               = [];
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [
			'manage_product_terms' => true,
			'edit_term'            => true,
			'edit_post'            => true,
			'edit_products'        => true,
		];
		$GLOBALS['bulkcat_test']['term_list']               = [
			self::term( 41, 'Kitchen', 0 ),
			self::term( 42, 'Pots', 41 ),
			self::term( 43, 'Pans', 41 ),
		];
		// A product with the same number as a category: never read, written or saved.
		$GLOBALS['bulkcat_test']['products'][41] = new SavedProduct(
			'simple',
			[
				'id'   => 41,
				'name' => 'Product 41',
			]
		);
		$this->store                             = new MemoryStore();
		$this->store->data[41]                   = [ ShelvesField::META => '["west"]' ];
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][41]->saves, 'The product with the same number was saved.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [], 'The core wrote post meta.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['updated_terms'] ?? [], 'The core updated a term.' );
		delete_option( ShelvesProvider::FAULTS );
	}

	private static function term( int $id, string $name, int $parent ): object {
		return (object) [
			'term_id'     => $id,
			'name'        => $name,
			'slug'        => strtolower( $name ),
			'parent'      => $parent,
			'taxonomy'    => 'product_cat',
			'description' => '',
		];
	}

	private function actions(): Actions {
		return new Actions( new Fields(), null, Providers::of( [ new ShelvesProvider( $this->store ) ], [ 'category_target' ] ) );
	}

	/**
	 * The normalised steps, as a batch stores them.
	 *
	 * @param string       $action  Action id.
	 * @param list<string> $shelves Shelves.
	 * @return list<array<string, mixed>>
	 */
	private function steps( string $action = 'x_shelves_add', array $shelves = [ 'north' ] ): array {
		$list = RunContext::within(
			RunContext::apply( self::USER, 0 ),
			fn(): mixed => $this->actions()->validate_list(
				[
					[
						'action' => $action,
						'args'   => [
							'field'   => ShelvesField::KEY,
							'shelves' => $shelves,
						],
					],
				]
			)
		);
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return json_decode( (string) wp_json_encode( $list ), true );
	}

	/**
	 * A queued category batch with its manifest.
	 *
	 * @param list<array<string, mixed>> $operations Normalised steps.
	 * @param list<int>                  $ids        Term ids.
	 */
	private function batch( array $operations, array $ids = [ 41, 42, 43 ] ): RunnerStore {
		$store                      = new RunnerStore( self::BATCH, array_map( static fn( int $id ): array => [ $id, 'product_cat' ], $ids ), $operations, self::USER );
		$store->batch['extensions'] = ValueCodec::encode( Manifest::build( Providers::of( [ new ShelvesProvider( $this->store ) ], [ 'category_target' ] )->get( 'shelves' ), $operations ) );
		$store->batch['actions']    = ',x_shelves_add,';
		return $store;
	}

	private function run_chunk(): string {
		return ( new ChunkRunner( null, $this->actions(), null, new Settings( [] ), fn(): float => $this->now += 1.0, 30 ) )->run( self::BATCH );
	}

	private function shelves( int $id ): mixed {
		$raw = $this->store->data[ $id ][ ShelvesField::META ] ?? null;
		return null === $raw ? [] : json_decode( $raw, true );
	}

	private static function envelope( array $value, string $text ): array {
		return [
			'external' => 1,
			'format'   => 1,
			'value'    => $value,
			'text'     => $text,
		];
	}

	public function test_each_category_is_written_as_a_typed_term_and_journalled_as_product_cat(): void {
		$runner = $this->batch( $this->steps() );
		$this->assertSame( 'complete', $this->run_chunk() );
		$this->assertSame( [ 'north', 'west' ], $this->shelves( 41 ) );
		$this->assertSame( [ 'north' ], $this->shelves( 42 ) );
		$this->assertSame(
			[
				[ 41, ShelvesField::KEY, self::envelope( [ 'west' ], 'West wall' ), self::envelope( [ 'north', 'west' ], 'North wall, West wall' ), 'applied' ],
				[ 42, ShelvesField::KEY, self::envelope( [], 'No shelves' ), self::envelope( [ 'north' ], 'North wall' ), 'applied' ],
				[ 43, ShelvesField::KEY, self::envelope( [], 'No shelves' ), self::envelope( [ 'north' ], 'North wall' ), 'applied' ],
			],
			$runner->journal()
		);
		$this->assertSame( [ 'product_cat' ], array_values( array_unique( array_column( $runner->changes, 'object_type' ) ) ) );
		$this->assertSame(
			[
				[ self::USER, 'product_cat', 41, 'term:product_cat:41' ],
				[ self::USER, 'product_cat', 42, 'term:product_cat:42' ],
				[ self::USER, 'product_cat', 43, 'term:product_cat:43' ],
			],
			ShelvesProvider::$calls['authorise'],
			'The provider is asked about typed categories, for the recorded user.'
		);
		$this->assertSame( [ [ 'product_cat', [ 41, 42, 43 ] ] ], ShelvesProvider::$calls['prime_terms'], 'prime_terms() once per chunk.' );
		$this->assertSame( [ [ 'product_cat', [ 41, 42, 43 ], 'apply' ] ], ShelvesProvider::$calls['flush_terms'], 'flush_terms() once with the terms written.' );
		$this->assertArrayNotHasKey( 'prime', ShelvesProvider::$calls, 'prime() is never called with term ids.' );
		$this->assertArrayNotHasKey( 'flush', ShelvesProvider::$calls, 'flush() is never called with term ids.' );
		$this->assertSame( 3, $runner->batch()['changed_items'] );
	}

	public function test_per_term_checks_skip_with_their_sentence_in_order(): void {
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_term:42'] = false;
		update_option( ShelvesProvider::FAULTS, [ 'refuse_terms' => [ 43 ] ] );
		// 44 was chosen, then deleted before its chunk ran.
		$runner = $this->batch( $this->steps(), [ 41, 42, 43, 44 ] );
		$this->assertSame( 'partial', $this->run_chunk() );
		$rows = [];
		foreach ( $runner->changes as $row ) {
			$rows[ $row['object_id'] ] = [ $row['result'], $row['message'] ];
		}
		$this->assertSame( 'applied', $rows[41][0] );
		$this->assertSame( 'skipped', $rows[42][0], 'No edit_term on 42.' );
		$this->assertStringContainsString( 'permission', $rows[42][1] );
		$this->assertSame( [ 'skipped', 'Sample shelves does not allow changes to this category.' ], $rows[43] );
		$this->assertSame( 'skipped', $rows[44][0], 'A deleted category is skipped as unavailable.' );
		$this->assertStringContainsString( 'no longer exists', $rows[44][1] );
		$this->assertSame( [ 41, 43 ], array_values( array_unique( array_column( ShelvesProvider::$calls['authorise'], 2 ) ) ), 'authorise() follows the core checks.' );
		$this->assertSame( [ 'north', 'west' ], $this->shelves( 41 ), 'Only 41 was written.' );
		$this->assertArrayNotHasKey( 42, $this->store->data );
		$this->assertArrayNotHasKey( 43, $this->store->data );
	}

	public function test_without_the_action_capability_nothing_is_asked_of_the_provider(): void {
		$steps = $this->steps();
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['manage_product_terms'] = false;
		$runner = $this->batch( $steps, [ 42 ] );
		$this->run_chunk();
		$this->assertSame( 'skipped', $runner->changes[0]['result'] );
		$this->assertArrayNotHasKey( 'authorise', ShelvesProvider::$calls );
		$this->assertSame( [], ShelvesField::$written );
	}

	public function test_a_background_run_writes_categories_term_queries_hide(): void {
		// Logged out, as a background run is, with another plugin hiding 42 and 43 from term queries.
		$GLOBALS['bulkcat_test']['user_id']      = 0;
		$GLOBALS['bulkcat_test']['hidden_terms'] = [ 42, 43 ];
		$this->assertCount( 1, get_terms( [ 'taxonomy' => 'product_cat' ] ) );
		$runner = $this->batch( $this->steps() );
		$this->assertSame( 'complete', $this->run_chunk() );
		$this->assertSame( [ 'applied', 'applied', 'applied' ], array_column( $runner->changes, 'result' ) );
		$this->assertSame( [ 'north' ], $this->shelves( 43 ) );
	}

	public function test_a_conflict_fails_only_that_category(): void {
		update_option( ShelvesProvider::FAULTS, [ 'write_conflict' => [ 42 ] ] );
		$runner = $this->batch( $this->steps() );
		$this->assertSame( 'partial', $this->run_chunk() );
		$this->assertSame( [ 'applied', 'failed', 'applied' ], array_column( $runner->changes, 'result' ) );
		$this->assertSame( [ [ 'product_cat', [ 41, 43 ], 'apply' ] ], ShelvesProvider::$calls['flush_terms'] );
	}

	public function test_a_retry_with_intent_rows_replays_without_applying_twice(): void {
		$operations = $this->steps();
		$runner     = $this->batch( $operations, [ 42 ] );
		// A run died after recording intent for 42 and writing it.
		$this->store->data[42] = [ ShelvesField::META => '["north"]' ];
		$GLOBALS['wpdb']->insert(
			'wp_bulkcat_changes',
			[
				'batch_id'     => self::BATCH,
				'object_id'    => 42,
				'object_type'  => 'product_cat',
				'prop'         => ShelvesField::KEY,
				'old_value'    => ValueCodec::encode( self::envelope( [], 'No shelves' ) ),
				'new_value'    => ValueCodec::encode( self::envelope( [ 'north' ], 'North wall' ) ),
				'modified_gmt' => '0000-00-00 00:00:00',
				'applied_gmt'  => '2026-09-23 08:00:00',
				'result'       => Schema::CHANGE_INTENT,
				'message'      => '',
			]
		);
		$this->assertSame( 'complete', $this->run_chunk() );
		$this->assertSame( [ 'north' ], $this->shelves( 42 ) );
		$this->assertSame( [], ShelvesField::$written, 'A value already written is settled, not written again.' );
		$this->assertSame( [ 'applied' ], array_column( $runner->changes, 'result' ) );
	}

	public function test_a_category_change_is_never_written_to_a_product_of_the_same_number(): void {
		$runner = new RunnerStore( self::BATCH, [ [ 41, 'product' ] ], $this->steps(), self::USER );
		$this->run_chunk();
		$runner->sync();
		$this->assertSame( [ [ 41, 'product', 'failed', Refusals::mixed_sentence() ] ], array_map( static fn( array $row ): array => [ $row['object_id'], $row['object_type'], $row['result'], $row['message'] ], $runner->changes ) );
		$this->assertSame( [], ShelvesField::$written );
		$this->assertArrayNotHasKey( 'authorise', ShelvesProvider::$calls );
	}
}
