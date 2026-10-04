<?php
/**
 * Stable identities survive renewal but signed epochs remain authentic.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Selection;

use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Data\FoundationStorage;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class SnapshotTokenTest extends TestCase {
	public function test_invalid_handle_has_uniform_code_status_and_plain_message(): void {
		bulkcat_test_reset();
		$invalid = SnapshotToken::invalid();
		$this->assertSame( 'bulkcat_invalid_selection', $invalid->get_error_code() );
		$this->assertSame( 403, $invalid->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $invalid->get_error_message() );
	}

	public function test_tokens_are_bound_to_owner_installation_and_epoch(): void {
		bulkcat_test_reset();
		$this->assertTrue( class_exists( SnapshotToken::class ) );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'a', 64 ) );
		$row   = [
			'id'                  => str_repeat( 'a', 32 ),
			'user_id'             => 7,
			'authorization_epoch' => 1,
		];
		$token = SnapshotToken::issue( $row );
		$this->assertSame(
			[
				'id'    => $row['id'],
				'epoch' => 1,
			],
			SnapshotToken::identify( $token, 7 )
		);
		$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( $token, 8 ) );
		$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( str_replace( '.1.', '.2.', $token ), 7 ) );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'b', 64 ) );
		$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( $token, 7 ) );
	}
	public function test_typed_roundtrip_version_confusion_and_literal_v2_bytes(): void {
		bulkcat_test_reset();
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'a', 64 ) );
		$row     = [
			'id'                       => str_repeat( 'b', 32 ),
			'user_id'                  => 7,
			'authorization_epoch'      => 12,
			'members'                  => '{"products":[41],"variations":[]}',
			'authorization_expires_at' => '2000-01-01 00:00:00',
		];
		$body    = 'v2.' . $row['id'] . '.12';
		$literal = $body . '.' . hash_hmac( 'sha256', implode( '/', [ 'bulk-catalogue-manager-for-woocommerce/snapshot', get_current_blog_id(), str_repeat( 'a', 64 ), 7, $body ] ), wp_salt( 'auth' ) );
		$this->assertSame( $literal, SnapshotToken::issue( $row ) );
		$this->assertSame(
			[
				'id'    => $row['id'],
				'epoch' => 12,
			],
			SnapshotToken::identify( $literal, 7 ),
			'Identity verification does not renew or require a live lease.'
		);
		$row['members'] = '{ "version" : 3, "targets" : [{"kind":"term","taxonomy":"product_cat","id":41}] }';
		$typed          = SnapshotToken::issue( $row );
		$this->assertStringStartsWith( 'v3.', $typed );
		$this->assertSame(
			[
				'id'    => $row['id'],
				'epoch' => 12,
			],
			SnapshotToken::identify( $typed, 7 )
		);
		foreach ( [ 'v2.' . substr( $typed, 3 ), 'v3.' . substr( $literal, 3 ), str_replace( '.12.', '.13.', $typed ), $typed . 'x', 'v4.' . substr( $typed, 3 ) ] as $invalid ) {
			$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( $invalid, 7 ) );
		}
		$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( $typed, 8 ) );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'c', 64 ) );
		$this->assertInstanceOf( \WP_Error::class, SnapshotToken::identify( $typed, 7 ) );
	}

	public function test_v3_uses_existing_receipts_compose_lease_and_retention_without_membership_rewrite(): void {
		$s = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		foreach ( [ 41, 42 ] as $id ) {
			$s->product(
				$id,
				[
					'a'    => [],
					'b'    => [],
					'c'    => [],
					'mode' => 'plain',
				]
			); }
		$target = static fn( int $id ): array => [
			'kind'     => 'product',
			'taxonomy' => null,
			'id'       => $id,
		];
		$grant  = $s->service->issue(
			'selection.capture_typed',
			[
				'serializer'          => 2,
				'roots'               => [ $target( 41 ) ],
				'include_descendants' => false,
			]
		);
		$this->assertTrue( is_array( $grant ) );
		$request  = array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) );
		$captured = $s->service->capture( $request );
		$this->assertTrue( is_array( $captured ) );
		$token = $captured['typed_token'];
		$this->assertStringStartsWith( 'v3.', $token );
		$this->assertSame( 1, $captured['count'] );
		$this->assertSame( [ $target( 41 ) ], ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_members( $token, 3 ) );
		$this->assertInstanceOf( \WP_Error::class, ( new \BulkCatalogueManager\Selection\SnapshotService() )->selection( $token, 3 ) );
		$compose = $s->service->issue(
			'selection.compose_typed',
			[
				'serializer'  => 2,
				'typed_token' => $token,
				'add_targets' => [ $target( 42 ), $target( 41 ) ],
			]
		);
		$this->assertTrue( is_array( $compose ) );
		$composed = $s->service->compose( array_intersect_key( $compose, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) );
		$this->assertTrue( is_array( $composed ) );
		$this->assertSame( 2, $composed['count'] );
		$page = $s->service->handle(
			'inspect',
			[
				'typed_token' => $composed['typed_token'],
				'page'        => 1,
				'per_page'    => 1,
			]
		);
		$this->assertSame( 2, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$s->sync_snapshots();
		$id      = SnapshotToken::identify( $token, 3 )['id'];
		$members = $s->snapshots[ $id ]['members'];
		$s->snapshots[ $id ]['authorization_expires_at'] = '2000-01-01 00:00:00';
		$this->assertInstanceOf( \WP_Error::class, ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_members( $token, 3 ) );
		$this->assertTrue( $s->service->capture( $request )['replayed'] );
		$renewed = $s->service->handle( 'renew', [ 'typed_token' => $token ] );
		$this->assertSame( 2, $renewed['authorization_epoch'] );
		$this->assertTrue( $renewed['requires_preview'] );
		$this->assertInstanceOf( \WP_Error::class, ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_members( $token, 3 ) );
		$this->assertSame( [ $target( 41 ) ], ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_members( $renewed['typed_token'], 3 ) );
		$this->assertTrue( is_array( $s->service->handle( 'release', [ 'tokens' => [ $renewed['typed_token'] ] ] ) ) );
		$s->sync_snapshots();
		$this->assertSame( $members, $s->snapshots[ $id ]['members'] );
		$this->assertTrue( null !== $s->snapshots[ $id ]['released_at'] );
	}

	public function test_fix1_pending_typed_composition_uses_renewed_base_and_operand_credentials(): void {
		foreach ( [ 'add_targets', 'add_typed_token', 'remove_typed_token' ] as $mutation ) {
			$s      = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
			$target = static fn( int $id ): array => [
				'kind'     => 'product',
				'taxonomy' => null,
				'id'       => $id,
			];
			foreach ( [ 41, 42, 43 ] as $id ) {
				$s->product(
					$id,
					[
						'a'    => [],
						'b'    => [],
						'c'    => [],
						'mode' => 'plain',
					]
				);
			}
			$capture = function ( array $ids ) use ( $s, $target ): array {
				$grant = $s->service->issue(
					'selection.capture_typed',
					[
						'serializer' => 2,
						'roots'      => array_map( $target, $ids ),
					]
				);
				$this->assertTrue( is_array( $grant ) );
				return $s->service->capture( array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) );
			};
			$base    = $capture( [ 41, 42 ] );
			$operand = $capture( [ 'remove_typed_token' === $mutation ? 42 : 43 ] );
			$grant   = $s->service->issue(
				'selection.compose_typed',
				[
					'serializer'  => 2,
					'typed_token' => $base['typed_token'],
					$mutation     => 'add_targets' === $mutation ? [ $target( 43 ) ] : $operand['typed_token'],
				]
			);
			$this->assertTrue( is_array( $grant ) );
			$request  = array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) );
			$original = $request;
			$s->sync_snapshots();
			$base_id    = SnapshotToken::identify( $base['typed_token'], 3 )['id'];
			$operand_id = SnapshotToken::identify( $operand['typed_token'], 3 )['id'];
			$s->snapshots[ $base_id ]['authorization_expires_at']    = '2000-01-01 00:00:00';
			$s->snapshots[ $operand_id ]['authorization_expires_at'] = '2000-01-01 00:00:00';
			$renewed         = $s->service->handle( 'renew', [ 'typed_token' => $base['typed_token'] ] );
			$renewed_operand = $s->service->handle( 'renew', [ 'typed_token' => $operand['typed_token'] ] );
			$this->assertSame( 2, $renewed['authorization_epoch'] );
			$this->assertSame( 2, $renewed_operand['authorization_epoch'] );
			$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $request ) );
			$request['typed_token'] = $renewed['typed_token'];
			if ( 'add_targets' !== $mutation ) {
				$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $request ) );
				$request[ $mutation ] = $renewed_operand['typed_token'];
			}
			foreach ( [ null, 'invalid', $renewed_operand['typed_token'], 'v2.' . substr( $renewed['typed_token'], 3 ) ] as $wrong ) {
				$bad                = $request;
				$bad['typed_token'] = $wrong;
				$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $bad ) );
			}
			if ( 'add_targets' !== $mutation ) {
				$bad              = $request;
				$bad[ $mutation ] = $renewed['typed_token'];
				$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $bad ) );
			}
			$bad                          = $request;
			$bad['intent']['typed_token'] = $renewed['typed_token'];
			$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $bad ) );
			$GLOBALS['bulkcat_test']['user_id']      = 4;
			$GLOBALS['bulkcat_test']['user_caps'][4] = $GLOBALS['bulkcat_test']['user_caps'][3];
			$this->assertInstanceOf( \WP_Error::class, $s->service->compose( $request ) );
			$GLOBALS['bulkcat_test']['user_id'] = 3;
			$s->sync_snapshots();
			$this->assertCount( 2, $s->snapshots );
			$answer = $s->service->compose( $request );
			$this->assertTrue( is_array( $answer ) );
			$this->assertSame( 'remove_typed_token' === $mutation ? [ $target( 41 ) ] : [ $target( 41 ), $target( 42 ), $target( 43 ) ], ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_members( $answer['typed_token'], 3 ) );
			$this->assertSame( $original['intent'], $request['intent'] );
			$this->assertSame( $original['uuid'], $request['uuid'] );
			// Receipt first: accepted results do not depend on current credentials.
			$replayed = $s->service->compose( $original + [ 'typed_token' => 'invalid' ] );
			$this->assertSame( $answer['typed_token'], $replayed['typed_token'] );
			$this->assertTrue( $replayed['replayed'] );
			$s->sync_snapshots();
			$this->assertCount( 3, $s->snapshots );
		}
	}
}
