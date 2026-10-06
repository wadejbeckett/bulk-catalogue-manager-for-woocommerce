<?php
/**
 * History in plain words for categories (ticket 133 bug 3): a category by
 * its path with entities decoded, parents and the default by path, images
 * by title, display types by WooCommerce's labels, product category lists
 * by names, a deleted category by the path its delete journalled, and a
 * real headline for every category change and for a product change that
 * only moves products between categories.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Deleter;
use BulkCatalogueManager\Categories\HistoryText;
use BulkCatalogueManager\Categories\Writer;
use BulkCatalogueManager\Categories\NativeForm;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Rest\BatchChangesController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';

final class HistoryTextTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		CatalogueFixture::seed();
		HistoryText::reset();
		$GLOBALS['bulkcat_test']['posts'][504] = [
			'post_title' => 'Red &amp; white banner',
			'post_type'  => 'attachment',
		];
	}

	/**
	 * A journal row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $type, string $prop, mixed $old, mixed $new ): array {
		return [
			'change_id'    => 1,
			'batch_id'     => 5,
			'object_id'    => $id,
			'object_type'  => $type,
			'prop'         => $prop,
			'old_value'    => $old,
			'new_value'    => $new,
			'modified_gmt' => '0000-00-00 00:00:00',
			'applied_gmt'  => '2026-10-05 08:00:00',
			'result'       => 'applied',
			'message'      => '',
		];
	}

	public function test_rows_name_categories_parents_images_and_lists_in_words(): void {
		$this->assertSame( 'Clothing › Men › Shirts › Formal & Office', BatchChangesController::name( 4, 'term' ), 'A category by its path, never "Category 4", no &amp;.' );
		$this->assertSame( [ 'Clothing › Men', 'Top level' ], HistoryText::values( self::row( 3, 'term', 'term_parent', 2, 0 ) ) );
		$this->assertSame( [ 'Formal', 'Formal & Office' ], HistoryText::values( self::row( 4, 'term', 'term_name', 'Formal', 'Formal &amp; Office' ) ) );
		$this->assertSame( [ 'No image', 'Red & white banner' ], HistoryText::values( self::row( 3, 'term', 'term_thumbnail_id', 0, 504 ) ) );
		$this->assertSame( [ 'Default', 'Subcategories' ], HistoryText::values( self::row( 3, 'term', 'term_display_type', '', 'subcategories' ) ) );
		$this->assertSame( [ 'Orphans', 'Clothing › Women' ], HistoryText::values( self::row( 5, 'term', Writer::DEFAULT_PROP, 9, 5 ) ) );
		$this->assertSame( [ 'Men, Women', 'Women' ], HistoryText::values( self::row( 101, 'product', 'category_ids', [ 2, 5 ], [ 5 ] ) ) );
		$this->assertNull( HistoryText::values( self::row( 101, 'product', 'regular_price', '1', '2' ) ), 'Other fields are formatted by the screen.' );
	}

	public function test_a_category_hidden_from_term_queries_is_named_not_called_deleted(): void {
		// Another plugin's filtering of term queries hides Shirts (3) and Men (2) from this viewer; both exist.
		$GLOBALS['bulkcat_test']['hidden_terms'] = [ 2, 3 ];
		$this->assertSame( 'Clothing › Men › Shirts', HistoryText::path( 3 ) );
	}

	public function test_other_plugins_fields_read_as_a_count_never_as_keys(): void {
		$row  = self::row(
			3,
			'term',
			NativeForm::OTHER_PROP,
			[ 'vendor_flag' => [ '1' ] ],
			[
				'vendor_flag' => [ '0' ],
				'vendor_list' => [ '' ],
			]
		);
		$read = ( new BatchChangesController( new BatchRepository(), new ChangeRepository(), new Fields() ) )->change( $row );
		$this->assertSame( [ 'Other fields', '', '2 fields changed' ], [ $read['field_label'], $read['old_value'], $read['new_value'] ] );
		$this->assertSame( [ '', '2 fields changed' ], [ $read['old_text'], $read['new_text'] ] );
		$this->assertStringNotContainsString( 'vendor_', wp_json_encode( array_diff_key( $read, [ 'detail' => true ] ) ) );
	}

	public function test_a_deleted_category_reads_by_the_path_its_delete_recorded(): void {
		$GLOBALS['wpdb']->responses['/^SELECT object_id, old_value FROM `wp_bulkcat_changes` WHERE object_type = \'term\' AND prop = \'term_deleted\'/'] = [
			[
				'object_id' => '77',
				'old_value' => '"Kitchen › Saucepans"',
			],
		];
		BatchChangesController::prime_names( [ self::row( 101, 'product', 'category_ids', [ 77, 5 ], [ 5 ] ) ] );
		$this->assertSame( [ 'Saucepans, Women', 'Women' ], HistoryText::values( self::row( 101, 'product', 'category_ids', [ 77, 5 ], [ 5 ] ) ) );
		$this->assertSame( 'Kitchen › Saucepans', BatchChangesController::name( 77, 'term' ) );
		$this->assertSame( 'A deleted category', HistoryText::path( 78 ) );
	}

	public function test_every_category_change_has_a_headline_with_names(): void {
		$tree = static fn( string $action, array $args, int $id = 3 ): ?string => HistoryText::headline(
			[
				'source'      => 'tree',
				'operations'  => [
					[
						'action' => $action,
						'args'   => $args,
					],
				],
				'selection'   => [ 'ids' => [ $id ] ],
				'total_items' => 1,
			]
		);
		$this->assertSame(
			'Renamed category Shirts to Golf shirts',
			$tree(
				Writer::EDIT,
				[
					'name' => 'Golf shirts',
					'id'   => 3,
					'from' => [ 'name' => 'Shirts' ],
				]
			)
		);
		$this->assertSame( 'Renamed a category to Golf shirts', $tree( Writer::EDIT, [ 'name' => 'Golf shirts' ] ), 'A batch from before names were recorded.' );
		$this->assertSame(
			'Moved category Shirts to Shoes › Boots',
			$tree(
				Writer::MOVE,
				[
					'parent'   => 7,
					'position' => null,
					'name'     => 'Shirts',
					'from'     => [ 'parent' => 2 ],
				]
			)
		);
		$this->assertSame(
			'Reordered category Shirts in Clothing › Men',
			$tree(
				Writer::MOVE,
				[
					'parent'   => 2,
					'position' => 0,
					'name'     => 'Shirts',
					'from'     => [ 'parent' => 2 ],
				]
			)
		);
		$this->assertSame( 'Changed the description of category Shirts', $tree( Writer::EDIT, [ 'description' => 'New' ] ) );
		$this->assertSame( 'Changed the display type or image of category Shirts', $tree( Writer::EDIT, [ 'thumbnail_id' => 504 ] ) );
		$this->assertSame(
			'Created category Clothing › Hats',
			$tree(
				Writer::CREATE,
				[
					'name'   => 'Hats',
					'parent' => 1,
				],
				12
			)
		);
		$this->assertSame( 'Made Clothing › Women the default category', $tree( Writer::MAKE_DEFAULT, [ 'id' => 5 ], 5 ) );
		$this->assertSame(
			'Deleted categories Clothing › Men, Shoes › Boots',
			$tree(
				Deleter::DELETE,
				[
					'ids'   => [ 2, 7 ],
					'names' => [
						'2' => 'Clothing › Men',
						'7' => 'Shoes › Boots',
					],
				]
			)
		);
		$this->assertSame(
			'Merged category Shoes › Boots into Clothing',
			$tree(
				Deleter::MERGE,
				[
					'ids'   => [ 7 ],
					'names' => [ '7' => 'Shoes › Boots' ],
					'into'  => 1,
					'path'  => 'Clothing',
				]
			)
		);
	}

	public function test_a_product_change_moving_categories_says_so(): void {
		$batch = static fn( array $steps ): ?string => HistoryText::headline(
			[
				'source'      => 'bulk',
				'operations'  => array_map(
					static fn( array $step ): array => [
						'action' => $step[0],
						'args'   => [
							'field' => $step[1],
							'terms' => $step[2],
						],
					],
					$steps
				),
				'total_items' => 15,
			]
		);
		$this->assertSame(
			'Moved 15 products from Women to Clothing › Men › Shirts › Formal & Office',
			$batch(
				[
					[ 'add_terms', 'category_ids', [ 4 ] ],
					[ 'remove_terms', 'category_ids', [ 5 ] ],
				]
			)
		);
		$this->assertSame( 'Added Shoes to 15 products', $batch( [ [ 'add_terms', 'category_ids', [ 6 ] ] ] ) );
		$this->assertSame( 'Removed Shoes from 15 products', $batch( [ [ 'remove_terms', 'category_ids', [ 6 ] ] ] ) );
		$this->assertNull( $batch( [ [ 'add_terms', 'tag_ids', [ 6 ] ] ] ), 'Tags keep the screen\'s own words.' );
	}
}
