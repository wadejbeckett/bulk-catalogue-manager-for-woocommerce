<?php
/**
 * "Also apply to variations" as a step argument (T69; SPEC §4.3, §6, §8,
 * §11): offered on exactly the fields a variation has a value of its own
 * for; its starting position comes from the shop's settings per panel group
 * (status on, price and stock off, anything else off); given, it is
 * normalised to a boolean; on for a parent-only or derived field it is
 * refused with a plain sentence, and off there it is dropped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\BaseOperation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class ApplyToVariationsTest extends TestCase {
	/**
	 * Fields a variation never takes a value for (SPEC §5.2 last column).
	 */
	private const NEVER = [ 'name', 'catalog_visibility', 'visible_on_site', 'featured', 'category_ids', 'tag_ids' ];

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['terms'] = [ 'product_cat' => [ 10 ] ];
	}

	private static function validate( string $action, array $args ): array|\WP_Error {
		return ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => $action,
					'args'   => $args,
				],
			]
		);
	}

	public function test_the_switch_is_offered_exactly_where_a_variation_has_its_own_value(): void {
		$reaching = [];
		foreach ( ( new Fields() )->editable() as $key => $field ) {
			$expected = in_array( $field->variation_rule(), [ Field::PER_VARIATION, Field::CONDITIONAL ], true );
			$this->assertSame( $expected, BaseOperation::reaches_variations( $field ), $key );
			if ( $expected ) {
				$reaching[] = $key;
			}
		}
		$this->assertSame( [], array_intersect( self::NEVER, $reaching ), 'Parent-only and derived fields never reach a variation.' );
		foreach ( [ 'status', 'regular_price', 'sale_price', 'stock_status', 'manage_stock', 'stock_quantity', 'sku', 'weight' ] as $key ) {
			$this->assertContains( $key, $reaching );
		}
	}

	public function test_the_default_follows_the_settings_per_field_group(): void {
		$fields   = new Fields();
		$expected = static fn( Field $field, bool $status, bool $price, bool $stock ): bool => match ( $field->group() ) {
			'publishing' => $status,
			'pricing'    => $price,
			'stock'      => $stock,
			default      => false,
		};
		foreach ( [
			[ [], true, false, false ],
			[
				[
					'variations_status' => false,
					'variations_price'  => true,
					'variations_stock'  => true,
				],
				false,
				true,
				true,
			],
		] as [ $stored, $status, $price, $stock ] ) {
			$settings = new Settings( $stored );
			foreach ( $fields->editable() as $key => $field ) {
				if ( BaseOperation::reaches_variations( $field ) ) {
					$this->assertSame( $expected( $field, $status, $price, $stock ), BaseOperation::variation_default( $field, $settings ), $key );
				}
			}
		}
		$this->assertTrue( BaseOperation::variation_default( $fields->get( 'status' ), new Settings( [] ) ), 'Status starts on.' );
		$this->assertFalse( BaseOperation::variation_default( $fields->get( 'regular_price' ), new Settings( [] ) ), 'Price starts off.' );
		$this->assertFalse( BaseOperation::variation_default( $fields->get( 'stock_quantity' ), new Settings( [] ) ), 'Stock starts off.' );
		$this->assertFalse( BaseOperation::variation_default( $fields->get( 'sku' ), new Settings( [] ) ), 'Any other group starts off.' );
	}

	public function test_an_absent_switch_is_filled_from_the_stored_settings(): void {
		$status = self::validate(
			'set_value',
			[
				'field' => 'status',
				'value' => 'private',
			]
		);
		$this->assertTrue( $status[0]['args']['apply_to_variations'] );

		$price = self::validate( 'clear', [ 'field' => 'sale_price' ] );
		$this->assertFalse( $price[0]['args']['apply_to_variations'] );

		$GLOBALS['bulkcat_test']['options']['bulkcat_settings'] = [ 'variations_price' => true ];
		$price = self::validate( 'clear', [ 'field' => 'sale_price' ] );
		$this->assertTrue( $price[0]['args']['apply_to_variations'], 'The shop setting moves the starting position.' );

		$given = self::validate(
			'clear',
			[
				'field'               => 'sale_price',
				'apply_to_variations' => 'no',
			]
		);
		$this->assertFalse( $given[0]['args']['apply_to_variations'], 'A given value wins, normalised to a boolean.' );
	}

	public function test_a_parent_only_field_refuses_the_switch_on_and_drops_it_off(): void {
		$refused = self::validate(
			'set_value',
			[
				'field'               => 'featured',
				'value'               => true,
				'apply_to_variations' => true,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( BaseOperation::INVALID, $refused->get_error_code() );
		$this->assertSame( 400, $refused->get_error_data()['status'] );
		$this->assertSame( 'apply_to_variations', $refused->get_error_data()['argument'] );
		$this->assertSame( 'Featured belongs to the product, not to each variation, so it cannot be applied to variations. Turn off Also apply to variations and try again.', $refused->get_error_message() );

		$off = self::validate(
			'add_terms',
			[
				'field'               => 'category_ids',
				'terms'               => [ 10 ],
				'apply_to_variations' => false,
			]
		);
		$this->assertTrue( is_array( $off ), $off instanceof \WP_Error ? $off->get_error_message() : '' );
		$this->assertArrayNotHasKey( 'apply_to_variations', $off[0]['args'], 'Off on a parent-only field is dropped.' );

		$absent = self::validate(
			'set_value',
			[
				'field' => 'featured',
				'value' => true,
			]
		);
		$this->assertArrayNotHasKey( 'apply_to_variations', $absent[0]['args'], 'A parent-only step never carries the switch.' );
	}

	public function test_a_switch_that_is_not_on_or_off_is_refused(): void {
		$refused = self::validate(
			'clear',
			[
				'field'               => 'sale_price',
				'apply_to_variations' => 'sometimes',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'Also apply to variations has to be on or off.', $refused->get_error_message() );
	}
}
