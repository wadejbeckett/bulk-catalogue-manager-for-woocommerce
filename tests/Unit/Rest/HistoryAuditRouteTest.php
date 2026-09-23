<?php
/**
 * The history and audit routes (T54; SPEC §4.6, §9.4, §9.5, §12.1, §12.6)
 * through the REST dispatcher over seeded batches, items and journal rows,
 * answered by the SQL evaluator running the repositories' real statements:
 * `GET /batches` filtered by action type (with status, user and dates);
 * every reversibility state with the trigger that produces it, read for a
 * whole page in one grouped pair of statements and the conflict counter
 * asked only for batches it can matter to; `GET /audit` filtered by
 * product, field, user and dates, its names resolved in one prime per page;
 * `per_page` read as at most 100; 400 for a value outside the registry;
 * 401 for a visitor and 403 for a user without the capability.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\AuditController;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

final class HistoryAuditRouteTest extends TestCase {
	/**
	 * What the routes take as now: 2026-09-23 08:01:00 GMT.
	 */
	private const NOW = 1790150460;

	/**
	 * Seeded batch rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $batches = [];

	/**
	 * Seeded journal rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $changes = [];

	/**
	 * Conflicts the injected counter reports, by batch id.
	 *
	 * @var array<int, int>
	 */
	private array $conflicts = [];

	/**
	 * Batch ids the conflict counter was asked about.
	 *
	 * @var list<int>
	 */
	private array $asked = [];

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->batches   = [];
		$this->changes   = [];
		$this->conflicts = [];
		$this->asked     = [];
		$clock           = static fn(): int => self::NOW;
		$reversibility   = new Reversibility(
			null,
			null,
			null,
			$clock,
			function ( array $batch ): int {
				$this->asked[] = $batch['batch_id'];
				return $this->conflicts[ $batch['batch_id'] ] ?? 0;
			}
		);
		// The same routes again over the test's clock and conflict counter.
		( new Routes( [ new BatchesController( null, null, null, null, $clock, $reversibility ) ] ) )->register();
		$this->seed();
		// User 3 owns most batches; 4 is Rosa; 9 ran no batch; 5 (below) is an account since removed.
		$GLOBALS['bcm_test']['users'] = [
			3 => 'Sam Shop',
			4 => 'Rosa Admin',
			9 => 'Nobody',
		];
	}

	/**
	 * One batch row.
	 *
	 * @param int                        $id         Batch id.
	 * @param string                     $status     Status.
	 * @param list<string>               $actions    Action ids its steps use.
	 * @param array<string, mixed>       $extra      Other columns.
	 */
	private function batch( int $id, string $status, array $actions, array $extra = [] ): void {
		$operations      = array_map(
			static fn( string $action ): array => [
				'action' => $action,
				'args'   => [ 'field' => 'regular_price' ],
			],
			$actions
		);
		$this->batches[] = $extra + [
			'batch_id'        => $id,
			'uuid'            => sprintf( '00000000-0000-4000-8000-%012d', $id ),
			'user_id'         => 3,
			'created_gmt'     => '2026-09-20 10:00:00',
			'finished_gmt'    => in_array( $status, [ 'queued', 'running' ], true ) ? null : '2026-09-20 10:05:00',
			'status'          => $status,
			'source'          => 'bulk',
			'undo_of'         => null,
			'operations'      => ValueCodec::encode( $operations ),
			'selection'       => ValueCodec::encode( [ 'ids' => [] ] ),
			'total_items'     => 2,
			'previewed_items' => null,
			'done_items'      => 2,
			'changed_items'   => 2,
			'failed_items'    => 0,
			'chunk_size'      => 50,
			'note'            => '',
			'actions'         => BatchRepository::actions( $operations ),
			'pruned_gmt'      => null,
		];
	}

	/**
	 * One journal row.
	 */
	private function change( int $batch_id, int $object_id, string $prop, mixed $old, mixed $new, string $result, string $applied_gmt, string $object_type = 'product' ): void {
		$this->changes[] = [
			'change_id'    => count( $this->changes ) + 1,
			'batch_id'     => $batch_id,
			'object_id'    => $object_id,
			'object_type'  => $object_type,
			'prop'         => $prop,
			'old_value'    => ValueCodec::encode( $old ),
			'new_value'    => ValueCodec::encode( $new ),
			'modified_gmt' => '2026-09-01 00:00:00',
			'applied_gmt'  => $applied_gmt,
			'result'       => $result,
			'message'      => 'applied' === $result ? '' : 'This price was not applied.',
		];
	}

	/**
	 * Batches covering every reversibility state, their journal, and the
	 * evaluator answering every read of the three tables.
	 */
	private function seed(): void {
		global $wpdb;
		$this->batch( 1, 'complete', [ 'adjust' ] );
		$this->batch( 2, 'complete', [ 'set_value', 'adjust' ], [ 'user_id' => 4 ] );
		$this->batch( 3, 'running', [ 'round' ], [ 'created_gmt' => '2026-09-23 08:00:30' ] );
		$this->batch( 4, 'queued', [ 'adjust' ], [ 'created_gmt' => '2026-09-23 08:00:40' ] );
		$this->batch( 5, 'partial', [ 'adjust' ], [ 'failed_items' => 1 ] );
		$this->batch( 6, 'failed', [ 'set_value' ], [ 'failed_items' => 2 ] );
		$this->batch( 7, 'complete', [ 'clear' ] );
		$this->batch(
			8,
			'complete',
			[ 'set_value' ],
			[
				'source'  => 'undo',
				'undo_of' => 7,
			]
		);
		$this->batch( 9, 'complete', [ 'adjust' ], [ 'pruned_gmt' => '2026-09-22 03:00:00' ] );
		$this->batch( 10, 'complete', [ 'adjust' ], [ 'created_gmt' => '2026-06-01 10:00:00' ] );
		$this->batch(
			11,
			'failed',
			[ 'set_value' ],
			[
				'source'  => 'undo',
				'undo_of' => 5,
			]
		);
		$this->batch( 12, 'cancelled', [ 'adjust' ] );
		$this->batch( 13, 'undone', [ 'adjust' ] );
		$this->batch( 14, 'complete', [ 'adjust' ], [ 'changed_items' => 0 ] );

		foreach ( [ 1, 2, 5, 7, 8, 9, 10, 11, 12, 13 ] as $id ) {
			$this->change( $id, 1001, 'regular_price', '10', '11', 'applied', '2026-09-20 10:01:00' );
		}
		$this->change( 5, 1002, 'regular_price', '10', '11', 'failed', '2026-09-20 10:01:00' );
		$this->change( 6, 1001, 'regular_price', '10', '11', 'failed', '2026-09-20 10:01:00' );
		$this->change( 6, 1002, 'regular_price', '10', '11', 'skipped', '2026-09-20 10:01:00' );
		// The audit log's own rows: another product, another field, another day, a lost product.
		$this->change( 2, 1002, 'sale_price', '', '8', 'applied', '2026-09-21 12:00:00' );
		$this->change( 2, 1003, 'regular_price', '5', '6', 'applied', '2026-09-22 12:00:00', 'variation' );
		$this->change( 2, 1009, 'regular_price', '5', '6', 'applied', '2026-09-22 13:00:00' );

		foreach ( [ 1001, 1002, 1003 ] as $id ) {
			$GLOBALS['bcm_test']['posts'][ $id ] = [
				'post_type'   => 1003 === $id ? 'product_variation' : 'product',
				'post_status' => 'publish',
				'post_title'  => "Shoe $id",
			];
		}

		$tables          = '`wp_bcm_(batches|batch_items|changes)`';
		$wpdb->responses = [
			'/^SELECT \* FROM `wp_bcm_batches` WHERE batch_id = (\d+)$/' => function ( string $sql ): ?array {
				preg_match( '/(\d+)$/', $sql, $m );
				foreach ( $this->batches as $row ) {
					if ( (int) $m[1] === $row['batch_id'] ) {
						return array_map( static fn( mixed $v ): ?string => null === $v ? null : (string) $v, $row );
					}
				}
				return null;
			},
			"/^SELECT .* FROM $tables/s" => function ( string $sql ): mixed {
				$db = new SqlEvaluator(
					[
						'wp_bcm_batches'     => $this->batches,
						'wp_bcm_batch_items' => [],
						'wp_bcm_changes'     => $this->changes,
					]
				);
				return 1 === preg_match( '/^SELECT (COUNT\(\*\)|claimed_gmt) FROM/', $sql ) ? $db->value( $sql ) : $db->rows( $sql );
			},
		];
	}

	private static function dispatch( string $path, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/bcm/v1' . $path );
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
	}

	/**
	 * The batch ids a history request lists.
	 *
	 * @return list<int>
	 */
	private function ids( array $params ): array {
		return array_column( $this->ok( self::dispatch( '/batches', $params + [ 'per_page' => 100 ] ) )['items'], 'batch_id' );
	}

	/**
	 * Every listed batch's state, as `id => [ state, conflicts, reason ]`.
	 *
	 * @return array<int, array{0: string, 1: ?int, 2: ?string}>
	 */
	private function states(): array {
		$states = [];
		foreach ( $this->ok( self::dispatch( '/batches', [ 'per_page' => 100 ] ) )['items'] as $item ) {
			$states[ $item['batch_id'] ] = array_values( $item['reversibility'] );
		}
		return $states;
	}

	public function test_the_routes_register_with_their_permissions_arguments_and_schemas(): void {
		$routes = $GLOBALS['bcm_test']['routes'];
		$audit  = $routes['/bcm/v1/audit'];
		$this->assertSame( 'GET', $audit[0]['methods'] );
		$this->assertSame( [ Access::class, 'can_read' ], $audit[0]['permission_callback'] );
		$this->assertSame( [ 'page', 'per_page', 'product', 'field', 'user', 'after', 'before' ], array_keys( $audit[0]['args'] ) );
		$this->assertSame( ( new Fields() )->keys(), $audit[0]['args']['field']['enum'], 'The field argument is the registry.' );
		$this->assertSame( 'bcm-audit-change', call_user_func( $audit['schema'] )['title'] );
		$this->assertSame( [ 'batch_id', 'user_id', 'user_name', 'change_id' ], array_slice( array_keys( call_user_func( $audit['schema'] )['properties'] ), 0, 4 ) );

		$list = $routes['/bcm/v1/batches'][1];
		$this->assertSame( [ Access::class, 'can_read' ], $list['permission_callback'] );
		$this->assertSame( \BulkCatalogueManager\Operations\Registry::instance()->ids(), $list['args']['action']['enum'], 'The action argument is the operations registry.' );
		$schema = call_user_func( $routes['/bcm/v1/batches']['schema'] )['properties']['reversibility'];
		$this->assertSame( Reversibility::STATES, $schema['properties']['state']['enum'] );
		$this->assertSame( [ ...Reversibility::REASONS, null ], $schema['properties']['reason']['enum'] );
		$this->assertSame( [], $GLOBALS['bcm_test']['wrong'] );
	}

	public function test_the_history_list_filters_by_action_type_with_its_other_filters(): void {
		$this->assertSame( [ 14, 13, 12, 10, 9, 5, 4, 2, 1 ], $this->ids( [ 'action' => 'adjust' ] ) );
		$this->assertSame( [ 11, 8, 6, 2 ], $this->ids( [ 'action' => 'set_value' ] ), 'The underscore is matched literally.' );
		$this->assertSame( [ 3 ], $this->ids( [ 'action' => 'round' ] ) );
		$this->assertSame( [], $this->ids( [ 'action' => 'find_replace' ] ) );
		$this->assertSame(
			[ 2 ],
			$this->ids(
				[
					'action' => 'adjust',
					'user'   => 4,
				]
			)
		);
		$this->assertSame(
			[ 12 ],
			$this->ids(
				[
					'action' => 'adjust',
					'status' => 'cancelled',
				]
			)
		);
		$this->assertSame(
			[ 4, 3 ],
			$this->ids(
				[
					'after'  => '2026-09-23',
					'before' => '2026-09-24',
				]
			)
		);
		$this->assertStringContainsString( "actions LIKE '%,set\\\\_value,%'", implode( "\n", $GLOBALS['wpdb']->queries ) );

		$item = $this->ok( self::dispatch( '/batches', [ 'action' => 'set_value' ] ) )['items'][3];
		$this->assertSame( [ 'set_value', 'adjust' ], $item['actions'], 'A row lists its action ids in step order.' );
		$this->assertSame( 4, $this->ok( self::dispatch( '/batches', [ 'action' => 'set_value' ] ) )['pagination']['total'] );

		$this->refused( self::dispatch( '/batches', [ 'action' => 'no_such_action' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/batches', [ 'action' => [ 'adjust' ] ] ), 'rest_invalid_param', 400 );
	}

	public function test_every_reversibility_state_follows_from_its_trigger(): void {
		$this->conflicts = [ 2 => 3 ];
		$this->assertSame(
			[
				14 => [ 'not_reversible', null, 'nothing_applied' ],          // Finished with every row already at its target.
				13 => [ 'not_reversible', null, 'already_undone' ],           // Its status says so.
				12 => [ 'reversible', null, null ],                           // Cancelled after applying some rows: those can be undone.
				11 => [ 'completed_with_errors', null, null ],                // A failed undo batch with applied rows.
				10 => [ 'not_reversible', null, 'outside_retention' ],        // Created 114 days ago, kept 90.
				9  => [ 'not_reversible', null, 'pruned' ],                   // Pruning removed its journal.
				8  => [ 'reversible', null, null ],                           // The undo batch itself can be undone: Redo.
				7  => [ 'not_reversible', null, 'already_undone' ],           // Batch 8 undid it and completed.
				6  => [ 'not_reversible', null, 'nothing_applied' ],          // Failed without applying anything.
				5  => [ 'completed_with_errors', null, null ],                // Partial with applied rows; its undo (11) did not complete.
				4  => [ 'in_progress', null, null ],                          // Queued.
				3  => [ 'in_progress', null, null ],                          // Running.
				2  => [ 'reversible', null, null ],                           // Conflicts are not counted on the list (T56): the single read says partially reversible.
				1  => [ 'reversible', null, null ],
			],
			$this->states()
		);
		$this->assertSame( [], $this->asked, 'The list counts no conflicts, so it builds no undo plan per row (T56).' );

		// One grouped read each for the whole page, not one per row.
		$queries = $GLOBALS['wpdb']->queries;
		$this->assertCount( 1, preg_grep( '/^SELECT batch_id, undo_of FROM .* undo_of IN \(/', $queries ) );
		$this->assertCount( 1, preg_grep( '/^SELECT batch_id, undo_of, user_id FROM .* undo_of IN \(/', $queries ), 'The list row\'s "put back by", for the undone rows only.' );
		$this->assertCount( 1, preg_grep( "/result = 'applied' GROUP BY batch_id/", $queries ) );

		// The single read counts them, only where undo is still possible.
		$states = [];
		foreach ( range( 1, 14 ) as $id ) {
			$states[ $id ] = array_values( $this->ok( self::dispatch( "/batches/$id" ) )['reversibility'] );
		}
		$this->assertSame( [ 'partially_reversible', 3, null ], $states[2], 'Three objects changed again since.' );
		$this->assertSame( [ 'in_progress', 0, null ], $states[3] );
		$this->assertSame( [ 'not_reversible', 0, 'pruned' ], $states[9] );
		sort( $this->asked );
		$this->assertSame( [ 1, 2, 5, 8, 11, 12 ], $this->asked, 'Conflicts are counted only where undo is still possible.' );
	}

	public function test_a_batch_read_carries_its_state(): void {
		$this->conflicts = [
			2 => 3,
			5 => 1,
		];
		$read            = fn( int $id ): array => $this->ok( self::dispatch( "/batches/$id" ) )['reversibility'];
		$this->assertSame(
			[
				'state'     => 'partially_reversible',
				'conflicts' => 3,
				'reason'    => null,
			],
			$read( 2 )
		);
		$this->assertSame(
			[
				'state'     => 'completed_with_errors',
				'conflicts' => 1,
				'reason'    => null,
			],
			$read( 5 ),
			'Errors outrank conflicts; the count still shows.'
		);
		$this->assertSame( 'already_undone', $read( 7 )['reason'] );
		$this->assertSame( 'in_progress', $read( 3 )['state'] );
		$this->assertSame( 'pruned', $read( 9 )['reason'] );
		$this->assertSame( [ 'adjust' ], $this->ok( self::dispatch( '/batches/1' ) )['actions'] );
	}

	public function test_retention_follows_the_setting_and_never_applies_when_kept_indefinitely(): void {
		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'history_days' => 730 ];
		$this->assertSame( [ 'reversible', null, null ], $this->states()[10] );

		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'history_days' => 0 ];
		$this->assertSame( [ 'reversible', null, null ], $this->states()[10], 'Kept indefinitely: never outside retention.' );

		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'history_days' => 7 ];
		$states = $this->states();
		$this->assertSame( [ 'not_reversible', null, 'outside_retention' ], $states[10] );
		$this->assertSame( [ 'reversible', null, null ], $states[1], 'Created three days ago, inside seven.' );
		$this->assertSame( 'pruned', $states[9][2], 'Pruned is reported before retention.' );
	}

	public function test_an_undo_that_completed_marks_its_batch_undone_and_repository_reads_it(): void {
		$repo = new BatchRepository();
		$this->assertSame( 8, $repo->undo_of( 7 ) );
		$this->assertNull( $repo->undo_of( 5 ), 'A failed undo leaves the batch undoable.' );
		$this->assertNull( $repo->undo_of( 1 ) );
		$this->assertSame( [ 7 => 8 ], $repo->undone_by( [ 1, 5, 7, 7 ] ) );
		$this->assertSame( [], $repo->undone_by( [] ) );
		$this->assertSame(
			[
				1 => 1,
				5 => 1,
			],
			( new ChangeRepository() )->applied_counts( [ 1, 5, 6 ] )
		);
	}

	public function test_list_rows_carry_the_user_name_whether_variations_were_included_and_who_put_them_back(): void {
		// Batch 1 reached 3 variations; batch 2's switch added none but left a parent out; batch 13 was put back by 15, run by a removed account.
		$this->batches[0]['selection'] = ValueCodec::encode(
			[
				'kind'             => 'ids',
				'ids'              => [ 103 ],
				'selected'         => 1,
				'variations_added' => 3,
				'parents_left_out' => 1,
			]
		);
		$this->batches[1]['selection'] = ValueCodec::encode(
			[
				'kind'             => 'ids',
				'ids'              => [ 103 ],
				'selected'         => 1,
				'variations_added' => 0,
				'parents_left_out' => 1,
			]
		);
		$this->batch(
			15,
			'complete',
			[ 'set_value' ],
			[
				'source'  => 'undo',
				'undo_of' => 13,
				'user_id' => 5,
			]
		);
		$before = count( $GLOBALS['wpdb']->queries );
		$items  = array_column( $this->ok( self::dispatch( '/batches', [ 'per_page' => 100 ] ) )['items'], null, 'batch_id' );
		$this->assertSame( [ 'Sam Shop', 'Rosa Admin', 'User 5' ], [ $items[1]['user_name'], $items[2]['user_name'], $items[15]['user_name'] ], 'A removed account reads as "User 5".' );
		$this->assertSame( [ 1 ], array_keys( array_filter( array_column( $items, 'variations_included', 'batch_id' ) ) ), 'Only a batch whose switch added variations.' );
		$this->assertSame(
			[
				'batch_id'  => 15,
				'user_id'   => 5,
				'user_name' => 'User 5',
			],
			$items[13]['undone_by']
		);
		$this->assertSame( [ 13 ], array_keys( array_filter( array_column( $items, 'undone_by', 'batch_id' ) ) ), 'Only an undone row says who put it back.' );
		$this->assertCount( 1, $GLOBALS['bcm_test']['get_users_calls'], 'Every name on the page from one get_users() call.' );
		$this->assertSame( [ 3, 4, 5 ], $GLOBALS['bcm_test']['get_users_calls'][0]['include'] );
		$queries = array_slice( $GLOBALS['wpdb']->queries, $before );
		$this->assertCount( 1, preg_grep( '/selection LIKE/', $queries ), 'One read for the variations flag.' );
		$this->assertCount( 0, preg_grep( '/SELECT \\* |SELECT selection/', $queries ), 'The selection snapshot is never loaded.' );

		$schema = call_user_func( $GLOBALS['bcm_test']['routes']['/bcm/v1/batches']['schema'] )['properties'];
		foreach ( [ 'user_name', 'variations_included', 'undone_by' ] as $key ) {
			$this->assertArrayHasKey( $key, $schema );
		}

		// A page with no undone row reads no undo batch.
		$before = count( $GLOBALS['wpdb']->queries );
		$this->ok( self::dispatch( '/batches', [ 'status' => 'complete' ] ) );
		$this->assertCount( 0, preg_grep( '/undo_of, user_id/', array_slice( $GLOBALS['wpdb']->queries, $before ) ) );
	}

	public function test_the_users_route_lists_every_batch_owner_by_name(): void {
		$this->batch( 15, 'complete', [ 'adjust' ], [ 'user_id' => 5 ] );
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/batches/users'];
		$this->assertSame( [ Access::class, 'can_read' ], $route[0]['permission_callback'] );
		$this->assertSame( 'bcm-batch-user', call_user_func( $route['schema'] )['title'] );
		$this->assertSame(
			[
				[
					'id'   => 4,
					'name' => 'Rosa Admin',
				],
				[
					'id'   => 3,
					'name' => 'Sam Shop',
				],
				[
					'id'   => 5,
					'name' => 'User 5',
				],
			],
			$this->ok( self::dispatch( '/batches/users' ) )['items'],
			'Owners only (user 9 ran nothing), by name.'
		);
		$this->assertCount( 1, $GLOBALS['bcm_test']['get_users_calls'] );

		$GLOBALS['bcm_test']['caps'] = [];
		$this->refused( self::dispatch( '/batches/users' ), 'bcm_forbidden', 403 );
	}

	public function test_the_audit_log_lists_changes_across_batches_newest_first_with_names_and_labels(): void {
		$before = count( $GLOBALS['wpdb']->queries );
		$page   = $this->ok( self::dispatch( '/audit', [ 'per_page' => 3 ] ) );
		$this->assertSame(
			[
				'page'       => 1,
				'perPage'    => 3,
				'total'      => count( $this->changes ),
				'totalPages' => (int) ceil( count( $this->changes ) / 3 ),
			],
			$page['pagination']
		);
		$this->assertSame( [ 16, 15, 14 ], array_column( $page['items'], 'change_id' ), 'Newest first.' );
		$row = $page['items'][1];
		$this->assertSame(
			[ 'batch_id', 'user_id', 'user_name', 'change_id', 'object_id', 'object_type', 'name', 'field', 'field_label', 'old_value', 'new_value', 'result', 'message', 'detail', 'applied_gmt' ],
			array_keys( $row )
		);
		$this->assertSame( [ 'Rosa Admin', 'Rosa Admin', 'Rosa Admin' ], array_column( $page['items'], 'user_name' ) );
		$this->assertCount( 1, $GLOBALS['bcm_test']['get_users_calls'], 'One get_users() call for the page.' );
		$this->assertSame( [ 4 ], $GLOBALS['bcm_test']['get_users_calls'][0]['include'] );
		$this->assertSame( [ 2, 4, 1003, 'variation', 'Shoe 1003', 'regular_price', 'Regular price', '5', '6', 'applied', '2026-09-22 12:00:00' ], [ $row['batch_id'], $row['user_id'], $row['object_id'], $row['object_type'], $row['name'], $row['field'], $row['field_label'], $row['old_value'], $row['new_value'], $row['result'], $row['applied_gmt'] ] );
		$this->assertSame( 'Product 1009', $page['items'][0]['name'], 'A product that is gone gets a plain stand-in.' );
		$this->assertSame( 'Sale price', $page['items'][2]['field_label'] );

		// Two statements (the page and its count) and one name prime for the page; no per-row read.
		$queries = array_slice( $GLOBALS['wpdb']->queries, $before );
		$this->assertCount( 1, preg_grep( '/_prime_post_caches/', $queries ) );
		$this->assertCount( 0, preg_grep( '/get_post/', preg_grep( '/_prime_post_caches/', $queries, PREG_GREP_INVERT ) ) );
		$this->assertCount( 3, $queries );
	}

	public function test_the_audit_log_filters_by_product_field_user_and_date(): void {
		$ids = fn( array $params ): array => array_column( $this->ok( self::dispatch( '/audit', $params + [ 'per_page' => 100 ] ) )['items'], 'change_id' );
		$this->assertSame( [ 12, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1 ], $ids( [ 'product' => 1001 ] ) );
		$this->assertSame( [ 15 ], $ids( [ 'product' => 1003 ] ), 'A variation id lists that variation\'s own changes.' );
		$this->assertSame( [ 14 ], $ids( [ 'field' => 'sale_price' ] ) );
		$this->assertSame( [ 16, 15, 14, 2 ], $ids( [ 'user' => 4 ] ), 'The user is the batch\'s.' );
		$this->assertSame(
			[ 15, 14 ],
			$ids(
				[
					'after'  => '2026-09-21',
					'before' => '2026-09-22T12:30:00Z',
				]
			)
		);
		$this->assertSame( [ 16 ], $ids( [ 'after' => '2026-09-22T14:30:00+02:00' ] ), 'A zone is honoured: 12:30 GMT.' );
		$this->assertSame(
			[ 15 ],
			$ids(
				[
					'user'    => 4,
					'field'   => 'regular_price',
					'product' => 1003,
				]
			)
		);
		$this->assertSame( [], $ids( [ 'product' => 4242 ] ) );
		$this->assertSame( 0, $this->ok( self::dispatch( '/audit', [ 'product' => 4242 ] ) )['pagination']['total'] );

		$this->refused( self::dispatch( '/audit', [ 'field' => 'no_such_field' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/audit', [ 'field' => '_regular_price' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/audit', [ 'product' => 0 ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/audit', [ 'user' => 'me' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/audit', [ 'before' => 'yesterday' ] ), 'rest_invalid_param', 400 );
		$this->refused( self::dispatch( '/audit', [ 'per_page' => 0 ] ), 'rest_invalid_param', 400 );
	}

	public function test_per_page_is_capped_at_100(): void {
		for ( $i = 0; $i < 110; $i++ ) {
			$this->change( 1, 2000 + $i, 'stock_quantity', 1, 2, 'applied', '2026-09-20 11:00:00' );
		}
		$page = $this->ok( self::dispatch( '/audit', [ 'per_page' => 500 ] ) );
		$this->assertSame( 100, $page['pagination']['perPage'] );
		$this->assertCount( 100, $page['items'] );
		$this->assertSame( 2, $page['pagination']['totalPages'] );

		$list = $this->ok( self::dispatch( '/batches', [ 'per_page' => 500 ] ) );
		$this->assertSame( 100, $list['pagination']['perPage'] );
		$this->assertCount( 14, $list['items'] );
	}

	public function test_visitors_and_users_without_the_capability_are_refused_before_any_row_is_read(): void {
		$reads = [ [ '/batches', [ 'action' => 'adjust' ] ], [ '/batches/1', [] ], [ '/audit', [ 'product' => 1001 ] ] ];

		$GLOBALS['bcm_test']['caps'] = [];
		foreach ( $reads as [ $path, $params ] ) {
			$this->refused( self::dispatch( $path, $params ), 'bcm_forbidden', 403 );
		}

		$GLOBALS['bcm_test']['options']['bcm_settings'] = [ 'administrators_only' => true ];
		$GLOBALS['bcm_test']['caps']                    = [ 'manage_woocommerce' => true ];
		foreach ( $reads as [ $path, $params ] ) {
			$this->refused( self::dispatch( $path, $params ), 'bcm_forbidden', 403 );
		}

		$GLOBALS['bcm_test']['logged_in'] = false;
		foreach ( $reads as [ $path, $params ] ) {
			$this->refused( self::dispatch( $path, $params ), 'bcm_forbidden', 401 );
		}
		$this->assertSame( [], preg_grep( '/bcm_(batches|changes)/', $GLOBALS['wpdb']->queries ), 'Nothing was read.' );

		$GLOBALS['bcm_test']['logged_in'] = true;
		$GLOBALS['bcm_test']['caps']      = [ 'manage_options' => true ];
		foreach ( $reads as [ $path, $params ] ) {
			$this->ok( self::dispatch( $path, $params ) );
		}
	}
}
