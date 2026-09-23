<?php
/**
 * Round (T26; SPEC §5.4, §6): to decimals, to the nearest step and to a
 * charm ending, each up, down and to the nearest, at two price precisions
 * (2 and 0 decimals); ties go up; an ending below zero is refused, never
 * clamped; an empty price is skipped; a price already on target is
 * already; a price step finer than the shop's decimals is refused; and
 * "increase regular by 12 per cent, then round to .95" (and by 10 per cent,
 * then up to .99) compose in list order in one pass over one object.
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

final class RoundTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	/**
	 * Assert a table of [ old, to, setting, direction, state, new ] on the regular price.
	 *
	 * @param array<int, array> $cases Cases.
	 * @param string            $label Label for messages.
	 */
	private function run_table( array $cases, string $label ): void {
		$operation = ( new Registry( new Fields() ) )->get( 'round' );
		foreach ( $cases as $i => [ $old, $to, $setting, $direction, $state, $new ] ) {
			$case       = "$label #$i: $old to $to $setting $direction";
			$validation = $operation->validate(
				[
					'field'     => 'regular_price',
					'to'        => $to,
					$to         => $setting,
					'direction' => $direction,
				]
			);
			$this->assertTrue( $validation->ok(), $case . ' refused' );
			$product = new FakeProduct( 'simple', [ 'regular_price' => $old ] );
			$result  = $operation->compute( $product, $validation->args() );
			$this->assertSame( $state, $result->state(), $case . ' (' . $result->reason() . ')' );
			$this->assertSame( $old, $result->old(), $case );
			$this->assertSame( $new, $result->new(), $case );
			$this->assertSame( [], $product->sets, $case . ' wrote' );
		}
	}

	public function test_rounding_to_decimals(): void {
		$GLOBALS['bcm_test']['decimals'] = 2;
		$this->run_table(
			[
				[ '12.345', 'decimals', 1, 'nearest', 'changed', '12.30' ],
				[ '12.35', 'decimals', 1, 'nearest', 'changed', '12.40' ],
				[ '12.31', 'decimals', 1, 'up', 'changed', '12.40' ],
				[ '12.39', 'decimals', 1, 'down', 'changed', '12.30' ],
				[ '12.50', 'decimals', 0, 'nearest', 'changed', '13.00' ],
				[ '12.49', 'decimals', 0, 'nearest', 'changed', '12.00' ],
				[ '12.01', 'decimals', 0, 'up', 'changed', '13.00' ],
				[ '12.99', 'decimals', 0, 'down', 'changed', '12.00' ],
				[ '12.00', 'decimals', 0, 'up', 'already', '12.00' ],
				[ '12', 'decimals', 2, 'nearest', 'already', '12' ],
				[ '99.995', 'decimals', 2, 'nearest', 'changed', '100.00' ],
				[ '', 'decimals', 0, 'nearest', 'skipped', '' ],
			],
			'2dp decimals'
		);
		$GLOBALS['bcm_test']['decimals'] = 0;
		$this->run_table(
			[
				[ '12.5', 'decimals', 0, 'nearest', 'changed', '13' ],
				[ '12.4', 'decimals', 0, 'up', 'changed', '13' ],
				[ '12', 'decimals', 0, 'down', 'already', '12' ],
			],
			'0dp decimals'
		);
	}

	public function test_rounding_to_a_step(): void {
		$GLOBALS['bcm_test']['decimals'] = 2;
		$this->run_table(
			[
				[ '12.49', 'step', '5', 'nearest', 'changed', '10.00' ],
				[ '12.50', 'step', '5', 'nearest', 'changed', '15.00' ],
				[ '10.01', 'step', '5', 'up', 'changed', '15.00' ],
				[ '14.99', 'step', '5', 'down', 'changed', '10.00' ],
				[ '15.00', 'step', '5', 'up', 'already', '15.00' ],
				[ '12.26', 'step', '0.50', 'nearest', 'changed', '12.50' ],
				[ '12.24', 'step', '0.50', 'nearest', 'changed', '12.00' ],
				[ '12.01', 'step', '0.50', 'up', 'changed', '12.50' ],
				[ '0.30', 'step', '0.10', 'up', 'already', '0.30' ],
				[ '0.29', 'step', '0.05', 'down', 'changed', '0.25' ],
				[ '2.40', 'step', '5', 'down', 'changed', '0.00' ],
				[ '', 'step', '5', 'up', 'skipped', '' ],
			],
			'2dp step'
		);
		$GLOBALS['bcm_test']['decimals'] = 0;
		$this->run_table(
			[
				[ '12', 'step', '5', 'nearest', 'changed', '10' ],
				[ '13', 'step', '5', 'nearest', 'changed', '15' ],
				[ '101', 'step', '10', 'up', 'changed', '110' ],
				[ '109', 'step', '10', 'down', 'changed', '100' ],
			],
			'0dp step'
		);
	}

	public function test_a_price_step_finer_than_the_shop_is_refused(): void {
		$operation                       = ( new Registry( new Fields() ) )->get( 'round' );
		$GLOBALS['bcm_test']['decimals'] = 2;
		$refused                         = $operation->validate(
			[
				'field' => 'regular_price',
				'to'    => 'step',
				'step'  => '0.005',
			]
		);
		$this->assertFalse( $refused->ok() );
		$this->assertSame( 'bcm_invalid_operation', $refused->error()->get_error_code() );
		$this->assertTrue(
			$operation->validate(
				[
					'field' => 'regular_price',
					'to'    => 'step',
					'step'  => '0.050',
				]
			)->ok(),
			'Trailing zeros do not count.'
		);
		$GLOBALS['bcm_test']['decimals'] = 0;
		$this->assertFalse(
			( new Registry( new Fields() ) )->get( 'round' )->validate(
				[
					'field' => 'regular_price',
					'to'    => 'step',
					'step'  => '0.5',
				]
			)->ok()
		);
	}

	public function test_rounding_to_a_charm_ending(): void {
		$GLOBALS['bcm_test']['decimals'] = 2;
		$this->run_table(
			[
				// Nearest: the closer of the ending below and above.
				[ '12.40', 'ending', '.99', 'nearest', 'changed', '11.99' ],
				[ '12.60', 'ending', '.99', 'nearest', 'changed', '12.99' ],
				[ '12.48', 'ending', '.99', 'nearest', 'changed', '11.99' ],
				[ '12.50', 'ending', '.99', 'nearest', 'changed', '12.99' ],
				// A tie goes up: 12.49 sits 0.50 from both 11.99 and 12.99.
				[ '12.49', 'ending', '.99', 'nearest', 'changed', '12.99' ],
				[ '12.45', 'ending', '.95', 'nearest', 'changed', '12.95' ],
				// Up and down.
				[ '12.40', 'ending', '.99', 'up', 'changed', '12.99' ],
				[ '12.40', 'ending', '.99', 'down', 'changed', '11.99' ],
				[ '12.96', 'ending', '.95', 'up', 'changed', '13.95' ],
				[ '12.96', 'ending', '.95', 'down', 'changed', '12.95' ],
				[ '13', 'ending', '.95', 'down', 'changed', '12.95' ],
				// On the ending already.
				[ '12.99', 'ending', '.99', 'up', 'already', '12.99' ],
				[ '12.99', 'ending', '.99', 'down', 'already', '12.99' ],
				[ '12.99', 'ending', '.99', 'nearest', 'already', '12.99' ],
				// Near zero: down would go below zero, so it is refused; nearest goes up.
				[ '0.50', 'ending', '.95', 'down', 'skipped', '0.50' ],
				[ '0.50', 'ending', '.95', 'nearest', 'changed', '0.95' ],
				[ '0.95', 'ending', '.95', 'down', 'already', '0.95' ],
				// Ending .00: whole numbers.
				[ '12.40', 'ending', '0', 'up', 'changed', '13.00' ],
				[ '', 'ending', '.99', 'nearest', 'skipped', '' ],
			],
			'2dp ending'
		);
		$GLOBALS['bcm_test']['decimals'] = 0;
		$this->run_table(
			[
				[ '12.6', 'ending', '0', 'nearest', 'changed', '13' ],
				[ '12', 'ending', '0', 'nearest', 'already', '12' ],
			],
			'0dp ending'
		);
	}

	public function test_an_ending_below_zero_names_the_refused_value(): void {
		$operation = ( new Registry( new Fields() ) )->get( 'round' );
		$args      = $operation->validate(
			[
				'field'     => 'sale_price',
				'to'        => 'ending',
				'ending'    => '.99',
				'direction' => 'down',
			]
		)->args();
		$result    = $operation->compute( new FakeProduct( 'simple', [ 'sale_price' => '0.50' ] ), $args );
		$this->assertSame( Result::SKIPPED, $result->state() );
		$this->assertSame( 'Sale price would go below zero (-0.01), so it was not changed.', $result->reason() );
		$this->assertSame( 'bcm_invalid_value', $result->code() );
	}

	/**
	 * Run a validated operation list over one object in list order, each
	 * step computing on what the one before set, as the writer does.
	 *
	 * @param FakeProduct       $product The object.
	 * @param array<int, array> $steps   [ { action, args } ].
	 * @return Result[]
	 */
	private static function run_list( FakeProduct $product, array $steps ): array {
		$registry = new Registry( new Fields() );
		$list     = $registry->validate_list( $steps );
		self::assertTrue( is_array( $list ), 'The list was refused.' );
		$results = [];
		foreach ( $list as $step ) {
			$operation = $registry->get( $step['action'] );
			$result    = $operation->compute( $product, $step['args'] );
			$operation->apply( $result, $product );
			$results[] = $result;
		}
		return $results;
	}

	public function test_increase_by_twelve_per_cent_then_round_to_95_composes_in_one_pass(): void {
		$GLOBALS['bcm_test']['decimals'] = 2;
		$product                         = new FakeProduct( 'simple', [ 'regular_price' => '25.00' ] );
		$results                         = self::run_list(
			$product,
			[
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '12',
					],
				],
				[
					'action' => 'round',
					'args'   => [
						'field'  => 'regular_price',
						'to'     => 'ending',
						'ending' => '.95',
					],
				],
			]
		);
		$this->assertSame( [ '25.00', '28.00' ], [ $results[0]->old(), $results[0]->new() ] );
		$this->assertSame( [ '28.00', '27.95' ], [ $results[1]->old(), $results[1]->new() ], 'Round reads what the increase set.' );
		$this->assertSame(
			[
				[ 'set_regular_price', '28.00' ],
				[ 'set_regular_price', '27.95' ],
			],
			$product->sets
		);
		$this->assertSame( '27.95', $product->props['regular_price'] );

		// Reversed, the list means something else: order is the list's.
		$reversed = new FakeProduct( 'simple', [ 'regular_price' => '25.00' ] );
		self::run_list(
			$reversed,
			[
				[
					'action' => 'round',
					'args'   => [
						'field'  => 'regular_price',
						'to'     => 'ending',
						'ending' => '.95',
					],
				],
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '12',
					],
				],
			]
		);
		$this->assertSame( '27.94', $reversed->props['regular_price'], '24.95 plus 12 per cent.' );
	}

	public function test_increase_by_ten_per_cent_then_round_up_to_99_at_both_precisions(): void {
		$steps                           = [
			[
				'action' => 'adjust',
				'args'   => [
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'percent',
					'amount'    => '10',
				],
			],
			[
				'action' => 'round',
				'args'   => [
					'field'     => 'regular_price',
					'to'        => 'ending',
					'ending'    => '.99',
					'direction' => 'up',
				],
			],
		];
		$GLOBALS['bcm_test']['decimals'] = 2;
		foreach ( [
			'10.00' => '11.99',
			'18.20' => '20.99',
			'9.99'  => '10.99',
			''      => '',
		] as $old => $expected ) {
			$product = new FakeProduct( 'simple', [ 'regular_price' => (string) $old ] );
			$results = self::run_list( $product, $steps );
			$this->assertSame( $expected, $product->props['regular_price'], "2dp from $old" );
			if ( '' === (string) $old ) {
				$this->assertSame( [ Result::SKIPPED, Result::SKIPPED ], [ $results[0]->state(), $results[1]->state() ] );
				$this->assertSame( [], $product->sets );
			}
		}

		// At 0 decimals an ending of .99 is refused: the shop has no cents.
		$GLOBALS['bcm_test']['decimals'] = 0;
		$refused                         = ( new Registry( new Fields() ) )->validate_list( $steps );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bcm_invalid_operation', $refused->get_error_code() );
		$this->assertSame( 2, $refused->get_error_data()['step'] );

		$product                  = new FakeProduct( 'simple', [ 'regular_price' => '99' ] );
		$steps[1]['args']['to']   = 'step';
		$steps[1]['args']['step'] = '10';
		unset( $steps[1]['args']['ending'] );
		self::run_list( $product, $steps );
		$this->assertSame( '110', $product->props['regular_price'], '99 plus 10 per cent is 109, up to the next 10.' );
	}
}
