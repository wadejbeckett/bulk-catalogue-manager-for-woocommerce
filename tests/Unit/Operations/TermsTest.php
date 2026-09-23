<?php
/**
 * Category and tag operations (T27; SPEC §6, §8, §12.3): add, remove and
 * replace compute the whole resulting list from the object's current terms,
 * so add and remove touch only the named terms; an unchanged list is
 * already correct; replace is marked for the preview's bold line; a term
 * not in the field's taxonomy fails the row; more than 100 terms is refused;
 * no ancestor is added; a variation fails with the §8 reason; apply() hands
 * wp_set_object_terms() exactly the computed list, the field's taxonomy and
 * append off, then keeps the object in step. The integration check (an
 * unrelated tag and custom taxonomy left intact on a real site) waits on
 * wp-env.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Terms;
use BulkCatalogueManager\Operations\WriteRefused;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class TermsTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat' => [ 11, 12, 13, 14 ],
			'product_tag' => [ 21, 22, 23 ],
		];
	}

	private static function action( string $mode ): Terms {
		return new Terms( new Fields(), $mode );
	}

	/**
	 * Validated arguments for a mode.
	 *
	 * @param string $mode  Action id.
	 * @param string $field Field key.
	 * @param array  $terms Term ids as submitted.
	 */
	private function args( string $mode, string $field, array $terms ): array {
		$validation = self::action( $mode )->validate(
			[
				'field' => $field,
				'terms' => $terms,
			]
		);
		$this->assertTrue( $validation->ok(), "$mode on $field with " . implode( ',', $terms ) . ' was refused.' );
		return $validation->args();
	}

	private static function product( array $categories = [ 11, 12 ], array $tags = [ 21 ], string $type = 'simple' ): FakeProduct {
		return new FakeProduct(
			$type,
			[
				'id'           => 7,
				'category_ids' => $categories,
				'tag_ids'      => $tags,
			]
		);
	}

	/**
	 * Compute one mode on a product and assert nothing was written.
	 *
	 * @param string      $mode    Action id.
	 * @param string      $field   Field key.
	 * @param array       $terms   Term ids.
	 * @param FakeProduct $product Subject.
	 */
	private function compute( string $mode, string $field, array $terms, FakeProduct $product ): Result {
		$result = self::action( $mode )->compute( $product, $this->args( $mode, $field, $terms ) );
		$this->assertSame( [], $product->sets, 'compute() wrote to the product.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [], 'compute() wrote terms.' );
		return $result;
	}

	public function test_add_appends_new_terms_in_order_and_leaves_the_rest(): void {
		$result = $this->compute( Terms::ADD, 'category_ids', [ 14, 11, 13 ], self::product() );
		$this->assertSame( Result::CHANGED, $result->state() );
		$this->assertSame( 'category_ids', $result->field() );
		$this->assertSame( [ 11, 12 ], $result->old() );
		$this->assertSame( [ 11, 12, 14, 13 ], $result->new(), 'Current terms keep their order; new ones follow in the order given.' );
		$this->assertFalse( $result->replaces() );

		$tags = $this->compute( Terms::ADD, 'tag_ids', [ 22 ], self::product() );
		$this->assertSame( [ 21, 22 ], $tags->new() );

		$empty = $this->compute( Terms::ADD, 'tag_ids', [ 23 ], self::product( [ 11 ], [] ) );
		$this->assertSame( [], $empty->old() );
		$this->assertSame( [ 23 ], $empty->new() );
	}

	public function test_add_adds_no_ancestor(): void {
		// 13 stands for a child category; its parent is never added implicitly.
		$result = $this->compute( Terms::ADD, 'category_ids', [ 13 ], self::product( [], [] ) );
		$this->assertSame( [ 13 ], $result->new() );
	}

	public function test_adding_terms_already_there_is_already(): void {
		$result = $this->compute( Terms::ADD, 'category_ids', [ 12, 11 ], self::product() );
		$this->assertSame( Result::ALREADY, $result->state() );
		$this->assertSame( [ 11, 12 ], $result->old() );
		$this->assertSame( [ 11, 12 ], $result->new() );
	}

	public function test_remove_takes_only_the_named_terms(): void {
		$result = $this->compute( Terms::REMOVE, 'category_ids', [ 12 ], self::product( [ 11, 12, 13 ] ) );
		$this->assertSame( Result::CHANGED, $result->state() );
		$this->assertSame( [ 11, 13 ], $result->new() );
		$this->assertFalse( $result->replaces() );

		$all = $this->compute( Terms::REMOVE, 'tag_ids', [ 21, 22 ], self::product( [ 11 ], [ 22, 21 ] ) );
		$this->assertSame( [], $all->new() );

		$partly = $this->compute( Terms::REMOVE, 'tag_ids', [ 22, 23 ], self::product( [ 11 ], [ 21, 22 ] ) );
		$this->assertSame( [ 21 ], $partly->new(), 'An absent term among the named ones is ignored.' );
	}

	public function test_removing_absent_terms_is_already(): void {
		$result = $this->compute( Terms::REMOVE, 'category_ids', [ 13, 14 ], self::product() );
		$this->assertSame( Result::ALREADY, $result->state() );
		$this->assertSame( [ 11, 12 ], $result->new() );

		$none = $this->compute( Terms::REMOVE, 'tag_ids', [ 21 ], self::product( [ 11 ], [] ) );
		$this->assertSame( Result::ALREADY, $none->state() );
	}

	public function test_replace_sets_exactly_the_list_and_is_marked(): void {
		$result = $this->compute( Terms::REPLACE, 'category_ids', [ 14, 12 ], self::product() );
		$this->assertSame( Result::CHANGED, $result->state() );
		$this->assertSame( [ 11, 12 ], $result->old() );
		$this->assertSame( [ 14, 12 ], $result->new() );
		$this->assertTrue( $result->replaces(), 'The preview states a replace in bold.' );
		$this->assertTrue( $result->to_array()['replaces'] );
	}

	public function test_replacing_with_the_same_terms_in_any_order_is_already(): void {
		foreach ( [ [ 11, 12 ], [ 12, 11 ], [ 12, 11, 12 ] ] as $terms ) {
			$result = $this->compute( Terms::REPLACE, 'category_ids', $terms, self::product() );
			$this->assertSame( Result::ALREADY, $result->state(), implode( ',', $terms ) );
			$this->assertFalse( $result->replaces() );
		}
	}

	public function test_duplicates_are_dropped_keeping_the_first(): void {
		$args = $this->args( Terms::ADD, 'category_ids', [ 13, '14', 13, 14 ] );
		$this->assertSame( [ 13, 14 ], $args['terms'] );

		$result = self::action( Terms::ADD )->compute( self::product( [ 12, 11, 12 ] ), $args );
		$this->assertSame( [ 12, 11 ], $result->old(), 'A duplicated current term is read once.' );
		$this->assertSame( [ 12, 11, 13, 14 ], $result->new() );

		$replace = $this->compute( Terms::REPLACE, 'tag_ids', [ 23, 21, 23 ], self::product() );
		$this->assertSame( [ 23, 21 ], $replace->new() );
	}

	public function test_at_most_one_hundred_terms_in_one_operation(): void {
		$GLOBALS['bcm_test']['terms']['product_cat'] = range( 1, 120 );
		foreach ( Terms::MODES as $mode ) {
			$this->assertTrue(
				self::action( $mode )->validate(
					[
						'field' => 'category_ids',
						'terms' => range( 1, 100 ),
					]
				)->ok(),
				"$mode refused 100 terms."
			);

			$refused = self::action( $mode )->validate(
				[
					'field' => 'category_ids',
					'terms' => range( 1, 101 ),
				]
			);
			$this->assertFalse( $refused->ok(), "$mode took 101 terms." );
			$this->assertSame( 'bcm_invalid_operation', $refused->error()->get_error_code() );
			$this->assertSame( 'terms', $refused->error()->get_error_data()['argument'] );
			$this->assertStringContainsString( '100', $refused->error()->get_error_message() );

			$empty = self::action( $mode )->validate(
				[
					'field' => 'category_ids',
					'terms' => [],
				]
			);
			$this->assertFalse( $empty->ok(), "$mode took no terms." );
		}

		// Adding may not take a product past the same limit.
		$result = self::action( Terms::ADD )->compute( self::product( range( 1, 95 ), [] ), $this->args( Terms::ADD, 'category_ids', range( 96, 110 ) ) );
		$this->assertSame( Result::FAILED, $result->state() );
		$this->assertSame( 'bcm_invalid_value', $result->code() );
		$this->assertStringContainsString( '100', $result->reason() );
		$this->assertSame( range( 1, 95 ), $result->new(), 'A failed row keeps its list.' );

		$fits = self::action( Terms::ADD )->compute( self::product( range( 1, 95 ), [] ), $this->args( Terms::ADD, 'category_ids', range( 96, 100 ) ) );
		$this->assertSame( range( 1, 100 ), $fits->new() );
	}

	public function test_a_term_outside_the_fields_taxonomy_is_rejected(): void {
		foreach ( Terms::MODES as $mode ) {
			$wrong = self::action( $mode )->validate(
				[
					'field' => 'category_ids',
					'terms' => [ 11, 21 ],
				]
			);
			$this->assertFalse( $wrong->ok(), "$mode took a tag as a category." );
			$this->assertSame( 'bcm_invalid_operation', $wrong->error()->get_error_code() );

			$missing = self::action( $mode )->validate(
				[
					'field' => 'tag_ids',
					'terms' => [ 99 ],
				]
			);
			$this->assertFalse( $missing->ok(), "$mode took a term that does not exist." );
		}

		// Deleted after the form was validated: that row fails with the id.
		foreach ( Terms::MODES as $mode ) {
			$args                                        = $this->args( $mode, 'category_ids', [ 12, 14 ] );
			$GLOBALS['bcm_test']['terms']['product_cat'] = [ 11, 12, 13 ];
			$product                                     = self::product();
			$result                                      = self::action( $mode )->compute( $product, $args );
			$this->assertSame( Result::FAILED, $result->state(), $mode );
			$this->assertSame( 'bcm_invalid_value', $result->code() );
			$this->assertStringContainsString( 'ID 14', $result->reason() );
			$this->assertStringStartsWith( 'Categories', $result->reason() );
			$this->assertSame( [ 11, 12 ], $result->old() );
			$this->assertSame( [], $product->sets );
			$GLOBALS['bcm_test']['terms']['product_cat'] = [ 11, 12, 13, 14 ];
		}

		// The taxonomy is the field's: a tag id checked against product_tag only.
		$args                                        = $this->args( Terms::ADD, 'tag_ids', [ 22 ] );
		$GLOBALS['bcm_test']['terms']['product_tag'] = [];
		$GLOBALS['bcm_test']['terms']['product_cat'] = [ 11, 12, 13, 14, 22 ];
		$this->assertSame( Result::FAILED, self::action( Terms::ADD )->compute( self::product(), $args )->state() );
	}

	public function test_a_variation_is_refused_with_its_reason(): void {
		$cases = [
			'category_ids' => [ [ 13 ], 'Categories belong to the product, not to each variation.' ],
			'tag_ids'      => [ [ 22 ], 'Tags belong to the product, not to each variation.' ],
		];
		foreach ( Terms::MODES as $mode ) {
			foreach ( $cases as $field => [ $terms, $reason ] ) {
				$variation = self::product( [ 11 ], [ 21 ], 'variation' );
				$result    = self::action( $mode )->compute( $variation, $this->args( $mode, $field, $terms ) );
				$this->assertSame( Result::FAILED, $result->state(), "$mode $field" );
				$this->assertSame( $reason, $result->reason() );
				$this->assertSame( 'bcm_not_available', $result->code() );
				$this->assertSame( [], $variation->sets );
			}
		}

		$variation = self::product( [ 11 ], [ 21 ], 'variation' );
		try {
			self::action( Terms::ADD )->apply( Result::changed( 'category_ids', [ 11 ], [ 11, 13 ] ), $variation );
			$this->fail( 'Categories were written to a variation.' );
		} catch ( WriteRefused $e ) {
			$this->assertSame( 'bcm_not_available', $e->error()->get_error_code() );
			$this->assertSame( 'Categories belong to the product, not to each variation.', $e->error()->get_error_message() );
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [] );
		$this->assertSame( [], $variation->sets );
	}

	public function test_apply_writes_the_computed_list_through_wp_set_object_terms(): void {
		$cases = [
			[ Terms::ADD, 'category_ids', [ 13 ], [ 11, 12, 13 ], 'product_cat', 'set_category_ids' ],
			[ Terms::REMOVE, 'category_ids', [ 11 ], [ 12 ], 'product_cat', 'set_category_ids' ],
			[ Terms::REMOVE, 'tag_ids', [ 21 ], [], 'product_tag', 'set_tag_ids' ],
			[ Terms::REPLACE, 'tag_ids', [ 23, 22 ], [ 23, 22 ], 'product_tag', 'set_tag_ids' ],
		];
		foreach ( $cases as [ $mode, $field, $terms, $expected, $taxonomy, $setter ] ) {
			$GLOBALS['bcm_test']['set_object_terms'] = [];
			$product                                 = self::product();
			$action                                  = self::action( $mode );
			$result                                  = $action->compute( $product, $this->args( $mode, $field, $terms ) );
			$this->assertSame( $expected, $result->new() );

			$action->apply( $result, $product );
			$this->assertSame( [ [ 7, $expected, $taxonomy, false ] ], $GLOBALS['bcm_test']['set_object_terms'], "$mode $field: one call, full list, append off." );
			$this->assertSame( [ [ $setter, $expected ] ], $product->sets, 'The object is kept in step through the field setter.' );
		}
	}

	public function test_apply_passes_integers_never_names(): void {
		$product = self::product();
		self::action( Terms::REPLACE )->apply( Result::changed( 'category_ids', [ 11, 12 ], [ '13', '14' ] ), $product );
		$this->assertSame( [ 13, 14 ], $GLOBALS['bcm_test']['set_object_terms'][0][1] );
	}

	public function test_a_later_step_computes_from_the_applied_list(): void {
		$product = self::product();
		$add     = self::action( Terms::ADD );
		$add->apply( $add->compute( $product, $this->args( Terms::ADD, 'category_ids', [ 13 ] ) ), $product );

		$remove = self::action( Terms::REMOVE );
		$result = $remove->compute( $product, $this->args( Terms::REMOVE, 'category_ids', [ 11 ] ) );
		$this->assertSame( [ 11, 12, 13 ], $result->old() );
		$this->assertSame( [ 12, 13 ], $result->new() );
	}

	public function test_apply_writes_nothing_but_a_change(): void {
		$product = self::product();
		$action  = self::action( Terms::ADD );
		foreach ( [ Result::already( 'category_ids', [ 11, 12 ] ), Result::skipped( 'category_ids', [ 11, 12 ], 'Skipped for a reason.' ), Result::failed( 'category_ids', [ 11, 12 ], 'Failed for a reason.' ) ] as $result ) {
			$action->apply( $result, $product );
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [] );
		$this->assertSame( [], $product->sets );

		try {
			$action->apply( Result::changed( 'regular_price', '10.00', '12.00' ), $product );
			$this->fail( 'A term action applied a price.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'regular_price', $e->getMessage() );
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [] );
	}

	public function test_apply_refuses_a_term_deleted_since_the_preview(): void {
		$product                                     = self::product();
		$action                                      = self::action( Terms::ADD );
		$result                                      = $action->compute( $product, $this->args( Terms::ADD, 'category_ids', [ 14 ] ) );
		$GLOBALS['bcm_test']['terms']['product_cat'] = [ 11, 12, 13 ];
		try {
			$action->apply( $result, $product );
			$this->fail( 'A deleted term was written.' );
		} catch ( WriteRefused $e ) {
			$this->assertSame( 'bcm_invalid_value', $e->error()->get_error_code() );
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [] );
		$this->assertSame( [], $product->sets );
	}

	public function test_a_failed_term_write_is_refused_and_the_object_left_alone(): void {
		$GLOBALS['bcm_test']['set_object_terms_error'] = new \WP_Error( 'db_insert_error', 'Could not insert term relationship into the database.' );
		$product                                       = self::product();
		$action                                        = self::action( Terms::ADD );
		try {
			$action->apply( $action->compute( $product, $this->args( Terms::ADD, 'tag_ids', [ 22 ] ) ), $product );
			$this->fail( 'A failed write passed.' );
		} catch ( WriteRefused $e ) {
			$this->assertSame( 'Tags could not be saved on this product. Try again in a moment.', $e->error()->get_error_message() );
			$this->assertSame( 400, $e->error()->get_error_data()['status'] );
		}
		$this->assertSame( [], $product->sets );
	}
}
