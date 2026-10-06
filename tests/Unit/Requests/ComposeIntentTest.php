<?php
/**
 * Composition fingerprints name immutable sets, never their changing leases.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\ComposeIntent;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Data\FoundationStorage;
use PHPUnit\Framework\TestCase;
final class ComposeIntentTest extends TestCase {
	public function test_intent_survives_renewal_and_rejects_ambiguous_mutations(): void {
		bulkcat_test_reset();
		$this->assertTrue( class_exists( ComposeIntent::class ) );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'a', 64 ) );
		$row                        = [
			'id'                  => str_repeat( 'a', 32 ),
			'user_id'             => 7,
			'authorization_epoch' => 1,
		];
		$old                        = SnapshotToken::issue( $row );
		$row['authorization_epoch'] = 2;
		$new                        = SnapshotToken::issue( $row );
		$expected                   = [
			'version' => 1,
			'base'    => $row['id'],
			'add_ids' => [ 2, 7 ],
		];
		$this->assertSame(
			$expected,
			ComposeIntent::normalize(
				[
					'token'   => $old,
					'add_ids' => [ 7, 2, 7 ],
				],
				7
			)
		);
		$this->assertSame(
			$expected,
			ComposeIntent::normalize(
				[
					'token'   => $new,
					'add_ids' => [ 2, 7 ],
				],
				7
			)
		);
		$this->assertSame( $expected, ComposeIntent::normalize( $expected, 7 ) );
		foreach ( [
			[
				'token'      => $old,
				'add_ids'    => [ 1 ],
				'remove_ids' => [ 1 ],
			],
			[
				'token'   => $old,
				'add_ids' => range( 1, 1001 ),
			],
			[
				'token'   => $old,
				'add_ids' => [ '1' ],
			],
			[
				'version' => 1,
				'base'    => $row['id'],
				'query'   => [],
			],
		] as $input ) {
			try {
				ComposeIntent::normalize( $input, 7 );
				$this->fail( 'Invalid intent accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->assertInstanceOf(
			\WP_Error::class,
			ComposeIntent::normalize(
				[
					'token'   => $old,
					'add_ids' => [],
				],
				8
			)
		);
	}
}
