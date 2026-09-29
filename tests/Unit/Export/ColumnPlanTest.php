<?php
/**
 * The export's column plan (SPEC §10, T97): with the identifier switch off
 * the columns are exactly as sent and the header is each column's registry
 * label; with it on, `ID`, `SKU`, `Parent` and `Type` come first in English
 * in every site language, the sent columns follow in their order without
 * repeating an identifier, and a column outside `ColumnPlan::ROUND_TRIP`
 * keeps its values but its heading gains "(for reference)" (R5).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\ColumnPlan;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Fields\Storage;
use PHPUnit\Framework\TestCase;

final class ColumnPlanTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	public function test_switch_off_keeps_the_sent_columns_and_gives_no_labels(): void {
		$sent = [ 'name', 'sku', 'category_ids', 'regular_price', 'type', 'id' ];
		$plan = new ColumnPlan( new Registry(), $sent, false );
		$this->assertFalse( $plan->identifiers() );
		$this->assertSame( $sent, $plan->columns() );
		$this->assertNull( $plan->labels(), 'CsvExport then writes each registry label, as today.' );
		$this->assertSame( $sent, $plan->fields() );
	}

	public function test_switch_on_starts_with_id_sku_parent_type_then_the_sent_columns_in_order(): void {
		$plan = new ColumnPlan( new Registry(), [ 'name', 'regular_price', 'stock_status', 'status' ], true );
		$this->assertTrue( $plan->identifiers() );
		$this->assertSame( [ 'id', 'sku', 'parent_sku', 'type', 'name', 'regular_price', 'stock_status', 'status' ], $plan->columns() );
		$this->assertSame(
			[
				'id'            => 'ID',
				'sku'           => 'SKU',
				'parent_sku'    => 'Parent',
				'type'          => 'Type',
				'name'          => 'Name',
				'regular_price' => 'Regular price',
				'stock_status'  => 'Stock status',
				'status'        => 'Status',
			],
			$plan->labels()
		);
		$this->assertSame( ColumnPlan::IDENTIFIERS, array_slice( $plan->columns(), 0, 4 ) );
	}

	public function test_a_sent_id_sku_or_type_column_appears_once_in_the_identifier_block(): void {
		$plan = new ColumnPlan( new Registry(), [ 'name', 'type', 'sku', 'regular_price', 'id' ], true );
		$this->assertSame( [ 'id', 'sku', 'parent_sku', 'type', 'name', 'regular_price' ], $plan->columns() );
		$this->assertSame( count( $plan->columns() ), count( array_unique( $plan->columns() ) ) );
		$this->assertSame( array_keys( (array) $plan->labels() ), $plan->columns(), 'One heading per column, in column order.' );
	}

	public function test_identifier_headings_stay_english_under_a_translation(): void {
		$GLOBALS['bulkcat_test']['translate'] = [
			'ID'     => 'Kennung',
			'SKU'    => 'Artikelnummer',
			'Type'   => 'Typ',
			'Parent' => 'Übergeordnet',
			'Name'   => 'Bezeichnung',
		];
		$registry                             = new Registry();
		$this->assertSame( 'Kennung', $registry->get( 'id' )->label(), 'The translation reaches the registry.' );
		$labels = ( new ColumnPlan( $registry, [ 'name', 'id', 'sku', 'type' ], true ) )->labels();
		$this->assertSame(
			[
				'id'         => 'ID',
				'sku'        => 'SKU',
				'parent_sku' => 'Parent',
				'type'       => 'Type',
				'name'       => 'Bezeichnung',
			],
			$labels
		);
	}

	public function test_the_v1_registry_puts_for_reference_on_categories_tags_shipping_class_and_price_only(): void {
		$registry = new Registry();
		$plan     = new ColumnPlan( $registry, $registry->keys(), true );
		$suffixed = [];
		foreach ( (array) $plan->labels() as $key => $heading ) {
			if ( in_array( $key, ColumnPlan::IDENTIFIERS, true ) ) {
				continue;
			}
			$label = $registry->get( $key )->label();
			if ( $heading !== $label ) {
				$this->assertSame( $label . ' (for reference)', $heading, $key );
				$suffixed[] = $key;
			}
		}
		$this->assertSame( [ 'category_ids', 'tag_ids', 'shipping_class_id', 'price_html' ], $suffixed );
		// R5 pins the list: every registry field is either proven or not mapped, or is one of the four.
		$this->assertSame(
			[ 'category_ids', 'tag_ids', 'shipping_class_id', 'price_html' ],
			array_values( array_diff( $registry->keys(), ColumnPlan::ROUND_TRIP ) )
		);
		$this->assertSame( [], array_diff( ColumnPlan::ROUND_TRIP, $registry->keys() ), 'ROUND_TRIP names only registry fields.' );
	}

	public function test_a_column_outside_the_round_trip_list_is_for_reference(): void {
		// A field added later, such as an external one, is for reference until the round trip proves it.
		$registry = new Registry(
			[
				...array_values( ( new Registry() )->all() ),
				new Field(
					key: 'supplier_code',
					label: 'Supplier code',
					type: Field::TEXT,
					storage: Storage::meta( '_supplier_code' ),
					edit_kinds: [],
					variation_rule: Field::PER_VARIATION,
					getter: 'get_supplier_code',
					setter: null
				),
			]
		);
		$labels   = ( new ColumnPlan( $registry, [ 'name', 'supplier_code', 'weight' ], true ) )->labels();
		$this->assertSame( 'Supplier code (for reference)', $labels['supplier_code'] );
		$this->assertSame( 'Weight', $labels['weight'] );
		$this->assertSame( 'Name', $labels['name'] );
		$this->assertNull( ( new ColumnPlan( $registry, [ 'name', 'supplier_code' ], false ) )->labels(), 'Switch off: registry labels, no suffix.' );
	}

	public function test_a_translation_that_drops_the_marker_falls_back_to_english(): void {
		foreach ( [ '%s', ' %s ', 'Nur zur Ansicht', '' ] as $broken ) {
			$GLOBALS['bulkcat_test']['translate'] = [ '%s (for reference)' => $broken ];
			$labels                               = ( new ColumnPlan( new Registry(), [ 'name', 'category_ids' ], true ) )->labels();
			$this->assertSame( 'Categories (for reference)', $labels['category_ids'], wp_json_encode( $broken ) );
		}
		$GLOBALS['bulkcat_test']['translate'] = [ '%s (for reference)' => '%s (nur zur Ansicht)' ];
		$labels                               = ( new ColumnPlan( new Registry(), [ 'name', 'category_ids' ], true ) )->labels();
		$this->assertSame( 'Categories (nur zur Ansicht)', $labels['category_ids'], 'A translation keeping the label and a marker is used.' );
	}

	public function test_the_rows_read_carry_id_sku_and_type_only_when_the_switch_is_on(): void {
		$registry = new Registry();
		$this->assertSame( [ 'name', 'regular_price' ], ( new ColumnPlan( $registry, [ 'name', 'regular_price' ], false ) )->fields() );
		$fields = ( new ColumnPlan( $registry, [ 'name', 'regular_price' ], true ) )->fields();
		$this->assertSame( [ 'name', 'regular_price', 'id', 'sku', 'type' ], $fields );
		$this->assertNotContains( 'parent_sku', $fields, 'Parent is not a registry field: it comes from ReimportIdentifiers.' );
		$this->assertSame( [ 'sku', 'name', 'id', 'type' ], ( new ColumnPlan( $registry, [ 'sku', 'name' ], true ) )->fields(), 'Nothing is read twice.' );
		foreach ( $fields as $key ) {
			$this->assertTrue( $registry->has( $key ), $key );
		}
	}

	public function test_switch_on_leaves_every_other_cell_as_the_switch_off_file_has_it(): void {
		$registry = new Registry();
		$rows     = [
			[
				'id'            => 7,
				'parent_id'     => 0,
				'name'          => '=SUM(A1)',
				'sku'           => 'S-7',
				'type'          => 'simple',
				'category_ids'  => [ 12, 15 ],
				'regular_price' => '10.50',
				'featured'      => true,
				'price_html'    => '<span>R10.50</span>',
			],
			[
				'id'            => 9,
				'parent_id'     => 7,
				'name'          => 'Tom & Jerry\'s "Classic" Mug',
				'sku'           => '',
				'type'          => 'variation',
				'category_ids'  => [],
				'regular_price' => '',
				'featured'      => false,
				'price_html'    => '',
			],
		];
		$source   = new CallableRowSource(
			static fn( array $params, int $page, int $per_page ): array => [
				'items' => 1 === $page ? $rows : [],
				'total' => count( $rows ),
			]
		);
		$sent     = [ 'name', 'category_ids', 'regular_price', 'featured', 'price_html' ];
		$read     = static function ( ColumnPlan $plan ) use ( $source, $registry ): array {
			$export = new CsvExport( $source, $registry, $plan->columns(), [ 'fields' => $plan->fields() ], ';', 'x.csv', $plan->labels() );
			$stream = fopen( 'php://memory', 'w+b' );
			$export->stream( $stream );
			rewind( $stream );
			$lines = explode( CsvWriter::EOL, substr( (string) stream_get_contents( $stream ), strlen( CsvWriter::BOM ) ) );
			return array_map( static fn( string $line ): array => str_getcsv( $line, ';', '"', '' ), array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
		};
		$off      = $read( new ColumnPlan( $registry, $sent, false ) );
		$on       = $read( new ColumnPlan( $registry, $sent, true ) );
		$this->assertSame( [ 'Name', 'Categories', 'Regular price', 'Featured', 'Price' ], $off[0] );
		$this->assertSame( [ 'ID', 'SKU', 'Parent', 'Type', 'Name', 'Categories (for reference)', 'Regular price', 'Featured', 'Price (for reference)' ], $on[0] );
		$this->assertCount( 3, $off );
		$this->assertCount( 3, $on );
		for ( $line = 1; $line <= 2; ++$line ) {
			$this->assertSame( $off[ $line ], array_slice( $on[ $line ], 4 ), "Row $line: every cell after the identifier block is the switch-off cell." );
		}
		$this->assertSame( "'=SUM(A1)", $on[1][4], 'The guard still applies.' );
	}
}
