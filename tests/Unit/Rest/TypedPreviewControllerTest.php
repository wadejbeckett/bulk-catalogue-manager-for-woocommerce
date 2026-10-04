<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Rest\PreviewController;
use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class TypedPreviewControllerTest extends TestCase {
	public function test_schema_preserves_optional_typed_selection_keys(): void {
		$args = PreviewController::args();
		foreach ( [ 'serializer', 'family', 'external', 'entry', 'targets', 'typed_token' ] as $key ) {
			$this->assertArrayHasKey( $key, $args );
			$this->assertArrayNotHasKey( 'default', $args[ $key ] );
		}
		foreach ( [ 'targets', 'typed_token', 'token', 'ids' ] as $key ) {
			$this->assertArrayNotHasKey( 'default', $args[ $key ] );
		}
	}

	public function test_controller_retains_typed_row_identity(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		// The standalone WordPress shim supplies the actor through its existing state.
		$GLOBALS['bulkcat_test']['user_id'] = 3;
		$body                               = [
			'serializer'  => 2,
			'family'      => 'product',
			'external'    => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'       => 'product.row',
			'targets'     => [
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			'operations'  => [ $s->step( 'a', 'add', [ 'v1' ] ) ],
			'sample_size' => 25,
		];
		$request                            = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$reply = ( new PreviewController() )->create_item( $request );
		$this->assertTrue( $reply instanceof \WP_REST_Response );
		$this->assertSame( 200, $reply->get_status() );
		$data = $reply->get_data();
		$this->assertSame( 2, $data['serializer'] );
		$this->assertSame( 'product', $data['family'] );
		$this->assertSame( $body['external'], $data['external'] );
		$this->assertSame( 'product.row', $data['entry'] );
		$this->assertTrue( is_object( $data['totals']['would_fail'] ) );
	}

	public function test_typed_keys_never_downgrade_to_legacy_preview(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
		$request->set_param( 'family', 'product' );
		$request->set_param(
			'targets',
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			]
		);
		$request->set_param( 'operations', [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		$reply = ( new PreviewController() )->create_item( $request );
		$this->assertTrue( $reply instanceof \WP_Error );
		$this->assertSame( 'bulkcat_invalid_value', $reply->get_error_code() );
	}
	public function test_controller_rejects_ambiguous_and_unsupported_typed_input(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$body = [
			'serializer' => 2,
			'external'   => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'      => 'product.bulk',
			'targets'    => [
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			'operations' => [ $s->step( 'a', 'add', [ 'v1' ] ) ],
		];
		foreach ( [
			[ 'token' => '' ],
			[ 'targets' => null ],
			[ 'targets' => [] ],
			[
				'typed_token' => 'v3.invalid',
				'ids'         => [ 41 ],
			],
			[ 'family' => 'category' ],
			[ 'serializer' => 3 ],
			[ 'validation' => [ 'trusted' => true ] ],
			[
				'external' => [
					'contract' => 2,
					'provider' => 'sample',
					'identity' => 'org.example.foreign',
				],
			],
		] as $patch ) {
			$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
			foreach ( array_replace( $body, $patch ) as $key => $value ) {
				$request->set_param( $key, $value ); }
			$this->assertInstanceOf( \WP_Error::class, ( new PreviewController() )->create_item( $request ) );
		}
	}

	public function test_controller_preserves_existing_product_snapshot_and_hides_validation(): void {
		$s = new Scenario();
		$s->product(
			101,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$grant = $s->service->issue( 'selection.capture', [ 'ids' => [ 101 ] ] );
		$this->assertTrue( is_array( $grant ) );
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/selection' );
		foreach ( array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) as $key => $value ) {
			$request->set_param( $key, $value ); }
		$captured = \BulkCatalogueManager\Rest\RequestsController::capture( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $captured );
		$token = $captured->get_data()['token'];
		$this->assertStringStartsWith( 'v2.', $token );
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
		$body    = [
			'serializer' => 2,
			'external'   => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'      => 'product.row',
			'token'      => $token,
			'operations' => [ $s->step( 'a', 'add', [ 'v1' ] ) ],
		];
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value ); }
		$reply = ( new PreviewController() )->create_item( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $reply );
		$data = $reply->get_data();
		$this->assertSame( $body['external'], $data['external'] );
		$this->assertSame( 'product.row', $data['entry'] );
		$this->assertSame( $token, $data['selection']['token'] );
		$this->assertSame(
			( new \BulkCatalogueManager\External\V2\TypedScope(
				[
					[
						'kind'     => 'product',
						'taxonomy' => null,
						'id'       => 101,
					],
				]
			) )->signature(),
			$data['scope_hash']
		);
		$this->assertStringNotContainsString( 'validation', wp_json_encode( $data ) );
		$this->assertTrue( is_object( $data['totals']['would_fail'] ) );
		$s->sync_snapshots();
		$this->assertSame( '{"products":[101],"variations":[]}', array_values( $s->snapshots )[0]['members'] );
	}
	public function test_category_snapshot_dispatch_retains_unavailable_slot_without_product_gate(): void {
		$s = new Scenario();
		$s->term(
			41,
			0,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		unset( $GLOBALS['bulkcat_test']['caps']['edit_products'], $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] );
		// Stored membership infrastructure only; category root capture belongs to C1.
		$row     = ( new \BulkCatalogueManager\Selection\SnapshotRepository() )->create(
			[
				'version' => 3,
				'targets' => [
					[
						'kind'     => 'term',
						'taxonomy' => 'product_cat',
						'id'       => 41,
					],
				],
			],
			3,
			time()
		);
		$body    = [
			'serializer'  => 2,
			'family'      => 'category',
			'external'    => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'       => 'category.bulk',
			'typed_token' => \BulkCatalogueManager\Selection\SnapshotToken::issue( $row ),
			'operations'  => [
				[
					'action' => 'x_sample_assign_category',
					'args'   => [
						'field'     => 'x_sample_category_composite',
						'part'      => 'a',
						'operation' => 'add',
						'values'    => [ 'v1' ],
					],
				],
			],
		];
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/preview' );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value ); }
		$normalized = \BulkCatalogueManager\Rest\TypedPreviewInput::normalize( $body, 3 );
		$this->assertTrue( is_array( $normalized ) );
		$this->assertSame( 'category', $normalized['family'] );
		$this->assertTrue( \BulkCatalogueManager\Rest\Access::can_family_request( $request ) );
		$subject = \BulkCatalogueManager\External\V2\SubjectLoader::load( new \BulkCatalogueManager\Extension\V2\TargetRef( 'term', 'product_cat', 41 ), 3 );
		$this->assertInstanceOf( \WP_Error::class, $subject );
		$expected = \BulkCatalogueManager\External\Refusals::incompatible( \BulkCatalogueManager\External\V2\Inspector::unavailable() );
		$this->assertSame( $expected->get_error_code(), $subject->get_error_code() );
		// A captured unavailable identity keeps its slot; it does not become a product refusal.
		$reply = ( new PreviewController() )->create_item( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $reply );
		$data = $reply->get_data();
		$this->assertSame( 'category', $data['family'] );
		$this->assertSame( 'category.bulk', $data['entry'] );
		$this->assertSame( 1, $data['unavailable_count'] );
		$this->assertSame( 1, $data['totals']['matched'] );
		$this->assertSame( 0, $data['totals']['computed'] );
		$this->assertSame( 0, $data['totals']['would_change'] );
		$this->assertSame( [], $data['sample'] );
		$this->assertSame( [], $s->store->reads );
		$this->assertCount( 0, $s->database->batches );
	}
}
