<?php
/**
 * The `bulkcat_settings` schema, sanitiser and typed reader (SPEC §11): every
 * setting's default, valid, boundary and invalid values; rejections name the
 * setting; the stored option never holds a key outside the schema.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Settings\Schema;
use BulkCatalogueManager\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsSchemaTest extends TestCase {
	private const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * One default per row of SPEC §11.
	 */
	private const DEFAULTS = [
		'administrators_only'   => false,
		'rows_per_page'         => 25,
		'batch_size'            => 50,
		'variation_batch_size'  => 100,
		'history_days'          => 90,
		'confirm_threshold'     => 200,
		'variations_status'     => true,
		'variations_visibility' => true,
		'variations_price'      => false,
		'variations_stock'      => false,
		'record_unchanged'      => false,
		'category_panel'        => true,
		'csv_separator'         => 'comma',
		'remove_data_on_delete' => false,
	];

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Accepted values: [ key, submitted, stored ]. Includes every boundary.
	 *
	 * @return array<int, array{0: string, 1: mixed, 2: bool|int|string}>
	 */
	private static function valid_cases(): array {
		return [
			[ 'administrators_only', true, true ],
			[ 'administrators_only', false, false ],
			[ 'administrators_only', '1', true ],
			[ 'administrators_only', '0', false ],
			[ 'administrators_only', 1, true ],
			[ 'administrators_only', 'false', false ],
			[ 'rows_per_page', 25, 25 ],
			[ 'rows_per_page', 50, 50 ],
			[ 'rows_per_page', 100, 100 ],
			[ 'rows_per_page', '50', 50 ],
			[ 'batch_size', 10, 10 ],
			[ 'batch_size', 100, 100 ],
			[ 'batch_size', '75', 75 ],
			[ 'variation_batch_size', 10, 10 ],
			[ 'variation_batch_size', 100, 100 ],
			[ 'variation_batch_size', '60', 60 ],
			[ 'history_days', 7, 7 ],
			[ 'history_days', 730, 730 ],
			[ 'history_days', 0, 0 ],
			[ 'history_days', '365', 365 ],
			[ 'confirm_threshold', 0, 0 ],
			[ 'confirm_threshold', 1, 1 ],
			[ 'confirm_threshold', 100000, 100000 ],
			[ 'variations_status', false, false ],
			[ 'variations_visibility', false, false ],
			[ 'variations_price', true, true ],
			[ 'variations_stock', true, true ],
			[ 'record_unchanged', true, true ],
			[ 'category_panel', false, false ],
			[ 'csv_separator', 'comma', 'comma' ],
			[ 'csv_separator', 'semicolon', 'semicolon' ],
			[ 'remove_data_on_delete', true, true ],
		];
	}

	/**
	 * Refused values: [ key, submitted, why ]. Out of range, one step past
	 * each boundary, and the wrong type.
	 *
	 * @return array<int, array{0: string, 1: mixed, 2: string}>
	 */
	private static function invalid_cases(): array {
		return [
			[ 'batch_size', 9, 'below 10' ],
			[ 'batch_size', 101, 'above 100' ],
			[ 'batch_size', 0, 'zero' ],
			[ 'batch_size', 'fifty', 'wrong type: word' ],
			[ 'batch_size', 50.0, 'wrong type: float' ],
			[ 'batch_size', [ 50 ], 'wrong type: array' ],
			[ 'batch_size', null, 'wrong type: null' ],
			[ 'batch_size', '5e1', 'wrong type: exponent string' ],
			[ 'variation_batch_size', 9, 'below 10' ],
			[ 'variation_batch_size', 101, 'above 100' ],
			[ 'history_days', 6, 'below 7' ],
			[ 'history_days', 731, 'above 730' ],
			[ 'history_days', -1, 'negative' ],
			[ 'history_days', '90 days', 'wrong type: text' ],
			[ 'confirm_threshold', -1, 'negative threshold' ],
			[ 'confirm_threshold', '-200', 'negative threshold as text' ],
			[ 'confirm_threshold', true, 'wrong type: boolean' ],
			[ 'rows_per_page', 30, 'not an offered size' ],
			[ 'rows_per_page', 0, 'zero' ],
			[ 'rows_per_page', 200, 'not an offered size' ],
			[ 'administrators_only', 'yes', 'wrong type: word' ],
			[ 'administrators_only', 2, 'wrong type: 2' ],
			[ 'administrators_only', [], 'wrong type: array' ],
			[ 'variations_price', 'on', 'wrong type: word' ],
			[ 'record_unchanged', null, 'wrong type: null' ],
			[ 'category_panel', 1.0, 'wrong type: float' ],
			[ 'remove_data_on_delete', 'TRUE', 'wrong type: upper case' ],
			[ 'csv_separator', 'tab', 'not an offered separator' ],
			[ 'csv_separator', ',', 'the character, not the choice' ],
			[ 'csv_separator', 1, 'wrong type: integer' ],
		];
	}

	public function test_the_schema_holds_every_row_of_section_11_with_its_default(): void {
		$this->assertSame( self::DEFAULTS, Schema::defaults() );
		$this->assertSame( array_keys( self::DEFAULTS ), Schema::keys() );
	}

	public function test_every_setting_has_a_label_through_the_text_domain(): void {
		foreach ( Schema::keys() as $key ) {
			$this->assertNotSame( 'Unknown setting', Schema::label( $key ), "$key has no label." );
		}
		$this->assertNotEmpty( $GLOBALS['bulkcat_test']['gettext'] );
		foreach ( $GLOBALS['bulkcat_test']['gettext'] as [ $text, $domain ] ) {
			$this->assertSame( self::DOMAIN, $domain, "\"$text\" uses the wrong text domain." );
		}
	}

	public function test_valid_and_boundary_values_are_accepted_in_their_stored_type(): void {
		foreach ( self::valid_cases() as [ $key, $submitted, $stored ] ) {
			$result = Schema::validate( [ $key => $submitted ] );
			$label  = $key . ' = ' . json_encode( $submitted );

			$this->assertSame( [], $result->errors(), "$label was refused." );
			$this->assertSame( [ $key => $stored ], $result->values(), $label );
		}
	}

	public function test_invalid_values_are_refused_by_setting_name(): void {
		foreach ( self::invalid_cases() as [ $key, $submitted, $why ] ) {
			$result = Schema::validate( [ $key => $submitted ] );
			$label  = "$key ($why)";

			$this->assertTrue( $result->has_errors(), "$label was accepted." );
			$this->assertSame( [ $key ], array_keys( $result->errors() ), "$label: the rejection must name the setting." );
			$this->assertStringContainsString( Schema::label( $key ), $result->errors()[ $key ], "$label: the message must name the setting." );
			$this->assertSame( [], $result->values(), "$label: nothing may be accepted." );
		}
	}

	public function test_an_unknown_key_is_refused_by_name_and_never_accepted(): void {
		$result = Schema::validate(
			[
				'batch_size'  => 60,
				'secret_hook' => 'x',
			]
		);

		$this->assertSame( [ 'batch_size' => 60 ], $result->values() );
		$this->assertSame( [ 'secret_hook' ], array_keys( $result->errors() ) );
		$this->assertStringContainsString( 'secret_hook', $result->errors()['secret_hook'] );
	}

	public function test_a_submission_that_is_not_a_list_is_refused_naming_the_option(): void {
		foreach ( [ 'batch_size=60', 60, null, true ] as $input ) {
			$result = Schema::validate( $input );
			$this->assertSame( [ Settings::OPTION ], array_keys( $result->errors() ), json_encode( $input ) );
			$this->assertSame( [], $result->values() );
		}
	}

	public function test_saving_stores_exactly_the_schema_keys_and_keeps_refused_values(): void {
		Settings::register();
		update_option(
			Settings::OPTION,
			[
				'rows_per_page'     => 100,
				'batch_size'        => 101,
				'confirm_threshold' => -5,
				'csv_separator'     => 'semicolon',
				'secret_hook'       => 'x',
				7                   => 'numeric key',
			]
		);

		$stored = $GLOBALS['bulkcat_test']['options'][ Settings::OPTION ];
		$this->assertSame( Schema::keys(), array_keys( $stored ), 'The stored option must hold exactly the schema keys.' );
		$this->assertSame( 100, $stored['rows_per_page'] );
		$this->assertSame( 'semicolon', $stored['csv_separator'] );
		$this->assertSame( 50, $stored['batch_size'], 'A refused value keeps the stored one.' );
		$this->assertSame( 200, $stored['confirm_threshold'], 'A refused value keeps the stored one.' );

		$codes = array_column( $GLOBALS['bulkcat_test']['errors'], 1 );
		sort( $codes );
		$this->assertSame( [ 'bulkcat_invalid_7', 'bulkcat_invalid_batch_size', 'bulkcat_invalid_confirm_threshold', 'bulkcat_invalid_secret_hook' ], $codes );
		foreach ( $GLOBALS['bulkcat_test']['errors'] as [ $setting, , $message, $type ] ) {
			$this->assertSame( Settings::OPTION, $setting );
			$this->assertSame( 'error', $type );
			$this->assertNotSame( '', $message );
		}
	}

	public function test_a_later_save_keeps_earlier_values_and_never_grows_a_key(): void {
		Settings::register();
		update_option( Settings::OPTION, [ 'history_days' => 0 ] );
		update_option(
			Settings::OPTION,
			[
				'category_panel' => false,
				'unknown'        => true,
			]
		);
		update_option( Settings::OPTION, 'not a list' );

		$stored = $GLOBALS['bulkcat_test']['options'][ Settings::OPTION ];
		$this->assertSame( Schema::keys(), array_keys( $stored ) );
		$this->assertSame( 0, $stored['history_days'] );
		$this->assertFalse( $stored['category_panel'] );
	}

	public function test_a_stored_option_with_foreign_keys_is_cleaned_on_the_next_save(): void {
		$GLOBALS['bulkcat_test']['options'][ Settings::OPTION ] = [
			'batch_size' => 80,
			'legacy'     => 'x',
		];
		Settings::register();
		update_option( Settings::OPTION, [ 'rows_per_page' => 50 ] );

		$stored = $GLOBALS['bulkcat_test']['options'][ Settings::OPTION ];
		$this->assertSame( Schema::keys(), array_keys( $stored ) );
		$this->assertSame( 80, $stored['batch_size'] );
		$this->assertSame( 50, $stored['rows_per_page'] );
	}

	public function test_the_reader_returns_defaults_when_the_option_is_missing(): void {
		$settings = Settings::load();

		$this->assertFalse( $settings->administrators_only() );
		$this->assertSame( 25, $settings->rows_per_page() );
		$this->assertSame( 50, $settings->batch_size() );
		$this->assertSame( 90, $settings->history_days() );
		$this->assertFalse( $settings->keeps_history_indefinitely() );
		$this->assertSame( 200, $settings->confirm_threshold() );
		$this->assertTrue( $settings->apply_to_variations( 'status' ) );
		$this->assertTrue( $settings->apply_to_variations( 'visibility' ) );
		$this->assertFalse( $settings->apply_to_variations( 'price' ) );
		$this->assertFalse( $settings->apply_to_variations( 'stock' ) );
		$this->assertFalse( $settings->apply_to_variations( 'sku' ), 'A field without a default starts off.' );
		$this->assertFalse( $settings->record_unchanged_rows() );
		$this->assertTrue( $settings->category_panel_open() );
		$this->assertSame( ',', $settings->csv_separator() );
		$this->assertFalse( $settings->remove_data_on_delete() );
		$this->assertSame( self::DEFAULTS, $settings->values() );
	}

	public function test_the_reader_returns_defaults_for_a_corrupt_option(): void {
		foreach ( [ 'a:1:{broken', 42, true, null, (object) [ 'batch_size' => 20 ] ] as $stored ) {
			$GLOBALS['bulkcat_test']['options'][ Settings::OPTION ] = $stored;
			$this->assertSame( self::DEFAULTS, Settings::load()->values(), json_encode( $stored ) );
		}
	}

	public function test_the_reader_returns_the_default_for_each_corrupt_key_only(): void {
		$GLOBALS['bulkcat_test']['options'][ Settings::OPTION ] = [
			'rows_per_page'     => 'many',
			'batch_size'        => 500,
			'history_days'      => 0,
			'confirm_threshold' => -1,
			'csv_separator'     => 'semicolon',
			'variations_price'  => 'maybe',
			'variations_stock'  => true,
			'legacy'            => 'x',
		];
		$settings = Settings::load();

		$this->assertSame( 25, $settings->rows_per_page() );
		$this->assertSame( 50, $settings->batch_size() );
		$this->assertSame( 0, $settings->history_days() );
		$this->assertTrue( $settings->keeps_history_indefinitely() );
		$this->assertSame( 200, $settings->confirm_threshold() );
		$this->assertSame( ';', $settings->csv_separator() );
		$this->assertFalse( $settings->apply_to_variations( 'price' ) );
		$this->assertTrue( $settings->apply_to_variations( 'stock' ) );
		$this->assertSame( Schema::keys(), array_keys( $settings->values() ), 'The reader must not expose a foreign key.' );
	}

	public function test_the_reader_exposes_no_raw_option(): void {
		$GLOBALS['bulkcat_test']['options'][ Settings::OPTION ] = [
			'batch_size' => '60',
			'legacy'     => 'x',
		];
		$settings = Settings::load();

		$this->assertSame( 60, $settings->batch_size(), 'A stored numeric string reads as an integer.' );
		$this->assertNotSame( $GLOBALS['bulkcat_test']['options'][ Settings::OPTION ], $settings->values() );
		foreach ( ( new \ReflectionClass( Settings::class ) )->getProperties() as $property ) {
			$this->assertFalse( $property->isPublic(), $property->getName() . ' must not be public.' );
		}
	}

	public function test_boot_registers_the_option_with_its_sanitiser_on_init(): void {
		$plugin = new Plugin();
		$plugin->boot();
		$this->assertArrayNotHasKey( Settings::OPTION, $GLOBALS['bulkcat_test']['settings'], 'Nothing registers before init.' );

		do_action( 'init' );

		$this->assertArrayHasKey( Settings::OPTION, $GLOBALS['bulkcat_test']['settings'] );
		$args = $GLOBALS['bulkcat_test']['settings'][ Settings::OPTION ];
		$this->assertSame( Settings::OPTION, $args['group'] );
		$this->assertSame( 'object', $args['type'] );
		$this->assertSame( [ Settings::class, 'sanitize' ], $args['sanitize_callback'] );
		$this->assertSame( self::DEFAULTS, $args['default'] );
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['filters'][ 'sanitize_option_' . Settings::OPTION ] );
	}
}
