<?php
/**
 * Progress, cancellation, the stalled queue, partial failure reporting and
 * the changes feed (T35, T36; SPEC §4.6, §4.8, §9.4, §12.4, §12.6) through
 * the REST dispatcher, over the chunk runner and its in-memory store:
 * `GET /batches/{id}` counts up after every chunk; `POST
 * /batches/{id}/cancel` mid-batch leaves the first chunk applied and
 * journalled, the rest pending, the status cancelled and no further claim;
 * the stalled flag turns on after five minutes without a claim while items
 * are pending, and never on a finished batch; a batch with failures and
 * skips ends partial, its grouped summary counts identical reasons in one
 * §4.8 sentence; `GET /batches/{id}/changes` pages the per-row outcome in
 * its fixed shape with per_page capped at 100; `GET /batches` pages newest
 * first with its filters; visitors 401, users without the capability 403, a
 * read-only user may read but not cancel; a missing batch 404
 * `bcm_not_found`, a finished one 409 `bcm_not_cancellable`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\BatchChangesController;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class BatchProgressRouteTest extends TestCase {
	private const BATCH = 12;

	/**
	 * The batch's own user.
	 */
	private const OWNER = 3;

	/**
	 * RunnerStore's batch creation time, 2026-09-23 08:00:00 GMT.
	 */
	private const CREATED = 1790150400;

	/**
	 * What the controller takes as now.
	 */
	private int $now = self::CREATED + 60;

	/**
	 * What the runner takes as now; every reading advances it a second.
	 */
	private float $run_clock = self::CREATED + 30.0;

	protected function setUp(): void {
		bcm_test_reset();
		$this->now                        = self::CREATED + 60;
		$this->run_clock                  = self::CREATED + 30.0;
		$GLOBALS['bcm_test']['logged_in'] = true;
		$GLOBALS['bcm_test']['user_id']   = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
		$GLOBALS['bcm_test']['user_caps']                  = [ self::OWNER => [ 'edit_post' => true ] ];
		$GLOBALS['bcm_test']['scheduled']                  = [];
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		// The same routes again over a clock the test sets.
		( new Routes( [ new BatchesController( null, null, null, null, fn(): int => $this->now ) ] ) )->register();
	}

	private static function dispatch( string $method, string $path, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bcm/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The data of a 200 response.
	 *
	 * @return array<string, mixed>
	 */
	private function ok( \WP_REST_Response|\WP_Error $response ): array {
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function refused( \WP_REST_Response|\WP_Error $response, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bcm_', $response->get_error_message() );
	}

	private function runner(): ChunkRunner {
		return new ChunkRunner( null, new Registry( new Fields() ), null, new Settings( [] ), fn(): float => $this->run_clock += 1.0, 30 );
	}

	/**
	 * Operations normalised and stored as JSON, as a batch holds them.
	 *
	 * @param list<array<string, mixed>> $operations Operations.
	 * @return list<array<string, mixed>>
	 */
	private function operations( array $operations ): array {
		$list = ( new Registry( new Fields() ) )->validate_list( $operations );
		$this->assertTrue( is_array( $list ) );
		return json_decode( (string) wp_json_encode( $list ), true );
	}

	private function increase(): array {
		return $this->operations(
			[
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
			]
		);
	}

	/**
	 * Products from 1001 at a regular price of 10, each with a title.
	 *
	 * @return list<int>
	 */
	private static function products( int $count ): array {
		$ids = range( 1001, 1000 + $count );
		foreach ( $ids as $id ) {
			$GLOBALS['bcm_test']['products'][ $id ] = new SavedProduct(
				'simple',
				[
					'id'            => $id,
					'regular_price' => '10',
				]
			);
			$GLOBALS['bcm_test']['posts'][ $id ]    = [
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => "Shoe $id",
			];
		}
		return $ids;
	}

	private static function price( int $id ): string {
		return (string) $GLOBALS['bcm_test']['products'][ $id ]->props['regular_price'];
	}

	private static function claims(): int {
		return count( preg_grep( "/^UPDATE `wp_bcm_batch_items` SET claim_token = '/", $GLOBALS['wpdb']->queries ) );
	}

	public function test_the_routes_register_with_their_permissions_and_schemas(): void {
		$routes = $GLOBALS['bcm_test']['routes'];
		$this->assertSame( [ 'POST', 'GET' ], array_column( array_filter( $routes['/bcm/v1/batches'], 'is_int', ARRAY_FILTER_USE_KEY ), 'methods' ) );
		$this->assertSame( [ Access::class, 'can_read' ], $routes['/bcm/v1/batches'][1]['permission_callback'] );
		$this->assertSame( [ 'page', 'per_page', 'status', 'user', 'after', 'before', 'action' ], array_keys( $routes['/bcm/v1/batches'][1]['args'] ) );
		$this->assertSame( [ Access::class, 'can_read' ], $routes[ '/bcm/v1' . BatchesController::ITEM_ROUTE ][0]['permission_callback'] );
		$this->assertSame( [ Access::class, 'can_read' ], $routes[ '/bcm/v1' . BatchChangesController::ROUTE ][0]['permission_callback'] );
		$this->assertSame( [ 'id', 'page', 'per_page', 'result' ], array_keys( $routes[ '/bcm/v1' . BatchChangesController::ROUTE ][0]['args'] ) );
		$this->assertSame( 'POST', $routes[ '/bcm/v1' . BatchesController::CANCEL_ROUTE ][0]['methods'] );
		$this->assertSame( [ Access::class, 'can_write' ], $routes[ '/bcm/v1' . BatchesController::CANCEL_ROUTE ][0]['permission_callback'] );
		$this->assertSame( 'bcm-batch', call_user_func( $routes[ '/bcm/v1' . BatchesController::ITEM_ROUTE ]['schema'] )['title'] );
		$this->assertSame( 'bcm-batch-change', call_user_func( $routes[ '/bcm/v1' . BatchChangesController::ROUTE ]['schema'] )['title'] );
	}

	public function test_the_counters_count_up_after_every_chunk(): void {
		$store = new RunnerStore( self::BATCH, self::products( 120 ), $this->increase(), self::OWNER );

		$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( [ 'queued', 120, 0, 0, 0, 120 ], [ $report['status'], $report['total_items'], $report['done_items'], $report['changed_items'], $report['failed_items'], $report['pending_items'] ] );
		$this->assertFalse( $report['stalled'] );
		$this->assertTrue( $report['cancellable'] );
		$this->assertSame( '2026-09-23 08:00:00', $report['created_gmt'] );
		$this->assertNull( $report['finished_gmt'] );
		$this->assertSame( [ 'bulk', '', 0 ], [ $report['source'], $report['note'], $report['chunk_size'] ] );
		$this->assertSame( [], $report['summary'] );

		foreach ( [ [ 'continued', 'running', 50, 70 ], [ 'continued', 'running', 100, 20 ], [ 'complete', 'complete', 120, 0 ] ] as [ $outcome, $status, $done, $pending ] ) {
			$this->assertSame( $outcome, $this->runner()->run( self::BATCH ) );
			$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
			$this->assertSame( [ $status, $done, $done, 0, $pending ], [ $report['status'], $report['done_items'], $report['changed_items'], $report['failed_items'], $report['pending_items'] ], "After a chunk: $done done." );
		}
		$this->assertSame( 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $report['finished_gmt'] ) );
		$this->assertFalse( $report['cancellable'] );
		$this->assertFalse( $report['stalled'] );
		$this->assertSame( 'complete', $store->batch()['status'] );
	}

	public function test_cancelling_mid_batch_keeps_the_first_chunk_and_claims_nothing_more(): void {
		$store = new RunnerStore( self::BATCH, self::products( 120 ), $this->increase(), self::OWNER );
		$this->assertSame( 'continued', $this->runner()->run( self::BATCH ) );

		$report = $this->ok( self::dispatch( 'POST', '/batches/12/cancel' ) );
		$this->assertSame( [ 'cancelled', 50, 50 ], [ $report['status'], $report['done_items'], $report['changed_items'] ] );
		$this->assertFalse( $report['cancellable'] );
		$this->assertNotNull( $report['finished_gmt'] );

		// The successor the first chunk enqueued runs, and exits without claiming.
		$claims = self::claims();
		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame( $claims, self::claims(), 'No further claim.' );
		$this->assertSame(
			[
				'done'    => 50,
				'pending' => 70,
			],
			array_count_values( $store->states() )
		);
		$this->assertSame( '11.00', self::price( 1050 ) );
		$this->assertSame( '10', self::price( 1051 ) );

		$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( [ 'cancelled', 50, 0 ], [ $report['status'], $report['done_items'], $report['pending_items'] ], 'A cancelled batch has nothing waiting to run.' );
		$this->assertFalse( $report['stalled'], 'A cancelled batch is never stalled.' );

		// Cancelling again changes nothing.
		$this->assertSame( 'cancelled', $this->ok( self::dispatch( 'POST', '/batches/12/cancel' ) )['status'] );

		// The applied part stays journalled, so it can be undone on its own.
		$changes = $this->ok( self::dispatch( 'GET', '/batches/12/changes', [ 'per_page' => 100 ] ) );
		$this->assertSame( 50, $changes['pagination']['total'] );
		$this->assertSame( [ 'applied' ], array_values( array_unique( array_column( $changes['items'], 'result' ) ) ) );
	}

	public function test_a_queued_batch_cancels_before_any_claim_and_a_finished_one_refuses(): void {
		$store = new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::OWNER );
		$this->assertSame( 'cancelled', $this->ok( self::dispatch( 'POST', '/batches/12/cancel' ) )['status'] );
		$this->assertSame( 'stopped', $this->runner()->run( self::BATCH ) );
		$this->assertSame( 0, self::claims() );
		$this->assertSame( '10', self::price( 1001 ) );

		$store->batch['status'] = 'complete';
		$this->refused( self::dispatch( 'POST', '/batches/12/cancel' ), BatchesController::NOT_CANCELLABLE, 409 );
		$this->assertSame( 'complete', $store->batch()['status'] );
	}

	public function test_a_frozen_queue_is_reported_stalled_after_five_minutes(): void {
		$store = new RunnerStore( self::BATCH, self::products( 60 ), $this->increase(), self::OWNER );

		$this->now = self::CREATED + Progress::STALL_SECONDS;
		$this->assertFalse( $this->ok( self::dispatch( 'GET', '/batches/12' ) )['stalled'], 'Five minutes exactly is not yet stalled.' );

		$this->now = self::CREATED + Progress::STALL_SECONDS + 1;
		$report    = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertTrue( $report['stalled'] );
		$this->assertSame( 0, $report['done_items'], 'Nothing was applied.' );
		$this->assertSame( '2026-09-23 08:00:00', $report['last_activity_gmt'] );
		$this->assertStringContainsString( 'Scheduled Actions', $report['stalled_message'] );
		$this->assertSame( [], $store->journal() );

		// A chunk runs: the claim is the latest activity.
		$this->run_clock = self::CREATED + 600.0;
		$this->runner()->run( self::BATCH );
		$this->now = self::CREATED + 601 + Progress::STALL_SECONDS - 1;
		$report    = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertFalse( $report['stalled'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::CREATED + 601 ), $report['last_activity_gmt'] );
		$this->now = self::CREATED + 601 + Progress::STALL_SECONDS + 1;
		$this->assertTrue( $this->ok( self::dispatch( 'GET', '/batches/12' ) )['stalled'], 'Pending items and no claim for five minutes.' );

		// Finished: never stalled, however long ago.
		$this->runner()->run( self::BATCH );
		$this->now = self::CREATED + 86400;
		$report    = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( 'complete', $report['status'] );
		$this->assertFalse( $report['stalled'] );
		$this->assertSame( '', $report['stalled_message'] );
	}

	public function test_failures_and_skips_finish_partial_with_grouped_reasons_and_a_changes_feed(): void {
		self::products( 3 );
		$GLOBALS['bcm_test']['skus']['SHOE-42']                            = 999;
		$GLOBALS['bcm_test']['user_caps'][ self::OWNER ]['edit_post:1003'] = false;
		$operations = $this->operations(
			[
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'sku',
						'value' => 'SHOE-42',
					],
				],
			]
		);
		$store      = new RunnerStore( self::BATCH, [ 1001, 1002, 1003 ], $operations, self::OWNER );

		$this->assertSame( 'partial', $this->runner()->run( self::BATCH ), 'A batch with any failure or skip is partial.' );
		$this->assertSame( [ '11.00', '11.00', '10' ], [ self::price( 1001 ), self::price( 1002 ), self::price( 1003 ) ], 'The good rows are applied.' );

		$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( 'partial', $report['status'] );
		$sentences = array_column( $report['summary'], 'sentence', 'message' );
		$sku       = 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.';
		$this->assertArrayHasKey( $sku, $sentences );
		$this->assertSame( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied to 2 products.', $sentences[ $sku ] );
		$this->assertSame( 2, $report['summary'][0]['count'], 'The most common reason first.' );
		$this->assertCount( 2, $report['summary'] );
		$permission = $report['summary'][1];
		$this->assertSame( 1, $permission['count'] );
		$this->assertSame( '1 product: ' . $permission['message'], $permission['sentence'] );
		foreach ( $report['summary'] as $group ) {
			$this->assertSame( 0, preg_match( '/bcm_|\\\\|::|Exception/', $group['sentence'] ), 'No code or class name in the main text.' );
		}

		// The changes feed: every row, in order, in its fixed shape; failing rows on their own.
		$all = $this->ok( self::dispatch( 'GET', '/batches/12/changes' ) );
		$this->assertSame( count( $store->journal() ), $all['pagination']['total'] );
		$row = $all['items'][0];
		$this->assertSame( [ 'change_id', 'object_id', 'object_type', 'name', 'field', 'field_label', 'old_value', 'new_value', 'result', 'message', 'detail', 'applied_gmt' ], array_keys( $row ) );
		$this->assertSame( [ 1001, 'product', 'Shoe 1001', 'regular_price', '10', '11.00', 'applied', '' ], [ $row['object_id'], $row['object_type'], $row['name'], $row['field'], $row['old_value'], $row['new_value'], $row['result'], $row['message'] ] );
		$this->assertSame( 'Regular price', $row['field_label'] );
		$this->assertStringContainsString( 'regular_price', $row['detail'], 'The technical line sits in detail.' );

		$problems = $this->ok( self::dispatch( 'GET', '/batches/12/changes', [ 'result' => [ 'failed', 'skipped' ] ] ) );
		$this->assertNotEmpty( $problems['items'] );
		foreach ( $problems['items'] as $item ) {
			$this->assertContains( $item['result'], [ 'failed', 'skipped' ] );
			$this->assertNotSame( '', $item['message'] );
			$this->assertStringNotContainsString( 'bcm_', $item['message'] );
		}
		$this->assertContains( $sku, array_column( $problems['items'], 'message' ) );
		$this->assertSame( [ 'Shoe 1001', 'Shoe 1002' ], array_values( array_unique( array_column( array_filter( $problems['items'], static fn( array $i ): bool => $sku === $i['message'] ), 'name' ) ) ) );
	}

	public function test_items_that_failed_without_a_journal_row_join_the_summary(): void {
		$store = new RunnerStore( self::BATCH, self::products( 2 ), $this->increase(), self::OWNER );
		$repo  = new BatchRepository();
		$gmt   = fn( int $offset = 0 ): string => gmdate( 'Y-m-d H:i:s', (int) $this->run_clock + $offset );
		// Three chunks died holding 1002 alone.
		$store->items[101]['state'] = 'done';
		foreach ( [ 'b', 'c', 'd' ] as $token ) {
			$repo->claim_chunk( self::BATCH, 50, str_repeat( $token, 32 ), $gmt(), $gmt( -300 ) );
			$this->run_clock += BatchRepository::LEASE_SECONDS + 1;
		}
		$this->assertSame( 'failed', $this->runner()->run( self::BATCH ) );

		$summary = $this->ok( self::dispatch( 'GET', '/batches/12' ) )['summary'];
		$this->assertCount( 1, $summary );
		$this->assertSame( 1, $summary[0]['count'] );
		$this->assertStringContainsString( 'three tries', $summary[0]['sentence'] );
		$this->assertStringStartsWith( '1 product: ', $summary[0]['sentence'] );
	}

	public function test_the_sentence_names_the_count(): void {
		$this->assertSame( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied to 1 product.', Progress::sentence( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.', 1 ) );
		$this->assertSame( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied to 1,284 products.', Progress::sentence( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.', 1284 ) );
		$this->assertSame( '3 products: Stock quantity has to be a whole number. Enter a number like 12.', Progress::sentence( 'Stock quantity has to be a whole number. Enter a number like 12.', 3 ) );
	}

	public function test_the_changes_feed_pages_and_caps_per_page(): void {
		new RunnerStore( self::BATCH, self::products( 7 ), $this->increase(), self::OWNER );
		$this->runner()->run( self::BATCH );

		$page = $this->ok(
			self::dispatch(
				'GET',
				'/batches/12/changes',
				[
					'page'     => 2,
					'per_page' => 3,
				]
			)
		);
		$this->assertSame(
			[
				'page'       => 2,
				'perPage'    => 3,
				'total'      => 7,
				'totalPages' => 3,
			],
			$page['pagination']
		);
		$this->assertSame( [ 1004, 1005, 1006 ], array_column( $page['items'], 'object_id' ) );
		$last = $this->ok(
			self::dispatch(
				'GET',
				'/batches/12/changes',
				[
					'page'     => 3,
					'per_page' => 3,
				]
			)
		);
		$this->assertSame( [ 1007 ], array_column( $last['items'], 'object_id' ) );
		$this->assertSame( 100, $this->ok( self::dispatch( 'GET', '/batches/12/changes', [ 'per_page' => 500 ] ) )['pagination']['perPage'] );
		$reads = preg_grep( '/^SELECT change_id/', $GLOBALS['wpdb']->queries );
		$this->assertStringEndsWith( 'LIMIT 100 OFFSET 0', (string) end( $reads ) );

		$this->refused( self::dispatch( 'GET', '/batches/12/changes', [ 'result' => [ 'lost' ] ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( 'GET', '/batches/12/changes', [ 'per_page' => 0 ] ), 'rest_invalid_param', 400 );
	}

	public function test_a_missing_batch_is_not_found(): void {
		new RunnerStore( self::BATCH, [], [], self::OWNER );
		$this->refused( self::dispatch( 'GET', '/batches/99' ), BatchesController::NOT_FOUND, 404 );
		$this->refused( self::dispatch( 'GET', '/batches/99/changes' ), BatchesController::NOT_FOUND, 404 );
		$this->refused( self::dispatch( 'POST', '/batches/99/cancel' ), BatchesController::NOT_FOUND, 404 );
	}

	public function test_visitors_and_users_without_the_capability_are_refused(): void {
		$store = new RunnerStore( self::BATCH, self::products( 2 ), $this->increase(), self::OWNER );
		$reads = [ [ 'GET', '/batches' ], [ 'GET', '/batches/12' ], [ 'GET', '/batches/12/changes' ] ];

		// A read-only user (the screen, not product editing) may follow any batch but not cancel it.
		$GLOBALS['bcm_test']['caps']['edit_products'] = false;
		foreach ( $reads as [ $method, $path ] ) {
			$this->ok( self::dispatch( $method, $path ) );
		}
		$this->refused( self::dispatch( 'POST', '/batches/12/cancel' ), 'bcm_forbidden', 403 );
		$this->assertSame( 'queued', $store->batch()['status'] );

		$GLOBALS['bcm_test']['caps'] = [];
		foreach ( array_merge( $reads, [ [ 'POST', '/batches/12/cancel' ] ] ) as [ $method, $path ] ) {
			$this->refused( self::dispatch( $method, $path ), 'bcm_forbidden', 403 );
		}

		$GLOBALS['bcm_test']['logged_in'] = false;
		foreach ( array_merge( $reads, [ [ 'POST', '/batches/12/cancel' ] ] ) as [ $method, $path ] ) {
			$this->refused( self::dispatch( $method, $path ), 'bcm_forbidden', 401 );
		}
		$this->assertSame( 'queued', $store->batch()['status'] );
	}

	public function test_the_history_list_pages_newest_first_with_its_filters(): void {
		global $wpdb;
		$rows = [];
		foreach ( [ [ 1, 3, 'complete', '2026-09-01 10:00:00' ], [ 2, 4, 'partial', '2026-09-10 10:00:00' ], [ 3, 3, 'partial', '2026-09-20 10:00:00' ], [ 4, 3, 'running', '2026-09-23 07:59:00' ] ] as [ $id, $user, $status, $created ] ) {
			$rows[] = [
				'batch_id'        => $id,
				'uuid'            => sprintf( '00000000-0000-4000-8000-%012d', $id ),
				'user_id'         => $user,
				'created_gmt'     => $created,
				'finished_gmt'    => 'running' === $status ? null : $created,
				'status'          => $status,
				'source'          => 'bulk',
				'undo_of'         => null,
				'operations'      => ValueCodec::encode( [] ),
				'selection'       => ValueCodec::encode( [ 'ids' => range( 1, 1000 ) ] ),
				'total_items'     => 10,
				'previewed_items' => 3 === $id ? 8 : null,
				'done_items'      => 'running' === $status ? 4 : 10,
				'changed_items'   => 4,
				'failed_items'    => 'partial' === $status ? 1 : 0,
				'chunk_size'      => 50,
				'note'            => '',
				'actions'         => '',
				'pruned_gmt'      => null,
			];
		}
		$db              = new SqlEvaluator(
			[
				'wp_bcm_batches'     => $rows,
				'wp_bcm_batch_items' => [
					[
						'batch_id'    => 4,
						'state'       => 'pending',
						'claimed_gmt' => null,
					],
				],
			]
		);
		$wpdb->responses = [
			'/^SELECT COUNT\(\*\) FROM `wp_bcm_(batches|batch_items)`/' => static fn( string $sql ): ?string => $db->value( $sql ),
			'/^SELECT claimed_gmt FROM/' => static fn( string $sql ): ?string => $db->value( $sql ),
			'/^SELECT batch_id, uuid/'   => static fn( string $sql ): array => $db->rows( $sql ),
		];

		$page = $this->ok( self::dispatch( 'GET', '/batches', [ 'per_page' => 2 ] ) );
		$this->assertSame( [ 4, 3 ], array_column( $page['items'], 'batch_id' ), 'Newest first.' );
		$this->assertSame(
			[
				'page'       => 1,
				'perPage'    => 2,
				'total'      => 4,
				'totalPages' => 2,
			],
			$page['pagination']
		);
		$this->assertArrayNotHasKey( 'summary', $page['items'][0], 'The list leaves the grouped reasons to the batch read.' );
		$this->assertSame( [ 1, false ], [ $page['items'][0]['pending_items'], $page['items'][0]['stalled'] ] );
		$this->assertArrayNotHasKey( 'selection', $page['items'][0] );
		$this->assertSame( [ null, '' ], [ $page['items'][0]['previewed_count'], $page['items'][0]['selection_difference'] ] );
		$this->assertSame( 8, $page['items'][1]['previewed_count'] );
		$this->assertSame( 'The preview showed 8 products, but 10 matched the filters when the change started, so 2 more products were included.', $page['items'][1]['selection_difference'], 'The history row states the difference.' );

		$ids = static fn( array $params ): array => array_column( self::dispatch( 'GET', '/batches', $params )->get_data()['items'], 'batch_id' );
		$this->assertSame( [ 3, 2 ], $ids( [ 'status' => 'partial' ] ) );
		$this->assertSame( [ 4, 3, 1 ], $ids( [ 'user' => 3 ] ) );
		$this->assertSame(
			[ 3 ],
			$ids(
				[
					'user'   => 3,
					'status' => 'partial',
				]
			)
		);
		$this->assertSame(
			[ 3, 2 ],
			$ids(
				[
					'after'  => '2026-09-05',
					'before' => '2026-09-23T00:00:00Z',
				]
			)
		);
		$this->assertSame( [ 4 ], $ids( [ 'after' => '2026-09-23T09:58:00+02:00' ] ), 'A zone is honoured: 07:58 GMT.' );

		$this->refused( self::dispatch( 'GET', '/batches', [ 'status' => 'lost' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( 'GET', '/batches', [ 'after' => 'last week' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( 'GET', '/batches', [ 'user' => 0 ] ), 'rest_invalid_param', 400 );
	}

	public function test_a_batch_states_the_difference_from_its_previewed_count(): void {
		$store                           = new RunnerStore( self::BATCH, self::products( 3 ), $this->increase(), self::OWNER );
		$store->batch['previewed_items'] = 2;
		$this->runner()->run( self::BATCH );
		$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( 'complete', $report['status'] );
		$this->assertSame( 2, $report['previewed_count'] );
		$this->assertSame( 3, $report['total_items'] );
		$this->assertSame( 'The preview showed 2 products, but 3 matched the filters when the change started, so 1 more product was included.', $report['selection_difference'] );

		$this->assertSame( 'The preview showed 5 products, but 3 matched the filters when the change started, so 2 fewer products were included.', Progress::difference( 5, 3 ) );
		$this->assertSame( '', Progress::difference( 3, 3 ), 'No difference, no sentence.' );
		$this->assertSame( '', Progress::difference( null, 3 ), 'An id selection has no previewed count.' );
	}

	/**
	 * A running inline batch of one whose request died after claiming its item.
	 */
	private function dead_inline_edit(): RunnerStore {
		$store                  = new RunnerStore( self::BATCH, self::products( 1 ), $this->increase(), self::OWNER );
		$store->batch['status'] = 'running';
		$store->batch['source'] = 'inline';
		foreach ( array_keys( $store->items ) as $id ) {
			$store->items[ $id ]['claim_token'] = str_repeat( 'a', 32 );
			$store->items[ $id ]['claimed_gmt'] = '2026-09-23 08:00:05';
			$store->items[ $id ]['attempts']    = 1;
		}
		return $store;
	}

	public function test_a_stale_inline_batch_is_swept_to_failed_on_read(): void {
		$store = $this->dead_inline_edit();

		$this->now = self::CREATED + 5 + Progress::INLINE_STALE_SECONDS;
		$report    = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( 'running', $report['status'], 'Ten minutes exactly is not yet stale.' );
		$this->assertSame( 'pending', $store->item( 1001 )['state'] );

		$this->now = self::CREATED + 5 + Progress::INLINE_STALE_SECONDS + 1;
		$report    = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertSame( 'failed', $report['status'] );
		$this->assertFalse( $report['cancellable'] );
		$this->assertNotNull( $report['finished_gmt'] );
		$this->assertSame( [ 1, 0, 1, 0 ], [ $report['done_items'], $report['changed_items'], $report['failed_items'], $report['pending_items'] ] );
		$this->assertStringContainsString( 'marked as failed', $report['note'] );
		$this->assertStringNotContainsString( 'bcm_', $report['note'] );
		$this->assertSame( 'failed', $store->item( 1001 )['state'] );
		$this->assertStringContainsString( 'stopped before it was saved', $store->item( 1001 )['message'] );
		$this->assertSame( [ '1 product: This edit stopped before it was saved. Check the product and make the edit again.' ], array_column( $report['summary'], 'sentence' ) );
		$this->assertSame( '10.00', number_format( (float) self::price( 1001 ), 2 ), 'Nothing was written to the product.' );

		// A second read changes nothing more.
		$writes = count( $GLOBALS['wpdb']->writes );
		$this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$this->assertCount( $writes, $GLOBALS['wpdb']->writes );
		$this->assertSame( 1, $store->batch()['failed_items'] );
	}

	public function test_only_a_running_inline_batch_is_swept(): void {
		foreach ( [ [ 'running', 'bulk' ], [ 'queued', 'inline' ], [ 'complete', 'inline' ] ] as [ $status, $source ] ) {
			$store                  = $this->dead_inline_edit();
			$store->batch['status'] = $status;
			$store->batch['source'] = $source;
			$this->now              = self::CREATED + 86400;
			$report                 = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
			$this->assertSame( $status, $report['status'], "A $status $source batch is left alone." );
			$this->assertSame( 'pending', $store->item( 1001 )['state'] );
		}
	}
}
