<?php
/**
 * Journal values keep their type: empty is not zero, '0' is not 0, and false
 * is not null (SPEC §5.4, §9.2).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\ValueCodec;
use PHPUnit\Framework\TestCase;

final class ValueCodecTest extends TestCase {
	/**
	 * Values that must each survive as themselves, with their stored form.
	 */
	private const CASES = [
		'null'         => [ null, 'null' ],
		'empty string' => [ '', '""' ],
		'int zero'     => [ 0, '0' ],
		'string zero'  => [ '0', '"0"' ],
		'price zero'   => [ '0.00', '"0.00"' ],
		'false'        => [ false, 'false' ],
		'true'         => [ true, 'true' ],
		'float zero'   => [ 0.0, '0.0' ],
		'whole float'  => [ 12.0, '12.0' ],
		'float'        => [ 0.1, '0.1' ],
		'negative int' => [ -3, '-3' ],
		'price'        => [ '19.99', '"19.99"' ],
		'unicode'      => [ 'Crème brûlée / 50%', '"Crème brûlée / 50%"' ],
		'quotes'       => [ "It's \"new\"\n", '"It\'s \"new\"\n"' ],
		'empty list'   => [ [], '[]' ],
		'term ids'     => [ [ 12, 7, 3 ], '[12,7,3]' ],
		'mixed list'   => [ [ null, '', 0, '0', false ], '[null,"",0,"0",false]' ],
		'map'          => [
			[
				'width'  => '10',
				'height' => '',
			],
			'{"width":"10","height":""}',
		],
	];

	public function test_each_value_round_trips_as_itself(): void {
		foreach ( self::CASES as $label => [ $value, $stored ] ) {
			$json = ValueCodec::encode( $value );
			$this->assertSame( $stored, $json, "Stored form of $label." );
			$this->assertSame( $value, ValueCodec::decode( $json ), "Round trip of $label." );
		}
	}

	public function test_the_six_section_5_4_values_are_stored_distinctly(): void {
		$stored = array_map( [ ValueCodec::class, 'encode' ], [ null, '', 0, '0', '0.00', false ] );
		$this->assertSame( [ 'null', '""', '0', '"0"', '"0.00"', 'false' ], $stored );
		$this->assertCount( 6, array_unique( $stored ) );
	}

	public function test_objects_resources_and_non_finite_numbers_are_refused(): void {
		$handle = fopen( 'php://memory', 'r' );
		foreach ( [ new \stdClass(), [ 1, new \ArrayObject() ], $handle, NAN, INF, [ 'x' => [ INF ] ] ] as $i => $value ) {
			try {
				ValueCodec::encode( $value );
				$this->fail( "Value $i was accepted." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		fclose( $handle );
	}

	public function test_invalid_stored_text_is_an_error_not_a_null(): void {
		foreach ( [ '', 'nul', '{"a":', "'x'" ] as $text ) {
			try {
				ValueCodec::decode( $text );
				$this->fail( "\"$text\" decoded." );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertStringContainsString( 'not valid JSON', $e->getMessage() );
			}
		}
	}
}
