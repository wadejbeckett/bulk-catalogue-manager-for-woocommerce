<?php
/**
 * The single typed normalizer moved from S3 by the execution-order ruling.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\External\V2\Arguments;
use BulkCatalogueManager\Tests\Support\Extension\V2\{AssignAction, MemoryStore};
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';
final class ArgumentsTest extends TestCase {
	public function test_lookup_defaults_and_inactive_values(): void {
		$d = ( new AssignAction( new MemoryStore() ) )->describe();
		$r = Arguments::normalize(
			$d,
			[
				'field'  => $d->field,
				'values' => [ 'v1' ],
			]
		);
		$this->assertTrue( is_array( $r ) );
		$this->assertSame( 'a', $r['part'] );
		$this->assertSame( 'keep', $r['mode'] );
		$this->assertSame( [ 'v1' ], $r['values'] );
		$clear = Arguments::normalize(
			$d,
			[
				'field'     => $d->field,
				'operation' => 'clear',
			]
		);
		$this->assertSame( [], $clear['values'] );
		$this->assertTrue(
			Arguments::normalize(
				$d,
				[
					'field'     => $d->field,
					'operation' => 'clear',
					'values'    => [ 'v1' ],
				]
			) instanceof \WP_Error
		);
	}
	public function test_malformed_unknown_and_bounded_values_refuse(): void {
		$d = ( new AssignAction( new MemoryStore() ) )->describe();
		foreach ( [
			[ 'values' => [ 'v1', 'v1' ] ],
			[ 'values' => [ '' ] ],
			[ 'values' => [ str_repeat( 'x', 129 ) ] ],
			[ 'values' => [ "a\nb" ] ],
			[ 'values' => [ 1 ] ],
			[ 'values' => 'v1' ],
			[ 'values' => array_map( 'strval', range( 1, 101 ) ) ],
			[
				'values'   => [ 'v1' ],
				'snapshot' => [],
			],
			[
				'values' => [ 'v1' ],
				'part'   => 'wrong',
			],
		] as $args ) {
			$this->assertTrue( Arguments::normalize( $d, [ 'field' => $d->field ] + $args ) instanceof \WP_Error );
		}
	}
	public function test_field_and_flags_are_core_owned_and_never_coerce_wrong_field(): void {
		$d = ( new AssignAction( new MemoryStore() ) )->describe();
		$this->assertTrue(
			Arguments::normalize(
				$d,
				[
					'field'  => 'x_other_field',
					'values' => [ 'v1' ],
				]
			) instanceof \WP_Error
		);
		$r = Arguments::normalize(
			$d,
			[
				'field'               => $d->field,
				'values'              => [ 'v2','v1' ],
				'apply_to_variations' => false,
				'skip_already'        => true,
			]
		);
		$this->assertSame( [ 'v1','v2' ], $r['values'] );
		$this->assertSame( $r, Arguments::normalize( $d, $r ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			Arguments::normalize(
				$d,
				[
					'field'     => $d->field,
					'values'    => [ 'v1' ],
					'operation' => null,
				]
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			Arguments::normalize(
				$d,
				[
					'field'        => $d->field,
					'values'       => [ 'v1' ],
					'skip_already' => null,
				]
			)
		);
	}
}
