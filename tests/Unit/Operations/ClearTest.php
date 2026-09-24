<?php
/**
 * Clear (T25; SPEC §5.4, §6): every field the action accepts is nullable;
 * clearing writes an empty string, distinct from zero — a sale price of
 * 0.00 is cleared to '', an empty one is already cleared — and a field the
 * object refuses is failed with its reason.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class ClearTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function operation(): Operation {
		return ( new Registry( new Fields() ) )->get( 'clear' );
	}

	private function args( Operation $operation, string $field ): array {
		$validation = $operation->validate( [ 'field' => $field ] );
		$this->assertTrue( $validation->ok(), "clear refused $field." );
		return $validation->args();
	}

	public function test_it_accepts_only_nullable_fields(): void {
		$fields = new Fields();
		$keys   = self::operation()->describe()->fields();
		$this->assertSame( [ 'sale_price', 'weight', 'length', 'width', 'height' ], $keys );
		foreach ( $keys as $key ) {
			$this->assertTrue( $fields->get( $key )->nullable(), $key );
		}
	}

	public function test_clearing_writes_empty_distinct_from_zero(): void {
		$operation = self::operation();
		// [ field, value held, expected state ].
		$cases = [
			[ 'sale_price', '0.00', Result::CHANGED ],
			[ 'sale_price', '0', Result::CHANGED ],
			[ 'sale_price', '8.50', Result::CHANGED ],
			[ 'sale_price', '', Result::ALREADY ],
			[ 'sale_price', null, Result::ALREADY ],
			[ 'weight', '0', Result::CHANGED ],
			[ 'weight', '', Result::ALREADY ],
			[ 'length', '12', Result::CHANGED ],
			[ 'width', '0.0', Result::CHANGED ],
			[ 'height', '', Result::ALREADY ],
		];
		foreach ( $cases as [ $key, $held, $state ] ) {
			$product = new FakeProduct( 'simple', [ $key => $held ] );
			$result  = $operation->compute( $product, $this->args( $operation, $key ) );
			$label   = "$key held " . wp_json_encode( $held );
			$this->assertSame( $state, $result->state(), $label );
			$this->assertSame( (string) $held, $result->old(), $label );
			$this->assertSame( Result::CHANGED === $state ? '' : (string) $held, $result->new(), $label );
			$this->assertSame( [], $product->sets, "$label: compute() wrote." );

			$operation->apply( $result, $product );
			$this->assertSame( Result::CHANGED === $state ? [ [ 'set_' . $key, '' ] ] : [], $product->sets, $label );
		}
	}

	public function test_a_field_the_object_refuses_is_failed_with_its_reason(): void {
		$operation = self::operation();
		$product   = new FakeProduct( 'variable', [ 'sale_price' => '5.00' ] );
		$result    = $operation->compute( $product, $this->args( $operation, 'sale_price' ) );
		$this->assertSame( Result::FAILED, $result->state() );
		$this->assertSame( 'A variable product takes its price from its variations. Change the variations instead.', $result->reason() );
		$this->assertSame( 'bulkcat_not_available', $result->code() );
		$operation->apply( $result, $product );
		$this->assertSame( [], $product->sets );

		$variation = new FakeProduct( 'variation', [ 'sale_price' => '5.00' ] );
		$this->assertSame( Result::CHANGED, $operation->compute( $variation, $this->args( $operation, 'sale_price' ) )->state(), 'A variation holds its own sale price.' );
	}
}
