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
}
