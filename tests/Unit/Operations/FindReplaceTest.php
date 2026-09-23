<?php
/**
 * Find and replace (T25; SPEC §5.4, §6, §13): plain text only on name and
 * SKU, table-driven over empty, zero, accented, multibyte and metacharacter
 * needles, whole word and case sensitivity both ways; no match is already;
 * the needle never becomes a pattern and the replacement is literal; a
 * renamed product keeps its slug; a name passes wp_filter_post_kses for a
 * user without unfiltered_html; a field the object refuses fails with its
 * reason.
 *
 * Integration (pending wp-env): a product renamed through the writer keeps
 * its post_name and permalink.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\FindReplace;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class FindReplaceTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function operation(): Operation {
		return ( new Registry( new Fields() ) )->get( 'find_replace' );
	}

	/**
	 * Normalised arguments, asserting they validate.
	 */
	private function args( Operation $operation, string $field, string $find, string $replace, bool $whole_word = false, bool $case_sensitive = false ): array {
		$validation = $operation->validate(
			[
				'field'          => $field,
				'find'           => $find,
				'replace'        => $replace,
				'whole_word'     => $whole_word,
				'case_sensitive' => $case_sensitive,
			]
		);
		$this->assertTrue( $validation->ok(), "find_replace refused '$find' on $field." );
		return $validation->args();
	}

	/**
	 * [ field, text held, find, replace, whole word, case sensitive, expected text or null for already ].
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: bool, 6: string|null}>
	 */
	private static function table(): array {
		return [
			// Plain.
			[ 'name', 'Blue shirt', 'shirt', 'top', false, false, 'Blue top' ],
			[ 'name', 'Blue shirt', 'jumper', 'top', false, false, null ],
			[ 'name', 'Blue shirt blue', 'blue', 'red', false, false, 'red shirt red' ],
			// Case sensitivity, both ways.
			[ 'name', 'Blue Shirt', 'shirt', 'top', false, true, null ],
			[ 'name', 'Blue Shirt', 'shirt', 'top', false, false, 'Blue top' ],
			[ 'name', 'Shirt shirt SHIRT', 'shirt', 'x', false, true, 'Shirt x SHIRT' ],
			[ 'name', 'Shirt shirt SHIRT', 'shirt', 'x', false, false, 'x x x' ],
			[ 'name', 'blue shirt', 'shirt', 'Shirt', false, false, 'blue Shirt' ],
			// Whole word, both ways.
			[ 'name', 'Tshirt shirt shirts', 'shirt', 'top', true, false, 'Tshirt top shirts' ],
			[ 'name', 'Tshirt shirt shirts', 'shirt', 'top', false, false, 'Ttop top tops' ],
			[ 'name', 'Tshirts', 'shirt', 'top', true, false, null ],
			[ 'name', 'shirt_1 shirt', 'shirt', 'top', true, false, 'shirt_1 top' ],
			[ 'name', 'Shirt (shirt), shirt.', 'shirt', 'top', true, false, 'top (top), top.' ],
			// Whole word and case sensitive together.
			[ 'name', 'Shirt shirt Shirts', 'Shirt', 'Top', true, true, 'Top shirt Shirts' ],
			[ 'name', 'shirt', 'Shirt', 'Top', true, true, null ],
			// Zero: a needle or replacement of "0" is text, not empty.
			[ 'name', 'Size 10 pack 0', '0', 'O', false, false, 'Size 1O pack O' ],
			[ 'name', 'Size 10 pack 0', '0', 'O', true, false, 'Size 10 pack O' ],
			[ 'name', 'Pack of 2', '2', '0', false, false, 'Pack of 0' ],
			[ 'sku', 'A00', '0', '', false, false, 'A' ],
			// Accented letters: matched as letters, capitals folded, never split.
			[ 'name', 'Crème brûlée', 'brûlée', 'caramel', false, false, 'Crème caramel' ],
			[ 'name', 'CRÈME brûlée', 'crème', 'Cream', false, false, 'Cream brûlée' ],
			[ 'name', 'CRÈME brûlée', 'crème', 'Cream', false, true, null ],
			[ 'name', 'Crèmeé crème', 'crème', 'X', true, false, 'Crèmeé X' ],
			[ 'name', 'éclair', 'e', 'a', false, false, null ],
			[ 'name', 'Café', 'É', 'e', false, false, 'Cafe' ],
			[ 'name', 'Straße', 'SS', 'x', false, false, null ],
			[ 'name', '日本の茶', '本', '本当', false, false, '日本当の茶' ],
			[ 'name', '日本の茶 本', '本', 'x', true, false, '日本の茶 x' ],
			// Metacharacters are text; the replacement is literal.
			[ 'name', 'Price (was $5.00) [sale]', '(was $5.00)', '(now $4)', false, false, 'Price (now $4) [sale]' ],
			[ 'name', 'abc', '.*', 'x', false, false, null ],
			[ 'name', 'a.*b', '.*', '-', false, false, 'a-b' ],
			[ 'name', 'a.b', '.', '$0 \\1 ${1}', false, false, 'a$0 \\1 ${1}b' ],
			[ 'name', 'A/B#C', '/', '|', false, false, 'A|B#C' ],
			[ 'name', 'A/B#C', '#', '/', false, false, 'A/B/C' ],
			[ 'name', 'x^y$z', '^y$', '-', false, false, 'x-z' ],
			[ 'name', 'a\\d b', '\\d', 'digit', false, false, 'adigit b' ],
			[ 'name', 'a1 b', '\\d', 'digit', false, false, null ],
			[ 'name', '(a|b)', 'a|b', 'c', false, false, '(c)' ],
			[ 'name', 'ab', 'a|b', 'c', false, false, null ],
			[ 'sku', 'SHOE-42', 'shoe', 'BOOT', false, false, 'BOOT-42' ],
			[ 'sku', 'SHOE-42', 'SHOE', 'BOOT', true, true, 'BOOT-42' ],
			[ 'sku', 'SHOE42', 'SHOE', 'BOOT', true, true, null ],
			[ 'sku', 'A+B', '+', '-', false, false, 'A-B' ],
			// Removing the whole SKU clears it (a SKU may be empty).
			[ 'sku', 'OLD', 'OLD', '', false, true, '' ],
		];
	}

	public function test_the_replacement_table(): void {
		$operation = self::operation();
		foreach ( self::table() as $index => [ $key, $held, $find, $replace, $whole, $case, $expected ] ) {
			$label   = sprintf( '#%d %s "%s": "%s" -> "%s"%s%s', $index, $key, $held, $find, $replace, $whole ? ' whole word' : '', $case ? ' case sensitive' : '' );
			$product = new FakeProduct( 'simple', [ $key => $held ] );
			$result  = $operation->compute( $product, $this->args( $operation, $key, $find, $replace, $whole, $case ) );
			$this->assertSame( $held, $result->old(), $label );
			if ( null === $expected ) {
				$this->assertSame( Result::ALREADY, $result->state(), $label );
				$this->assertSame( $held, $result->new(), $label );
			} else {
				$this->assertSame( Result::CHANGED, $result->state(), $label );
				$this->assertSame( $expected, $result->new(), $label );
			}
			$this->assertSame( [], $product->sets, "$label: compute() wrote." );
		}
	}

	public function test_an_empty_needle_is_refused_and_matches_nothing(): void {
		$operation = self::operation();
		$refused   = $operation->validate(
			[
				'field'   => 'name',
				'find'    => '',
				'replace' => 'x',
			]
		);
		$this->assertFalse( $refused->ok() );
		$this->assertSame( 'bcm_invalid_operation', $refused->error()->get_error_code() );

		$this->assertSame( 'Blue', FindReplace::replace( 'Blue', '', 'x', false, false ), 'An empty needle never inserts between characters.' );
		$result = $operation->compute(
			new FakeProduct( 'simple', [ 'name' => 'Blue' ] ),
			[
				'field'          => 'name',
				'find'           => '',
				'replace'        => 'x',
				'whole_word'     => false,
				'case_sensitive' => false,
				'skip_already'   => true,
			]
		);
		$this->assertSame( Result::ALREADY, $result->state() );
	}

	public function test_text_that_is_not_utf8_is_refused_or_failed(): void {
		$operation = self::operation();
		foreach ( [ 'find', 'replace' ] as $argument ) {
			$args = [
				'field'   => 'name',
				'find'    => 'a',
				'replace' => 'b',
			];

			$args[ $argument ] = "\xC3\x28";
			$this->assertFalse( $operation->validate( $args )->ok(), "Invalid UTF-8 in $argument." );
		}

		$product = new FakeProduct( 'simple', [ 'name' => "Caf\xC3\x28" ] );
		$result  = $operation->compute( $product, $this->args( $operation, 'name', 'Caf', 'Cafe' ) );
		$this->assertSame( [ Result::FAILED, 'bcm_invalid_value' ], [ $result->state(), $result->code() ] );
		$this->assertStringContainsString( 'Name', $result->reason() );
		$this->assertSame( [], $product->sets );
	}

	public function test_a_renamed_product_keeps_its_slug(): void {
		$operation = self::operation();
		$product   = new FakeProduct(
			'simple',
			[
				'name' => 'Blue shirt',
				'slug' => 'blue-shirt',
			]
		);
		$result    = $operation->compute( $product, $this->args( $operation, 'name', 'shirt', 'top' ) );
		$operation->apply( $result, $product );

		$this->assertSame( [ [ 'set_name', 'Blue top' ] ], $product->sets, 'Only the name setter is called.' );
		$this->assertSame( 'blue-shirt', $product->props['slug'] );
		$name = ( new Fields() )->get( 'name' );
		$this->assertSame( [ 'post', 'post_title', 'set_name' ], [ $name->storage()->kind(), $name->storage()->key(), $name->setter() ], 'The name field writes post_title only, never post_name.' );
	}

	public function test_a_name_written_without_unfiltered_html_passes_the_post_html_filter(): void {
		$operation = self::operation();
		$held      = 'Blue shirt';
		$replace   = '<script>x()</script><em>top</em>';

		$result = $operation->compute( new FakeProduct( 'simple', [ 'name' => $held ] ), $this->args( $operation, 'name', 'shirt', $replace ) );
		$this->assertSame( stripslashes( wp_filter_post_kses( addslashes( 'Blue ' . $replace ) ) ), $result->new() );
		$this->assertSame( 'Blue x()<em>top</em>', $result->new() );

		$GLOBALS['bcm_test']['caps']['unfiltered_html'] = true;
		$trusted                                        = $operation->compute( new FakeProduct( 'simple', [ 'name' => $held ] ), $this->args( $operation, 'name', 'shirt', $replace ) );
		$this->assertSame( 'Blue ' . $replace, $trusted->new() );

		// A replacement the filter reduces to the text already held is already.
		$GLOBALS['bcm_test']['caps'] = [];
		$same                        = $operation->compute( new FakeProduct( 'simple', [ 'name' => 'Blue x' ] ), $this->args( $operation, 'name', 'x', '<script></script>x', false, true ) );
		$this->assertSame( Result::ALREADY, $same->state() );
		$this->assertSame( 'Blue x', $same->new() );
	}

	public function test_a_result_the_field_or_object_refuses_is_failed_with_its_reason(): void {
		$operation = self::operation();

		$empty = $operation->compute( new FakeProduct( 'simple', [ 'name' => 'Shirt' ] ), $this->args( $operation, 'name', 'Shirt', '', false, true ) );
		$this->assertSame( [ Result::FAILED, 'bcm_invalid_value', 'Shirt' ], [ $empty->state(), $empty->code(), $empty->new() ] );
		$this->assertSame( ( new Fields() )->get( 'name' )->rule_text(), $empty->reason(), 'A name cannot become empty.' );

		$long = $operation->compute( new FakeProduct( 'simple', [ 'sku' => str_repeat( 'A', 100 ) ] ), $this->args( $operation, 'sku', 'A', 'BB' ) );
		$this->assertSame( [ Result::FAILED, 'bcm_invalid_value' ], [ $long->state(), $long->code() ], 'A SKU over 100 characters.' );

		$variation = new FakeProduct( 'variation', [ 'name' => 'Shirt - Blue' ] );
		$derived   = $operation->compute( $variation, $this->args( $operation, 'name', 'Shirt', 'Top' ) );
		$this->assertSame( [ Result::FAILED, 'bcm_not_available' ], [ $derived->state(), $derived->code() ] );
		$this->assertSame( 'A variation takes its name from its product and its attributes, so it cannot be changed here.', $derived->reason() );
		$operation->apply( $derived, $variation );
		$this->assertSame( [], $variation->sets );

		$sku = $operation->compute( new FakeProduct( 'variation', [ 'sku' => 'SHIRT-B' ] ), $this->args( $operation, 'sku', 'SHIRT', 'TOP' ) );
		$this->assertSame( [ Result::CHANGED, 'TOP-B' ], [ $sku->state(), $sku->new() ], 'A variation has its own SKU.' );
	}
}
