<?php
/**
 * Increase or decrease (T26; SPEC §5.4, §6): a table over percentage,
 * fixed, negative, zero, empty and rounding-boundary cases at two price
 * precisions (2 and 0 decimals); stock stays a whole number (a percentage
 * rounds to the nearest unit, half up); weight and a dimension adjust as
 * decimals; an empty value is skipped, not read as zero; a result below zero
 * is refused and the row skipped, never clamped; an unchanged result is
 * already; apply() writes the computed value through the setter.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class AdjustTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Compute adjust on a product holding $props.
	 *
	 * @param array<string, mixed> $props Product props.
	 * @param array<string, mixed> $args  Arguments before validation.
	 * @param string               $type  Product type.
	 * @param Registry|null        $registry Registry to use.
	 */
	private function compute( array $props, array $args, string $type = 'simple', ?Registry $registry = null ): Result {
		$operation  = ( $registry ?? new Registry( new Fields() ) )->get( 'adjust' );
		$validation = $operation->validate( $args );
		$this->assertTrue( $validation->ok(), 'Arguments refused: ' . wp_json_encode( $args ) );
		$product = new FakeProduct( $type, $props );
		$result  = $operation->compute( $product, $validation->args() );
		$this->assertSame( [], $product->sets, 'compute() wrote to the product.' );
		return $result;
	}

	/**
	 * Assert one case: [ old, direction, by, amount, state, new ].
	 *
	 * @param string            $field Field key.
	 * @param array<int, array> $cases Cases.
	 * @param string            $label Label for messages.
	 */
	private function run_table( string $field, array $cases, string $label ): void {
		foreach ( $cases as $i => [ $old, $direction, $by, $amount, $state, $new ] ) {
			$case   = "$label #$i: $field '" . ( null === $old ? 'null' : (string) $old ) . "' $direction $by $amount";
			$result = $this->compute(
				[ $field => $old ],
				[
					'field'     => $field,
					'direction' => $direction,
					'by'        => $by,
					'amount'    => $amount,
				]
			);
			$this->assertSame( $state, $result->state(), $case . ' (' . $result->reason() . ')' );
			$this->assertSame( $field, $result->field(), $case );
			$this->assertSame( $old, $result->old(), $case );
			$this->assertSame( $new, $result->new(), $case );
			if ( Result::SKIPPED === $state ) {
				$this->assertNotSame( '', $result->reason(), $case );
			}
		}
	}

	public function test_price_table_at_two_decimals(): void {
		$GLOBALS['bulkcat_test']['decimals'] = 2;
		$this->run_table(
			'regular_price',
			[
				// Percentage.
				[ '10.00', 'increase', 'percent', '10', 'changed', '11.00' ],
				[ '10', 'increase', 'percent', '12.5', 'changed', '11.25' ],
				[ '19.99', 'decrease', 'percent', '15', 'changed', '16.99' ],
				[ '10.00', 'decrease', 'percent', '100', 'changed', '0.00' ],
				// Fixed.
				[ '10.00', 'increase', 'amount', '2.5', 'changed', '12.50' ],
				[ '0.10', 'increase', 'amount', '0.2', 'changed', '0.30' ],
				[ '10.00', 'decrease', 'amount', '10', 'changed', '0.00' ],
				// Below zero: refused, never clamped.
				[ '10.00', 'decrease', 'amount', '10.01', 'skipped', '10.00' ],
				[ '0.00', 'decrease', 'amount', '0.001', 'skipped', '0.00' ],
				[ '-5', 'increase', 'amount', '2', 'skipped', '-5' ],
				// Zero stays zero under a percentage; a fixed amount moves it.
				[ '0', 'increase', 'percent', '50', 'already', '0' ],
				[ '0.00', 'increase', 'amount', '1', 'changed', '1.00' ],
				// Empty is not zero.
				[ '', 'increase', 'percent', '10', 'skipped', '' ],
				[ '', 'increase', 'amount', '5', 'skipped', '' ],
				// Rounding boundaries: half up at the shop's precision.
				[ '1.15', 'increase', 'percent', '10', 'changed', '1.27' ],
				[ '1.05', 'decrease', 'percent', '10', 'changed', '0.95' ],
				[ '10.00', 'increase', 'amount', '0.004', 'already', '10.00' ],
				[ '10.00', 'increase', 'amount', '0.005', 'changed', '10.01' ],
				[ '10', 'increase', 'amount', '0.001', 'already', '10' ],
			],
			'2dp'
		);
		$this->run_table(
			'sale_price',
			[
				[ '8.00', 'decrease', 'percent', '25', 'changed', '6.00' ],
				[ '', 'decrease', 'percent', '25', 'skipped', '' ],
			],
			'2dp sale'
		);
	}

	public function test_price_table_at_zero_decimals(): void {
		$GLOBALS['bulkcat_test']['decimals'] = 0;
		$this->run_table(
			'regular_price',
			[
				[ '100', 'increase', 'percent', '10', 'changed', '110' ],
				[ '99', 'increase', 'percent', '10', 'changed', '109' ],
				[ '10', 'increase', 'percent', '5', 'changed', '11' ],
				[ '10', 'increase', 'percent', '4', 'already', '10' ],
				[ '5', 'decrease', 'amount', '0.4', 'already', '5' ],
				[ '5', 'decrease', 'amount', '5', 'changed', '0' ],
				[ '5', 'decrease', 'amount', '6', 'skipped', '5' ],
				[ '0', 'increase', 'percent', '10', 'already', '0' ],
				[ '', 'increase', 'amount', '1', 'skipped', '' ],
			],
			'0dp'
		);
	}

	public function test_a_result_below_zero_is_refused_with_a_plain_reason(): void {
		$result = $this->compute(
			[ 'regular_price' => '3.00' ],
			[
				'field'     => 'regular_price',
				'direction' => 'decrease',
				'by'        => 'amount',
				'amount'    => '5.5',
			]
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'Regular price would go below zero (-2.50), so it was not changed.', $result->reason() );
		$this->assertSame( 'bulkcat_invalid_value', $result->code() );
		$this->assertSame( '3.00', $result->new(), 'Never clamped to zero.' );
	}

	public function test_an_empty_value_is_skipped_and_not_read_as_zero(): void {
		$result = $this->compute(
			[ 'sale_price' => '' ],
			[
				'field'     => 'sale_price',
				'direction' => 'increase',
				'by'        => 'amount',
				'amount'    => '5',
			]
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'Sale price is empty, so there was nothing to change.', $result->reason() );
		$this->assertSame( '', $result->new() );
	}

	public function test_stock_stays_a_whole_number(): void {
		$cases = [
			[ 10, 'increase', 'amount', '5', 'changed', 15 ],
			[ 10, 'increase', 'percent', '15', 'changed', 12 ],
			[ 10, 'increase', 'percent', '14', 'changed', 11 ],
			[ 7, 'decrease', 'percent', '10', 'changed', 6 ],
			[ 3, 'decrease', 'percent', '10', 'already', 3 ],
			[ 0, 'increase', 'amount', '4', 'changed', 4 ],
			[ 2, 'decrease', 'amount', '5', 'skipped', 2 ],
			[ -3, 'increase', 'amount', '2', 'skipped', -3 ],
			[ null, 'increase', 'amount', '5', 'skipped', null ],
		];
		foreach ( $cases as $i => [ $old, $direction, $by, $amount, $state, $new ] ) {
			$result = $this->compute(
				[
					'manage_stock'   => true,
					'stock_quantity' => $old,
				],
				[
					'field'     => 'stock_quantity',
					'direction' => $direction,
					'by'        => $by,
					'amount'    => $amount,
				]
			);
			$this->assertSame( $state, $result->state(), "stock #$i ({$result->reason()})" );
			$this->assertSame( $new, $result->new(), "stock #$i" );
		}

		$fraction = ( new Registry( new Fields() ) )->get( 'adjust' )->validate(
			[
				'field'     => 'stock_quantity',
				'direction' => 'increase',
				'by'        => 'amount',
				'amount'    => '1.5',
			]
		);
		$this->assertFalse( $fraction->ok(), 'A fractional amount on stock is refused before any object is read.' );
	}

	public function test_stock_is_skipped_where_it_is_not_managed(): void {
		$result = $this->compute(
			[
				'manage_stock'   => false,
				'stock_quantity' => 10,
			],
			[
				'field'     => 'stock_quantity',
				'direction' => 'increase',
				'by'        => 'amount',
				'amount'    => '5',
			],
			'variation'
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'bulkcat_not_available', $result->code() );
		$this->assertStringContainsString( 'Manage stock', $result->reason() );
	}

	public function test_a_variable_parent_price_is_skipped(): void {
		$result = $this->compute(
			[ 'regular_price' => '' ],
			[
				'field'     => 'regular_price',
				'direction' => 'increase',
				'by'        => 'percent',
				'amount'    => '10',
			],
			'variable'
		);
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'bulkcat_not_available', $result->code() );
	}

	public function test_weight_and_a_dimension_adjust_as_decimals(): void {
		$weights = [
			[ '1.5', 'increase', 'percent', '10', 'changed', '1.65' ],
			[ '0.333', 'increase', 'percent', '10', 'changed', '0.3663' ],
			[ '2', 'decrease', 'amount', '0.25', 'changed', '1.75' ],
			[ '2', 'decrease', 'amount', '3', 'skipped', '2' ],
			[ '', 'increase', 'amount', '1', 'skipped', '' ],
		];
		$this->run_table( 'weight', $weights, 'weight' );

		// SPEC §6 lists dimensions for increase or decrease; §5.2 gives them set
		// and clear only, so the registry does not offer it yet. A dimension
		// that allows adjust computes like weight, untouched here.
		$fields = [];
		foreach ( ( new Fields() )->all() as $key => $field ) {
			$fields[] = 'length' === $key
				? new Field(
					key: 'length',
					label: 'Length',
					type: Field::DECIMAL,
					storage: Storage::meta( '_length' ),
					edit_kinds: [ Field::SET, Field::ADJUST, Field::CLEAR ],
					variation_rule: Field::PER_VARIATION,
					getter: 'get_length',
					setter: 'set_length',
					nullable: true,
					group: Group::SHIPPING_TAX
				)
				: $field;
		}
		$double = new Registry( new Fields( $fields ) );
		$result = $this->compute(
			[ 'length' => '12.5' ],
			[
				'field'     => 'length',
				'direction' => 'increase',
				'by'        => 'percent',
				'amount'    => '20',
			],
			'simple',
			$double
		);
		$this->assertSame( Result::CHANGED, $result->state() );
		$this->assertSame( '15', $result->new() );
	}

	public function test_apply_writes_the_computed_value_through_the_setter(): void {
		$operation = ( new Registry( new Fields() ) )->get( 'adjust' );
		$args      = $operation->validate(
			[
				'field'     => 'regular_price',
				'direction' => 'increase',
				'by'        => 'percent',
				'amount'    => '10',
			]
		)->args();
		$product   = new FakeProduct( 'simple', [ 'regular_price' => '10.00' ] );
		$operation->apply( $operation->compute( $product, $args ), $product );
		$this->assertSame( [ [ 'set_regular_price', '11.00' ] ], $product->sets );

		$empty = new FakeProduct( 'simple', [ 'regular_price' => '' ] );
		$operation->apply( $operation->compute( $empty, $args ), $empty );
		$this->assertSame( [], $empty->sets, 'A skipped row is not written.' );
	}
}
