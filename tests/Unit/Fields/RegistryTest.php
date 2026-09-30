<?php
/**
 * The field registry (SPEC §5.1, §5.2, §5.4, §8): every §5.2 field and every
 * read-only column declared exactly once with the right type, storage, edit
 * kinds and variation rule; every field has a label, sanitiser, validator,
 * REST schema and DataViews definition; the value rules per type; the getter
 * and setter contract; and a snapshot of the `/fields` payload.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Fields;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\FieldsController;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase {
	private const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * Refresh the snapshot with BULKCAT_UPDATE_SNAPSHOT=1 composer test, then review the diff.
	 */
	private const SNAPSHOT = __DIR__ . '/fields.snapshot.json';

	/**
	 * One row per §5.2 field: key => [ type, storage kind, storage key, edit kinds, variation rule, inline ].
	 */
	private const TABLE = [
		'name'               => [ 'text', 'post', 'post_title', [ 'set', 'find_replace' ], 'derived', false ],
		'status'             => [ 'enum', 'post', 'post_status', [ 'set' ], 'conditional', true ], // T92: set inline, with the Off/On confirm.
		'catalog_visibility' => [ 'enum', 'taxonomy', 'product_visibility', [ 'set' ], 'parent_only', false ],
		'visible_on_site'    => [ 'enum', 'taxonomy', 'product_visibility', [ 'set' ], 'parent_only', true ],
		'featured'           => [ 'boolean', 'taxonomy', 'product_visibility', [ 'set' ], 'parent_only', true ],
		'category_ids'       => [ 'terms', 'taxonomy', 'product_cat', [ 'set', 'add', 'remove' ], 'parent_only', false ],
		'tag_ids'            => [ 'terms', 'taxonomy', 'product_tag', [ 'set', 'add', 'remove' ], 'parent_only', false ],
		'regular_price'      => [ 'money', 'meta', '_regular_price', [ 'set', 'adjust', 'round', 'copy_from_sale' ], 'per_variation', true ],
		'sale_price'         => [ 'money', 'meta', '_sale_price', [ 'set', 'clear', 'adjust', 'round', 'copy_from_regular' ], 'per_variation', true ],
		'stock_status'       => [ 'enum', 'meta', '_stock_status', [ 'set' ], 'per_variation', true ],
		'manage_stock'       => [ 'boolean', 'meta', '_manage_stock', [ 'set' ], 'per_variation', false ],
		'stock_quantity'     => [ 'integer', 'meta', '_stock', [ 'set', 'adjust' ], 'conditional', true ],
		'sku'                => [ 'text', 'meta', '_sku', [ 'set', 'find_replace', 'prefix', 'suffix' ], 'per_variation', true ],
		'weight'             => [ 'decimal', 'meta', '_weight', [ 'set', 'adjust', 'clear' ], 'per_variation', true ],
		'length'             => [ 'decimal', 'meta', '_length', [ 'set', 'clear' ], 'per_variation', true ],
		'width'              => [ 'decimal', 'meta', '_width', [ 'set', 'clear' ], 'per_variation', true ],
		'height'             => [ 'decimal', 'meta', '_height', [ 'set', 'clear' ], 'per_variation', true ],
		'shipping_class_id'  => [ 'term', 'taxonomy', 'product_shipping_class', [ 'set' ], 'per_variation', false ],
		'tax_status'         => [ 'enum', 'meta', '_tax_status', [ 'set' ], 'per_variation', false ],
		'tax_class'          => [ 'enum', 'meta', '_tax_class', [ 'set' ], 'per_variation', false ],
		'sold_individually'  => [ 'boolean', 'meta', '_sold_individually', [ 'set' ], 'parent_only', false ],
		'reviews_allowed'    => [ 'boolean', 'post', 'comment_status', [ 'set' ], 'parent_only', false ],
	];

	/**
	 * The read-only columns: key => [ type, storage kind, storage key ].
	 */
	private const COLUMNS = [
		'id'              => [ 'id', 'post', 'ID' ],
		'type'            => [ 'enum', 'taxonomy', 'product_type' ],
		'thumbnail'       => [ 'image', 'meta', '_thumbnail_id' ],
		'price_html'      => [ 'html', 'derived', '' ],
		'date_created'    => [ 'datetime', 'post', 'post_date_gmt' ],
		'date_modified'   => [ 'datetime', 'post', 'post_modified_gmt' ],
		'units_sold'      => [ 'integer', 'meta', 'total_sales' ],
		'variation_count' => [ 'integer', 'derived', '' ],
	];

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['terms'] = [
			'product_cat'            => [ 5, 6, 7 ],
			'product_tag'            => [ 9 ],
			'product_shipping_class' => [ 12 ],
		];
	}

	private static function field( string $key ): Field {
		$field = ( new Registry() )->get( $key );
		self::assertInstanceOf( Field::class, $field, "No field \"$key\"." );
		return $field;
	}

	public function test_every_field_of_the_table_and_every_column_is_declared_once_in_order(): void {
		$keys = ( new Registry() )->keys();
		$this->assertSame( [ ...array_keys( self::TABLE ), ...array_keys( self::COLUMNS ) ], $keys );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'No two fields may share a key.' );
		$this->assertSame( array_keys( self::TABLE ), array_keys( ( new Registry() )->editable() ) );
		$this->assertSame( array_keys( self::COLUMNS ), array_keys( ( new Registry() )->columns() ) );
	}

	public function test_each_field_has_the_type_storage_edit_kinds_and_variation_rule_of_the_table(): void {
		foreach ( self::TABLE as $key => [ $type, $kind, $storage_key, $kinds, $rule, $inline ] ) {
			$field = self::field( $key );
			$this->assertSame( $type, $field->type(), "$key type." );
			$this->assertSame( $kind, $field->storage()->kind(), "$key storage kind." );
			$this->assertSame( $storage_key, $field->storage()->key(), "$key storage key." );
			$this->assertSame( $kinds, $field->edit_kinds(), "$key edit kinds." );
			$this->assertSame( $rule, $field->variation_rule(), "$key variation rule." );
			$this->assertSame( $inline, $field->inline(), "$key inline." );
			$this->assertFalse( $field->read_only(), "$key is editable." );
			$this->assertSame( 'set_' . substr( $field->getter(), 4 ), $field->setter(), "$key setter pairs with its getter." );
		}
		foreach ( self::COLUMNS as $key => [ $type, $kind, $storage_key ] ) {
			$field = self::field( $key );
			$this->assertSame( $type, $field->type(), "$key type." );
			$this->assertSame( $kind, $field->storage()->kind(), "$key storage kind." );
			$this->assertSame( $storage_key, $field->storage()->key(), "$key storage key." );
			$this->assertTrue( $field->read_only(), "$key is read-only." );
			$this->assertSame( [], $field->edit_kinds() );
			$this->assertNull( $field->setter() );
		}
	}

	public function test_visibility_terms_and_lookup_columns_are_declared(): void {
		$this->assertSame( [ 'exclude-from-catalog', 'exclude-from-search' ], self::field( 'catalog_visibility' )->storage()->terms() );
		$this->assertSame( [ 'exclude-from-catalog', 'exclude-from-search' ], self::field( 'visible_on_site' )->storage()->terms() );
		$this->assertSame( [ 'featured' ], self::field( 'featured' )->storage()->terms() );
		$this->assertSame( [ 'min_price', 'max_price' ], self::field( 'regular_price' )->storage()->lookup() );
		$this->assertSame( [ 'stock_status' ], self::field( 'stock_status' )->storage()->lookup() );
		$this->assertSame( [ 'stock_quantity' ], self::field( 'stock_quantity' )->storage()->lookup() );
		$this->assertSame( [ 'sku' ], self::field( 'sku' )->storage()->lookup() );
	}

	public function test_no_field_lacks_a_label_sanitiser_validator_schema_or_dataviews_definition(): void {
		foreach ( ( new Registry() )->all() as $key => $field ) {
			$this->assertNotSame( '', trim( $field->label() ), "$key label." );
			$this->assertTrue( is_callable( [ $field, 'sanitise' ] ), "$key sanitiser." );
			$this->assertTrue( is_callable( [ $field, 'validate' ] ), "$key validator." );
			$schema = $field->rest_schema();
			$this->assertArrayHasKey( 'type', $schema, "$key schema type." );
			$this->assertSame( $field->label(), $schema['description'] );
			$this->assertSame( $field->read_only(), $schema['readonly'] );
			$dataviews = $field->dataviews();
			$this->assertSame( $key, $dataviews['id'] );
			$this->assertSame( $field->label(), $dataviews['label'] );
			$this->assertContains( $dataviews['type'], [ 'text', 'integer', 'number', 'boolean', 'array', 'datetime' ] );
			if ( $field->read_only() ) {
				$this->assertSame( 'bulkcat_read_only', self::code( $field->validate( 'x' ) ), "$key refuses every write." );
				$this->assertNotNull( $field->unavailable_reason( new FakeProduct() ) );
			} else {
				$arg = $field->rest_arg();
				$this->assertSame( [ $field, 'validate' ], $arg['validate_callback'] );
				$this->assertSame( [ $field, 'sanitise' ], $arg['sanitize_callback'] );
			}
			if ( Field::PARENT_ONLY === $field->variation_rule() || Field::DERIVED === $field->variation_rule() ) {
				if ( ! $field->read_only() ) {
					$this->assertNotSame( '', $field->unavailable_reason( new FakeProduct( 'variation' ) ) ?? '', "$key names why a variation cannot take it." );
				}
			}
		}
	}

	public function test_labels_are_translated_with_the_text_domain_and_name_no_storage_key(): void {
		$GLOBALS['bulkcat_test']['gettext'] = [];
		$registry                           = new Registry();
		foreach ( $registry->all() as $field ) {
			$field->choices();
		}
		$texts = [];
		foreach ( $GLOBALS['bulkcat_test']['gettext'] as [ $text, $domain ] ) {
			$this->assertSame( self::DOMAIN, $domain, "\"$text\"." );
			$texts[] = $text;
		}
		$raw = self::raw_identifiers();
		foreach ( $registry->all() as $key => $field ) {
			$this->assertContains( $field->label(), $texts, "$key label goes through __()." );
			$this->assertSame( 0, preg_match( '/\b_[a-z]|[a-z]_[a-z]/', $field->label() ), "$key label looks like a key: {$field->label()}" );
			foreach ( $raw as $identifier ) {
				$this->assertStringNotContainsString( $identifier, $field->label(), "$key label names $identifier." );
			}
		}
	}

	public function test_the_default_columns_and_the_choices_that_hide_a_product_are_flagged(): void {
		$client  = array_map( static fn( Field $field ): array => $field->to_client(), ( new Registry() )->all() );
		$default = array_filter( $client, static fn( array $field ): bool => $field['defaultColumn'] );
		uasort( $default, static fn( array $a, array $b ): int => $a['defaultOrder'] <=> $b['defaultOrder'] );
		$this->assertSame( [ 'sku', 'category_ids', 'regular_price', 'stock_status', 'status' ], array_keys( $default ), 'T40, T45, T75: the columns after the product cell, in the prototype\'s order; the sale price shows under the regular price. T93: Shown in shop and search is not a default column; the status badge carries Off/On.' );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $default, 'defaultOrder' ) );
		foreach ( array_diff_key( $client, $default ) as $key => $field ) {
			$this->assertNull( $field['defaultOrder'], $key );
		}

		$hiding = [];
		foreach ( $client as $key => $field ) {
			foreach ( $field['choices'] ?? [] as $choice ) {
				$this->assertSame( null !== $choice['hidesAs'], $choice['hidesProduct'], "$key {$choice['value']}" );
				if ( $choice['hidesProduct'] ) {
					$hiding[ $key ][ $choice['value'] ] = $choice['hidesAs'];
				}
			}
		}
		$this->assertSame(
			[
				'status'             => [
					'future'  => Field::HIDES_OFF_SITE,
					'draft'   => Field::HIDES_OFF_SITE,
					'pending' => Field::HIDES_OFF_SITE,
				],
				'catalog_visibility' => [ 'hidden' => Field::HIDES_FROM_SHOP ],
				'visible_on_site'    => [ 'off' => Field::HIDES_FROM_SHOP ],
			],
			$hiding
		);
		$this->assertArrayNotHasKey( 'hidesProduct', $client['status']['dataviews']['elements'][0], 'The DataViews elements stay value and label.' );

		// T114: a scheduled product reads as Scheduled (its post status future), which the field
		// cannot be set to: shown, filtered and validated when read, never offered or accepted to write.
		$status = self::field( 'status' );
		$this->assertSame( 'Scheduled', $status->choices()['future'] ?? null );
		$this->assertSame( [ 'publish', 'draft', 'pending', 'private' ], $status->writable_choices() );
		$this->assertTrue( true === rest_validate_value_from_schema( 'future', $status->rest_schema(), 'status' ), 'A scheduled row validates against the read schema.' );
		$this->assertNotContains( 'future', $status->rest_arg()['enum'] ?? [], 'Never accepted as a value to write.' );
		$this->assertInstanceOf( \WP_Error::class, $status->validate( 'future' ), 'Nor written by a bulk step.' );
		$this->assertContains( 'future', array_column( $client['status']['dataviews']['elements'], 'value' ), 'The Status filter offers Scheduled.' );

		$bad = static fn( array $args ): Field => new Field(
			...( $args + [
				'key'            => 'thing',
				'label'          => 'Thing',
				'type'           => Field::ENUM,
				'storage'        => Storage::meta( '_thing' ),
				'edit_kinds'     => [],
				'variation_rule' => Field::PER_VARIATION,
				'getter'         => 'get_thing',
				'setter'         => null,
				'choices'        => [ 'a' => 'A' ],
			] )
		);
		foreach ( [
			[ 'hiding_choices' => [ 'b' => Field::HIDES_FROM_SHOP ] ],
			[ 'hiding_choices' => [ 'a' => 'gone' ] ],
			[
				'type'           => Field::TEXT,
				'choices'        => [],
				'hiding_choices' => [ 'a' => Field::HIDES_FROM_SHOP ],
			],
			[ 'default_order' => -1 ],
		] as $args ) {
			try {
				$bad( $args );
				$this->fail( 'Accepted ' . wp_json_encode( $args ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is invalid', $e->getMessage() );
			}
		}
	}

	public function test_the_client_payload_exposes_no_storage_key(): void {
		$json = (string) wp_json_encode( ( new FieldsController( new Registry() ) )->payload() );
		foreach ( self::raw_identifiers() as $identifier ) {
			$this->assertStringNotContainsString( '"' . $identifier . '"', $json, "The /fields payload exposes $identifier." );
		}
		$this->assertStringNotContainsString( '"_', $json, 'No meta key may reach the client.' );
		$this->assertStringNotContainsString( 'getter', $json );
		$this->assertStringNotContainsString( 'storage', $json );
	}

	public function test_every_declared_edit_kind_resolves_to_a_registered_operation(): void {
		$registry = new Registry();
		$actions  = new Actions( $registry );
		foreach ( $registry->all() as $key => $field ) {
			foreach ( $field->edit_kinds() as $kind ) {
				$this->assertContains( $kind, Field::EDIT_KINDS, "$key: $kind." );
				$this->assertTrue( $field->accepts( $kind ) );
				$this->assertNotSame( [], $actions->actions_for( $key, $kind ), "No registered action performs $kind on $key." );
			}
		}
		$this->assertFalse( self::field( 'status' )->accepts( Field::ADJUST ) );
		$this->assertSame( [], $actions->actions_for( 'status', Field::ADJUST ) );
	}

	public function test_every_editable_field_sits_in_one_panel_group_and_no_column_does(): void {
		$groups = [];
		foreach ( ( new Registry() )->all() as $key => $field ) {
			if ( $field->read_only() ) {
				$this->assertSame( '', $field->group(), "$key is a column." );
				continue;
			}
			$this->assertContains( $field->group(), Group::ALL, "$key has no group." );
			$groups[ $field->group() ] = true;
		}
		$this->assertSame( Group::ALL, array_values( array_intersect( Group::ALL, array_keys( $groups ) ) ), 'Every panel group holds at least one field.' );
		$this->assertSame( Group::PRICING, self::field( 'regular_price' )->group() );
		$this->assertSame( Group::STOCK, self::field( 'stock_quantity' )->group() );
		$this->assertSame( Group::ORGANISATION, self::field( 'category_ids' )->group() );
		$this->assertSame( Group::SHIPPING_TAX, self::field( 'weight' )->group() );
		$this->assertSame( Group::PUBLISHING, self::field( 'status' )->group() );
	}

	/**
	 * The sanitise and validate matrix, one row per case:
	 * [ field, submitted, valid, canonical value when valid ].
	 *
	 * @return array<int, array{0: string, 1: mixed, 2: bool, 3?: mixed}>
	 */
	private static function matrix(): array {
		return [
			// Money (§5.4): stored at the shop's precision; empty is distinct from zero; below zero refused.
			[ 'regular_price', '12.5', true, '12.50' ],
			[ 'regular_price', 12, true, '12.00' ],
			[ 'regular_price', 9.999, true, '10.00' ],
			[ 'regular_price', '0', true, '0.00' ],
			[ 'regular_price', '', true, '' ],
			[ 'regular_price', null, true, '' ],
			[ 'regular_price', ' 7 ', true, '7.00' ],
			[ 'regular_price', '.5', true, '0.50' ],
			[ 'regular_price', '-0.01', false ],
			[ 'regular_price', -5, false ],
			[ 'regular_price', '1,234.50', false ],
			[ 'regular_price', '1e3', false ],
			[ 'regular_price', 'R12', false ],
			[ 'regular_price', true, false ],
			[ 'regular_price', [ '12' ], false ],
			[ 'regular_price', NAN, false ],
			[ 'sale_price', '', true, '' ],
			[ 'sale_price', '0', true, '0.00' ],
			[ 'sale_price', 'abc', false ],
			// Decimals: weight and dimensions, no rounding to price precision.
			[ 'weight', '1.2345', true, '1.2345' ],
			[ 'weight', 3, true, '3' ],
			[ 'weight', '', true, '' ],
			[ 'weight', '-1', false ],
			[ 'length', '10', true, '10' ],
			[ 'width', 'wide', false ],
			[ 'height', '0', true, '0' ],
			// Integer: stock quantity may be empty or negative, never fractional.
			[ 'stock_quantity', '12', true, 12 ],
			[ 'stock_quantity', 12, true, 12 ],
			[ 'stock_quantity', '-3', true, -3 ],
			[ 'stock_quantity', 4.0, true, 4 ],
			[ 'stock_quantity', '', true, null ],
			[ 'stock_quantity', null, true, null ],
			[ 'stock_quantity', '0', true, 0 ],
			[ 'stock_quantity', '12.5', false ],
			[ 'stock_quantity', 1.5, false ],
			[ 'stock_quantity', 'twelve', false ],
			[ 'stock_quantity', '99999999999999999999', false ],
			// Enum.
			[ 'stock_status', 'instock', true, 'instock' ],
			[ 'stock_status', 'onbackorder', true, 'onbackorder' ],
			[ 'stock_status', 'In stock', false ],
			[ 'stock_status', '', false ],
			[ 'stock_status', 1, false ],
			[ 'status', 'draft', true, 'draft' ],
			[ 'status', 'trash', false ],
			[ 'status', 'future', false ],
			[ 'catalog_visibility', 'search', true, 'search' ],
			[ 'catalog_visibility', 'exclude-from-search', false ],
			[ 'visible_on_site', 'on', true, 'on' ],
			[ 'visible_on_site', 'off', true, 'off' ],
			[ 'visible_on_site', 'partly', false ],
			[ 'tax_status', 'shipping', true, 'shipping' ],
			[ 'tax_class', '', true, '' ],
			[ 'tax_class', 'parent', true, 'parent' ],
			[ 'tax_class', 'luxury', false ],
			// Boolean.
			[ 'featured', true, true, true ],
			[ 'featured', false, true, false ],
			[ 'featured', '1', true, true ],
			[ 'featured', 0, true, false ],
			[ 'manage_stock', 'yes', true, true ],
			[ 'sold_individually', 'no', true, false ],
			[ 'reviews_allowed', 'maybe', false ],
			[ 'reviews_allowed', null, false ],
			[ 'reviews_allowed', 2, false ],
			// Text: the name cannot be empty; a SKU can; both have a length limit.
			[ 'name', 'Blue shoe', true, 'Blue shoe' ],
			[ 'name', '  Blue shoe  ', true, 'Blue shoe' ],
			[ 'name', '', false ],
			[ 'name', '   ', false ],
			[ 'name', str_repeat( 'a', 201 ), false ],
			[ 'name', [ 'a' ], false ],
			[ 'sku', 'SHOE-42', true, 'SHOE-42' ],
			[ 'sku', '', true, '' ],
			[ 'sku', 4242, true, '4242' ],
			[ 'sku', '<b>SHOE</b>', true, 'SHOE' ],
			[ 'sku', str_repeat( 'a', 101 ), false ],
			// Taxonomy ids: existing terms only, at most 100, each once.
			[ 'category_ids', [ 5, 6 ], true, [ 5, 6 ] ],
			[ 'category_ids', [ '7', 5, 7 ], true, [ 7, 5 ] ],
			[ 'category_ids', '5,6', true, [ 5, 6 ] ],
			[ 'category_ids', [], true, [] ],
			[ 'category_ids', [ 5, 99 ], false ],
			[ 'category_ids', [ 0 ], false ],
			[ 'category_ids', [ -5 ], false ],
			[ 'category_ids', [ 'a' ], false ],
			[ 'category_ids', [ 'x' => 5 ], false ],
			[ 'category_ids', 5, false ],
			[ 'tag_ids', [ 9 ], true, [ 9 ] ],
			[ 'tag_ids', [ 5 ], false ],
			[ 'shipping_class_id', 12, true, 12 ],
			[ 'shipping_class_id', '0', true, 0 ],
			[ 'shipping_class_id', 13, false ],
			[ 'shipping_class_id', -1, false ],
		];
	}

	public function test_sanitise_and_validate_matrix_per_type(): void {
		$types = [];
		foreach ( self::matrix() as $row ) {
			[ $key, $value, $valid ] = $row;
			$field                   = self::field( $key );
			$types[ $field->type() ] = true;
			$label                   = $key . ' ' . (string) wp_json_encode( $value, JSON_PARTIAL_OUTPUT_ON_ERROR );
			$result                  = $field->validate( $value );
			if ( $valid ) {
				$this->assertTrue( $result, "$label should be valid." );
				$this->assertSame( $row[3], $field->sanitise( $value ), "$label canonical value." );
				$this->assertSame( $row[3], $field->clean( $value ), "$label through clean()." );
			} else {
				$this->assertInstanceOf( \WP_Error::class, $result, "$label should be refused." );
				$this->assertSame( 'bulkcat_invalid_value', $result->get_error_code() );
				$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
				$this->assertStringContainsString( $field->label(), $result->get_error_message(), "$label: the refusal names the field." );
				$this->assertInstanceOf( \WP_Error::class, $field->clean( $value ) );
			}
		}
		$this->assertSame( [ 'boolean', 'decimal', 'enum', 'integer', 'money', 'term', 'terms', 'text' ], self::sorted_keys( $types ), 'The matrix covers every editable type.' );
	}

	public function test_refusals_use_the_plain_wording(): void {
		$this->assertSame( 'Stock quantity has to be a whole number. Enter a number like 12.', self::field( 'stock_quantity' )->validate( '1.5' )->get_error_message() );
		$this->assertSame( 'Regular price has to be a price of 0 or more, like 12.50, or left empty.', self::field( 'regular_price' )->validate( '-1' )->get_error_message() );
		$this->assertSame( 'Stock status has to be one of: In stock, Out of stock, On backorder.', self::field( 'stock_status' )->validate( 'x' )->get_error_message() );
		$this->assertSame( 'Shown in shop and search has to be one of: Shown, Hidden.', self::field( 'visible_on_site' )->validate( 'partly' )->get_error_message() );
	}

	public function test_money_uses_the_shop_precision_and_decimal_separator(): void {
		$GLOBALS['bulkcat_test']['decimals']  = 3;
		$GLOBALS['bulkcat_test']['separator'] = ',';
		$price                                = self::field( 'regular_price' );
		$this->assertSame( '12.500', $price->clean( '12,5' ) );
		$this->assertSame( '12.500', $price->clean( '12.5' ) );
		$this->assertInstanceOf( \WP_Error::class, $price->clean( '1.234,5' ) );
		$GLOBALS['bulkcat_test']['decimals'] = 0;
		$this->assertSame( '13', $price->clean( '12.5' ) );
	}

	public function test_a_sale_price_must_be_below_the_regular_price(): void {
		$this->assertTrue( Registry::check_prices( '10.00', '9.99' ) );
		$this->assertTrue( Registry::check_prices( '10.00', '' ), 'An empty sale price always passes.' );
		$this->assertTrue( Registry::check_prices( '10.00', '0.00' ) );
		$this->assertTrue( Registry::check_prices( '', '5.00' ) );
		foreach ( [ '10.00', '10', '12.00' ] as $sale ) {
			$error = Registry::check_prices( '10.00', $sale );
			$this->assertInstanceOf( \WP_Error::class, $error, "Sale $sale against regular 10.00." );
			$this->assertSame( 'bulkcat_invalid_value', $error->get_error_code() );
			$this->assertStringContainsString( 'skipped', $error->get_error_message() );
		}
	}

	public function test_a_name_is_filtered_for_a_user_without_unfiltered_html(): void {
		$name = self::field( 'name' );
		$this->assertSame( 'Shoe alert(1)', $name->sanitise( 'Shoe <script>alert(1)</script>' ) );
		$this->assertSame( 'Shoe <strong>new</strong>', $name->sanitise( 'Shoe <strong>new</strong>' ) );
		$GLOBALS['bulkcat_test']['caps']['unfiltered_html'] = true;
		$this->assertSame( 'Shoe <script>alert(1)</script>', $name->sanitise( 'Shoe <script>alert(1)</script>' ) );
	}

	public function test_invalid_input_sanitises_to_the_empty_value_rather_than_a_guess(): void {
		$this->assertSame( '', self::field( 'regular_price' )->sanitise( '1,234.50' ) );
		$this->assertNull( self::field( 'stock_quantity' )->sanitise( '' ) );
		$this->assertSame( '', self::field( 'stock_status' )->sanitise( 'nonsense' ) );
		$this->assertSame( [], self::field( 'category_ids' )->sanitise( [ 'x' ] ) );
		$this->assertSame( 0, self::field( 'shipping_class_id' )->sanitise( -4 ) );
		$this->assertFalse( self::field( 'featured' )->sanitise( 'maybe' ) );
	}

	public function test_read_calls_the_named_getter_in_edit_context_and_returns_the_canonical_form(): void {
		$date     = new class() {
			public function date( string $format ): string {
				return 'c' === $format ? '2026-09-23T10:00:00+00:00' : '';
			}
		};
		$product  = new FakeProduct(
			'variable',
			[
				'id'                 => 42,
				'name'               => 'Shoe',
				'regular_price'      => '12.00',
				'sale_price'         => '',
				'stock_quantity'     => null,
				'manage_stock'       => false,
				'featured'           => true,
				'category_ids'       => [ '5', 6 ],
				'catalog_visibility' => 'search',
				'shipping_class_id'  => '12',
				'date_created'       => $date,
				'date_modified'      => null,
				'total_sales'        => '7',
				'children'           => [ 101, 102, 103 ],
				'image_id'           => '0',
				'price_html'         => '<span>R12</span>',
			]
		);
		$expected = [
			'id'                 => 42,
			'name'               => 'Shoe',
			'regular_price'      => '12.00',
			'sale_price'         => '',
			'stock_quantity'     => null,
			'manage_stock'       => false,
			'featured'           => true,
			'category_ids'       => [ 5, 6 ],
			'catalog_visibility' => 'search',
			'visible_on_site'    => 'partly',
			'shipping_class_id'  => 12,
			'date_created'       => '2026-09-23T10:00:00+00:00',
			'date_modified'      => null,
			'units_sold'         => 7,
			'variation_count'    => 3,
			'thumbnail'          => 0,
			'price_html'         => '<span>R12</span>',
			'type'               => 'variable',
		];
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, self::field( $key )->read( $product ), "read( $key )." );
		}
		$this->assertContains( [ 'get_regular_price', [ 'edit' ] ], $product->gets );
		$this->assertContains( [ 'get_price_html', [] ], $product->gets, 'The rendered price is read in view context.' );
		$this->assertSame( [], $product->sets, 'Reading writes nothing.' );

		foreach ( [
			'visible' => 'on',
			'hidden'  => 'off',
			'catalog' => 'partly',
			'search'  => 'partly',
		] as $visibility => $switch ) {
			$this->assertSame( $switch, self::field( 'visible_on_site' )->read( new FakeProduct( 'simple', [ 'catalog_visibility' => $visibility ] ) ) );
		}
	}

	public function test_write_sets_the_canonical_value_through_the_named_setter_and_does_not_save(): void {
		$product = new FakeProduct( 'simple', [ 'id' => 1 ] );
		$this->assertSame( '10.00', self::field( 'regular_price' )->write( $product, '10' ) );
		$this->assertSame( '', self::field( 'sale_price' )->write( $product, '' ) );
		$this->assertSame( [ 5 ], self::field( 'category_ids' )->write( $product, [ 5 ] ) );
		$this->assertSame( 'on', self::field( 'visible_on_site' )->write( $product, 'on' ) );
		$this->assertSame( 'off', self::field( 'visible_on_site' )->write( $product, 'off' ) );
		$this->assertSame( true, self::field( 'featured' )->write( $product, '1' ) );
		$this->assertSame(
			[
				[ 'set_regular_price', '10.00' ],
				[ 'set_sale_price', '' ],
				[ 'set_category_ids', [ 5 ] ],
				[ 'set_catalog_visibility', 'visible' ],
				[ 'set_catalog_visibility', 'hidden' ],
				[ 'set_featured', true ],
			],
			$product->sets
		);
		$this->assertNotContains( 'save', array_column( $product->gets, 0 ) );

		$refused = self::field( 'regular_price' )->write( $product, '-1' );
		$this->assertSame( 'bulkcat_invalid_value', self::code( $refused ) );
		$this->assertCount( 6, $product->sets, 'A refused value sets nothing.' );
		$this->assertSame( 'bulkcat_read_only', self::code( self::field( 'units_sold' )->write( $product, 3 ) ) );
	}

	public function test_variation_rules_refuse_with_the_reason(): void {
		$variation = new FakeProduct( 'variation', [ 'id' => 7 ] );
		foreach ( [
			'category_ids'       => [ 5 ],
			'tag_ids'            => [ 9 ],
			'featured'           => true,
			'catalog_visibility' => 'hidden',
			'visible_on_site'    => 'off',
			'sold_individually'  => true,
			'reviews_allowed'    => false,
			'name'               => 'X',
		] as $key => $value ) {
			$result = self::field( $key )->write( $variation, $value );
			$this->assertSame( 'bulkcat_not_available', self::code( $result ), "$key on a variation." );
		}
		$this->assertSame( 'Categories belong to the product, not to each variation.', self::field( 'category_ids' )->write( $variation, [ 5 ] )->get_error_message() );
		$this->assertSame( [], $variation->sets, 'Nothing parent-only is pushed down.' );

		// Status: publish and private only on a variation.
		$this->assertSame( 'private', self::field( 'status' )->write( $variation, 'private' ) );
		$draft = self::field( 'status' )->write( $variation, 'draft' );
		$this->assertSame( 'bulkcat_invalid_value', self::code( $draft ) );
		$this->assertSame( 'On a variation, Status can only be: Published, Private.', $draft->get_error_message() );
		$this->assertSame( 'draft', self::field( 'status' )->write( new FakeProduct(), 'draft' ) );

		// Tax class "same as product" only on a variation.
		$this->assertSame( 'parent', self::field( 'tax_class' )->write( $variation, 'parent' ) );
		$this->assertSame( 'bulkcat_invalid_value', self::code( self::field( 'tax_class' )->write( new FakeProduct(), 'parent' ) ) );

		// Shipping class 0 on a variation means the same as its product.
		$this->assertSame( 0, self::field( 'shipping_class_id' )->write( $variation, 0 ) );

		// Per-variation price on a variation; derived and read-only on a variable parent.
		$this->assertSame( '5.00', self::field( 'regular_price' )->write( $variation, '5' ) );
		$parent = new FakeProduct( 'variable' );
		foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
			$result = self::field( $key )->write( $parent, '5' );
			$this->assertSame( 'bulkcat_not_available', self::code( $result ), "$key on a variable product." );
			$this->assertStringContainsString( 'variations', $result->get_error_message() );
		}
		$this->assertSame( [], $parent->sets );
	}

	public function test_stock_quantity_only_where_stock_is_managed(): void {
		$field = self::field( 'stock_quantity' );
		foreach ( [ false, 'parent', null ] as $managed ) {
			$variation = new FakeProduct( 'variation', [ 'manage_stock' => $managed ] );
			$this->assertSame( 'bulkcat_not_available', self::code( $field->write( $variation, 3 ) ), 'manage_stock ' . wp_json_encode( $managed ) );
			$this->assertSame( [], $variation->sets );
		}
		$this->assertSame( 3, $field->write( new FakeProduct( 'variation', [ 'manage_stock' => true ] ), '3' ) );
		$this->assertSame( 'bulkcat_not_available', self::code( $field->write( new FakeProduct( 'simple', [ 'manage_stock' => false ] ), 3 ) ) );

		$product = new FakeProduct( 'simple', [ 'manage_stock' => false ] );
		self::field( 'manage_stock' )->write( $product, true );
		$this->assertSame( 4, $field->write( $product, 4 ), 'Turning on Manage stock first in the same batch lets the quantity through.' );
	}

	public function test_a_sku_in_use_elsewhere_is_refused_for_that_row(): void {
		$GLOBALS['bulkcat_test']['skus'] = [
			'SHOE-42' => 10,
			'OWN-1'   => 11,
		];
		$sku                             = self::field( 'sku' );
		$clash                           = $sku->write( new FakeProduct( 'variation', [ 'id' => 12 ] ), 'SHOE-42' );
		$this->assertSame( 'bulkcat_invalid_value', self::code( $clash ) );
		$this->assertSame( 'A product with the SKU SHOE-42 already exists, so this SKU was not applied.', $clash->get_error_message() );
		$this->assertSame( 'OWN-1', $sku->write( new FakeProduct( 'simple', [ 'id' => 11 ] ), 'OWN-1' ), 'Its own SKU is not a clash.' );
		$this->assertSame( '', $sku->write( new FakeProduct( 'simple', [ 'id' => 12 ] ), '' ), 'Clearing a SKU never clashes.' );
	}

	public function test_inconsistent_field_definitions_are_refused(): void {
		$base = [
			'key'            => 'thing',
			'label'          => 'Thing',
			'type'           => Field::TEXT,
			'storage'        => Storage::meta( '_thing' ),
			'edit_kinds'     => [ Field::SET ],
			'variation_rule' => Field::PER_VARIATION,
			'getter'         => 'get_thing',
			'setter'         => 'set_thing',
		];
		$this->assertSame( 'thing', ( new Field( ...$base ) )->key() );
		$this->assertSame( Group::STOCK, ( new Field( ...( $base + [ 'group' => Group::STOCK ] ) ) )->group() );
		$bad = [
			[ 'key' => '_thing' ],
			[ 'key' => 'Thing' ],
			[ 'label' => ' ' ],
			[ 'type' => 'float' ],
			[ 'variation_rule' => 'sometimes' ],
			[ 'edit_kinds' => [ 'set', 'explode' ] ],
			[ 'edit_kinds' => [ 'set', 'set' ] ],
			[ 'setter' => null ],
			[ 'edit_kinds' => [] ],
			[
				'edit_kinds' => [ Field::ADJUST ],
				'inline'     => true,
			],
			[ 'edit_kinds' => [ Field::SET, Field::CLEAR ] ],
			[ 'variation_rule' => Field::PARENT_ONLY ],
			[ 'group' => 'marketing' ],
			[
				'edit_kinds' => [],
				'setter'     => null,
				'group'      => Group::PRICING,
			],
		];
		foreach ( $bad as $change ) {
			try {
				new Field( ...array_merge( $base, $change ) );
				$this->fail( 'Accepted ' . wp_json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is invalid', $e->getMessage() );
			}
		}
	}

	public function test_the_off_record_is_an_internal_field_no_public_list_holds(): void {
		// T88: the journal, preview, writer and undo resolve it by key; nothing a client sees lists it.
		$registry = new Registry();
		$field    = $registry->get( 'availability_off' );
		$this->assertTrue( null !== $field, 'get() resolves the internal field.' );
		$this->assertSame( [ 'availability_off' ], array_keys( $registry->internal() ) );
		$this->assertTrue( $field->internal() );
		$this->assertSame( 'Status before Off', $field->label() );
		$this->assertSame( [ Field::SET, Field::CLEAR ], $field->edit_kinds() );
		$this->assertSame( Field::PARENT_ONLY, $field->variation_rule() );
		$this->assertSame( '', $field->group() );
		$this->assertFalse( $field->inline() );
		$this->assertFalse( $field->filterable() );
		$this->assertFalse( $field->sortable() );
		foreach ( [
			'all'        => $registry->all(),
			'editable'   => $registry->editable(),
			'columns'    => $registry->columns(),
			'filterable' => $registry->filterable(),
			'sortable'   => $registry->sortable(),
		] as $list => $fields ) {
			$this->assertArrayNotHasKey( 'availability_off', $fields, "$list() lists the internal field." );
		}
		$this->assertNotContains( 'availability_off', $registry->keys() );
		$this->assertFalse( $registry->has( 'availability_off' ), 'has() answers for the public fields only.' );
		$this->assertStringNotContainsString( 'availability_off', (string) wp_json_encode( $registry->to_client() ) );
		$payload = (string) wp_json_encode( ( new FieldsController( $registry ) )->payload() );
		$this->assertStringNotContainsString( 'availability_off', $payload );
		$this->assertStringNotContainsString( 'Status before Off', $payload );
		$this->assertSame( [], ( new Actions( $registry ) )->actions_for( 'availability_off', Field::SET ), 'No public action accepts it.' );
		$this->assertSame( [], ( new Actions( $registry ) )->actions_for( 'availability_off', Field::CLEAR ) );
		// A registry built from a test's own list still resolves it.
		$this->assertTrue( null !== ( new Registry( [ $registry->get( 'sku' ) ] ) )->get( 'availability_off' ) );
	}

	public function test_the_off_record_field_reads_and_writes_the_product_bound_record(): void {
		$field   = ( new Registry() )->get( 'availability_off' );
		$json    = '{"id":41,"status":"private","at":"2026-09-28T14:03:11Z","batch":3}';
		$product = new FakeProduct( 'simple', [ 'id' => 41 ] );
		$this->assertSame( '', $field->read( $product ) );
		$this->assertSame( $json, $field->write( $product, $json ) );
		$this->assertSame( $json, $field->read( $product ) );
		$this->assertSame( [ [ 'update', '_bulkcat_availability_off', $json ] ], $product->meta_calls );
		$this->assertSame( '', $field->write( $product, '' ) );
		$this->assertSame( '', $field->read( $product ) );
		$this->assertSame( 'delete', $product->meta_calls[1][0] );
		$this->assertSame( [], $product->sets, 'No CRUD setter runs, and nothing saves.' );

		// Another product's record, an unreadable value, a variation: refused, nothing written.
		$other = $field->write( $product, '{"id":42,"status":"private","at":"2026-09-28T14:03:11Z","batch":3}' );
		$this->assertInstanceOf( \WP_Error::class, $other );
		$this->assertInstanceOf( \WP_Error::class, $field->write( $product, '{"id":41,"status":"draft","at":"2026-09-28T14:03:11Z","batch":3}' ) );
		$this->assertInstanceOf( \WP_Error::class, $field->write( new FakeProduct( 'variation', [ 'id' => 41 ] ), $json ) );
		$this->assertCount( 2, $product->meta_calls );
		$this->assertStringNotContainsString( 'bulkcat_', $other->get_error_message() );
		$this->assertStringNotContainsString( '_bulkcat', $other->get_error_message() );
	}

	public function test_an_internal_field_has_no_group_default_place_filter_or_inline_edit(): void {
		$base = [
			'key'            => 'thing',
			'label'          => 'Thing',
			'type'           => Field::TEXT,
			'storage'        => Storage::meta( '_thing' ),
			'edit_kinds'     => [ Field::SET, Field::CLEAR ],
			'variation_rule' => Field::PER_VARIATION,
			'getter'         => 'get_thing',
			'setter'         => 'set_thing',
			'nullable'       => true,
			'internal'       => true,
		];
		$this->assertTrue( ( new Field( ...$base ) )->internal() );
		foreach ( [ [ 'group' => Group::STOCK ], [ 'default_order' => 3 ], [ 'filter_operators' => [ 'isAny' ] ], [ 'inline' => true ], [ 'sortable' => true ] ] as $change ) {
			try {
				new Field( ...array_merge( $base, $change ) );
				$this->fail( 'Accepted ' . wp_json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is invalid', $e->getMessage() );
			}
		}
	}

	public function test_the_listing_switch_is_named_for_what_it_does_and_is_not_a_default_column(): void {
		// T93 (SPEC §5.3): a listing control, not availability; the key stays for saved views, journals and CSV ids.
		$field = self::field( 'visible_on_site' );
		$this->assertSame( 'Shown in shop and search', $field->label() );
		$this->assertSame(
			[
				'on'     => 'Shown',
				'off'    => 'Hidden',
				'partly' => 'Partly',
			],
			$field->choices()
		);
		$client = $field->to_client();
		$this->assertFalse( $client['defaultColumn'] );
		$this->assertNull( $client['defaultOrder'] );
		$this->assertTrue( $field->inline(), 'Still set in place for an owner who adds the column.' );
		$this->assertSame( [ 'on', 'off' ], $field->writable_choices() );
	}

	public function test_status_filters_by_off_and_changed_outside_but_never_writes_them(): void {
		// T92 (SPEC §4.1, §5.5): filter-only choices are filter elements and filter values, never choices.
		$status = self::field( 'status' );
		$this->assertTrue( $status->inline(), 'Status is set in the cell, with the Off/On confirm.' );
		$this->assertSame(
			[
				'off'             => 'Off',
				'changed_outside' => 'Changed outside this screen',
			],
			$status->filter_choices()
		);
		$this->assertArrayNotHasKey( 'off', $status->choices() );
		$this->assertNotContains( 'off', $status->writable_choices() );
		$this->assertNotContains( 'changed_outside', $status->writable_choices() );
		$elements = array_column( $status->dataviews()['elements'], 'label', 'value' );
		// T114: Scheduled is read and filtered, never written.
		$this->assertSame( [ 'publish', 'future', 'draft', 'pending', 'private', 'off', 'changed_outside' ], array_keys( $elements ) );
		foreach ( ( new Registry() )->all() as $key => $field ) {
			if ( 'status' !== $key ) {
				$this->assertSame( [], $field->filter_choices(), "$key has no filter-only choices." );
			}
		}
		// Only a filterable field of choices takes them.
		$base = [
			'key'              => 'thing',
			'label'            => 'Thing',
			'type'             => Field::ENUM,
			'storage'          => Storage::meta( '_thing' ),
			'edit_kinds'       => [ Field::SET ],
			'variation_rule'   => Field::PER_VARIATION,
			'getter'           => 'get_thing',
			'setter'           => 'set_thing',
			'choices'          => [ 'a' => 'A' ],
			'filter_operators' => [ 'isAny' ],
			'filter_choices'   => [ 'z' => 'Z' ],
		];
		$this->assertSame( [ 'z' => 'Z' ], ( new Field( ...$base ) )->filter_choices() );
		foreach ( [
			[ 'filter_operators' => [] ],
			[
				'type'    => Field::TEXT,
				'choices' => [],
			],
		] as $change ) {
			try {
				new Field( ...array_merge( $base, $change ) );
				$this->fail( 'Accepted ' . wp_json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is invalid', $e->getMessage() );
			}
		}
	}

	public function test_the_fields_payload_matches_the_snapshot(): void {
		// The whole `GET /fields` payload: fields, actions (T24) and groups.
		$json = (string) wp_json_encode( ( new FieldsController( new Registry() ) )->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		if ( '1' === getenv( 'BULKCAT_UPDATE_SNAPSHOT' ) ) {
			file_put_contents( self::SNAPSHOT, $json );
		}
		$this->assertFileExists( self::SNAPSHOT );
		$this->assertSame( (string) file_get_contents( self::SNAPSHOT ), $json, 'The /fields payload changed. If that is deliberate, run BULKCAT_UPDATE_SNAPSHOT=1 composer test and commit the snapshot.' );
	}

	/**
	 * Every raw storage identifier the registry names: meta keys, post
	 * columns, taxonomies and visibility term slugs.
	 *
	 * @return string[]
	 */
	private static function raw_identifiers(): array {
		$raw = [];
		foreach ( ( new Registry() )->all() as $field ) {
			foreach ( $field->storage()->identifiers() as $identifier ) {
				// "featured" is both a visibility term and the field key, and "ID" the plain
				// label of the ID column; neither is a storage key leaking.
				if ( ! in_array( $identifier, [ 'featured', 'ID' ], true ) ) {
					$raw[ $identifier ] = true;
				}
			}
		}
		foreach ( [ '_regular_price', 'post_title', 'product_cat', 'product_visibility', 'exclude-from-catalog', 'total_sales', 'comment_status' ] as $must ) {
			self::assertArrayHasKey( $must, $raw );
		}
		return array_keys( $raw );
	}

	private static function code( mixed $result ): string {
		return $result instanceof \WP_Error ? (string) $result->get_error_code() : 'none: ' . wp_json_encode( $result );
	}

	/**
	 * @param array<string, bool> $set Keys.
	 * @return string[]
	 */
	private static function sorted_keys( array $set ): array {
		$keys = array_keys( $set );
		sort( $keys );
		return $keys;
	}
}
