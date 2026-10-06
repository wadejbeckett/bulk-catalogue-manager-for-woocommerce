<?php
/**
 * Capture input cannot silently broaden by dropping unknown keys or invalid IDs.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\CaptureIntent;
use PHPUnit\Framework\TestCase;
final class CaptureIntentTest extends TestCase {
	public function test_ids_are_validated_then_canonicalized_without_losing_identity(): void {
		bulkcat_test_reset();
		$this->assertTrue( class_exists( CaptureIntent::class ) );
		$this->assertSame(
			[
				'version' => 1,
				'ids'     => [ 2, 4 ],
			],
			CaptureIntent::normalize( [ 'ids' => [ 4, 2, 4 ] ] )
		);
		foreach ( [
			[ 'ids' => [ 0 ] ],
			[ 'ids' => [ true ] ],
			[ 'ids' => [ 1.5 ] ],
			[
				'ids'   => [ 1 ],
				'query' => [],
			],
			[ 'query' => [ 'nonsense' => 9 ] ],
			[ 'query' => [ 'category_ids' => 'broken' ] ],
			[
				'uuid' => 'old',
				'ids'  => [ 1 ],
			],
			[ 'ids' => range( 1, 1001 ) ],
		] as $input ) {
			try {
				CaptureIntent::normalize( $input );
				$this->fail( 'Unsafe input was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotSame( '', $error->getMessage() );
			}
		}
	}
	public function test_fingerprint_is_stable_for_canonical_intent_but_changes_with_targets(): void {
		$this->assertTrue( class_exists( CaptureIntent::class ) );
		$a = CaptureIntent::normalize( [ 'ids' => [ 2, 4 ] ] );
		$b = CaptureIntent::normalize( [ 'ids' => [ 4, 2 ] ] );
		$this->assertSame( CaptureIntent::fingerprint( $a ), CaptureIntent::fingerprint( $b ) );
		$this->assertNotSame( CaptureIntent::fingerprint( $a ), CaptureIntent::fingerprint( CaptureIntent::normalize( [ 'ids' => [ 2, 5 ] ] ) ) );
	}
}
