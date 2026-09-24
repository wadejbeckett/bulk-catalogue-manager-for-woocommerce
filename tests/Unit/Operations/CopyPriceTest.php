<?php
/**
 * Copy between regular and sale price (T26; SPEC §5.4, §6): regular into
 * sale with and without a percentage discount in the same step, at two
 * price precisions; sale into regular; an empty source skipped, not read as
 * zero; a sale price not below the regular price skipped, or cleared when
 * `clear_invalid_sale` is on; a copy already in place is already; a
 * variable parent is skipped; apply() writes through the setter.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class CopyPriceTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Compute copy_price on a product.
	 *
	 * @param array<string, mixed> $props Product props.
	 * @param array<string, mixed> $args  Arguments before validation.
	 * @param string               $type  Product type.
	 */
	private function compute( array $props, array $args, string $type = 'simple' ): Result {
		$operation  = ( new Registry( new Fields() ) )->get( 'copy_price' );
		$validation = $operation->validate( $args );
		$this->assertTrue( $validation->ok(), 'Arguments refused: ' . wp_json_encode( $args ) );
		$product = new FakeProduct( $type, $props );
		$result  = $operation->compute( $product, $validation->args() );
		$this->assertSame( [], $product->sets, 'compute() wrote to the product.' );
		return $result;
	}

	public function test_regular_into_sale_with_a_discount_at_two_precisions(): void {
		$cases = [
			// [ decimals, regular, sale, discount, state, new sale ].
			[ 2, '20.00', '', '20', 'changed', '16.00' ],
			[ 2, '19.99', '', '12.5', 'changed', '17.49' ],
			[ 2, '10', '9.00', '10', 'already', '9.00' ],
			[ 2, '10.00', '5.00', '10', 'changed', '9.00' ],
			// 0.05 less 10 per cent is 0.045, stored as 0.05: not below the regular price.
			[ 2, '0.05', '', '10', 'skipped', '' ],
			[ 0, '199', '', '15', 'changed', '169' ],
			// 10 less 5 per cent is 9.5, stored as 10 at 0 decimals: not below the regular price.
			[ 0, '10', '', '5', 'skipped', '' ],
			[ 0, '100', '', '99.5', 'changed', '1' ],
		];
		foreach ( $cases as $i => [ $decimals, $regular, $sale, $discount, $state, $new ] ) {
			$GLOBALS['bulkcat_test']['decimals'] = $decimals;
			$result                              = $this->compute(
				[
					'regular_price' => $regular,
					'sale_price'    => $sale,
				],
				[
					'field'    => 'sale_price',
					'discount' => $discount,
				]
			);
			$case                                = "#$i: {$decimals}dp $regular less $discount%";
			$this->assertSame( $state, $result->state(), $case . ' (' . $result->reason() . ')' );
			$this->assertSame( 'sale_price', $result->field(), $case );
			$this->assertSame( $sale, $result->old(), $case );
			$this->assertSame( $new, $result->new(), $case );
		}
	}

	public function test_a_sale_price_not_below_the_regular_is_skipped_or_cleared(): void {
		$props = [
			'regular_price' => '20.00',
			'sale_price'    => '15.00',
		];
		$plain = $this->compute( $props, [ 'field' => 'sale_price' ] );
		$this->assertSame( Result::SKIPPED, $plain->state(), 'With no discount the sale price would equal the regular price.' );
		$this->assertSame( 'bulkcat_invalid_value', $plain->code() );
		$this->assertStringContainsString( 'would not be below Regular price', $plain->reason() );
		$this->assertSame( '15.00', $plain->new(), 'The sale price is left alone, not cleared.' );

		$cleared = $this->compute(
			$props,
			[
				'field'              => 'sale_price',
				'clear_invalid_sale' => true,
			]
		);
		$this->assertSame( Result::CHANGED, $cleared->state() );
		$this->assertSame( '', $cleared->new(), 'Cleared as an empty string, never 0.' );

		$none = $this->compute(
			[
				'regular_price' => '20.00',
				'sale_price'    => '',
			],
			[
				'field'              => 'sale_price',
				'clear_invalid_sale' => true,
			]
		);
		$this->assertSame( Result::ALREADY, $none->state() );
	}

	public function test_an_empty_source_is_skipped(): void {
		$result = $this->compute(
			[
				'regular_price' => '',
				'sale_price'    => '8.00',
			],
			[
				'field'    => 'sale_price',
				'discount' => '10',
			]
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'Regular price is empty, so there was nothing to copy into Sale price.', $result->reason() );
		$this->assertSame( '8.00', $result->new() );

		$back = $this->compute(
			[
				'regular_price' => '20.00',
				'sale_price'    => '',
			],
			[ 'field' => 'regular_price' ]
		);
		$this->assertSame( Result::SKIPPED, $back->state(), 'No sale price is not a sale price of 0.' );
		$this->assertSame( '20.00', $back->new() );
	}

	public function test_sale_into_regular(): void {
		$GLOBALS['bulkcat_test']['decimals'] = 2;
		$result                              = $this->compute(
			[
				'regular_price' => '20.00',
				'sale_price'    => '15',
			],
			[ 'field' => 'regular_price' ]
		);
		$this->assertSame( Result::CHANGED, $result->state() );
		$this->assertSame( [ '20.00', '15.00' ], [ $result->old(), $result->new() ] );

		$same = $this->compute(
			[
				'regular_price' => '15.00',
				'sale_price'    => '15',
			],
			[ 'field' => 'regular_price' ]
		);
		$this->assertSame( Result::ALREADY, $same->state() );

		$refused = ( new Registry( new Fields() ) )->get( 'copy_price' )->validate(
			[
				'field'    => 'regular_price',
				'discount' => '10',
			]
		);
		$this->assertFalse( $refused->ok(), 'A discount only applies when copying into the sale price.' );
	}

	public function test_a_variable_parent_is_skipped(): void {
		$result = $this->compute(
			[
				'regular_price' => '',
				'sale_price'    => '',
			],
			[
				'field'    => 'sale_price',
				'discount' => '10',
			],
			'variable'
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'bulkcat_not_available', $result->code() );
	}

	public function test_apply_writes_the_copy_through_the_setter(): void {
		$operation = ( new Registry( new Fields() ) )->get( 'copy_price' );
		$args      = $operation->validate(
			[
				'field'    => 'sale_price',
				'discount' => '25',
			]
		)->args();
		$product   = new FakeProduct(
			'variation',
			[
				'regular_price' => '40.00',
				'sale_price'    => '',
			]
		);
		$operation->apply( $operation->compute( $product, $args ), $product );
		$this->assertSame( [ [ 'set_sale_price', '30.00' ] ], $product->sets );
		$this->assertSame( '40.00', $product->props['regular_price'], 'The source is not touched.' );
	}
}
