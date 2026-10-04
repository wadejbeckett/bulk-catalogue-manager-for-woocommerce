<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class TypedReceiptTest extends TestCase {
	public function test_accepted_request_replays_without_its_provider(): void {
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
		$preview = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$this->assertTrue( is_array( $preview ) );
		$accepted = $s->accept( $preview );
		$this->assertTrue( is_array( $accepted ) );
		$this->assertFalse( $accepted['replayed'] );
		$s->remove_provider();
		$again = $s->accept( $preview );
		$this->assertTrue( is_array( $again ) );
		$this->assertSame( $accepted['batch_id'], $again['batch_id'] );
		$this->assertTrue( $again['replayed'] );
	}
	private function preview( Scenario $s ): array {
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		return $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
	}

	public function test_same_uuid_changed_arguments_foreign_owner_and_removed_actor_are_refused(): void {
		$s        = new Scenario();
		$preview  = $this->preview( $s );
		$accepted = $s->accept( $preview );
		$this->assertTrue( is_array( $accepted ) );
		$s->database->sync();
		$headers = count( $s->database->batches );
		$request = $s->request( $preview );
		$changed = $request;
		$changed['intent']['operations'][0]['args']['values'] = [ 'v2' ];
		$error = $s->service->apply( $changed );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'bulkcat_request_conflict', $error->get_error_code() );
		$s->remove_provider();
		$this->assertSame( $accepted['batch_id'], $s->service->apply( $request )['batch_id'] );
		$s->database->sync();
		$this->assertSame( $headers, count( $s->database->batches ) );
		$this->assertCount( 1, $s->database->receipts );
		$GLOBALS['bulkcat_test']['user_id']      = 4;
		$GLOBALS['bulkcat_test']['user_caps'][4] = $GLOBALS['bulkcat_test']['user_caps'][3];
		$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request + [ 'operation' => 'batch.apply' ] ) );
		$GLOBALS['bulkcat_test']['user_id']      = 3;
		$GLOBALS['bulkcat_test']['user_caps'][3] = [];
		$error                                   = $s->service->apply( $request );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_live_snapshot_is_required_only_for_new_acceptance(): void {
		$s     = new Scenario();
		$p     = $this->preview( $s );
		$row   = ( new \BulkCatalogueManager\Selection\SnapshotRepository() )->create(
			[
				'products'   => [ 41 ],
				'variations' => [],
			],
			3,
			time()
		);
		$token = \BulkCatalogueManager\Selection\SnapshotToken::issue( $row );
		$input = [
			'serializer' => 2,
			'external'   => $p['external'],
			'entry'      => 'product.bulk',
			'token'      => $token,
			'operations' => $p['operations'],
		];
		$p     = $s->service->preview( $input );
		$this->assertTrue( is_array( $p ) );
		$accepted = $s->accept( $p );
		$this->assertTrue( is_array( $accepted ) );
		$s->sync_snapshots();
		$s->snapshots[ $row['id'] ]['authorization_expires_at'] = '2000-01-01 00:00:00';
		$s->remove_provider();
		$this->assertSame( $accepted['batch_id'], $s->accept( $p )['batch_id'] );
		$this->assertInstanceOf( \WP_Error::class, $s->service->preview( $input ) );
	}

	public function test_retained_category_receipt_needs_family_rights_but_no_provider_or_live_token(): void {
		$s       = new Scenario();
		$targets = [
			[
				'kind'     => 'term',
				'taxonomy' => 'product_cat',
				'id'       => 41,
			],
		];
		$intent  = [
			'version'    => 1,
			'serializer' => 2,
			'family'     => 'category',
			'source'     => 'bulk',
			'entry'      => 'category.bulk',
			'external'   => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'selection'  => [ 'targets' => $targets ],
			'operations' => [
				[
					'action' => 'x_sample_assign_category',
					'args'   => [ 'field' => 'x_sample_category_composite' ],
				],
			],
			'scope_hash' => ( new \BulkCatalogueManager\External\V2\TypedScope( $targets ) )->signature(),
		];
		$signer  = new \BulkCatalogueManager\Requests\RequestGrant( wp_salt( 'auth' ), (string) get_current_blog_id(), \BulkCatalogueManager\Data\FoundationStorage::epoch() );
		// A retained accepted batch fixture: no claim of C1 category execution.
		$grant  = $signer->issue( 'batch.apply', \BulkCatalogueManager\Requests\CaptureIntent::fingerprint( $intent ), 3, time() - 172800 );
		$claims = $signer->verify( $grant['uuid'], $grant['request_proof'], 'batch.apply', \BulkCatalogueManager\Requests\CaptureIntent::fingerprint( $intent ), 3 );
		$id     = \BulkCatalogueManager\Data\FoundationStorage::atomic(
			function () use ( $intent, $claims, $targets ): int {
				$r = new \BulkCatalogueManager\Requests\RequestRepository();
				$r->claim( $claims );
				$b  = new \BulkCatalogueManager\Data\BatchRepository();
				$id = $b->create(
					$claims['uuid'],
					3,
					'bulk',
					$intent['operations'],
					[ 'family' => 'category' ],
					1,
					null,
					null,
					[
						'serializer' => 2,
						'family'     => 'category',
					]
				);
				$b->materialise_typed( $id, $targets );
				$r->accepted_batch( $claims, $id );
				return $id;
			}
		);
		$s->remove_provider();
		unset( $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'], $GLOBALS['bulkcat_test']['caps']['edit_products'] );
		$request = array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof' ] ) ) + [
			'operation' => 'batch.apply',
			'intent'    => $intent,
		];
		$answer  = $s->service->resolve( $request );
		$this->assertTrue( is_array( $answer ) );
		$this->assertSame( $id, $answer['batch_id'] );
		$this->assertSame( [ 'batch_id', 'status', 'total_items', 'replayed' ], array_keys( $answer ) );
		$changed                     = $request;
		$changed['intent']['family'] = 'product';
		$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $changed ) );
		unset( $GLOBALS['bulkcat_test']['user_caps'][3]['manage_product_terms'] );
		$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request ) );
	}

	public function test_fix1_pruned_apply_and_resolve_retain_authenticated_result(): void {
		$s        = new Scenario();
		$p        = $this->preview( $s );
		$accepted = $s->accept( $p );
		$this->assertTrue( is_array( $accepted ) );
		$request = $s->request( $p ) + [ 'operation' => 'batch.apply' ];
		$id      = $accepted['batch_id'];
		$s->database->sync();
		$s->database->batches[ $id ]['status']       = 'cancelled';
		$s->database->batches[ $id ]['finished_gmt'] = '2026-01-01 00:00:00';
		$before                                      = $s->service->resolve( $request );
		$this->assertTrue( is_array( $before ) );
		$s->database->items = [];
		$s->remove_provider();
		// Missing items without a pruning marker remain corruption, not a retained result.
		$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request ) );
		$s->database->batches[ $id ]['pruned_gmt'] = '2026-02-01 00:00:00';
		foreach ( [ 'apply', 'resolve' ] as $method ) {
			$this->assertSame( $before, $s->service->$method( $request ) );
			$changed = $request;
			$changed['intent']['operations'][0]['args']['values'] = [ 'v2' ];
			$this->assertInstanceOf( \WP_Error::class, $s->service->$method( $changed ) );
			$changed                     = $request;
			$changed['intent']['family'] = 'category';
			$this->assertInstanceOf( \WP_Error::class, $s->service->$method( $changed ) );
			$changed                   = $request;
			$changed['request_proof'] .= 'x';
			$this->assertInstanceOf( \WP_Error::class, $s->service->$method( $changed ) );
			$GLOBALS['bulkcat_test']['user_id']      = 4;
			$GLOBALS['bulkcat_test']['user_caps'][4] = $GLOBALS['bulkcat_test']['user_caps'][3];
			$this->assertInstanceOf( \WP_Error::class, $s->service->$method( $request ) );
			$GLOBALS['bulkcat_test']['user_id'] = 3;
			unset( $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] );
			$this->assertInstanceOf( \WP_Error::class, $s->service->$method( $request ) );
			$GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] = true;
		}
		$valid = $s->database->batches[ $id ];
		foreach ( [ 'queued', 'running', 'undoing' ] as $status ) {
			$s->database->batches[ $id ] = array_replace( $valid, [ 'status' => $status ] );
			$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request ) );
		}
		foreach ( [
			'family'     => 'category',
			'scope_hash' => str_repeat( 'f', 64 ),
		] as $key => $value ) {
			$s->database->batches[ $id ]              = $valid;
			$selection                                = json_decode( $valid['selection'], true );
			$selection[ $key ]                        = $value;
			$s->database->batches[ $id ]['selection'] = wp_json_encode( $selection );
			$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request ) );
		}
		foreach ( [
			'family'   => 'category',
			'identity' => 'org.example.foreign',
			'actor'    => 4,
		] as $key => $value ) {
			$s->database->batches[ $id ]               = $valid;
			$manifest                                  = json_decode( $valid['extensions'], true );
			$manifest[ $key ]                          = $value;
			$s->database->batches[ $id ]['extensions'] = wp_json_encode( $manifest );
			$this->assertInstanceOf( \WP_Error::class, $s->service->resolve( $request ) );
		}
		$s->database->batches[ $id ] = $valid;
		$this->assertSame( $before, $s->service->resolve( $request ) );
		$this->assertCount( 1, $s->database->batches );
		$this->assertCount( 1, $s->database->receipts );
		$this->assertSame( [], $s->database->items );
		// The general/new-work family adapter still requires actual tuple rows.
		$this->assertInstanceOf( \WP_Error::class, \BulkCatalogueManager\Requests\RequestFamily::from_batch( $id, 3 ) );
	}
}
