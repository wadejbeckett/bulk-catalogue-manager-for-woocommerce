<?php
/**
 * The external write path (T107; docs/EXTENSIONS.md §7.3, §8.1, §8.2, §8.5,
 * §11; acceptance tests E7 and E8): each object of a chunk is checked by the
 * core, then by the provider, computed, recorded as intent before anything
 * is written, written once per field through the provider's compare-and-set
 * write, and settled by reading it back; a conflict, a write error, an
 * exception or a value that did not stick fails only that object; flush()
 * runs once per chunk even when the run throws; the product is never saved;
 * a retry replays intent without applying twice; and a chunk whose provider
 * is missing, unavailable or cannot read its recorded formats stops and
 * says what it changed.
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
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class WritePathTest extends TestCase {
	private const BATCH = 40;

	private const USER = 3;

	private const IDS = [ 1001, 1002, 1003 ];

	private MemoryStore $store;

	private float $now = 1790000000.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		SampleProvider::reset();
		$this->now = 1790000000.0;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [
			'edit_post'     => true,
			'edit_products' => true,
		];
		$GLOBALS['bulkcat_test']['scheduled']               = [];
		foreach ( self::IDS as $id ) {
			$GLOBALS['bulkcat_test']['products'][ $id ] = new SavedProduct(
				'simple',
				[
					'id'            => $id,
					'name'          => "Product $id",
					'date_modified' => '2026-09-20T10:00:00+00:00',
				]
			);
		}
		$this->store             = new MemoryStore();
		$this->store->data[1001] = [ '_sample_labels' => '["blue"]' ];
	}

	protected function tearDown(): void {
		foreach ( self::IDS as $id ) {
			$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][ $id ]->saves, "Product $id was saved." );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [], 'The core wrote meta.' );
	}

	private function actions( bool $registered = true ): Actions {
		return new Actions( new Fields(), null, $registered ? Providers::of( [ new SampleProvider( $this->store ) ] ) : Providers::none() );
	}

	/**
	 * The normalised steps, as a batch stores them.
	 *
	 * @param list<array<string, mixed>> $steps Steps as submitted.
	 * @return list<array<string, mixed>>
	 */
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
	 * A queued batch with its manifest.
	 *
	 * @param list<array<string, mixed>> $operations Normalised steps.
	 * @param list<int>                  $ids        Objects.
	 */
	private function batch( array $operations, array $ids = self::IDS ): RunnerStore {
		$store                      = new RunnerStore( self::BATCH, $ids, $operations, self::USER );
		$store->batch['extensions'] = ValueCodec::encode( Manifest::build( Providers::of( [ new SampleProvider( $this->store ) ] )->get( 'sample' ), $operations ) );
		$store->batch['actions']    = ',x_sample_add,';
		return $store;
	}

	private function run_chunk( ?Actions $actions = null ): string {
		$runner = new ChunkRunner( null, $actions ?? $this->actions(), null, new Settings( [] ), fn(): float => $this->now += 1.0, 30 );
		return $runner->run( self::BATCH );
	}

	private static function envelope( mixed $value, string $text, int $format = 1 ): array {
		return [
			'external' => 1,
			'format'   => $format,
			'value'    => $value,
			'text'     => $text,
		];
	}

	private function labels( int $id ): mixed {
		$raw = $this->store->data[ $id ]['_sample_labels'] ?? null;
		return null === $raw ? [] : json_decode( $raw, true );
	}

	public function test_intent_then_write_then_settle_with_envelopes_and_one_flush(): void {
		$runner                = $this->batch( $this->steps( [ self::add( 'red' ) ] ) );
		$seen                  = [];
		$this->store->on_write = static function ( string $verb, int $id ) use ( $runner, &$seen ): void {
			$rows        = array_values( array_filter( $runner->journal(), static fn( array $row ): bool => $id === $row[0] ) );
			$seen[ $id ] = array_column( $rows, 4 );
		};
		$this->assertSame( 'complete', $this->run_chunk() );
		$this->assertSame( [ 'blue', 'red' ], $this->labels( 1001 ) );
		$this->assertSame( [ 'red' ], $this->labels( 1002 ) );
		$this->assertSame(
			[
				1001 => [ Schema::CHANGE_INTENT ],
				1002 => [ Schema::CHANGE_INTENT ],
				1003 => [ Schema::CHANGE_INTENT ],
			],
			$seen,
			'Each intent row was recorded before the provider wrote.'
		);
		$this->assertSame(
			[
				[ 1001, 'x_sample_labels', self::envelope( [ 'blue' ], 'Blue' ), self::envelope( [ 'blue', 'red' ], 'Blue, Red' ), 'applied' ],
				[ 1002, 'x_sample_labels', self::envelope( [], 'No labels' ), self::envelope( [ 'red' ], 'Red' ), 'applied' ],
				[ 1003, 'x_sample_labels', self::envelope( [], 'No labels' ), self::envelope( [ 'red' ], 'Red' ), 'applied' ],
			],
			$runner->journal()
		);
		$this->assertSame( [ [ self::IDS, 'apply' ] ], SampleProvider::$flushed, 'flush() once for the chunk, with the objects written.' );
		$this->assertSame(
			[
				1001 => 'done',
				1002 => 'done',
				1003 => 'done',
			],
			$runner->states()
		);
		$this->assertSame( 3, $runner->batch()['changed_items'] );
		$this->assertSame( [ [ 1001, 1002, 1003 ] ], SampleProvider::$primed, 'The chunk primes its objects once.' );
	}

	/**
	 * The journal row of an object, in full.
	 *
	 * @param RunnerStore $runner The store.
	 * @param int         $id     The object.
	 * @return array<string, mixed>
	 */
	private static function row( RunnerStore $runner, int $id ): array {
		$runner->sync();
		foreach ( $runner->changes as $row ) {
			if ( $id === $row['object_id'] ) {
				return $row;
			}
		}
		throw new \LogicException( "No journal row for $id." );
	}

	public function test_a_conflict_a_write_error_and_an_exception_each_fail_only_their_object(): void {
		update_option(
			'sample_provider_faults',
			[
				'write_conflict' => [ 1001 ],
				'write_error'    => [ 1002 ],
			]
		);
		$runner = $this->batch( $this->steps( [ self::add( 'red' ) ] ) );
		$this->assertSame( 'partial', $this->run_chunk(), 'The chunk carries on past the failures.' );
		$this->assertSame( 'failed', self::row( $runner, 1001 )['result'] );
		$this->assertSame( 'The sample labels were changed by someone else during this change.', self::row( $runner, 1001 )['message'] );
		$this->assertSame( self::envelope( [ 'blue' ], 'Blue' ), self::row( $runner, 1001 )['new_value'], 'A failed row keeps the value it found.' );
		$this->assertSame( 'failed', self::row( $runner, 1002 )['result'] );
		$this->assertSame( 'The sample labels could not be saved.', $runner->item( 1002 )['message'] );
		$this->assertSame( 'applied', self::row( $runner, 1003 )['result'] );
		$this->assertSame( [ 'blue' ], $this->labels( 1001 ), 'Nothing written on a conflict.' );
		$this->assertSame( [], $this->labels( 1002 ) );
		$this->assertSame( [ 'red' ], $this->labels( 1003 ) );
		$this->assertSame(
			[
				1001 => 'failed',
				1002 => 'failed',
				1003 => 'done',
			],
			$runner->states()
		);
		$this->assertSame( [ [ [ 1003 ], 'apply' ] ], SampleProvider::$flushed, 'Only the object written is flushed.' );
	}

	public function test_an_exception_a_value_that_did_not_stick_and_a_throwing_flush(): void {
		update_option(
			'sample_provider_faults',
			[
				'throw_compute' => [ 1001 ],
				'not_stored'    => [ 1002 ],
				'throw_flush'   => true,
			]
		);
		$runner = $this->batch( $this->steps( [ self::add( 'red' ) ] ) );
		$this->assertSame( 'partial', $this->run_chunk() );
		$this->assertSame( 'failed', self::row( $runner, 1001 )['result'] );
		$this->assertSame( 'The add-on could not work out this change, so it was not made. Try again, or ask the add-on\'s author.', $runner->item( 1001 )['message'] );
		$this->assertSame( 'failed', self::row( $runner, 1002 )['result'] );
		$this->assertSame( 'The change did not stick, so it was reported as not made.', self::row( $runner, 1002 )['message'] );
		$this->assertSame( 'applied', self::row( $runner, 1003 )['result'] );
		$this->assertCount( 1, SampleProvider::$flushed, 'flush() ran once although it threw.' );
		$this->assertStringContainsString( 'The sample flush failed on purpose.', implode( "\n", array_column( $GLOBALS['bulkcat_test']['logged'] ?? [], 1 ) ), 'Its exception is logged, and changes no result.' );
	}

	public function test_permission_and_unknown_values_skip_or_fail_before_anything_is_written(): void {
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['edit_post:1001'] = false;
		update_option(
			'sample_provider_faults',
			[
				'refuse'  => [ 1002 ],
				'unknown' => [ 1003 ],
			]
		);
		$runner = $this->batch( $this->steps( [ self::add( 'red' ) ] ) );
		$this->run_chunk();
		$this->assertSame( 'skipped', self::row( $runner, 1001 )['result'] );
		$this->assertNotContains( 1001, SampleProvider::$authorised, 'The core refuses first; the provider is never asked.' );
		$this->assertSame( 'skipped', self::row( $runner, 1002 )['result'] );
		$this->assertSame( 'The sample add-on does not allow changes to this product.', self::row( $runner, 1002 )['message'] );
		$this->assertSame( 'failed', self::row( $runner, 1003 )['result'] );
		$this->assertSame( 'The sample labels of this product could not be read.', self::row( $runner, 1003 )['message'] );
		$this->assertSame( [], $this->store->writes );
		$this->assertSame( [], SampleProvider::$flushed, 'Nothing was written, so nothing is flushed.' );
	}

	public function test_a_retry_replays_intent_without_applying_twice(): void {
		// E8: the run was killed after the provider wrote 1001 but before the journal settled it.
		$operations              = $this->steps( [ self::add( 'red' ) ] );
		$runner                  = $this->batch( $operations, [ 1001, 1002, 1003 ] );
		$this->store->data[1001] = [ '_sample_labels' => '["blue","red"]' ];
		$this->store->data[1003] = [ '_sample_labels' => '["green"]' ];
		foreach ( [
			1001 => [ [ 'blue' ], 'Blue' ],
			1002 => [ [], 'No labels' ],
			1003 => [ [], 'No labels' ],
		] as $id => [ $old, $text ] ) {
			$new               = [ ...$old, 'red' ];
			$runner->changes[] = [
				'batch_id'     => self::BATCH,
				'object_id'    => $id,
				'object_type'  => 'product',
				'prop'         => 'x_sample_labels',
				'old_value'    => self::envelope( $old, $text ),
				'new_value'    => self::envelope( $new, 'x' ),
				'modified_gmt' => '2026-09-20 10:00:00',
				'applied_gmt'  => '2026-09-29 10:00:00',
				'result'       => Schema::CHANGE_INTENT,
				'message'      => '',
			];
		}
		$this->run_chunk();
		$this->assertSame( 'applied', self::row( $runner, 1001 )['result'], 'The earlier write landed: settled applied.' );
		$this->assertSame( self::envelope( [ 'blue', 'red' ], 'Blue, Red' ), self::row( $runner, 1001 )['new_value'] );
		$this->assertSame( 'applied', self::row( $runner, 1002 )['result'], 'It had not landed: written once, then settled.' );
		$this->assertSame( [ 'red' ], $this->labels( 1002 ) );
		$this->assertSame( 'failed', self::row( $runner, 1003 )['result'], 'Someone else changed it: never overwritten.' );
		$this->assertSame( [ 'green' ], $this->labels( 1003 ) );
		$this->assertSame( [ [ 'set', 1002, '_sample_labels' ] ], $this->store->writes, 'Only the write that had not landed happened, once.' );
		$this->assertSame( [], SampleProvider::$computed, 'Nothing is computed again on a replay.' );
		$this->assertCount( 3, $runner->journal(), 'No second row for any object.' );
	}

	/**
	 * A chunk run after its provider went away in one way, and what it left.
	 *
	 * @param bool                 $registered Whether the provider is registered for the run.
	 * @param array<string, mixed> $faults     Faults switched on after the batch was accepted.
	 * @param int                  $format     The format the manifest records, 1 unless newer.
	 */
	private function stops( bool $registered, array $faults, int $format = 1 ): void {
		$runner = $this->batch( $this->steps( [ self::add( 'red' ) ] ) );
		update_option( 'sample_provider_faults', $faults );
		$manifest = ValueCodec::decode( $runner->batch['extensions'] );
		$manifest['sample']['fields']['x_sample_labels']['format'] = $format;
		$runner->batch['extensions']                               = ValueCodec::encode( $manifest );
		$this->assertSame( 'partial', $this->run_chunk( $this->actions( $registered ) ), 'Never complete.' );
		$this->assertSame(
			[
				1001 => 'skipped',
				1002 => 'skipped',
				1003 => 'skipped',
			],
			$runner->states()
		);
		$this->assertSame( 'Sample labels is not active now, so this was left alone. Turn it on again, then preview and apply the change again.', $runner->item( 1001 )['message'] );
		$this->assertSame( 'Stopped because Sample labels is not active. 0 products were changed; the rest were left alone.', $runner->batch()['note'] );
		$this->assertSame( [], SampleProvider::$computed );
		$this->assertSame( [], $runner->journal() );
		$this->assertSame( 3, $runner->batch()['done_items'] );
	}

	public function test_a_chunk_stops_when_its_provider_is_unavailable(): void {
		$this->stops( true, [ 'unavailable' => true ] );
	}

	public function test_a_chunk_stops_when_its_provider_is_missing(): void {
		$this->stops( false, [] );
	}

	public function test_a_chunk_stops_when_a_recorded_format_is_newer_than_the_provider_reads(): void {
		$this->stops( true, [], 2 );
	}

	public function test_a_mixed_list_reaching_a_chunk_is_refused_there_too(): void {
		$mixed  = [
			...$this->steps( [ self::add( 'red' ) ] ),
			[
				'action' => 'clear',
				'args'   => [
					'field'        => 'sale_price',
					'skip_already' => true,
				],
			],
		];
		$runner = $this->batch( $mixed, [ 1001 ] );
		$this->run_chunk();
		$this->assertSame( 'failed', $runner->states()[1001] );
		$this->assertSame( "This action can't be combined with other kinds of change. Make it a separate change.", $runner->item( 1001 )['message'] );
		$this->assertSame( [], $this->store->writes );
	}
}
