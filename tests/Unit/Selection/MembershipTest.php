<?php
/**
 * Exact typed-set operations preserve off-page/unavailable identities.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Selection;

use BulkCatalogueManager\Selection\Membership;
use PHPUnit\Framework\TestCase;
final class MembershipTest extends TestCase {
	public function test_union_deduplicates_before_cap_and_subtraction_never_queries(): void {
		$this->assertTrue( class_exists( Membership::class ) );
		$a = [
			'products'   => [ 1, 8 ],
			'variations' => [ 5 ],
		];
		$b = [
			'products'   => [ 8, 20 ],
			'variations' => [ 7 ],
		];
		$this->assertSame(
			[
				'products'   => [ 1, 8, 20 ],
				'variations' => [ 5, 7 ],
			],
			Membership::union( $a, $b )
		);
		$this->assertSame(
			[
				'products'   => [ 1 ],
				'variations' => [ 5 ],
			],
			Membership::subtract( $a, [ 8, 999 ] )
		);
		$full = [
			'products'   => range( 1, 100000 ),
			'variations' => [],
		];
		$this->assertSame( $full, Membership::union( $full, $full ) );
		$this->assertSame(
			[
				'products'   => [],
				'variations' => [],
			],
			Membership::subtract( $full, $full['products'] )
		);
	}
	public function test_conflicting_types_and_overflow_refuse(): void {
		$this->assertTrue( class_exists( Membership::class ) );
		foreach ( [
			[
				[
					'products'   => [ 1 ],
					'variations' => [],
				],
				[
					'products'   => [],
					'variations' => [ 1 ],
				],
			],
			[
				[
					'products'   => range( 1, 100000 ),
					'variations' => [],
				],
				[
					'products'   => [ 100001 ],
					'variations' => [],
				],
			],
		] as [ $a, $b ] ) {
			try {
				Membership::union( $a, $b );
				$this->fail( 'Invalid set accepted.' );
			} catch ( \InvalidArgumentException | \LengthException $error ) {
				$this->assertNotEmpty( $error->getMessage() ); }
		}
	}
}
