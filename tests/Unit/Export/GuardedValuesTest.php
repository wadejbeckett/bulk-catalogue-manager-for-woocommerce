<?php
/**
 * The dialog's guarded counts (SPEC §10, T98, R3, amendment 1): rows whose
 * own SKU would not read back unchanged through WooCommerce's importer (the
 * formula guard, or `wc_clean()` would change it) and rows whose name the
 * writer guards, counted for exactly the rows the export writes, from `ID`,
 * `post_title` and `_sku` read in keyset pages without hydrating a row. The
 * statements run through the in-memory SqlEvaluator.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\GuardedValues;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;
use PHPUnit\Framework\TestCase;

final class GuardedValuesTest extends TestCase {
	/**
	 * The statements the counter sent.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	protected function setUp(): void {
		bulkcat_test_reset();
		$this->statements = [];
	}

	/**
	 * Seed posts (and their SKUs in postmeta and the lookup) and route every SELECT to the evaluator.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3?: string, 4?: int}> $rows Id => [ post type, title, sku, status, parent ].
	 */
	private function seed( array $rows ): void {
		global $wpdb;
		$tables = [
			'wp_posts'                  => [],
			'wp_postmeta'               => [],
			'wp_wc_product_meta_lookup' => [],
			'wp_term_relationships'     => [],
			'wp_term_taxonomy'          => [],
			'wp_terms'                  => [],
		];
		$meta   = 0;
		foreach ( $rows as $id => $row ) {
			$tables['wp_posts'][] = [
				'ID'                => $id,
				'post_type'         => $row[0],
				'post_title'        => $row[1],
				'post_status'       => $row[3] ?? 'publish',
				'post_parent'       => $row[4] ?? 0,
				'menu_order'        => 0,
				'post_date_gmt'     => '2026-09-01 08:00:00',
				'post_modified_gmt' => '2026-09-01 08:00:00',
			];
			if ( '' !== $row[2] ) {
				$tables['wp_postmeta'][] = [
					'meta_id'    => ++$meta,
					'post_id'    => $id,
					'meta_key'   => '_sku',
					'meta_value' => $row[2],
				];
			}
			$tables['wp_postmeta'][]               = [
				'meta_id'    => ++$meta,
				'post_id'    => $id,
				'meta_key'   => '_price',
				'meta_value' => '1',
			];
			$tables['wp_wc_product_meta_lookup'][] = [
				'product_id'     => $id,
				'sku'            => $row[2],
				'min_price'      => 1,
				'max_price'      => 1,
				'stock_status'   => 'instock',
				'stock_quantity' => null,
				'tax_class'      => '',
				'total_sales'    => 0,
			];
		}
		$db              = new SqlEvaluator( $tables );
		$wpdb->responses = [
			'/^SELECT /' => function ( string $sql ) use ( $db ): array {
				$this->statements[] = $sql;
				return $db->rows( $sql );
			},
		];
	}

	/**
	 * The fixture: plain rows, and rows whose SKU or name would change.
	 */
	private function catalogue(): void {
		$this->seed(
			[
				1  => [ 'product', 'Plain Mug', 'MUG-1' ],
				2  => [ 'product', 'Tom & Jerry\'s "Classic" Mug', '-P1' ],
				3  => [ 'product', '@home Mug', '0' ],
				4  => [ 'product', 'Spaced Mug', ' SPACE' ],
				5  => [ 'product', '=cmd Mug', 'id:5' ],
				6  => [ 'product', 'Tagged Mug', '<b>T</b>' ],
				7  => [ 'product', 'Coded Mug', 'C%20D' ],
				8  => [ 'product', '  -leading Mug', "LINE\nBREAK" ],
				9  => [ 'product', 'Binned Mug', '=BIN', 'trash' ],
				10 => [ 'product_variation', 'Plain Mug - Blue', '+V', 'publish', 1 ],
				11 => [ 'product', 'No SKU Mug', '' ],
				12 => [ 'page', '=Not a product', '=PAGE' ],
			]
		);
	}

	public function test_counts_rows_whose_sku_would_not_read_back(): void {
		$this->catalogue();
		$counts = ( new GuardedValues() )->of_ids( [ 1, 2, 3, 4, 5, 6, 7, 8, 10, 11 ] );
		// -P1, SPACE (edge space), <b>T</b>, C%20D, LINE\nBREAK and +V; 0 and id:5 read back as a row's own SKU; no SKU is nothing to change.
		$this->assertSame( 6, $counts['identifiers'] );
	}

	public function test_counts_rows_whose_name_the_writer_would_guard(): void {
		$this->catalogue();
		$counts = ( new GuardedValues() )->of_ids( [ 1, 2, 3, 4, 5, 6, 7, 8, 10, 11 ] );
		// @home Mug, =cmd Mug and "  -leading Mug"; & and quotes are not guarded.
		$this->assertSame( 3, $counts['names'] );
	}

	public function test_names_are_not_counted_when_the_name_is_not_exported(): void {
		// Lead review minor: a file without the Name column has no guarded name to warn about.
		$this->catalogue();
		$ids = [ 1, 2, 3, 4, 5, 6, 7, 8, 10, 11 ];
		$this->assertSame(
			[
				'identifiers' => 6,
				'names'       => 0,
			],
			( new GuardedValues( GuardedValues::PAGE, false ) )->of_ids( $ids )
		);
		$this->assertSame(
			[
				'identifiers' => 5,
				'names'       => 0,
			],
			( new GuardedValues( GuardedValues::PAGE, false ) )->of_query( new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ) ) )
		);
		$this->assertSame( 3, ( new GuardedValues() )->of_ids( $ids )['names'], 'Counted by default.' );
	}

	public function test_counts_only_the_rows_the_export_writes(): void {
		$this->catalogue();
		// A filter: products only, listed statuses (the binned one and the page never list), searched.
		$all = ( new GuardedValues() )->of_query( new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ) ) );
		$this->assertSame(
			[
				'identifiers' => 5,
				'names'       => 3,
			],
			$all,
			'The variation (+V), the binned product (=BIN) and the page (=PAGE) are not in a products export.'
		);
		$both = ( new GuardedValues() )->of_query( new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ) ) );
		$this->assertSame( 6, $both['identifiers'], 'With variations, +V is written too.' );
		$search = ( new GuardedValues() )->of_query(
			new ProductQuery(
				Criteria::from_array(
					[
						'mode'   => Criteria::MODE_PRODUCTS,
						'search' => 'Tagged',
					]
				)
			)
		);
		$this->assertSame(
			[
				'identifiers' => 1,
				'names'       => 0,
			],
			$search
		);
		// A selection's present members: the binned product and the page are left out, so not counted (T99).
		$present = ProductQuery::present_members( range( 1, 12 ) );
		$this->assertSame( [ 1, 2, 3, 4, 5, 6, 7, 8, 10, 11 ], array_keys( $present ) );
		$this->assertSame(
			[
				'identifiers' => 6,
				'names'       => 3,
			],
			( new GuardedValues() )->of_ids( array_keys( $present ) ),
			'=BIN and =PAGE are left out of the file, so out of the counts.'
		);
		// Given ids: only those, however many other rows are guarded.
		$this->assertSame(
			[
				'identifiers' => 0,
				'names'       => 0,
			],
			( new GuardedValues() )->of_ids( [ 1, 11 ] )
		);
		$this->assertSame(
			[
				'identifiers' => 0,
				'names'       => 0,
			],
			( new GuardedValues() )->of_ids( [] )
		);
	}

	public function test_reads_in_keyset_pages_of_5000_and_never_hydrates(): void {
		$this->assertSame( 5000, GuardedValues::PAGE );
		$this->catalogue();
		$GLOBALS['bulkcat_test']['calls'] = [];

		( new GuardedValues() )->of_query( new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ) ) );
		$this->assertCount( 1, $this->statements, 'Ten rows fit one page of 5,000.' );
		$this->assertStringEndsWith( 'ORDER BY p.ID ASC LIMIT 5000', $this->statements[0] );
		$this->assertStringContainsString( "(SELECT m.meta_value FROM `wp_postmeta` AS m WHERE m.post_id = p.ID AND m.meta_key = '_sku' LIMIT 1) AS sku", $this->statements[0] );

		$this->statements = [];
		$counts           = ( new GuardedValues( 3 ) )->of_query( new ProductQuery( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ) ) );
		$this->assertSame( 6, $counts['identifiers'], 'Keyset pages count the same rows.' );
		$this->assertCount( 4, $this->statements, 'Ten rows in pages of three: three full pages, then a short one.' );
		$this->assertStringContainsString( 'AND p.ID > 0 ORDER BY', $this->statements[0] );
		$this->assertStringContainsString( 'AND p.ID > 3 ORDER BY', $this->statements[1] );
		$this->assertStringContainsString( 'AND p.ID > 6 ORDER BY', $this->statements[2] );

		$this->statements = [];
		$counts           = ( new GuardedValues( 3 ) )->of_ids( [ 1, 2, 3, 4, 5, 6, 7, 8, 10, 11 ] );
		$this->assertSame( 6, $counts['identifiers'] );
		$this->assertCount( 4, $this->statements, 'Ten ids in pages of three.' );
		$this->assertStringContainsString( 'p.ID IN (1, 2, 3)', $this->statements[0] );

		$reads = array_filter( $GLOBALS['bulkcat_test']['calls'], static fn( array $call ): bool => in_array( $call[0], [ '_prime_post_caches', 'update_meta_cache', 'get_post', 'get_post_meta', 'wp_get_object_terms' ], true ) );
		$this->assertSame( [], $reads, 'No row is hydrated.' );
	}
}
