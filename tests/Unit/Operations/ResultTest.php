<?php
/**
 * The operation Result (T24; SPEC §6, §9.1, §9.3): four states, a reason on
 * exactly the skipped and failed ones, a change that really changes the
 * value, and no way to alter a Result once built.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	public function test_the_state_enum_is_changed_already_skipped_failed(): void {
		$this->assertSame( [ 'changed', 'already', 'skipped', 'failed' ], Result::STATES );
		$this->assertSame( Result::CHANGED, Result::changed( 'regular_price', '10.00', '11.20' )->state() );
		$this->assertSame( Result::ALREADY, Result::already( 'regular_price', '10.00' )->state() );
		$this->assertSame( Result::SKIPPED, Result::skipped( 'regular_price', '', 'This product has no regular price to increase, so it was skipped.' )->state() );
		$this->assertSame( Result::FAILED, Result::failed( 'sku', 'A-1', 'A product with the SKU B-1 already exists.', 'bcm_invalid_value' )->state() );
	}

	public function test_each_state_carries_field_old_new_and_reason(): void {
		$changed = Result::changed( 'regular_price', '10.00', '11.20' );
		$this->assertSame( [ 'regular_price', '10.00', '11.20', '' ], [ $changed->field(), $changed->old(), $changed->new(), $changed->reason() ] );
		$this->assertTrue( $changed->is_changed() );
		$this->assertFalse( $changed->replaces() );

		$already = Result::already( 'stock_quantity', 12 );
		$this->assertSame( [ 12, 12 ], [ $already->old(), $already->new() ] );
		$this->assertFalse( $already->is_changed() );

		$skipped = Result::skipped( 'sale_price', '', 'This product has no sale price to round, so it was skipped.' );
		$this->assertSame( '', $skipped->new(), 'A skipped result keeps the value it found.' );
		$this->assertSame( 'This product has no sale price to round, so it was skipped.', $skipped->reason() );

		$failed = Result::failed( 'sku', 'A-1', 'A product with the SKU B-1 already exists, so this SKU was not applied.', 'bcm_invalid_value' );
		$this->assertSame( 'A-1', $failed->new() );
		$this->assertSame( 'bcm_invalid_value', $failed->code() );

		$replace = Result::changed( 'category_ids', [ 3, 4 ], [ 9 ], true );
		$this->assertTrue( $replace->replaces() );
		$this->assertSame(
			[
				'field'    => 'category_ids',
				'old'      => [ 3, 4 ],
				'new'      => [ 9 ],
				'state'    => 'changed',
				'reason'   => '',
				'code'     => '',
				'replaces' => true,
			],
			$replace->to_array()
		);
	}

	public function test_empty_and_zero_are_different_values(): void {
		$result = Result::changed( 'sale_price', '0.00', '' );
		$this->assertSame( '0.00', $result->old() );
		$this->assertSame( '', $result->new() );
	}

	public function test_inconsistent_results_are_refused(): void {
		$cases = [
			'changed without a change'       => static fn() => Result::changed( 'regular_price', '10.00', '10.00' ),
			'skipped without a reason'       => static fn() => Result::skipped( 'regular_price', '', ' ' ),
			'failed without a reason'        => static fn() => Result::failed( 'regular_price', '', '' ),
			'a code without the bcm_ prefix' => static fn() => Result::failed( 'sku', 'A', 'Clash.', 'invalid' ),
			'a bad field key'                => static fn() => Result::already( '_price', '1' ),
			'a code in the sentence'         => static fn() => Result::failed( 'sku', 'A', 'Refused (bcm_invalid_value).', 'bcm_invalid_value' ),
		];
		foreach ( $cases as $case => $build ) {
			try {
				$build();
				$this->fail( "Accepted $case." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'Result is invalid', $e->getMessage(), $case );
			}
		}
	}

	public function test_a_result_cannot_be_changed_once_built(): void {
		$class = new \ReflectionClass( Result::class );
		$this->assertTrue( $class->isFinal() );
		$this->assertFalse( $class->getConstructor()->isPublic(), 'Results are built only through the named constructors.' );
		$this->assertNotEmpty( $class->getProperties() );
		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() . ' is readonly.' );
		}
		foreach ( $class->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$this->assertFalse( str_starts_with( $method->getName(), 'set' ) || str_starts_with( $method->getName(), 'with' ), $method->getName() . ' would alter a Result.' );
		}

		$result = Result::changed( 'regular_price', '10.00', '11.20' );
		$writer = \Closure::bind(
			static function ( Result $result ): void {
				$result->new_value = '0.00';
			},
			null,
			Result::class
		);
		try {
			$writer( $result );
			$this->fail( 'A readonly property was overwritten.' );
		} catch ( \Error $e ) {
			$this->assertStringContainsString( 'readonly', $e->getMessage() );
		}
		$this->assertSame( '11.20', $result->new() );
	}

	public function test_a_validation_is_either_arguments_or_a_refusal(): void {
		$passed = Validation::passed( [ 'field' => 'sku' ] );
		$this->assertTrue( $passed->ok() );
		$this->assertNull( $passed->error() );
		$this->assertSame( [ 'field' => 'sku' ], $passed->args() );

		$refused = Validation::refused( new \WP_Error( 'bcm_invalid_operation', 'Enter the text to add.', [ 'status' => 400 ] ) );
		$this->assertFalse( $refused->ok() );
		$this->assertSame( 'bcm_invalid_operation', $refused->error()->get_error_code() );
		try {
			$refused->args();
			$this->fail( 'Refused arguments were handed out.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'Refused', $e->getMessage() );
		}
	}
}
