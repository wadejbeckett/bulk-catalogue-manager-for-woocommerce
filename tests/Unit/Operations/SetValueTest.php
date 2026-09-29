<?php
/**
 * Set value (T25; SPEC §5.2, §5.4, §6, §13): every field the action accepts
 * computes a change to the field's canonical value and, once applied, reads
 * back as already; the same value is already (10 and 10.00 are one price);
 * a field the object refuses is failed with the field's own sentence; a name
 * written without unfiltered_html passes wp_filter_post_kses; compute()
 * writes nothing and apply() writes only through the field's setter.
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

final class SetValueTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['terms'] = [ 'product_shipping_class' => [ 31 ] ];
	}

	private static function operation(): Operation {
		return ( new Registry( new Fields() ) )->get( 'set_value' );
	}

	/**
	 * Normalised arguments for a field and value, asserting they validate.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function args( Operation $operation, string $field, mixed $value ): array {
		$validation = $operation->validate(
			[
				'field' => $field,
				'value' => $value,
			]
		);
		$this->assertTrue( $validation->ok(), "set_value refused $field." );
		return $validation->args();
	}

	/**
	 * Every accepted field: [ object type, props, submitted value, old as read, new canonical ].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed, 3: mixed, 4: mixed}>
	 */
	private static function every_field(): array {
		return [
			'name'               => [ 'simple', [ 'name' => 'Blue shirt' ], ' Red shirt ', 'Blue shirt', 'Red shirt' ],
			'status'             => [ 'simple', [ 'status' => 'draft' ], 'publish', 'draft', 'publish' ],
			'catalog_visibility' => [ 'simple', [ 'catalog_visibility' => 'visible' ], 'hidden', 'visible', 'hidden' ],
			'visible_on_site'    => [ 'simple', [ 'catalog_visibility' => 'catalog' ], 'on', 'partly', 'on' ],
			'featured'           => [ 'simple', [ 'featured' => false ], true, false, true ],
			'regular_price'      => [ 'simple', [ 'regular_price' => '10' ], '12.5', '10', '12.50' ],
			'sale_price'         => [ 'simple', [ 'sale_price' => '' ], '8', '', '8.00' ],
			'stock_status'       => [ 'simple', [ 'stock_status' => 'instock' ], 'outofstock', 'instock', 'outofstock' ],
			'manage_stock'       => [ 'simple', [ 'manage_stock' => false ], 'yes', false, true ],
			'stock_quantity'     => [
				'simple',
				[
					'manage_stock'   => true,
					'stock_quantity' => 5,
				],
				'-3',
				5,
				-3,
			],
			'sku'                => [ 'simple', [ 'sku' => 'A-1' ], 'B-2', 'A-1', 'B-2' ],
			'weight'             => [ 'simple', [ 'weight' => '1.5' ], '2', '1.5', '2' ],
			'length'             => [ 'variation', [ 'length' => '' ], '10', '', '10' ],
			'width'              => [ 'simple', [ 'width' => '' ], '0', '', '0' ],
			'height'             => [ 'simple', [ 'height' => '3' ], '4.25', '3', '4.25' ],
			'shipping_class_id'  => [ 'simple', [ 'shipping_class_id' => 0 ], 31, 0, 31 ],
			'tax_status'         => [ 'simple', [ 'tax_status' => 'taxable' ], 'none', 'taxable', 'none' ],
			'tax_class'          => [ 'variation', [ 'tax_class' => '' ], 'parent', '', 'parent' ],
			'sold_individually'  => [ 'simple', [ 'sold_individually' => false ], true, false, true ],
			'reviews_allowed'    => [ 'simple', [ 'reviews_allowed' => true ], false, true, false ],
		];
	}

	public function test_every_accepted_field_is_set_then_reads_back_as_already(): void {
		$operation = self::operation();
		$table     = self::every_field();
		$this->assertSame( $operation->describe()->fields(), array_keys( $table ), 'The table covers every field set_value accepts, in registry order.' );

		foreach ( $table as $key => [ $type, $props, $value, $old, $new ] ) {
			$product = new FakeProduct( $type, $props );
			$args    = $this->args( $operation, $key, $value );
			$result  = $operation->compute( $product, $args );

			$this->assertSame( Result::CHANGED, $result->state(), $key );
			$this->assertSame( $key, $result->field() );
			$this->assertSame( $old, $result->old(), "$key old" );
			$this->assertSame( $new, $result->new(), "$key new" );
			$this->assertSame( [], $product->sets, "$key: compute() wrote to the object." );

			$operation->apply( $result, $product );
			$this->assertCount( 1, $product->sets, "$key: apply() writes once, through the setter." );

			$again = $operation->compute( $product, $args );
			$this->assertSame( Result::ALREADY, $again->state(), "$key after apply" );
			$this->assertSame( $new, $again->new() );
		}
	}

	public function test_status_on_a_product_goes_through_the_switch_and_on_a_variation_does_not(): void {
		// T89 (SPEC §5.5): Draft on a published product switches it Off and saves its status.
		$operation = self::operation();
		$args      = $this->args( $operation, 'status', 'draft' );
		$product   = new FakeProduct(
			'simple',
			[
				'id'     => 40,
				'status' => 'publish',
			]
		);
		$result    = $operation->compute( $product, $args );
		$this->assertSame( [ Result::CHANGED, 'publish', 'draft', 'off' ], [ $result->state(), $result->old(), $result->new(), $result->note() ] );
		$follow = $operation->follow( $product, $result, $args );
		$this->assertCount( 1, $follow );
		$this->assertSame( 'availability_off', $follow[0]->field() );
		$this->assertStringStartsWith( '{"id":40,"status":"publish","at":"', (string) $follow[0]->new() );
		$this->assertSame( [], $product->sets, 'compute() and follow() write nothing.' );
		$this->assertSame( [], $product->meta_calls );

		$variation = new FakeProduct(
			'variation',
			[
				'id'     => 41,
				'status' => 'publish',
			]
		);
		$private   = $operation->compute( $variation, $this->args( $operation, 'status', 'private' ) );
		$this->assertSame( [ Result::CHANGED, '' ], [ $private->state(), $private->note() ] );
		$this->assertSame( [], $operation->follow( $variation, $private, $this->args( $operation, 'status', 'private' ) ) );

		// Every other field follows with nothing.
		$price = $this->args( $operation, 'regular_price', '12' );
		$this->assertSame( [], $operation->follow( new FakeProduct( 'simple', [ 'regular_price' => '10' ] ), Result::changed( 'regular_price', '10', '12.00' ), $price ) );
	}

	public function test_keep_changed_status_is_kept_only_for_status(): void {
		$operation = self::operation();
		$status    = $operation->validate(
			[
				'field'               => 'status',
				'value'               => 'publish',
				'keep_changed_status' => true,
			]
		);
		$this->assertTrue( $status->ok() );
		$this->assertTrue( $status->args()['keep_changed_status'] );
		$this->assertFalse( $this->args( $operation, 'status', 'publish' )['keep_changed_status'], 'Off by default.' );

		// Asked for on another field it is refused; off, it is dropped.
		$refused = $operation->validate(
			[
				'field'               => 'regular_price',
				'value'               => '12',
				'keep_changed_status' => true,
			]
		);
		$this->assertFalse( $refused->ok() );
		$this->assertSame( 'bulkcat_invalid_operation', $refused->error()->get_error_code() );
		$this->assertStringContainsString( 'Status', $refused->error()->get_error_message() );
		$dropped = $operation->validate(
			[
				'field'               => 'regular_price',
				'value'               => '12',
				'keep_changed_status' => false,
			]
		);
		$this->assertTrue( $dropped->ok() );
		$this->assertArrayNotHasKey( 'keep_changed_status', $dropped->args() );
		$this->assertArrayNotHasKey( 'keep_changed_status', $this->args( $operation, 'sku', 'A-1' ) );
	}

	public function test_setting_the_value_already_held_is_already(): void {
		$operation = self::operation();
		$cases     = [
			[ 'regular_price', [ 'regular_price' => '10' ], '10.00' ],
			[ 'regular_price', [ 'regular_price' => '10.00' ], '10' ],
			[ 'sale_price', [ 'sale_price' => '' ], '' ],
			[ 'name', [ 'name' => 'Blue shirt' ], 'Blue shirt' ],
			[ 'featured', [ 'featured' => true ], 'yes' ],
			[ 'stock_status', [ 'stock_status' => 'instock' ], 'instock' ],
			[ 'visible_on_site', [ 'catalog_visibility' => 'hidden' ], 'off' ],
			[ 'sku', [ 'sku' => '' ], '' ],
			[ 'weight', [ 'weight' => '0' ], '0' ],
		];
		foreach ( $cases as [ $key, $props, $value ] ) {
			$product = new FakeProduct( 'simple', $props );
			$result  = $operation->compute( $product, $this->args( $operation, $key, $value ) );
			$this->assertSame( Result::ALREADY, $result->state(), "$key = " . wp_json_encode( $value ) );
			$this->assertSame( $result->old(), $result->new() );
			$this->assertSame( '', $result->reason() );
		}
	}

	public function test_empty_is_a_value_distinct_from_zero(): void {
		$operation = self::operation();

		$zero = $operation->compute( new FakeProduct( 'simple', [ 'sale_price' => '' ] ), $this->args( $operation, 'sale_price', '0' ) );
		$this->assertSame( [ Result::CHANGED, '', '0.00' ], [ $zero->state(), $zero->old(), $zero->new() ] );

		$empty = $operation->compute( new FakeProduct( 'simple', [ 'sale_price' => '0.00' ] ), $this->args( $operation, 'sale_price', '' ) );
		$this->assertSame( [ Result::CHANGED, '0.00', '' ], [ $empty->state(), $empty->old(), $empty->new() ] );

		$stock = $operation->compute(
			new FakeProduct(
				'simple',
				[
					'manage_stock'   => true,
					'stock_quantity' => 0,
				]
			),
			$this->args( $operation, 'stock_quantity', '' )
		);
		$this->assertSame( [ Result::CHANGED, 0, null ], [ $stock->state(), $stock->old(), $stock->new() ] );

		$refused = $operation->validate(
			[
				'field' => 'name',
				'value' => '',
			]
		);
		$this->assertFalse( $refused->ok(), 'A field that is not nullable cannot be set to empty.' );
	}

	public function test_a_field_the_object_refuses_is_failed_with_its_reason(): void {
		$operation = self::operation();
		$cases     = [
			[ 'featured', 'variation', [ 'featured' => false ], true, 'Featured belongs to the product, not to each variation.' ],
			[ 'name', 'variation', [ 'name' => 'Shirt - Blue' ], 'Shirt', 'A variation takes its name from its product and its attributes, so it cannot be changed here.' ],
			[ 'regular_price', 'variable', [ 'regular_price' => '' ], '10', 'A variable product takes its price from its variations. Change the variations instead.' ],
			[ 'stock_quantity', 'simple', [ 'manage_stock' => false ], '4', 'Stock quantity only applies where stock is managed, so it was not changed here. Turn on Manage stock first.' ],
			[ 'category_ids', 'simple', [], [ 1 ], null ],
		];
		foreach ( $cases as [ $key, $type, $props, $value, $reason ] ) {
			if ( null === $reason ) {
				$this->assertFalse( $operation->describe()->accepts( $key ), 'Term lists are replace_terms (T27).' );
				continue;
			}
			$product = new FakeProduct( $type, $props );
			$result  = $operation->compute( $product, $this->args( $operation, $key, $value ) );
			$this->assertSame( Result::FAILED, $result->state(), $key );
			$this->assertSame( $reason, $result->reason() );
			$this->assertSame( 'bulkcat_not_available', $result->code() );
			$this->assertSame( $result->old(), $result->new() );
			$operation->apply( $result, $product );
			$this->assertSame( [], $product->sets, "$key: a failed row is not written." );
		}

		// A value the field refuses at compute time (the arguments bypassing validation) fails with the field's rule.
		$bad = $operation->compute(
			new FakeProduct( 'simple', [ 'regular_price' => '10' ] ),
			[
				'field'        => 'regular_price',
				'value'        => '-1',
				'skip_already' => true,
			]
		);
		$this->assertSame( [ Result::FAILED, 'bulkcat_invalid_value' ], [ $bad->state(), $bad->code() ] );
		$this->assertNotSame( '', $bad->reason() );
	}

	public function test_a_name_written_without_unfiltered_html_passes_the_post_html_filter(): void {
		$operation = self::operation();
		$raw       = '<script>alert(1)</script>Shirt <b>bold</b> A\\B';

		$result = $operation->compute( new FakeProduct( 'simple', [ 'name' => 'Old' ] ), $this->args( $operation, 'name', $raw ) );
		$this->assertSame( stripslashes( wp_filter_post_kses( addslashes( $raw ) ) ), $result->new() );
		$this->assertSame( 'alert(1)Shirt <b>bold</b> A\\B', $result->new(), 'Tags go, allowed markup and backslashes stay.' );

		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = true;
		$trusted = $operation->compute( new FakeProduct( 'simple', [ 'name' => 'Old' ] ), $this->args( $operation, 'name', $raw ) );
		$this->assertSame( $raw, $trusted->new(), 'A user with unfiltered_html writes the name as given.' );

		// Arguments validated for a trusted user, computed later with no user (background work): filtered.
		$args                            = $this->args( $operation, 'name', $raw );
		$GLOBALS['bulkcat_test']['caps'] = [];
		$later                           = $operation->compute( new FakeProduct( 'simple', [ 'name' => 'Old' ] ), $args );
		$this->assertSame( 'alert(1)Shirt <b>bold</b> A\\B', $later->new() );
	}

	public function test_setting_the_name_never_touches_the_slug(): void {
		$operation = self::operation();
		$product   = new FakeProduct(
			'simple',
			[
				'name' => 'Blue shirt',
				'slug' => 'blue-shirt',
			]
		);
		$operation->apply( $operation->compute( $product, $this->args( $operation, 'name', 'Red shirt' ) ), $product );
		$this->assertSame( [ [ 'set_name', 'Red shirt' ] ], $product->sets );
		$this->assertSame( 'blue-shirt', $product->props['slug'] );
		$this->assertSame( 'post_title', ( new Fields() )->get( 'name' )->storage()->key() );
	}
}
