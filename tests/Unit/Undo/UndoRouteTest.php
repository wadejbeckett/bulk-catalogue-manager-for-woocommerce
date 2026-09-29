<?php
/**
 * Undo a batch end to end (T56; SPEC §4.6, §9.4, §12.3, §12.4, §12.6)
 * through `POST /bulkcat/v1/batches/{id}/undo`'s preview, the undo batch
 * factory and the chunk runner, over the runner's in-memory batches, items
 * and journal: the preview's counts with conflicts first, bound to the plan
 * hash; the undo batch created with `undo_of` and each item's own
 * operations before any work; the runner writing the reverse operations
 * through the same writer and journal; the batch undone moved to `undoing`
 * and then `undone` (or back after a failed or cancelled undo); conflicted
 * objects left alone unless forced, and the count stated ("Undo of #12",
 * "1 of 3 was changed again…"); an undo of the undo; the planner's
 * refusals; 401, 403 and 404; and the history list reading no product.
 *
 * Since T83 the route applies an undo only as a durable request (grant,
 * canonical intent; Requests\RequestService), proved on native storage
 * (tests/probe/exact-batch*.php); these tests make the undo batch through
 * Engine\BatchFactory::create_undo(), the engine the durable path shares
 * (Engine\UndoRecorder, T111). Each undo item is checked again as its chunk
 * writes it (T111). A 1.0 screen's bare request id answers only the one undo
 * batch it exactly made and never plans or creates one.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Original;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class UndoRouteTest extends TestCase {
	private const BATCH = 12;

	private const USER = 3;

	/**
	 * A day after the fixture batch was created (2026-09-23 08:00:00 UTC).
	 */
	private const NOW = 1790208000;

	private const UUID = '0b8f4d2e-3c1a-4e5b-9a7d-6f2e1c3b4a50';

	private const UUID_2 = '7d3e9a1b-2c4f-4b6a-8e5d-1a9c0b7f3e21';

	private RunnerStore $store;

	private float $run_clock = self::NOW + 10.0;

	protected function setUp(): void {
		bulkcat_test_reset();
		$this->run_clock                                       = self::NOW + 10.0;
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = self::USER;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products']      = true;
		$GLOBALS['bulkcat_test']['user_caps']                  = [
			self::USER => [
				'edit_post'            => true,
				'assign_product_terms' => true,
			],
		];
		$GLOBALS['bulkcat_test']['scheduled']                  = [];
		$GLOBALS['bulkcat_test']['terms']                      = [ 'product_cat' => [ 5, 7 ] ];
		$GLOBALS['wpdb']->insert_id                            = 40;

		$this->store                        = new RunnerStore( self::BATCH, [ 1001, 1002, [ 2001, 'variation' ] ], [], self::USER );
		$this->store->batch['status']       = 'complete';
		$this->store->batch['finished_gmt'] = '2026-09-23 08:05:00';
		$this->store->batch['done_items']   = 3;
		$this->store->batch['actions']      = ',adjust,';
		foreach ( array_keys( $this->store->items ) as $id ) {
			$this->store->items[ $id ]['state'] = 'done';
		}
		// What the batch did: newest last, as the writer journals it.
		$this->change( 1001, 'regular_price', '10.00', '11.00' );
		$this->change( 1001, 'sale_price', '', '9.00' );
		$this->change( 1002, 'regular_price', '20.00', '22.00' );
		$this->change( 1002, 'category_ids', [ 5 ], [ 5, 7 ] );
		$this->change( 2001, 'regular_price', '5.00', '6.00', 'variation' );
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
				'category_ids'  => [ 5, 7 ],
			]
		);
		self::product( 2000, [], 'variable' );
		self::product(
			2001,
			[
				'regular_price' => '6.00',
				'parent_id'     => 2000,
			],
			'variation'
		);

		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		// The same routes again over the test's clock.
		( new Routes( [ new BatchesController( null, null, null, null, static fn(): int => self::NOW ) ] ) )->register();
		$GLOBALS['wpdb']->writes  = [];
		$GLOBALS['wpdb']->queries = [];
		$this->store->sync();
	}

	/**
	 * Append one journal row of the fixture batch, as the writer records it.
	 */
	private function change( int $object_id, string $prop, mixed $old, mixed $new, string $type = 'product' ): void {
		$this->store->changes[] = [
			'batch_id'     => self::BATCH,
			'object_id'    => $object_id,
			'object_type'  => $type,
			'prop'         => $prop,
			'old_value'    => $old,
			'new_value'    => $new,
			'modified_gmt' => '2026-09-23 07:00:00',
			'applied_gmt'  => '2026-09-23 08:01:00',
			'result'       => 'applied',
			'message'      => '',
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

	private static function prop( int $id, string $prop ): mixed {
		return $GLOBALS['bulkcat_test']['products'][ $id ]->props[ $prop ] ?? null;
	}

	private static function dispatch( string $method, string $path, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bulkcat/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The data of a response with this status.
	 *
	 * @return array<string, mixed>
	 */
	private function ok( \WP_REST_Response|\WP_Error $response, int $status = 200 ): array {
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$this->assertSame( $status, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	private function refused( \WP_REST_Response|\WP_Error $response, string $code, int $status ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $response->get_error_message() );
		return $response;
	}

	/**
	 * Preview the undo of a batch.
	 *
	 * @return array<string, mixed>
	 */
	private function preview( int $batch_id = self::BATCH, array $params = [] ): array {
		return $this->ok( self::dispatch( 'POST', "/batches/$batch_id/undo", [ 'preview' => true ] + $params ) );
	}

	/**
	 * Preview the undo of a batch through the route, then make the undo batch
	 * of that plan through the engine, answered as the route answers it.
	 *
	 * @return array<string, mixed> `{ batch_id, status, total_items, replayed, undo }`.
	 */
	private function undo( int $batch_id = self::BATCH, bool $force = false, string $uuid = self::UUID ): array {
		$hash = $this->preview( $batch_id )['plan_hash'];
		$plan = ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( $batch_id, self::USER );
		$this->assertInstanceOf( Plan::class, $plan );
		$this->assertSame( $hash, $plan->hash(), 'The engine undoes the plan the route previewed.' );
		$result = ( new BatchFactory( null, new BatchRepository() ) )->create_undo( $uuid, self::USER, $plan, $force );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return [
			'batch_id'    => $result['batch_id'],
			'status'      => $result['status'],
			'total_items' => $result['total_items'],
			'replayed'    => $result['replayed'],
			'undo'        => Progress::undo_summary( ( new BatchRepository() )->find( $result['batch_id'] ) ),
		];
	}

	/**
	 * Run a batch's chunk chain to its end.
	 */
	private function run_batch( int $batch_id ): string {
		$runner = new ChunkRunner( null, new Registry( new Fields() ), null, new Settings( [] ), fn(): float => $this->run_clock += 1.0, 30 );
		do {
			$result = $runner->run( $batch_id );
		} while ( 'continued' === $result );
		return $result;
	}

	/**
	 * The statements that wrote, so far.
	 *
	 * @return list<string>
	 */
	private static function writing_queries(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => ! str_starts_with( $sql, 'SELECT' ) && ! str_starts_with( $sql, '/*' ) ) );
	}

	public function test_the_route_registers_behind_the_write_capability(): void {
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/batches/(?P<id>[\d]+)/undo'];
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( [ 'id', 'page', 'per_page', 'request_proof', 'intent', 'uuid', 'preview', 'force', 'plan_hash' ], array_keys( $route[0]['args'] ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['wrong'] ?? [] );
	}

	public function test_the_preview_counts_orders_conflicts_first_binds_the_hash_and_writes_nothing(): void {
		// 1002 was edited by hand after the batch.
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';

		$preview = $this->preview();

		$this->assertTrue( $preview['preview'] );
		$this->assertSame(
			[
				'revertible' => 2,
				'conflicted' => 1,
				'skipped'    => 0,
			],
			$preview['counts']
		);
		$this->assertSame( [ 1002, 2001, 1001 ], array_column( $preview['items'], 'object_id' ), 'Conflicts first, then the plan order (journalled last first).' );
		$first = $preview['items'][0];
		$this->assertSame( 'conflicted', $first['state'] );
		$this->assertSame( 'This product was changed since this batch ran, so it is left alone unless you choose Revert anyway.', $first['reason'] );
		$this->assertSame( 'regular_price', $first['conflicts'][0]['field'] );
		$this->assertSame( '22.00', $first['conflicts'][0]['expected'] );
		$this->assertSame( '21.00', $first['conflicts'][0]['current'] );
		$this->assertSame( [ 'category_ids', 'regular_price' ], array_column( $first['fields'], 'field' ) );
		$this->assertNotSame( 'regular_price', $first['fields'][1]['field_label'], 'A plain label, never the key.' );
		$this->assertSame( 'variation', $preview['items'][1]['object_type'] );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $preview['plan_hash'] ) );
		$this->assertSame( ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( self::BATCH )->hash(), $preview['plan_hash'], 'The hash is the plan\'s.' );
		$this->assertSame( '1 of 3 was changed again after this batch and will be left alone unless you choose Revert anyway.', $preview['sentence'] );
		$this->assertSame( 3, $preview['pagination']['total'] );

		$page = $this->preview(
			self::BATCH,
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 1001 ], array_column( $page['items'], 'object_id' ) );
		$this->assertSame( 2, $page['pagination']['totalPages'] );

		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'The preview writes nothing.' );
		$this->assertSame( [], self::writing_queries() );
		$this->assertSame( 'complete', $this->store->batch()['status'] );
		foreach ( $GLOBALS['bulkcat_test']['products'] as $product ) {
			$this->assertSame( [], $product->saves );
		}
	}

	public function test_the_preview_and_the_undo_say_how_many_products_and_variations_they_hold(): void {
		// T114: the undo dialog and its result count products and variations apart, not all as products.
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';
		$preview = $this->preview();
		$this->assertSame(
			[
				'product'   => [
					'revertible' => 1,
					'conflicted' => 1,
					'skipped'    => 0,
				],
				'variation' => [
					'revertible' => 1,
					'conflicted' => 0,
					'skipped'    => 0,
				],
			],
			$preview['by_type']
		);
		$created = $this->undo();
		$this->assertSame(
			[
				'products'   => 1,
				'variations' => 1,
			],
			$created['undo']['by_type'],
			'The undo batch holds 1001 and 2001; 1002 was left alone.'
		);
		$report = $this->ok( self::dispatch( 'GET', '/batches/' . $created['batch_id'] ) );
		$this->assertSame( $created['undo']['by_type'], $report['undo']['by_type'] );
	}

	public function test_every_request_that_walks_the_whole_plan_lifts_the_time_limit(): void {
		// T111: the plan of a 100,000-object change takes about two minutes of work; at PHP's
		// default 30 seconds the web request died (measured on the scale fixture), not its memory.
		$GLOBALS['bulkcat_test']['time_limits'] = [];
		$this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['time_limits'], 'The cheap read plans nothing and keeps the limit.' );
		$this->preview();
		$this->assertSame( [ [ 0, null ] ], $GLOBALS['bulkcat_test']['time_limits'], 'The undo preview.' );
		$this->ok( self::dispatch( 'GET', '/batches/12', [ 'reversibility' => 'count' ] ) );
		$this->assertCount( 2, $GLOBALS['bulkcat_test']['time_limits'], 'The single read that counts conflicts.' );
	}

	public function test_a_1_0_undo_never_plans_or_creates_an_undo(): void {
		$hash                                       = $this->preview()['plan_hash'];
		$GLOBALS['bulkcat_test']['products_loaded'] = [];
		$refusal                                    = $this->refused(
			self::dispatch(
				'POST',
				'/batches/12/undo',
				[
					'uuid'      => self::UUID,
					'plan_hash' => $hash,
				]
			),
			'bulkcat_legacy_request',
			409
		);
		$this->assertSame( [], $refusal->get_error_data()['batch_ids'] );
		$this->assertStringContainsString( 'Reload the page', $refusal->get_error_message() );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'], 'Nothing was planned.' );
		$this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'plan_hash' => $hash ] ), 'bulkcat_invalid_value', 400 );
		$this->refused(
			self::dispatch(
				'POST',
				'/batches/12/undo',
				[
					'uuid'      => self::UUID,
					'plan_hash' => 'not-a-hash',
				]
			),
			'rest_invalid_param',
			400
		);
		$this->assertSame( [], $this->store->others, 'No undo batch was created.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		$this->assertSame( 'complete', $this->store->batch()['status'] );
	}

	public function test_a_durable_undo_names_its_batch_and_only_its_own_parameters(): void {
		$durable = [
			'uuid'          => self::UUID,
			'request_proof' => str_repeat( 'a', 64 ) . '.' . str_repeat( 'b', 64 ),
			'intent'        => [
				'version'   => 1,
				'source'    => 'undo',
				'batch_id'  => 13,
				'plan_hash' => str_repeat( 'c', 64 ),
				'force'     => false,
			],
		];
		$this->assertStringContainsString( 'does not match the change it names', $this->refused( self::dispatch( 'POST', '/batches/12/undo', $durable ), 'bulkcat_invalid_value', 400 )->get_error_message() );
		$durable['intent']['batch_id'] = self::BATCH;
		foreach ( [
			'plan_hash'  => str_repeat( 'c', 64 ),
			'force'      => true,
			'operations' => [],
		] as $key => $value ) {
			$this->assertStringContainsString( 'unknown option', $this->refused( self::dispatch( 'POST', '/batches/12/undo', [ $key => $value ] + $durable ), 'bulkcat_invalid_value', 400 )->get_error_message(), $key );
		}
		// The unit stubs hold no transactional storage: the durable path fails closed, recording nothing.
		$this->refused( self::dispatch( 'POST', '/batches/12/undo', $durable ), 'bulkcat_request_storage', 503 );
		$this->assertSame( [], $this->store->others );
		$this->assertSame( 'complete', $this->store->batch()['status'] );
	}

	public function test_undo_creates_a_batch_with_undo_of_and_each_items_own_operations_before_any_work(): void {
		$plan    = ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( self::BATCH );
		$created = $this->undo();

		$undo_id = $created['batch_id'];
		$this->assertSame( 41, $undo_id );
		$this->assertFalse( $created['replayed'] );
		$this->assertSame( 'queued', $created['status'] );
		$this->assertSame( 3, $created['total_items'] );
		$this->assertSame( 'Undo of #12', $created['undo']['label'] );
		$this->assertSame( 0, $created['undo']['left_alone'] );
		$this->assertSame( '', $created['undo']['sentence'] );

		$row = $this->store->other( $undo_id );
		$this->assertSame( 'undo', $row['source'] );
		$this->assertSame( self::BATCH, $row['undo_of'] );
		$this->assertSame( self::USER, $row['user_id'] );
		$this->assertSame( ',set_value,replace_terms,clear,', $row['actions'], 'The batch\'s actions come from the items\' operations.' );
		$items = $this->store->items_of( $undo_id );
		$this->assertSame( [ 2001, 1002, 1001 ], array_column( $items, 'object_id' ) );
		$this->assertSame( [ 'variation', 'product', 'product' ], array_column( $items, 'object_type' ) );
		foreach ( $items as $index => $item ) {
			$this->assertSame( 'pending', $item['state'] );
			$this->assertSame( $plan->items()[ $index ]['operations'], ValueCodec::decode( (string) $item['operations'] ), 'Each item carries its own operations.' );
		}
		$this->assertSame( 'undoing', $this->store->batch()['status'], 'The batch undone is marked undoing.' );
		$this->assertSame( '2026-09-23 08:05:00', $this->store->batch()['finished_gmt'] );
		$this->assertSame( [ [ 'enqueue', 'bulkcat_run_chunk', [ $undo_id ], 'bulk-catalogue-manager-for-woocommerce', null ] ], $GLOBALS['bulkcat_test']['scheduled'], 'Only then is the first chunk queued.' );
		foreach ( $GLOBALS['bulkcat_test']['products'] as $product ) {
			$this->assertSame( [], $product->saves, 'Nothing is written before the runner works it.' );
		}

		// While it runs, the batch cannot be undone again or read as reversible.
		$this->assertSame( 'undo_in_progress', $this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'preview' => true ] ), 'bulkcat_not_reversible', 409 )->get_error_data()['reason'] );
		$this->assertSame( 'in_progress', $this->ok( self::dispatch( 'GET', '/batches/12' ) )['reversibility']['state'] );
	}

	public function test_the_runner_writes_the_reverse_operations_journals_them_and_marks_the_batch_undone(): void {
		$undo_id = $this->undo()['batch_id'];
		$journal = count( $this->store->changes );

		$this->assertSame( 'complete', $this->run_batch( $undo_id ) );

		$this->assertSame( '10.00', self::prop( 1001, 'regular_price' ) );
		$this->assertSame( '', self::prop( 1001, 'sale_price' ) );
		$this->assertSame( '20.00', self::prop( 1002, 'regular_price' ) );
		$this->assertSame( [ 5 ], self::prop( 1002, 'category_ids' ) );
		$this->assertSame( '5.00', self::prop( 2001, 'regular_price' ) );
		$this->assertContains( [ 1002, [ 5 ], 'product_cat', false ], $GLOBALS['bulkcat_test']['set_object_terms'], 'Terms go back through wp_set_object_terms().' );
		foreach ( [ 1001, 1002, 2001 ] as $id ) {
			$this->assertCount( 1, $GLOBALS['bulkcat_test']['products'][ $id ]->saves, 'Each object saved once, through CRUD.' );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [] );

		$rows = array_values( array_filter( $this->store->changes, static fn( array $row ): bool => $undo_id === $row['batch_id'] ) );
		$this->assertCount( 5, $rows, 'The undo is journalled like any batch.' );
		$this->assertSame( $journal + 5, count( $this->store->changes ) );
		$by = [];
		foreach ( $rows as $row ) {
			$by[ $row['object_id'] . ':' . $row['prop'] ] = [ $row['old_value'], $row['new_value'], $row['result'] ];
		}
		$this->assertSame( [ '11.00', '10.00', 'applied' ], $by['1001:regular_price'] );
		$this->assertSame( [ '9.00', '', 'applied' ], $by['1001:sale_price'] );
		$this->assertSame( [ [ 5, 7 ], [ 5 ], 'applied' ], $by['1002:category_ids'] );
		$this->assertSame( [ '6.00', '5.00', 'applied' ], $by['2001:regular_price'] );

		$this->assertSame( 'undone', $this->store->batch()['status'], 'The batch undone is marked undone.' );
		$this->assertSame( '2026-09-23 08:05:00', $this->store->batch()['finished_gmt'], 'It keeps the time it finished.' );
		$this->assertSame( 'complete', $this->store->other( $undo_id )['status'] );

		$report = $this->ok( self::dispatch( 'GET', "/batches/$undo_id" ) );
		$this->assertSame( 'undo', $report['source'] );
		$this->assertSame( self::BATCH, $report['undo_of'] );
		$this->assertSame( 'Undo of #12', $report['undo']['label'] );
		$this->assertSame( 0, $report['undo']['left_alone'] );
		$this->assertSame( 3, $report['changed_items'] );
		$this->assertSame( 'reversible', $report['reversibility']['state'], 'The undo can itself be undone: Redo.' );
		$this->assertSame( 'already_undone', $this->ok( self::dispatch( 'GET', '/batches/12' ) )['reversibility']['reason'] );
		$this->assertNull( $this->ok( self::dispatch( 'GET', '/batches/12' ) )['undo'] );
	}

	public function test_an_undo_item_without_its_own_operations_fails_plainly_and_the_rest_revert(): void {
		$undo_id = $this->undo()['batch_id'];
		foreach ( $this->store->items as $id => $item ) {
			if ( $undo_id === $item['batch_id'] && 1001 === $item['object_id'] ) {
				$this->store->items[ $id ]['operations'] = null;
			}
		}

		$this->assertSame( 'partial', $this->run_batch( $undo_id ) );
		$failed = array_values( array_filter( $this->store->items_of( $undo_id ), static fn( array $item ): bool => 1001 === $item['object_id'] ) )[0];
		$this->assertSame( 'failed', $failed['state'] );
		$this->assertSame( 'The record of what to put back on this product is missing, so it was left alone.', $failed['message'] );
		$this->assertSame( '11.00', self::prop( 1001, 'regular_price' ), 'Never written with the batch\'s outline.' );
		$this->assertSame( '20.00', self::prop( 1002, 'regular_price' ) );
		$this->assertSame( 'undone', $this->store->batch()['status'], 'A partial undo still undoes the batch; its report says what was left.' );
	}

	public function test_a_conflicted_object_is_left_alone_and_counted_unless_forced(): void {
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';

		$created = $this->undo();
		$this->assertSame( 2, $created['total_items'] );
		$this->assertSame( 1, $created['undo']['left_alone'] );
		$this->assertSame( '1 of 3 was changed again after this batch and was left alone.', $created['undo']['sentence'] );
		$this->assertSame( [ 2001, 1001 ], array_column( $this->store->items_of( $created['batch_id'] ), 'object_id' ), 'The conflicted object is not an item.' );

		$this->assertSame( 'complete', $this->run_batch( $created['batch_id'] ) );
		$this->assertSame( '21.00', self::prop( 1002, 'regular_price' ), 'Left alone.' );
		$this->assertSame( [ 5, 7 ], self::prop( 1002, 'category_ids' ), 'All of it: its other field too.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][1002]->saves );
		$this->assertSame( '10.00', self::prop( 1001, 'regular_price' ), 'The rest revert.' );
		$this->assertSame( '5.00', self::prop( 2001, 'regular_price' ) );
		$this->assertSame( 'undone', $this->store->batch()['status'] );

		$report = $this->ok( self::dispatch( 'GET', '/batches/' . $created['batch_id'] ) );
		$this->assertSame( '1 of 3 was changed again after this batch and was left alone.', $report['undo']['sentence'] );
		$this->assertFalse( $report['undo']['forced'] );
		$this->assertSame(
			[
				'revertible' => 2,
				'conflicted' => 1,
				'skipped'    => 0,
			],
			$report['undo']['counts']
		);
	}

	public function test_force_reverts_the_conflicted_object_too(): void {
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';

		$created = $this->undo( self::BATCH, true );
		$this->assertSame( 3, $created['total_items'] );
		$this->assertSame( 0, $created['undo']['left_alone'] );
		$this->assertTrue( $created['undo']['forced'] );
		$this->assertSame( '1 of 3 was changed again after this batch and was put back anyway, because Revert anyway was chosen.', $created['undo']['sentence'] );

		$this->assertSame( 'complete', $this->run_batch( $created['batch_id'] ) );
		$this->assertSame( '20.00', self::prop( 1002, 'regular_price' ) );
		$this->assertSame( [ 5 ], self::prop( 1002, 'category_ids' ) );
		$rows = array_values( array_filter( $this->store->changes, static fn( array $row ): bool => $created['batch_id'] === $row['batch_id'] && 1002 === $row['object_id'] && 'regular_price' === $row['prop'] ) );
		$this->assertSame( [ '21.00', '20.00' ], [ $rows[0]['old_value'], $rows[0]['new_value'] ], 'The journal records the value it overwrote.' );
	}

	public function test_a_price_or_stock_changed_after_the_undo_started_is_left_alone_when_its_chunk_writes(): void {
		// T111: the plan is checked when previewed and confirmed; each item is checked again as its chunk writes it.
		$created = $this->undo();
		$this->assertSame( 3, $created['total_items'], 'Nothing had changed when the undo was confirmed.' );
		// Then, before the queue reaches them, someone edits a price and a variation's stock is set.
		$GLOBALS['bulkcat_test']['products'][1001]->props['regular_price'] = '15.00';
		$GLOBALS['bulkcat_test']['products'][2001]->props['regular_price'] = '6.50';

		$this->assertSame( 'partial', $this->run_batch( $created['batch_id'] ) );

		$this->assertSame( '15.00', self::prop( 1001, 'regular_price' ), 'The newer price stands.' );
		$this->assertSame( '9.00', self::prop( 1001, 'sale_price' ), 'All of that product is left alone, its other field too.' );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][1001]->saves );
		$this->assertSame( '6.50', self::prop( 2001, 'regular_price' ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products'][2001]->saves );
		$this->assertSame( '20.00', self::prop( 1002, 'regular_price' ), 'The rest revert.' );
		$this->assertSame( [ 5 ], self::prop( 1002, 'category_ids' ) );
		$items = array_column( $this->store->items_of( $created['batch_id'] ), null, 'object_id' );
		$this->assertSame( 'skipped', $items[1001]['state'] );
		$this->assertSame( 'This product was changed again after the undo was started, so it was left alone.', $items[1001]['message'] );
		$this->assertSame( 'skipped', $items[2001]['state'] );
		$this->assertSame( 'This variation was changed again after the undo was started, so it was left alone.', $items[2001]['message'] );
		$this->assertSame( 'done', $items[1002]['state'] );
		$this->assertSame( [], array_values( array_filter( $this->store->changes, static fn( array $row ): bool => $created['batch_id'] === $row['batch_id'] && in_array( $row['object_id'], [ 1001, 2001 ], true ) ) ), 'Nothing journalled for them: nothing was written.' );
		$this->assertSame( 'undone', $this->store->batch()['status'], 'A partial undo still undoes the batch; its report says what was left.' );
	}

	public function test_a_stock_quantity_changed_after_the_undo_started_is_left_alone(): void {
		$this->change( 1002, 'stock_quantity', 4, 0 );
		$GLOBALS['bulkcat_test']['products'][1002]->props['stock_quantity'] = 0;
		$GLOBALS['bulkcat_test']['products'][1002]->props['manage_stock']   = true;
		$this->store->sync();
		$created = $this->undo();
		$this->assertSame( 3, $created['total_items'] );
		// A sale takes one more: stock is now -1, not the 0 the batch left.
		$GLOBALS['bulkcat_test']['products'][1002]->props['stock_quantity'] = -1;

		$this->assertSame( 'partial', $this->run_batch( $created['batch_id'] ) );
		$this->assertSame( -1, self::prop( 1002, 'stock_quantity' ) );
		$this->assertSame( '22.00', self::prop( 1002, 'regular_price' ) );
		$this->assertSame( '10.00', self::prop( 1001, 'regular_price' ) );
		$items = array_column( $this->store->items_of( $created['batch_id'] ), null, 'object_id' );
		$this->assertSame( 'skipped', $items[1002]['state'] );
	}

	public function test_revert_anyway_writes_a_product_changed_after_the_undo_started(): void {
		$created = $this->undo( self::BATCH, true );
		$GLOBALS['bulkcat_test']['products'][1001]->props['regular_price'] = '15.00';

		$this->assertSame( 'complete', $this->run_batch( $created['batch_id'] ) );
		$this->assertSame( '10.00', self::prop( 1001, 'regular_price' ), 'Revert anyway puts it back regardless.' );
		$this->assertSame( '', self::prop( 1001, 'sale_price' ) );
	}

	public function test_skipped_objects_are_reported_with_their_reason(): void {
		unset( $GLOBALS['bulkcat_test']['products'][2001] );

		$created = $this->undo();
		$this->assertSame( 1, $created['undo']['not_undone'] );
		$this->assertSame( '1 product: This variation no longer exists, so it was left alone.', $created['undo']['reasons'][0]['sentence'] );
		$this->assertSame( [ 1002, 1001 ], array_column( $this->store->items_of( $created['batch_id'] ), 'object_id' ) );
	}

	public function test_a_1_0_request_id_answers_the_one_undo_it_exactly_made(): void {
		$first = $this->undo();
		$plan  = $this->store->other( $first['batch_id'] );
		$hash  = ValueCodec::decode( (string) $plan['selection'] )['plan_hash'];

		$again = $this->ok(
			self::dispatch(
				'POST',
				'/batches/12/undo',
				[
					'uuid'      => self::UUID,
					'plan_hash' => $hash,
				]
			)
		);
		$this->assertSame( $first['batch_id'], $again['batch_id'] );
		$this->assertTrue( $again['replayed'] );
		$this->assertSame( 'Undo of #12', $again['undo']['label'] );

		foreach ( [
			'another plan' => [ 'plan_hash' => str_repeat( 'a', 64 ) ],
			'forced'       => [
				'plan_hash' => $hash,
				'force'     => true,
			],
		] as $case => $params ) {
			$refusal = $this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'uuid' => self::UUID ] + $params ), 'bulkcat_legacy_request', 409 );
			$this->assertSame( [ $first['batch_id'] ], $refusal->get_error_data()['batch_ids'], $case );
		}
		$this->assertCount( 1, $this->store->others, 'One undo batch.' );
		$this->assertCount( 1, array_filter( $GLOBALS['bulkcat_test']['scheduled'], static fn( array $call ): bool => 'enqueue' === $call[0] ) );
	}

	public function test_an_undo_of_the_undo_puts_the_batch_back(): void {
		$undo_id = $this->undo()['batch_id'];
		$this->run_batch( $undo_id );
		$this->assertSame( '10.00', self::prop( 1001, 'regular_price' ) );

		$preview = $this->preview( $undo_id );
		$this->assertSame( 3, $preview['counts']['revertible'] );

		$redo = $this->undo( $undo_id, false, self::UUID_2 );
		$this->assertSame( 'Undo of #' . $undo_id, $redo['undo']['label'] );
		$this->assertSame( 'undoing', $this->store->other( $undo_id )['status'] );
		$this->assertSame( 'complete', $this->run_batch( $redo['batch_id'] ) );

		$this->assertSame( '11.00', self::prop( 1001, 'regular_price' ) );
		$this->assertSame( '9.00', self::prop( 1001, 'sale_price' ) );
		$this->assertSame( '22.00', self::prop( 1002, 'regular_price' ) );
		$this->assertSame( [ 5, 7 ], self::prop( 1002, 'category_ids' ) );
		$this->assertSame( '6.00', self::prop( 2001, 'regular_price' ) );
		$this->assertSame( 'undone', $this->store->other( $undo_id )['status'], 'The undo batch is now undone.' );
		$this->assertSame( 'undone', $this->store->batch()['status'], 'The first batch stays as it was.' );
	}

	public function test_an_off_undoes_exactly_through_the_runner_and_redoes_back(): void {
		// T90 (E2): an Off batch undone puts the status back and removes the record; its redo gives both back.
		$record               = \BulkCatalogueManager\Availability\OffRecord::create( 1001, 'private', '2026-09-23T08:01:00Z', self::BATCH )->to_json();
		$this->store->changes = [];
		$this->change( 1001, 'status', 'private', 'draft' );
		$this->change( 1001, 'availability_off', '', $record );
		$product                                    = self::product( 1001, [ 'status' => 'draft' ] );
		$product->meta['_bulkcat_availability_off'] = $record;
		$this->store->sync();

		$preview = $this->preview();
		$this->assertSame( 1, $preview['counts']['revertible'] );
		$undo_id = $this->undo()['batch_id'];
		$this->assertSame( 'complete', $this->run_batch( $undo_id ) );
		$this->assertSame( 'private', self::prop( 1001, 'status' ) );
		$this->assertArrayNotHasKey( '_bulkcat_availability_off', $product->meta );
		$rows = array_values( array_filter( $this->store->changes, static fn( array $row ): bool => $undo_id === $row['batch_id'] ) );
		$this->assertSame( [ [ 'status', 'draft', 'private' ], [ 'availability_off', $record, '' ] ], array_map( static fn( array $r ): array => [ $r['prop'], $r['old_value'], $r['new_value'] ], $rows ) );
		$feed = $this->ok( self::dispatch( 'GET', "/batches/$undo_id/changes", [ 'per_page' => 100 ] ) )['items'];
		$this->assertSame(
			[ [ 'Status', 'draft', 'private' ], [ 'Status before Off', 'Private, saved 23 Sep 2026 08:01', '—' ] ],
			array_map( static fn( array $r ): array => [ $r['field_label'], $r['old_value'], $r['new_value'] ], $feed ),
			'History reads the record as a saved status; the journal keeps the JSON.'
		);

		$redo = $this->undo( $undo_id, false, self::UUID_2 );
		$this->assertSame( 'complete', $this->run_batch( $redo['batch_id'] ) );
		$this->assertSame( 'draft', self::prop( 1001, 'status' ) );
		$this->assertSame( $record, $product->meta['_bulkcat_availability_off'], 'The redo gives the original record back, byte for byte.' );

		// A conflicted record reads as a saved status in the undo preview, never as JSON.
		$product->meta['_bulkcat_availability_off'] = \BulkCatalogueManager\Availability\OffRecord::create( 1001, 'pending', '2026-09-24T09:30:00Z', 90 )->to_json();
		$again                                      = $this->preview( $redo['batch_id'] );
		$item                                       = $again['items'][0];
		$this->assertSame( 'conflicted', $item['state'] );
		$this->assertSame( 'Status before Off', $item['conflicts'][0]['field_label'] );
		$this->assertSame( 'Pending review, saved 24 Sep 2026 09:30', $item['conflicts'][0]['current'] );
		$this->assertSame( 'Private, saved 23 Sep 2026 08:01', $item['conflicts'][0]['expected'] );
	}

	public function test_a_batch_the_planner_refuses_answers_409_with_its_reason(): void {
		$this->run_batch( $this->undo()['batch_id'] );
		$refusal = $this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'preview' => true ] ), 'bulkcat_not_reversible', 409 );
		$this->assertSame( 'already_undone', $refusal->get_error_data()['reason'] );
		$refusal = ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( self::BATCH, self::USER );
		$this->assertInstanceOf( \WP_Error::class, $refusal, 'The durable path plans again and is refused the same way.' );
		$this->assertSame( 'already_undone', $refusal->get_error_data()['reason'] );

		$this->store->batch['status'] = 'running';
		$this->assertSame( 'in_progress', $this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'preview' => true ] ), 'bulkcat_not_reversible', 409 )->get_error_data()['reason'] );
	}

	public function test_an_undo_with_nothing_to_put_back_is_refused(): void {
		foreach ( [ 1001, 1002, 2001 ] as $id ) {
			$GLOBALS['bulkcat_test']['products'][ $id ]->props['regular_price'] = '1.00';
		}
		$this->preview();
		$plan    = ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( self::BATCH, self::USER );
		$refusal = $this->refused( ( new BatchFactory( null, new BatchRepository() ) )->create_undo( self::UUID, self::USER, $plan, false ), 'bulkcat_nothing_to_undo', 409 );
		$this->assertStringContainsString( 'Revert anyway', $refusal->get_error_message() );
		$this->assertSame( [], $this->store->others );
		$this->assertSame( 'complete', $this->store->batch()['status'] );
	}

	public function test_a_lost_race_to_undo_the_same_batch_removes_the_new_batch(): void {
		$plan = ( new Planner( null, null, null, null, static fn(): int => self::NOW ) )->plan( self::BATCH, self::USER );
		// Another undo moved the batch first: this one's move to undoing finds it gone from its status.
		$GLOBALS['wpdb']->failing = [ 'update:wp_bulkcat_batches:status' ];
		$refusal                  = $this->refused( ( new BatchFactory( null, new BatchRepository() ) )->create_undo( self::UUID, self::USER, $plan, false ), 'bulkcat_not_reversible', 409 );
		$GLOBALS['wpdb']->failing = [];
		$this->assertSame( 'undo_in_progress', $refusal->get_error_data()['reason'] );
		$this->assertSame( [], $this->store->others, 'The new batch was removed.' );
		$this->assertSame( [], $this->store->items_of( 41 ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
	}

	public function test_a_failed_or_cancelled_undo_gives_the_batch_back_its_status(): void {
		$undo_id = $this->undo()['batch_id'];
		$this->assertSame( 'undoing', $this->store->batch()['status'] );
		$this->ok( self::dispatch( 'POST', "/batches/$undo_id/cancel" ) );
		$this->assertSame( 'cancelled', $this->store->other( $undo_id )['status'] );
		$this->assertSame( 'complete', $this->store->batch()['status'], 'Cancelled before it put anything back: the batch is as it was.' );
		$this->assertSame( 'reversible', $this->ok( self::dispatch( 'GET', '/batches/12' ) )['reversibility']['state'] );

		// A failed undo, settled directly.
		$repo                                 = new BatchRepository();
		$this->store->batch['status']         = 'undoing';
		$undo                                 = $repo->find( $undo_id );
		$undo['selection']['previous_status'] = 'partial';
		$this->assertTrue( Original::settle( $repo, $undo, 'failed' ) );
		$this->assertSame( 'partial', $this->store->batch()['status'] );
		$this->assertFalse( Original::settle( $repo, [ 'source' => 'bulk' ] + $undo, 'complete' ), 'Only an undo batch settles anything.' );
	}

	public function test_visitors_users_without_the_capability_and_missing_batches_are_refused(): void {
		$this->refused( self::dispatch( 'POST', '/batches/999/undo', [ 'preview' => true ] ), 'bulkcat_not_found', 404 );

		$GLOBALS['bulkcat_test']['caps']['edit_products'] = false;
		$this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'preview' => true ] ), 'bulkcat_forbidden', 403 );

		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$this->refused( self::dispatch( 'POST', '/batches/12/undo', [ 'preview' => true ] ), 'bulkcat_forbidden', 401 );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'] ?? [], 'No refusal reads a product.' );
	}

	public function test_the_history_list_reads_no_product_and_leaves_conflicts_uncounted(): void {
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';
		$GLOBALS['bulkcat_test']['products_loaded']                        = [];

		$list = $this->ok( self::dispatch( 'GET', '/batches' ) );
		$this->assertSame( [ self::BATCH ], array_column( $list['items'], 'batch_id' ) );
		$this->assertSame(
			[
				'state'     => 'reversible',
				'conflicts' => null,
				'reason'    => null,
			],
			$list['items'][0]['reversibility']
		);
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'], 'The list builds no plan, so it reads no product.' );

		$read = $this->ok( self::dispatch( 'GET', '/batches/12', [ 'reversibility' => 'count' ] ) );
		$this->assertSame(
			[
				'state'     => 'partially_reversible',
				'conflicts' => 1,
				'reason'    => null,
			],
			$read['reversibility'],
			'The single read counts conflicts when asked.'
		);
		$this->assertNotSame( [], $GLOBALS['bulkcat_test']['products_loaded'] );

		// An undo batch's list row names the batch it undid.
		$this->undo();
		$rows = $this->ok( self::dispatch( 'GET', '/batches' ) )['items'];
		$this->assertSame( 'Undo of #12', $rows[0]['undo']['label'] );
		$this->assertNull( $rows[0]['undo']['left_alone'], 'The list reads no selection snapshot.' );
	}

	/**
	 * T85 (Main Build's blocker): a batch's progress read is what every open
	 * screen polls until the change finishes, so by default it plans
	 * nothing — no journal page, no product — and reports conflicts as not
	 * counted; `reversibility=count` asks for the count, which builds the
	 * undo plan, as before.
	 */
	public function test_the_default_single_read_plans_nothing_and_counts_conflicts_only_when_asked(): void {
		$GLOBALS['bulkcat_test']['products'][1002]->props['regular_price'] = '21.00';
		$GLOBALS['bulkcat_test']['products_loaded']                        = [];
		$GLOBALS['wpdb']->queries = [];
		$journal                  = static fn(): array => preg_grep( '/ORDER BY change_id DESC/', $GLOBALS['wpdb']->queries );

		$read = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame(
			[
				'state'     => 'reversible',
				'conflicts' => null,
				'reason'    => null,
			],
			$read['reversibility'],
			'The default read does not count conflicts.'
		);
		$this->assertSame( [], $GLOBALS['bulkcat_test']['products_loaded'], 'The default read loads no product.' );
		$this->assertSame( [], $journal(), 'The default read reads no journal page for a plan.' );

		$counted = $this->ok( self::dispatch( 'GET', '/batches/12', [ 'reversibility' => 'count' ] ) );
		$this->assertSame(
			[
				'state'     => 'partially_reversible',
				'conflicts' => 1,
				'reason'    => null,
			],
			$counted['reversibility'],
			'Asked for, the count plans the undo.'
		);
		$this->assertNotSame( [], $GLOBALS['bulkcat_test']['products_loaded'] );
		$this->assertNotSame( [], $journal() );
	}
}
