<?php
/**
 * Prefix or suffix (T25; SPEC §6): text added at the chosen end of each SKU,
 * multibyte-safe; a SKU already carrying the text is already, so a batch run
 * twice never doubles it; an object with no SKU is skipped with a reason; a
 * result over the field's length fails with the field's rule; compute()
 * writes nothing and apply() writes only the SKU.
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

final class AffixSkuTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function operation(): Operation {
		return ( new Registry( new Fields() ) )->get( 'affix_sku' );
	}

	private function args( Operation $operation, string $position, string $text ): array {
		$validation = $operation->validate(
			[
				'field'    => 'sku',
				'position' => $position,
				'text'     => $text,
			]
		);
		$this->assertTrue( $validation->ok(), "affix_sku refused $position '$text'." );
		return $validation->args();
	}

	public function test_the_affix_table(): void {
		$operation = self::operation();
		// [ SKU held, position, text, expected SKU or null for already ].
		$cases = [
			[ 'SHOE-42', 'prefix', 'NEW-', 'NEW-SHOE-42' ],
			[ 'SHOE-42', 'suffix', '-B', 'SHOE-42-B' ],
			[ 'NEW-SHOE-42', 'prefix', 'NEW-', null ],
			[ 'SHOE-42-B', 'suffix', '-B', null ],
			[ 'NEW-1', 'prefix', 'new-', 'new-NEW-1' ],
			[ 'SHOE-42', 'suffix', 'NEW-', 'SHOE-42NEW-' ],
			[ '0', 'prefix', '0', null ],
			[ '0', 'suffix', '1', '01' ],
			[ 'CAFÉ-1', 'suffix', '-É', 'CAFÉ-1-É' ],
			[ '茶-1', 'prefix', '日本-', '日本-茶-1' ],
			[ 'A1', 'prefix', '$1.*', '$1.*A1' ],
		];
		foreach ( $cases as [ $held, $position, $text, $expected ] ) {
			$label   = "$position '$text' on '$held'";
			$product = new FakeProduct( 'simple', [ 'sku' => $held ] );
			$result  = $operation->compute( $product, $this->args( $operation, $position, $text ) );
			$this->assertSame( $held, $result->old(), $label );
			$this->assertSame( null === $expected ? Result::ALREADY : Result::CHANGED, $result->state(), $label );
			$this->assertSame( $expected ?? $held, $result->new(), $label );
			$this->assertSame( [], $product->sets, "$label: compute() wrote." );

			$operation->apply( $result, $product );
			$this->assertSame( null === $expected ? [] : [ [ 'set_sku', $expected ] ], $product->sets, $label );
		}
	}

	public function test_an_object_with_no_sku_is_skipped_with_a_reason(): void {
		$operation = self::operation();
		foreach ( [ '', null ] as $held ) {
			$product = new FakeProduct( 'variation', [ 'sku' => $held ] );
			$result  = $operation->compute( $product, $this->args( $operation, 'prefix', 'NEW-' ) );
			$this->assertSame( Result::SKIPPED, $result->state() );
			$this->assertSame( '', $result->new() );
			$this->assertSame( 'This product has no SKU to add to, so it was skipped. Give it one first.', $result->reason() );
			$operation->apply( $result, $product );
			$this->assertSame( [], $product->sets );
		}
	}

	public function test_a_sku_over_the_field_length_fails_with_the_field_rule(): void {
		$operation = self::operation();
		$product   = new FakeProduct( 'simple', [ 'sku' => str_repeat( 'A', 98 ) ] );
		$result    = $operation->compute( $product, $this->args( $operation, 'suffix', '-B1' ) );
		$this->assertSame( [ Result::FAILED, 'bcm_invalid_value' ], [ $result->state(), $result->code() ] );
		$this->assertSame( ( new Fields() )->get( 'sku' )->rule_text(), $result->reason() );

		$fits = $operation->compute( $product, $this->args( $operation, 'suffix', '-B' ) );
		$this->assertSame( Result::CHANGED, $fits->state(), 'Exactly 100 characters fits.' );
	}

	public function test_text_with_spaces_or_markup_is_refused_before_any_object_is_read(): void {
		$operation = self::operation();
		foreach ( [ ' NEW', 'NEW ', '<b>NEW</b>', '' ] as $text ) {
			$validation = $operation->validate(
				[
					'field'    => 'sku',
					'position' => 'prefix',
					'text'     => $text,
				]
			);
			$this->assertFalse( $validation->ok(), "'$text'" );
			$this->assertSame( 'bcm_invalid_operation', $validation->error()->get_error_code() );
		}
	}
}
