<?php
/**
 * `POST /bcm/v1/batches` (T31; SPEC §9.2, §12.1, §13) through the REST
 * dispatcher: registered behind Access::can_write; an id list answers 201
 * with the queued batch; a selection token is materialised through its
 * filter; the same request id again answers 200 with the same batch id and
 * writes nothing; a visitor is refused 401 and a user who may not edit
 * products 403; a body without exactly one of `ids` and `token`, a bad
 * token, a bad request id, too many operations or any source but `bulk`
 * (inline and tree batches come only from their own routes) is refused
 * with nothing written.
 *
 * Stale selection (T38; §9.1, §12.6): a filter token needs the hash of a
 * current preview (missing, altered or bound to another operation list is
 * 409 `bcm_preview_stale`); when a product that matches is published between
 * the preview and apply, apply is refused 409 `bcm_selection_changed` naming
 * the previewed and the current count in plain words, with nothing written,
 * and applies with `confirm_changed`, keeping the previewed count on the
 * batch. An id list or id token needs its preview's hash too (security
 * review m6: every bulk write is previewed first), but no count.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

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
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
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
		$request = new \WP_REST_Request( 'POST', '/bcm/v1/batches' );
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
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/batches'] ?? null;
		$this->assertTrue( is_array( $route ), 'The route is registered.' );
		$this->assertSame( [ Access::class, 'can_write' ], $route[0]['permission_callback'] );
		$this->assertSame( 'POST', $route[0]['methods'] );
		$this->assertSame( [ 'uuid', 'operations', 'ids', 'token', 'source', 'preview_hash', 'preview_count', 'confirm_changed' ], array_keys( $route[0]['args'] ) );
		$this->assertTrue( $route[0]['args']['uuid']['required'] );
		$this->assertSame( BatchesController::SOURCES, $route[0]['args']['source']['enum'] );
	}

	public function test_an_id_list_queues_a_batch_and_a_repeat_answers_the_same_one(): void {
		global $wpdb;
		$response = self::post( self::body() );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			[
				'batch_id'    => 1,
				'status'      => 'queued',
				'total_items' => 2,
				'replayed'    => false,
			],
			$response->get_data()
		);
		$this->assertSame( [ 104, 201 ], $this->store->object_ids( 1 ) );
		$this->assertSame( [ 'product', 'variation' ], array_column( $this->store->items, 'object_type' ) );
		$this->assertSame( 12, $this->store->batches[1]['user_id'] );
		$this->assertSame( 'bulk', $this->store->batches[1]['source'] );

		$writes  = $wpdb->writes;
		$queries = count( $wpdb->queries );
		$again   = self::post( [ 'ids' => [ 101 ] ] + self::body() );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 1, $again->get_data()['batch_id'] );
		$this->assertTrue( $again->get_data()['replayed'] );
		$this->assertSame( $writes, $wpdb->writes, 'The double-click inserts no batch.' );
		$this->assertSame( [], preg_grep( '/^INSERT/', array_slice( $wpdb->queries, $queries ) ), 'The double-click inserts no items.' );
		$this->assertCount( 2, $this->store->items );
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
	 * A body applying a filter token with its preview's hash.
	 *
	 * @param string $token The selection token.
	 * @return array<string, mixed>
	 */
	private static function filter_body( string $token ): array {
		$body = array_diff_key( self::body(), [ 'ids' => 0 ] );
		return [
			'token'        => $token,
			'preview_hash' => self::preview_hash( $token, $body['operations'] ),
		] + $body;
	}

	/**
	 * Publish a product the "Shirt" search matches, after the preview.
	 */
	private function publish_a_shirt(): void {
		$this->db->tables['wp_posts'][] = [
			'ID'                => 109,
			'post_type'         => 'product',
			'post_title'        => 'Linen Shirt',
			'post_status'       => 'publish',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_date_gmt'     => '2026-09-23 08:00:00',
			'post_modified_gmt' => '2026-09-23 08:00:00',
		];
	}

	public function test_a_filter_whose_count_moved_since_the_preview_is_refused_with_both_numbers(): void {
		$token = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'search' => 'Shirt' ] ), 2 ), 12 );
		$this->publish_a_shirt();

		$response = self::post( [ 'preview_count' => 2 ] + self::filter_body( $token ) );
		$this->assert_refused( $response, BatchFactory::CHANGED, 409 );
		$this->assertSame( 2, $response->get_error_data()['previewed'] );
		$this->assertSame( 3, $response->get_error_data()['current'] );
		$this->assertStringContainsString( 'it showed 2, and 3 match now', $response->get_error_message() );
		$this->assertStringNotContainsString( 'bcm_', $response->get_error_message() );

		// Without preview_count the token's own count is the one the owner saw.
		$this->assert_refused( self::post( self::filter_body( $token ) ), BatchFactory::CHANGED, 409 );
		// A preview that already showed 3 applies as it is.
		$response = self::post( [ 'preview_count' => 3 ] + self::filter_body( $token ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 3, $this->store->batches[1]['previewed_items'] );
	}

	public function test_confirming_the_changed_count_applies_and_keeps_the_previewed_count(): void {
		$token = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'search' => 'Shirt' ] ), 2 ), 12 );
		$this->publish_a_shirt();

		$response = self::post(
			[
				'preview_count'   => 2,
				'confirm_changed' => true,
			] + self::filter_body( $token )
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['total_items'] );
		$this->assertSame( [ 101, 102, 109 ], $this->store->object_ids( 1 ) );
		$this->assertSame( 2, $this->store->batches[1]['previewed_items'], 'The previewed count is stored on the batch.' );
		$this->assertSame( 3, $this->store->batches[1]['total_items'] );
	}

	public function test_a_filter_apply_needs_the_hash_of_a_current_preview(): void {
		$token = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'search' => 'Shirt' ] ), 2 ), 12 );
		$body  = self::filter_body( $token );

		$response = self::post( array_diff_key( $body, [ 'preview_hash' => 0 ] ) );
		$this->assert_refused( $response, BatchFactory::STALE_PREVIEW, 409 );
		$this->assertStringContainsString( 'Preview the change again', $response->get_error_message() );
		$this->assert_refused( self::post( [ 'preview_hash' => str_repeat( '0', 64 ) ] + $body ), BatchFactory::STALE_PREVIEW, 409 );

		// A preview of another change, or of another selection, does not match.
		$other                     = $body['operations'];
		$other[0]['args']['value'] = 'private';
		$this->assert_refused( self::post( [ 'preview_hash' => self::preview_hash( $token, $other ) ] + $body ), BatchFactory::STALE_PREVIEW, 409 );
		$again = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'search' => 'Shirt' ] ), 2 ), 12, time() + 1 );
		$this->assertNotSame( $token, $again );
		$this->assert_refused( self::post( [ 'preview_hash' => self::preview_hash( $again, $body['operations'] ) ] + $body ), BatchFactory::STALE_PREVIEW, 409 );
		$this->assertSame( 201, self::post( $body )->get_status(), 'The current preview\'s hash applies.' );
	}

	public function test_an_id_token_needs_the_hash_of_its_preview_but_no_count(): void {
		$token = SelectionToken::mint( Selection::of_ids( [ 104, 201 ] ), 12 );
		$body  = [ 'token' => $token ] + array_diff_key( self::body(), [ 'ids' => 0 ] );
		$this->assert_refused( self::post( $body ), BatchFactory::STALE_PREVIEW, 409 );

		$response = self::post( [ 'preview_hash' => self::preview_hash( $token, $body['operations'] ) ] + $body );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNull( $this->store->batches[1]['previewed_items'] ?? null );
	}

	public function test_an_id_list_needs_the_hash_of_its_preview(): void {
		// Every bulk write is previewed first: ticked rows as much as a filter.
		$body     = self::body();
		$response = self::post( array_diff_key( $body, [ 'preview_hash' => 0 ] ) );
		$this->assert_refused( $response, BatchFactory::STALE_PREVIEW, 409 );
		$this->assertStringContainsString( 'Preview the change again', $response->get_error_message() );
		$this->assert_refused( self::post( [ 'preview_hash' => self::preview_hash( [ 104 ], $body['operations'] ) ] + $body ), BatchFactory::STALE_PREVIEW, 409 );
		$this->assert_refused( self::post( [ 'preview_hash' => self::preview_hash( [ 201, 104 ], $body['operations'] ) ] + $body ), BatchFactory::STALE_PREVIEW, 409 );

		$response = self::post( $body );
		$this->assertSame( 201, $response->get_status(), 'The preview of these ids and this change applies.' );
		$this->assertNull( $this->store->batches[1]['previewed_items'], 'Only a filter records the count it showed.' );
	}

	public function test_a_selection_token_is_materialised_through_its_filter(): void {
		$token    = SelectionToken::mint( Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_VARIATIONS ] ), 2 ), 12 );
		$response = self::post( self::filter_body( $token ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['total_items'] );
		$this->assertSame( SeededCatalogue::VARIATIONS, $this->store->object_ids( 1 ) );
		$this->assertSame( 'bulk', $this->store->batches[1]['source'] );
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
		$GLOBALS['bcm_test']['caps']['edit_products'] = false;
		$this->assert_refused( self::post( self::body() ), 'bcm_forbidden', 403 );

		$GLOBALS['bcm_test']['logged_in'] = false;
		$GLOBALS['bcm_test']['caps']      = [];
		$this->assert_refused( self::post( self::body() ), 'bcm_forbidden', 401 );
	}

	public function test_a_body_must_name_exactly_one_selection(): void {
		$body = self::body();
		$this->assert_refused( self::post( array_diff_key( $body, [ 'ids' => 0 ] ) ), BatchesController::SELECTION_REQUIRED, 400 );
		$this->assert_refused( self::post( [ 'token' => 'abc.def' ] + $body ), BatchesController::SELECTION_REQUIRED, 400 );
	}

	public function test_a_token_that_cannot_be_used_is_refused(): void {
		$body  = array_diff_key( self::body(), [ 'ids' => 0 ] );
		$other = SelectionToken::mint( Selection::of_ids( [ 101 ] ), 13 );
		$this->assert_refused( self::post( [ 'token' => $other ] + $body ), SelectionToken::INVALID, 403 );
		$this->assert_refused( self::post( [ 'token' => 'not-a-token' ] + $body ), SelectionToken::INVALID, 403 );
	}

	public function test_bad_arguments_are_refused_before_the_handler(): void {
		$this->assert_refused( self::post( [ 'uuid' => 'not-a-uuid' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( array_diff_key( self::body(), [ 'uuid' => 0 ] ) ), 'rest_missing_callback_param', 400 );
		$this->assert_refused( self::post( [ 'source' => 'undo' ] + self::body() ), 'rest_invalid_param', 400 );
		$this->assert_refused( self::post( [ 'ids' => [ 0 ] ] + self::body() ), 'rest_invalid_param', 400 );
	}

	public function test_too_many_operations_are_refused_with_nothing_written(): void {
		$body               = self::body();
		$body['operations'] = array_fill( 0, 21, $body['operations'][0] );
		$this->assert_refused( self::post( $body ), 'bcm_too_many_operations', 400 );
	}
}
