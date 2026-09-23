<?php
/**
 * Row validation (T28; SPEC §4.8, §5.4, §8, §13): every rule as a table with
 * its exact sentence, state and code; the registry's validator before the
 * cross-field rules; permission before both; one failing row leaving the
 * rest of a batch validated; the request and background permission checks;
 * clear_invalid_sale on set and copy; and agreement — for every refused
 * change the Validator's verdict is exactly the refusal Field::write() gives,
 * and Field::refusal() sets nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {
	private const SALE_NOT_BELOW = 'The sale price has to be below the regular price, so this product was skipped. Lower the sale price, or choose to clear it where it is not below the regular price.';

	private const CANNOT_EDIT = 'You do not have permission to edit this product, so it was skipped. Ask an administrator for access to it.';

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['caps']  = [
			'edit_post'            => true,
			'assign_product_terms' => true,
		];
		$GLOBALS['bcm_test']['skus']  = [ 'SHOE-42' => 99 ];
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat' => [ 5 ],
			'product_tag' => [ 6 ],
		];
	}

	private static function validator(): Validator {
		return Validator::for_request( new Fields() );
	}

	/**
	 * Every refused rule: [ object type, props, change, args, state, code, sentence ].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: Result, 3: array<string, mixed>, 4: string, 5: string, 6: string}>
	 */
	private static function refused_cases(): array {
		return [
			'sale price not below the regular'            => [
				'simple',
				[
					'id'            => 1,
					'regular_price' => '10.00',
					'sale_price'    => '8.00',
				],
				Result::changed( 'sale_price', '8.00', '12.00' ),
				[],
				Result::SKIPPED,
				'bcm_invalid_value',
				self::SALE_NOT_BELOW,
			],
			'sale price equal to the regular'             => [
				'variation',
				[
					'id'            => 2,
					'regular_price' => '10.00',
					'sale_price'    => '',
				],
				Result::changed( 'sale_price', '', '10.00' ),
				[],
				Result::SKIPPED,
				'bcm_invalid_value',
				self::SALE_NOT_BELOW,
			],
			'regular price lowered to the sale price'     => [
				'simple',
				[
					'id'            => 3,
					'regular_price' => '10.00',
					'sale_price'    => '8.00',
				],
				Result::changed( 'regular_price', '10.00', '7.50' ),
				[],
				Result::SKIPPED,
				'bcm_invalid_value',
				self::SALE_NOT_BELOW,
			],
			'a price below zero'                          => [
				'simple',
				[
					'id'            => 4,
					'regular_price' => '10.00',
				],
				Result::changed( 'regular_price', '10.00', '-1.00' ),
				[],
				Result::SKIPPED,
				'bcm_invalid_value',
				'Regular price has to be a price of 0 or more, like 12.50, or left empty.',
			],
			'a weight below zero'                         => [
				'variation',
				[
					'id'     => 5,
					'weight' => '2',
				],
				Result::changed( 'weight', '2', '-0.5' ),
				[],
				Result::SKIPPED,
				'bcm_invalid_value',
				'Weight has to be a number of 0 or more, like 1.5, or left empty.',
			],
			'a SKU another product holds'                 => [
				'simple',
				[
					'id'  => 12,
					'sku' => 'SHOE-41',
				],
				Result::changed( 'sku', 'SHOE-41', 'SHOE-42' ),
				[],
				Result::FAILED,
				'bcm_invalid_value',
				'A product with the SKU SHOE-42 already exists, so this SKU was not applied.',
			],
			'a SKU another product holds, on a variation' => [
				'variation',
				[
					'id'  => 13,
					'sku' => '',
				],
				Result::changed( 'sku', '', 'SHOE-42' ),
				[],
				Result::FAILED,
				'bcm_invalid_value',
				'A product with the SKU SHOE-42 already exists, so this SKU was not applied.',
			],
			'stock quantity on unmanaged variation stock' => [
				'variation',
				[
					'id'             => 14,
					'manage_stock'   => false,
					'stock_quantity' => null,
				],
				Result::changed( 'stock_quantity', null, 4 ),
				[],
				Result::SKIPPED,
				'bcm_not_available',
				'This variation does not manage its own stock, so its stock quantity was not changed. Turn on Manage stock for the variation first.',
			],
			'categories on a variation'                   => [
				'variation',
				[
					'id'           => 15,
					'category_ids' => [],
				],
				Result::changed( 'category_ids', [], [ 5 ] ),
				[],
				Result::FAILED,
				'bcm_not_available',
				'Categories belong to the product, not to each variation.',
			],
			'tags on a variation'                         => [
				'variation',
				[
					'id'      => 16,
					'tag_ids' => [],
				],
				Result::changed( 'tag_ids', [], [ 6 ] ),
				[],
				Result::FAILED,
				'bcm_not_available',
				'Tags belong to the product, not to each variation.',
			],
			'featured on a variation'                     => [
				'variation',
				[
					'id'       => 17,
					'featured' => false,
				],
				Result::changed( 'featured', false, true ),
				[],
				Result::FAILED,
				'bcm_not_available',
				'Featured belongs to the product, not to each variation.',
			],
			'a price on a variable product'               => [
				'variable',
				[
					'id'            => 18,
					'regular_price' => '',
				],
				Result::changed( 'regular_price', '', '10.00' ),
				[],
				Result::FAILED,
				'bcm_not_available',
				'A variable product takes its price from its variations. Change the variations instead.',
			],
			'draft on a variation'                        => [
				'variation',
				[
					'id'     => 19,
					'status' => 'publish',
				],
				Result::changed( 'status', 'publish', 'draft' ),
				[],
				Result::FAILED,
				'bcm_invalid_value',
				'On a variation, Status can only be: Published, Private.',
			],
		];
	}

	public function test_each_rule_refuses_with_its_state_code_and_exact_sentence(): void {
		foreach ( self::refused_cases() as $case => [ $type, $props, $change, $args, $state, $code, $sentence ] ) {
			$product = new FakeProduct( $type, $props );
			$results = self::validator()->check( $product, $change, $args );
			$this->assertCount( 1, $results, $case );
			$this->assertSame( $state, $results[0]->state(), $case );
			$this->assertSame( $code, $results[0]->code(), $case );
			$this->assertSame( $sentence, $results[0]->reason(), $case );
			$this->assertStringNotContainsString( 'bcm_', $results[0]->reason(), "$case: no code in the sentence." );
			$this->assertSame( $change->old(), $results[0]->new(), "$case: a refused row keeps its value." );
			$this->assertSame( [], $product->sets, "$case: validation writes nothing." );
		}
	}

	public function test_a_change_that_passes_every_rule_comes_back_unchanged(): void {
		$cases = [
			'a sale price below the regular' => [ 'simple', [ 'regular_price' => '10.00' ], Result::changed( 'sale_price', '', '9.99' ) ],
			'clearing the sale price'        => [
				'simple',
				[
					'regular_price' => '10.00',
					'sale_price'    => '8.00',
				],
				Result::changed( 'sale_price', '8.00', '' ),
			],
			'a regular price with no sale'   => [ 'variation', [ 'regular_price' => '10.00' ], Result::changed( 'regular_price', '10.00', '5.00' ) ],
			'a free SKU'                     => [ 'variation', [ 'sku' => '' ], Result::changed( 'sku', '', 'SHOE-43' ) ],
			'managed variation stock'        => [ 'variation', [ 'manage_stock' => true ], Result::changed( 'stock_quantity', null, -2 ) ],
			'categories on a product'        => [ 'simple', [ 'category_ids' => [] ], Result::changed( 'category_ids', [], [ 5 ] ) ],
		];
		foreach ( $cases as $case => [ $type, $props, $change ] ) {
			$product = new FakeProduct( $type, [ 'id' => 30 ] + $props );
			$this->assertSame( [ $change ], self::validator()->check( $product, $change ), $case );
			$this->assertSame( [], $product->sets, $case );
		}
	}

	public function test_already_skipped_and_failed_results_pass_through(): void {
		$product = new FakeProduct( 'variation', [ 'id' => 31 ] );
		foreach ( [
			Result::already( 'category_ids', [ 5 ] ),
			Result::skipped( 'regular_price', '', 'Regular price is empty, so there was nothing to change.' ),
			Result::failed( 'sku', 'A', 'A product with the SKU A already exists, so this SKU was not applied.', 'bcm_invalid_value' ),
		] as $result ) {
			$this->assertSame( [ $result ], self::validator()->check( $product, $result ) );
		}
	}

	public function test_clear_invalid_sale_clears_rather_than_skips(): void {
		$clear = [ 'clear_invalid_sale' => true ];

		$product = new FakeProduct(
			'simple',
			[
				'id'            => 40,
				'regular_price' => '10.00',
				'sale_price'    => '8.00',
			]
		);
		$results = self::validator()->check( $product, Result::changed( 'sale_price', '8.00', '12.00' ), $clear );
		$this->assertCount( 1, $results );
		$this->assertSame( [ 'sale_price', '8.00', '', Result::CHANGED ], [ $results[0]->field(), $results[0]->old(), $results[0]->new(), $results[0]->state() ] );

		$empty   = new FakeProduct(
			'simple',
			[
				'id'            => 41,
				'regular_price' => '10.00',
				'sale_price'    => '',
			]
		);
		$results = self::validator()->check( $empty, Result::changed( 'sale_price', '', '10.00' ), $clear );
		$this->assertSame( Result::ALREADY, $results[0]->state(), 'An empty sale price is already cleared.' );

		$results = self::validator()->check( $product, Result::changed( 'regular_price', '10.00', '7.50' ), $clear );
		$this->assertCount( 2, $results, 'A regular price change is followed by clearing the sale price.' );
		$this->assertSame( [ 'regular_price', '7.50', Result::CHANGED ], [ $results[0]->field(), $results[0]->new(), $results[0]->state() ] );
		$this->assertSame( [ 'sale_price', '8.00', '', Result::CHANGED ], [ $results[1]->field(), $results[1]->old(), $results[1]->new(), $results[1]->state() ] );
		$this->assertSame( [], $product->sets );
	}

	public function test_clear_invalid_sale_through_the_set_and_copy_actions(): void {
		$actions = new Registry( new Fields() );
		foreach ( [
			'set sale price'         => [
				'set_value',
				[
					'field'              => 'sale_price',
					'value'              => '15',
					'clear_invalid_sale' => true,
				],
				'sale_price',
			],
			'set regular price'      => [
				'set_value',
				[
					'field'              => 'regular_price',
					'value'              => '6',
					'clear_invalid_sale' => true,
				],
				'regular_price',
			],
			'copy sale into regular' => [
				'copy_price',
				[
					'field'              => 'regular_price',
					'clear_invalid_sale' => true,
				],
				'regular_price',
			],
		] as $case => [ $id, $submitted, $field ] ) {
			$operation  = $actions->get( $id );
			$validation = $operation->validate( $submitted );
			$this->assertTrue( $validation->ok(), $case );
			$product = new FakeProduct(
				'simple',
				[
					'id'            => 50,
					'regular_price' => '10.00',
					'sale_price'    => '8.00',
				]
			);
			$results = self::validator()->check( $product, $operation->compute( $product, $validation->args() ), $validation->args() );
			$this->assertSame( $field, $results[0]->field(), $case );
			foreach ( $results as $result ) {
				$this->assertTrue( $result->is_changed(), $case );
				$operation->apply( $result, $product );
			}
			$this->assertSame( '', $product->props['sale_price'], "$case: the sale price is cleared." );

			$off     = $operation->validate( [ 'clear_invalid_sale' => false ] + $submitted )->args();
			$product = new FakeProduct(
				'simple',
				[
					'id'            => 51,
					'regular_price' => '10.00',
					'sale_price'    => '8.00',
				]
			);
			$results = self::validator()->check( $product, $operation->compute( $product, $off ), $off );
			$this->assertSame( [ Result::SKIPPED, self::SALE_NOT_BELOW ], [ $results[0]->state(), $results[0]->reason() ], "$case: skipped without the choice." );
		}
	}

	public function test_the_registry_validator_runs_before_the_cross_field_rules(): void {
		$variable = new FakeProduct(
			'variable',
			[
				'id'            => 60,
				'regular_price' => '10.00',
				'sale_price'    => '',
			]
		);
		$results  = self::validator()->check( $variable, Result::changed( 'sale_price', '', '20.00' ), [ 'clear_invalid_sale' => true ] );
		$this->assertSame( 'A variable product takes its price from its variations. Change the variations instead.', $results[0]->reason(), 'The field refuses first, so the sale rule never speaks and nothing is cleared.' );
		$this->assertSame( Result::FAILED, $results[0]->state() );

		$clash   = new FakeProduct(
			'simple',
			[
				'id'  => 61,
				'sku' => '',
			]
		);
		$first   = self::validator();
		$results = $first->check( $clash, Result::changed( 'sku', '', 'SHOE-42' ) );
		$this->assertSame( 'bcm_invalid_value', $results[0]->code() );
		$this->assertSame( Result::FAILED, $results[0]->state() );

		$GLOBALS['bcm_test']['caps']['edit_post:62'] = false;
		$variation                                   = new FakeProduct(
			'variation',
			[
				'id'           => 62,
				'category_ids' => [],
			]
		);
		$results                                     = self::validator()->check( $variation, Result::changed( 'category_ids', [], [ 5 ] ) );
		$this->assertSame( [ Result::SKIPPED, 'bcm_cannot_edit', self::CANNOT_EDIT ], [ $results[0]->state(), $results[0]->code(), $results[0]->reason() ], 'Permission is checked before the field.' );
	}

	public function test_one_failing_row_leaves_the_rest_of_the_batch_validated(): void {
		$validator = self::validator();
		$rows      = [
			70 => 'SHOE-70',
			71 => 'SHOE-42',
			72 => 'SHOE-72',
			73 => 'SHOE-72',
			74 => 'SHOE-74',
		];
		$states    = [];
		foreach ( $rows as $id => $sku ) {
			$product       = new FakeProduct(
				74 === $id ? 'variation' : 'simple',
				[
					'id'  => $id,
					'sku' => '',
				]
			);
			$results       = $validator->check( $product, Result::changed( 'sku', '', $sku ) );
			$states[ $id ] = [ $results[0]->state(), $results[0]->reason() ];
		}
		$clash = static fn( string $sku ): array => [ Result::FAILED, "A product with the SKU $sku already exists, so this SKU was not applied." ];
		$this->assertSame(
			[
				70 => [ Result::CHANGED, '' ],
				71 => $clash( 'SHOE-42' ),
				72 => [ Result::CHANGED, '' ],
				73 => $clash( 'SHOE-72' ),
				74 => [ Result::CHANGED, '' ],
			],
			$states,
			'A clash with the catalogue or with an earlier row in the same run fails only that row.'
		);

		$again = $validator->check( new FakeProduct( 'simple', [ 'id' => 72 ] ), Result::changed( 'sku', 'X', 'SHOE-72' ) );
		$this->assertTrue( $again[0]->is_changed(), 'The object that claimed a SKU may take it again.' );
	}

	public function test_each_object_is_authorised_in_a_request(): void {
		$GLOBALS['bcm_test']['caps']['edit_post:81'] = false;
		$validator                                   = self::validator();
		$states                                      = [];
		foreach ( [ 80, 81, 82 ] as $id ) {
			$product       = new FakeProduct(
				'simple',
				[
					'id'     => $id,
					'status' => 'draft',
				]
			);
			$results       = $validator->check( $product, Result::changed( 'status', 'draft', 'publish' ) );
			$states[ $id ] = [ $results[0]->state(), $results[0]->code(), $results[0]->reason() ];
		}
		$this->assertSame(
			[
				80 => [ Result::CHANGED, '', '' ],
				81 => [ Result::SKIPPED, 'bcm_cannot_edit', self::CANNOT_EDIT ],
				82 => [ Result::CHANGED, '', '' ],
			],
			$states,
			'Another author\'s product is skipped with the reason; the rest pass.'
		);

		$already = $validator->check( new FakeProduct( 'simple', [ 'id' => 81 ] ), Result::already( 'status', 'publish' ) );
		$this->assertSame( Result::SKIPPED, $already[0]->state(), 'A row the user may not edit is reported as skipped whatever it computed.' );
		$this->assertNull( $validator->authorise( new FakeProduct( 'simple', [ 'id' => 80 ] ) ) );
		$this->assertSame( 'bcm_cannot_edit', $validator->authorise( new FakeProduct( 'simple', [ 'id' => 81 ] ) )->get_error_code() );
	}

	public function test_background_work_is_authorised_against_the_recorded_user(): void {
		$GLOBALS['bcm_test']['caps']      = [];
		$GLOBALS['bcm_test']['user_caps'] = [
			5 => [
				'edit_post'    => true,
				'edit_post:91' => false,
			],
		];
		$change                           = Result::changed( 'featured', false, true );
		$states                           = [];
		foreach ( [ 90, 91 ] as $id ) {
			$results       = Validator::for_user( new Fields(), 5 )->check( new FakeProduct( 'simple', [ 'id' => $id ] ), $change );
			$states[ $id ] = $results[0]->state();
		}
		$this->assertSame(
			[
				90 => Result::CHANGED,
				91 => Result::SKIPPED,
			],
			$states,
			'With no current user, the batch\'s recorded user decides.'
		);
		$this->assertSame( Result::SKIPPED, Validator::for_user( new Fields(), 0 )->check( new FakeProduct( 'simple', [ 'id' => 90 ] ), $change )[0]->state(), 'No recorded user edits nothing.' );
		$this->assertSame( Result::SKIPPED, self::validator()->check( new FakeProduct( 'simple', [ 'id' => 90 ] ), $change )[0]->state(), 'The current user, who has no rights here, is not the recorded user.' );
	}

	public function test_a_variation_needs_edit_rights_on_its_parent_too(): void {
		// The variation's own author may differ from its parent's; saving it resyncs the parent.
		$GLOBALS['bcm_test']['caps']['edit_post:50'] = false;
		$variation                                   = new FakeProduct(
			'variation',
			[
				'id'            => 60,
				'parent_id'     => 50,
				'regular_price' => '10.00',
			]
		);
		$change                                      = Result::changed( 'regular_price', '10.00', '12.00' );

		$refused = self::validator()->check( $variation, $change )[0];
		$this->assertSame( [ Result::SKIPPED, 'bcm_cannot_edit', self::CANNOT_EDIT ], [ $refused->state(), $refused->code(), $refused->reason() ] );

		$GLOBALS['bcm_test']['caps']['edit_post:50'] = true;
		$this->assertSame( Result::CHANGED, self::validator()->check( $variation, $change )[0]->state() );

		// In background work, against the batch's recorded user.
		$GLOBALS['bcm_test']['caps']      = [];
		$GLOBALS['bcm_test']['user_caps'] = [
			5 => [
				'edit_post'    => true,
				'edit_post:50' => false,
			],
		];
		$this->assertSame( Result::SKIPPED, Validator::for_user( new Fields(), 5 )->check( $variation, $change )[0]->state() );
		$GLOBALS['bcm_test']['user_caps'][5]['edit_post:50'] = true;
		$this->assertSame( Result::CHANGED, Validator::for_user( new Fields(), 5 )->check( $variation, $change )[0]->state() );
	}

	public function test_a_term_change_needs_the_taxonomy_assign_capability(): void {
		$product = new FakeProduct(
			'simple',
			[
				'id'           => 70,
				'category_ids' => [],
				'tag_ids'      => [],
			]
		);
		$cases   = [
			'categories' => Result::changed( 'category_ids', [], [ 5 ], true ),
			'tags'       => Result::changed( 'tag_ids', [], [ 6 ], true ),
		];
		$GLOBALS['bcm_test']['caps']['assign_product_terms'] = false;
		foreach ( $cases as $case => $change ) {
			$refused = self::validator()->check( $product, $change )[0];
			$this->assertSame( [ Result::SKIPPED, 'bcm_cannot_edit' ], [ $refused->state(), $refused->code() ], $case );
			$this->assertStringContainsString( 'permission', $refused->reason(), $case );
			$this->assertStringNotContainsString( 'bcm_', $refused->reason(), $case );
		}
		$this->assertSame( Result::ALREADY, self::validator()->check( $product, Result::already( 'category_ids', [] ) )[0]->state(), 'Only a change needs it.' );

		$GLOBALS['bcm_test']['caps']['assign_product_terms'] = true;
		foreach ( $cases as $case => $change ) {
			$this->assertSame( Result::CHANGED, self::validator()->check( $product, $change )[0]->state(), $case );
		}

		// In background work, against the batch's recorded user.
		$GLOBALS['bcm_test']['caps']      = [];
		$GLOBALS['bcm_test']['user_caps'] = [ 5 => [ 'edit_post' => true ] ];
		$this->assertSame( Result::SKIPPED, Validator::for_user( new Fields(), 5 )->check( $product, $cases['categories'] )[0]->state() );
		$GLOBALS['bcm_test']['user_caps'][5]['assign_product_terms'] = true;
		$this->assertSame( Result::CHANGED, Validator::for_user( new Fields(), 5 )->check( $product, $cases['categories'] )[0]->state() );
	}

	public function test_the_verdict_agrees_with_the_field_write_for_every_refused_change(): void {
		$checked = 0;
		foreach ( self::refused_cases() as $case => [ $type, $props, $change, $args, $state, $code ] ) {
			$field   = ( new Fields() )->get( $change->field() );
			$product = new FakeProduct( $type, $props );
			$verdict = self::validator()->check( $product, $change, $args )[0];

			$refusal = $field->refusal( $product, $change->new() );
			$this->assertSame( [], $product->sets, "$case: the dry run sets nothing." );
			$written = $field->write( $product, $change->new() );

			if ( null === $refusal ) {
				$this->assertFalse( $written instanceof \WP_Error, "$case: a cross-field rule, which the field alone allows." );
				continue;
			}
			$this->assertInstanceOf( \WP_Error::class, $written, $case );
			$this->assertSame( [ $refusal->get_error_code(), $refusal->get_error_message() ], [ $written->get_error_code(), $written->get_error_message() ], "$case: refusal() is write()'s refusal." );
			if ( 'stock quantity on unmanaged variation stock' === $case ) {
				// T70, SPEC §8: the one refusal the Validator words for itself, naming the variation.
				$this->assertSame( [ $written->get_error_code(), Validator::unmanaged_stock_reason() ], [ $verdict->code(), $verdict->reason() ], $case );
			} else {
				$this->assertSame( [ $written->get_error_code(), $written->get_error_message() ], [ $verdict->code(), $verdict->reason() ], "$case: the Validator says what the write says." );
			}
			$this->assertSame( $code, $verdict->code(), $case );
			$this->assertSame( [], $product->sets, "$case: a refused write sets nothing." );
			++$checked;
		}
		$this->assertSame( 10, $checked, 'Every registry refusal in the table was compared.' );
	}

	public function test_field_refusal_is_null_exactly_when_write_sets_the_value(): void {
		$fields = new Fields();
		foreach ( [
			[ 'regular_price', 'simple', [], '10' ],
			[ 'sale_price', 'variation', [], '' ],
			[ 'sku', 'simple', [ 'id' => 99 ], 'SHOE-42' ],
			[ 'stock_quantity', 'simple', [ 'manage_stock' => true ], '3' ],
			[ 'units_sold', 'simple', [], 3 ],
			[ 'tax_class', 'simple', [], 'parent' ],
		] as [ $key, $type, $props, $value ] ) {
			$product = new FakeProduct( $type, $props );
			$refusal = $fields->get( $key )->refusal( $product, $value );
			$this->assertSame( [], $product->sets, "$key: the dry run sets nothing." );
			$written = $fields->get( $key )->write( $product, $value );
			$this->assertSame( null === $refusal, ! $written instanceof \WP_Error, $key );
			$this->assertSame( null === $refusal ? 1 : 0, count( $product->sets ), $key );
		}
	}
}
