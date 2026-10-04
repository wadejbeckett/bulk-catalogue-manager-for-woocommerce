<?php
/**
 * `POST /bulkcat/v1/batches` (T31, T83; SPEC §9.2, §12.1, §12.6, §13)
 * through the REST dispatcher: registered behind Access::can_write; a
 * visitor is refused 401 and a user who may not edit products 403; a bad
 * request id or any source but `bulk` is refused before the handler.
 *
 * Since T83 the route creates a batch only as a durable request (grant,
 * canonical intent, preview hash; Requests\RequestService). Its receipt,
 * transaction, scope and scheduling behaviour run on native storage and
 * are proved on the fixture (tests/probe/exact-batch*.php); here: a durable
 * body with an unknown key is refused before any storage work, and on
 * storage that is not ready it fails closed with nothing written.
 *
 * A body from a 1.0 screen (a bare request id) never creates a batch: an
 * unknown id asks the page to reload; the one batch the id exactly made
 * (same operations and ids) answers 200 at any age; the id reused for
 * anything else, or shared by two batches, is refused 409
 * `bulkcat_legacy_request` naming the user's own batches, and nothing is
 * written. The T38 apply check moved with create() to BatchFactoryTest.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use BulkCatalogueManager\Tests\Unit\Engine\BatchStore;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';
require_once dirname( __DIR__ ) . '/Engine/BatchStore.php';

final class BatchesRouteTest extends TestCase {
	private const UUID = '9b2e6f1c-1d2a-4c3b-8e4f-5a6b7c8d9e0f';

	private BatchStore $store;

	private SqlEvaluator $db;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['user_id']                    = 12;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bulkcat_test']['caps']['edit_products']      = true;
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
		$this->db    = SeededCatalogue::seed();
		$this->store = new BatchStore( $this->db );
	}

	/**
	 * Dispatch a POST to the route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private static function post( array $params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/batches' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid body over an id list.
	 *
	 * @return array<string, mixed>
	 */
	private static function body(): array {
		$operations = [
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'status',
					'value' => 'draft',
				],
			],
		];
		return [
			'uuid'         => self::UUID,
			'operations'   => $operations,
			'ids'          => [ 104, 201 ],
			'preview_hash' => self::preview_hash( [ 104, 201 ], $operations ),
		];
	}

	private function assert_refused( mixed $response, string $code, int $status ): void {
		global $wpdb;
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertSame( [], $wpdb->writes, 'Nothing was written.' );
		$this->assertSame( [], $this->store->items );
	}

	public function test_the_route_needs_the_write_capability(): void {
		$route = $GLOBALS['bulkcat_test']['routes']['/bulkcat/v1/batches'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_family_request' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ 'request_proof', 'intent', 'uuid', 'operations', 'ids', 'typed_token', 'token', 'source', 'preview_hash', 'preview_count', 'confirm_changed' ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['uuid']['required'] );
		$this->assertFalse( $route[0]['args']['operations']['required'] ?? false, 'A durable change carries its operations in its intent.' );
		$this->assertSame( BatchesController::SOURCES, $route[0]['args']['source']['enum'] );
		$this->assertArrayNotHasKey( 'default', $route[0]['args']['source'], 'Only what a body sends counts as sent.' );
	}

	/**
	 * The hash a preview of this token and operation list answers.
	 *
	 * @param string|list<int> $token      The selection token, or the id list.
	 * @param array            $operations The operation list as posted.
	 */
	private static function preview_hash( string|array $token, array $operations ): string {
		$list = Actions::instance()->validate_list( $operations );
		return Preview::hash( $token, is_array( $list ) ? $list : [], Settings::load() );
	}

	/**
	 * A batch a 1.0 screen made under the test's request id.
	 */
	private function legacy_batch(): int {
		$body   = self::body();
		$result = ( new BatchFactory() )->create( self::UUID, 12, $body['operations'], Selection::of_ids( $body['ids'] ) );
		$this->assertTrue( is_array( $result ) && false === $result['replayed'] );
		$this->store->sync();
		return $result['batch_id'];
	}

	public function test_an_unknown_1_0_request_id_never_creates_a_batch(): void {
		$response = self::post( self::body() );
		$this->assert_refused( $response, BatchFactory::LEGACY, 409 );
		$this->assertSame( [], $response->get_error_data()['batch_ids'] );
		$this->assertStringContainsString( 'Reload the page', $response->get_error_message() );
		$this->assertSame( [], $this->store->batches );
		$this->assertSame( 0, did_action( BatchFactory::CREATED ) );
	}

	public function test_a_1_0_request_id_answers_the_one_batch_it_exactly_made_at_any_age(): void {
		global $wpdb;
		$batch_id = $this->legacy_batch();
		$this->store->batches[ $batch_id ]['created_gmt'] = '2026-01-01 00:00:00';
		$writes  = $wpdb->writes;
		$queries = count( $wpdb->queries );

		$again = self::post( self::body() );
		$this->assertInstanceOf( \WP_REST_Response::class, $again );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame(
			[
				'batch_id'    => $batch_id,
				'status'      => 'queued',
				'total_items' => 2,
				'replayed'    => true,
			],
			$again->get_data()
		);
		$this->assertSame( $writes, $wpdb->writes, 'The double-click inserts no batch.' );
		$this->assertSame( [], preg_grep( '/^(INSERT|UPDATE|DELETE)/', array_slice( $wpdb->queries, $queries ) ), 'Nothing is written.' );
		$this->assertCount( 1, $this->store->batches );
	}

	public function test_a_1_0_request_id_reused_for_another_change_is_refused_with_its_history_reference(): void {
		global $wpdb;
		$batch_id                                = $this->legacy_batch();
		$writes                                  = $wpdb->writes;
		$other                                   = self::body();
		$other['operations'][0]['args']['value'] = 'private';
		foreach ( [
			'other ids'        => [ 'ids' => [ 101 ] ] + self::body(),
			'other operations' => $other,
			'a 1.0 token'      => [ 'token' => SelectionToken::mint( Selection::of_ids( [ 101 ] ), 12 ) ] + array_diff_key( self::body(), [ 'ids' => 0 ] ),
		] as $case => $body ) {
			$response = self::post( $body );
			$this->assertInstanceOf( \WP_Error::class, $response, $case );
			$this->assertSame( BatchFactory::LEGACY, $response->get_error_code(), $case );
			$this->assertSame( 409, $response->get_error_data()['status'], $case );
			$this->assertSame( [ $batch_id ], $response->get_error_data()['batch_ids'], $case );
			$this->assertStringContainsString( 'Check History', $response->get_error_message(), $case );
		}
		$this->assertSame( $writes, $wpdb->writes, 'Nothing is written.' );
		$this->assertCount( 1, $this->store->batches );
	}

	public function test_two_batches_sharing_a_1_0_request_id_are_ambiguous_and_no_third_is_made(): void {
		global $wpdb;
		$repository = new BatchRepository();
		$first      = $repository->create( self::UUID, 12, 'bulk', self::body()['operations'], [ 'kind' => 'ids' ], 1 );
		$second     = $repository->create( self::UUID, 12, 'bulk', self::body()['operations'], [ 'kind' => 'ids' ], 1 );
		$this->store->sync();
		$writes = $wpdb->writes;

		$response = self::post( self::body() );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( BatchFactory::LEGACY, $response->get_error_code() );
		$this->assertSame( [ $first, $second ], $response->get_error_data()['batch_ids'] );
		$this->assertSame( $writes, $wpdb->writes );
		$this->assertSame( [ $first, $second ], array_keys( $this->store->batches ), 'No third batch.' );
	}

	public function test_another_users_request_id_is_not_theirs_to_resolve(): void {
		$this->legacy_batch();
		$GLOBALS['bulkcat_test']['user_id'] = 13;
		$response                           = self::post( self::body() );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( BatchFactory::LEGACY, $response->get_error_code() );
		$this->assertSame( [], $response->get_error_data()['batch_ids'], 'Another user\'s batches are never named.' );
		$this->assertCount( 1, $this->store->batches );
	}

	public function test_a_durable_body_names_only_its_own_parameters(): void {
		global $wpdb;
		$durable = [
			'uuid'          => self::UUID,
			'request_proof' => str_repeat( 'a', 64 ) . '.' . str_repeat( 'b', 64 ),
			'intent'        => [ 'version' => 1 ],
			'preview_hash'  => str_repeat( 'c', 64 ),
		];
		foreach ( [
			'operations'      => self::body()['operations'],
			'ids'             => [ 104 ],
			'preview_count'   => 2,
			'confirm_changed' => true,
			'source'          => 'bulk',
		] as $key => $value ) {
			$response = self::post( [ $key => $value ] + $durable );
			$this->assert_refused( $response, 'bulkcat_invalid_value', 400 );
			$this->assertStringContainsString( 'unknown option', $response->get_error_message(), $key );
		}
		$this->assertSame( [], preg_grep( '/^(START TRANSACTION|INSERT|SELECT \* FROM `wp_bulkcat_requests`)/', $wpdb->queries ), 'No storage work before the body is known.' );
	}

	public function test_a_durable_body_fails_closed_when_storage_is_not_ready(): void {
		$response = self::post(
			[
				'uuid'          => self::UUID,
				'request_proof' => str_repeat( 'a', 64 ) . '.' . str_repeat( 'b', 64 ),
				'intent'        => [ 'version' => 1 ],
				'preview_hash'  => str_repeat( 'c', 64 ),
			]
		);
		$this->assert_refused( $response, 'bulkcat_request_storage', 503 );
		$this->assertStringContainsString( 'Retry the same request', $response->get_error_message() );
		$this->assertSame( [], $this->store->batches );
	}

	public function test_a_request_cannot_label_its_batch_inline_or_tree(): void {
		// Inline and tree batches are opened by their own routes; one labelled
		// inline here would be swept to failed while it waits in the queue.
		$this->assertSame( [ 'bulk' ], BatchesController::SOURCES );
		foreach ( [ 'inline', 'tree', 'undo' ] as $source ) {
			$this->assert_refused( self::post( [ 'source' => $source ] + self::body() ), 'rest_invalid_param', 400 );
		}
	}

	public function test_a_visitor_and_a_user_who_cannot_edit_products_are_refused(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_products'] = false;
		$this->assert_refused( self::post( self::body() ), 'bulkcat_forbidden', 403 );

		$GLOBALS['bulkcat_test']['logged_in'] = false;
		$GLOBALS['bulkcat_test']['caps']      = [];
		$this->assert_refused( self::post( self::body() ), 'bulkcat_forbidden', 401 );
	}

	public function test_bad_arguments_are_refused_before_the_handler(): void {
		$this->assert_refused( self::post( [ 'uuid' => 'not-a-uuid' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( array_diff_key( self::body(), [ 'uuid' => 0 ] ) ), 'rest_missing_callback_param', 400 );
		$this->assert_refused( self::post( [ 'source' => 'undo' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( [ 'ids' => [ 0 ] ] + self::body() ), 'rest_invalid_param', 400 );
	}
}
