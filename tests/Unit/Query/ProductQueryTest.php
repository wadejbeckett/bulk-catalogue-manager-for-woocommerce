<?php
/**
 * The lean product query builder (T15; SPEC §4.1, §4.2, §12.2, §13): a matrix
 * of filter, search, mode and multi-column sort combinations pins the SQL of
 * the page statement; the count statement shares its conditions; the term
 * sub-queries appear only with a taxonomy filter; every value, including a
 * search holding `%`, `_`, a quote and `%s`, reaches SQL only as a prepare()
 * argument; every table comes from the allow-list and every statement ends in
 * a LIMIT.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

final class ProductQueryTest extends TestCase {
	/**
	 * The clock the "in the past" filters count back from.
	 */
	private const NOW = '2026-09-23 12:00:00';

	/**
	 * Shorthands the pinned SQL expands.
	 */
	private const FRAGMENTS = [
		'{FROM}'   => 'FROM `wp_posts` AS p LEFT JOIN `wp_wc_product_meta_lookup` AS l ON l.product_id = p.ID',
		'{STATUS}' => "p.post_status IN ('publish', 'future', 'draft', 'pending', 'private')",
		'{SLUG}'   => 'FROM `wp_term_relationships` AS tr INNER JOIN `wp_term_taxonomy` AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN `wp_terms` AS t ON t.term_id = tt.term_id',
		'{TERM}'   => 'FROM `wp_term_relationships` AS tr INNER JOIN `wp_term_taxonomy` AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id',
		'{OWNER}'  => "(CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END)",
	];

	/**
	 * A search holding both LIKE wildcards, a quote and a placeholder.
	 */
	private const HOSTILE_SEARCH = "50% off_'%s";

	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function db(): \BulkCatalogueManager\Tests\Support\WpdbStub {
		return $GLOBALS['wpdb'];
	}

	private static function query( array $args ): ProductQuery {
		return new ProductQuery( Criteria::from_array( $args ), new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) );
	}

	private static function normalise( string $sql ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	}

	/**
	 * Name => [ arguments, the page statement as the recording $wpdb prepares it ].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	private static function matrix(): array {
		return [
			'defaults'                                 => [
				[],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, p.post_date_gmt AS date_created {FROM}
				WHERE p.post_type IN ('product') AND {STATUS}
				ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT 25 OFFSET 0
				SQL,
			],
			'every variation that is out of stock'     => [
				[
					'mode'    => 'variations',
					'filters' => [
						[
							'field'    => 'stock_status',
							'operator' => 'isAny',
							'value'    => [ 'outofstock' ],
						],
					],
					'orderby' => 'sku',
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, l.sku AS sku {FROM}
				WHERE p.post_type IN ('product_variation') AND {STATUS} AND l.stock_status IN ('outofstock')
				ORDER BY l.sku ASC, p.ID ASC LIMIT 25 OFFSET 0
				SQL,
			],
			'category resolves against the parent'     => [
				[
					'mode'    => 'products_and_variations',
					'filters' => [
						[
							'field'    => 'category_ids',
							'operator' => 'isAny',
							'value'    => [ 12 ],
						],
					],
					'orderby' => 'name',
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, p.post_title AS name {FROM}
				WHERE p.post_type IN ('product', 'product_variation') AND {STATUS}
				AND EXISTS (SELECT 1 {TERM} WHERE tr.object_id = {OWNER} AND tt.taxonomy = 'product_cat' AND tt.term_id IN (12))
				ORDER BY p.post_title ASC, p.ID ASC LIMIT 25 OFFSET 0
				SQL,
			],
			'no tags, a stock range and two sort keys' => [
				[
					'filters'  => [
						[
							'field'    => 'tag_ids',
							'operator' => 'isNone',
							'value'    => [ 5, '4' ],
						],
						[
							'field'    => 'stock_quantity',
							'operator' => 'between',
							'value'    => [ 0, '10' ],
						],
					],
					'orderby'  => [ 'stock_quantity', 'name' ],
					'order'    => [ 'desc', 'asc' ],
					'page'     => 2,
					'per_page' => 50,
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, l.stock_quantity AS stock_quantity, p.post_title AS name {FROM}
				WHERE p.post_type IN ('product') AND {STATUS}
				AND NOT EXISTS (SELECT 1 {TERM} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_tag' AND tt.term_id IN (4, 5))
				AND (l.stock_quantity >= 0 AND l.stock_quantity <= 10)
				ORDER BY l.stock_quantity DESC, p.post_title ASC, p.ID ASC LIMIT 50 OFFSET 50
				SQL,
			],
			'featured, unmanaged stock and tax class'  => [
				[
					'filters' => [
						[
							'field'    => 'featured',
							'operator' => 'is',
							'value'    => true,
						],
						[
							'field'    => 'manage_stock',
							'operator' => 'is',
							'value'    => 'false',
						],
						[
							'field'    => 'tax_class',
							'operator' => 'isAny',
							'value'    => [ '', 'reduced-rate' ],
						],
					],
					'orderby' => 'id',
					'order'   => 'DESC',
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id {FROM}
				WHERE p.post_type IN ('product') AND {STATUS}
				AND EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_visibility' AND t.slug IN ('featured'))
				AND l.stock_quantity IS NULL AND l.tax_class IN ('', 'reduced-rate')
				ORDER BY p.ID DESC LIMIT 25 OFFSET 0
				SQL,
			],
			'catalogue visibility and type'            => [
				[
					'filters' => [
						[
							'field'    => 'catalog_visibility',
							'operator' => 'isAny',
							'value'    => [ 'catalog', 'hidden' ],
						],
						[
							'field'    => 'type',
							'operator' => 'isNone',
							'value'    => [ 'grouped', 'external' ],
						],
					],
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, p.post_date_gmt AS date_created {FROM}
				WHERE p.post_type IN ('product') AND {STATUS}
				AND ((NOT EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_visibility' AND t.slug IN ('exclude-from-catalog'))
				AND EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_visibility' AND t.slug IN ('exclude-from-search')))
				OR (EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_visibility' AND t.slug IN ('exclude-from-catalog'))
				AND EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_visibility' AND t.slug IN ('exclude-from-search'))))
				AND NOT (EXISTS (SELECT 1 {SLUG} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug IN ('grouped', 'external')))
				ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT 25 OFFSET 0
				SQL,
			],
			'variation filters on parent and row'      => [
				[
					'mode'    => 'variations',
					'filters' => [
						[
							'field'    => 'category_ids',
							'operator' => 'isAll',
							'value'    => [ 7, 3, 7 ],
						],
						[
							'field'    => 'shipping_class_id',
							'operator' => 'isAny',
							'value'    => [ 9 ],
						],
						[
							'field'    => 'status',
							'operator' => 'isNone',
							'value'    => [ 'private' ],
						],
						[
							'field'    => 'regular_price',
							'operator' => 'greaterThanOrEqual',
							'value'    => '5',
						],
					],
					'orderby' => 'regular_price',
					'order'   => 'desc',
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, l.max_price AS regular_price {FROM}
				WHERE p.post_type IN ('product_variation') AND {STATUS}
				AND (SELECT COUNT(DISTINCT tt.term_id) {TERM} WHERE tr.object_id = p.post_parent AND tt.taxonomy = 'product_cat' AND tt.term_id IN (3, 7)) = 2
				AND EXISTS (SELECT 1 {TERM} WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_shipping_class' AND tt.term_id IN (9))
				AND p.post_status NOT IN ('private') AND l.max_price >= 5
				ORDER BY l.max_price DESC, p.ID DESC LIMIT 25 OFFSET 0
				SQL,
			],
			'search, dates and a clamped page size'    => [
				[
					'search'   => '  ' . self::HOSTILE_SEARCH . ' ',
					'filters'  => [
						[
							'field'    => 'date_modified',
							'operator' => 'before',
							'value'    => '2026-09-01',
						],
						[
							'field'    => 'date_created',
							'operator' => 'after',
							'value'    => '2026-09-01 10:00:00',
						],
						[
							'field'    => 'date_modified',
							'operator' => 'inThePast',
							'value'    => [
								'value' => 2,
								'unit'  => 'weeks',
							],
						],
						[
							'field'    => 'regular_price',
							'operator' => 'lessThanOrEqual',
							'value'    => 99.95,
						],
					],
					'orderby'  => [ 'units_sold', 'date_modified' ],
					'order'    => [ 'desc', 'desc' ],
					'per_page' => 500,
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, l.total_sales AS units_sold, p.post_modified_gmt AS date_modified {FROM}
				WHERE p.post_type IN ('product') AND {STATUS}
				AND (p.post_title LIKE '%50\\% off\\_\'\\%s%' OR l.sku LIKE '%50\\% off\\_\'\\%s%')
				AND p.post_modified_gmt < '2026-09-01 00:00:00' AND p.post_date_gmt > '2026-09-01 10:00:00'
				AND p.post_modified_gmt >= '2026-09-09 12:00:00' AND l.min_price <= 99.95
				ORDER BY l.total_sales DESC, p.post_modified_gmt DESC, p.ID DESC LIMIT 100 OFFSET 0
				SQL,
			],
			'variation type needs no term'             => [
				[
					'mode'    => 'products_and_variations',
					'filters' => [
						[
							'field'    => 'type',
							'operator' => 'isAny',
							'value'    => [ 'variation' ],
						],
						[
							'field'    => 'date_created',
							'operator' => 'after',
							'value'    => '2026-12-31',
						],
					],
					'orderby' => 'date_created',
					'order'   => 'asc',
				],
				<<<'SQL'
				SELECT p.ID AS id, p.post_parent AS parent_id, p.post_date_gmt AS date_created {FROM}
				WHERE p.post_type IN ('product', 'product_variation') AND {STATUS}
				AND (p.post_type = 'product_variation') AND p.post_date_gmt >= '2027-01-01 00:00:00'
				ORDER BY p.post_date_gmt ASC, p.ID ASC LIMIT 25 OFFSET 0
				SQL,
			],
		];
	}

	public function test_the_matrix_pins_every_page_statement(): void {
		foreach ( self::matrix() as $name => [ $args, $expected ] ) {
			$this->assertSame( self::normalise( strtr( $expected, self::FRAGMENTS ) ), self::normalise( self::query( $args )->statement() ), $name );
		}
	}

	public function test_the_count_statement_shares_the_page_conditions(): void {
		foreach ( self::matrix() as $name => [ $args ] ) {
			$query = self::query( $args );
			$page  = self::normalise( $query->statement() );
			$count = self::normalise( $query->count_statement() );

			$this->assertSame( 1, preg_match( '/^SELECT .+? (FROM .+) ORDER BY /', $page, $page_parts ), $name );
			$this->assertSame( 'SELECT COUNT(*) ' . $page_parts[1] . ' LIMIT 1', $count, $name );
		}
	}

	public function test_no_statement_is_ever_built_without_a_limit(): void {
		foreach ( self::matrix() as $name => [ $args ] ) {
			$query = self::query( $args );
			$this->assertSame( 1, preg_match( '/ LIMIT \d+ OFFSET \d+$/', self::normalise( $query->statement() ) ), $name );
			$this->assertSame( 1, preg_match( '/ LIMIT 1$/', self::normalise( $query->count_statement() ) ), $name );
			foreach ( [ $query->statement(), $query->count_statement() ] as $sql ) {
				$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $sql, $name );
			}
		}
	}

	public function test_every_value_is_a_prepare_argument(): void {
		foreach ( self::matrix() as $name => [ $args ] ) {
			bcm_test_reset();
			$query = self::query( $args );
			$query->statement();
			$query->count_statement();

			$this->assertCount( 2, self::db()->prepared, $name );
			foreach ( self::db()->prepared as [ $template, $values ] ) {
				// No literal value in the template: no quote, and no digit but the constant of SELECT 1.
				$this->assertSame( 0, preg_match( '/[\'"`]/', $template ), $name . ': ' . $template );
				$this->assertSame( 0, preg_match( '/\d/', str_replace( 'SELECT 1 ', '', $template ) ), $name . ': ' . $template );
				// One argument per placeholder.
				$this->assertSame( preg_match_all( '/%[sdfi]/', $template ), count( $values ), $name );
				foreach ( $values as $value ) {
					$this->assertTrue( is_string( $value ) || is_int( $value ) || is_float( $value ), $name );
				}
			}
		}
	}

	public function test_a_hostile_search_is_escaped_for_like_and_passed_as_an_argument(): void {
		self::query( [ 'search' => self::HOSTILE_SEARCH ] )->statement();

		[ $template, $values ] = self::db()->prepared[0];
		$like                  = '%' . addcslashes( self::HOSTILE_SEARCH, '_%\\' ) . '%';

		$this->assertSame( '%50\% off\_\'\%s%', $like );
		$this->assertSame( 2, count( array_keys( $values, $like, true ) ) );
		$this->assertStringContainsString( '(p.post_title LIKE %s OR l.sku LIKE %s)', $template );
		$this->assertStringNotContainsString( 'off', $template );
		// The %s the owner typed did not become a placeholder.
		$this->assertSame( preg_match_all( '/%[sdfi]/', $template ), count( $values ) );
	}

	public function test_the_term_join_appears_only_with_a_taxonomy_filter(): void {
		$taxonomy = [
			[
				'field'    => 'category_ids',
				'operator' => 'isAny',
				'value'    => [ 1 ],
			],
			[
				'field'    => 'tag_ids',
				'operator' => 'isAll',
				'value'    => [ 1, 2 ],
			],
			[
				'field'    => 'shipping_class_id',
				'operator' => 'isNone',
				'value'    => [ 1 ],
			],
			[
				'field'    => 'featured',
				'operator' => 'is',
				'value'    => false,
			],
			[
				'field'    => 'catalog_visibility',
				'operator' => 'isAny',
				'value'    => [ 'visible' ],
			],
			[
				'field'    => 'type',
				'operator' => 'isAny',
				'value'    => [ 'simple' ],
			],
		];
		$plain    = [
			[
				'field'    => 'status',
				'operator' => 'isAny',
				'value'    => [ 'draft' ],
			],
			[
				'field'    => 'stock_status',
				'operator' => 'isNone',
				'value'    => [ 'instock' ],
			],
			[
				'field'    => 'manage_stock',
				'operator' => 'is',
				'value'    => true,
			],
			[
				'field'    => 'regular_price',
				'operator' => 'between',
				'value'    => [ 1, 2 ],
			],
			[
				'field'    => 'stock_quantity',
				'operator' => 'lessThanOrEqual',
				'value'    => 0,
			],
			[
				'field'    => 'tax_class',
				'operator' => 'isAny',
				'value'    => [ 'zero-rate' ],
			],
			[
				'field'    => 'type',
				'operator' => 'isAny',
				'value'    => [ 'variation' ],
			],
			[
				'field'    => 'date_created',
				'operator' => 'before',
				'value'    => '2026-01-01',
			],
			[
				'field'    => 'date_modified',
				'operator' => 'inThePast',
				'value'    => [
					'value' => 1,
					'unit'  => 'days',
				],
			],
		];
		foreach ( Criteria::MODES as $mode ) {
			foreach ( [
				true  => $taxonomy,
				false => $plain,
			] as $joins => $filters ) {
				foreach ( $filters as $filter ) {
					$query = self::query(
						[
							'mode'    => $mode,
							'search'  => 'shirt',
							'filters' => [ $filter ],
						]
					);
					foreach ( [ $query->statement(), $query->count_statement() ] as $sql ) {
						$label = $mode . ' ' . $filter['field'];
						if ( $joins ) {
							$this->assertStringContainsString( '`wp_term_relationships`', $sql, $label );
						} else {
							$this->assertStringNotContainsString( 'term_relationships', $sql, $label );
							$this->assertStringNotContainsString( 'term_taxonomy', $sql, $label );
						}
					}
				}
			}
		}
	}

	public function test_only_allow_listed_tables_are_named(): void {
		$allowed = array_map( static fn( string $table ): string => 'wp_' . $table, array_values( ProductQuery::TABLES ) );
		foreach ( self::matrix() as $name => [ $args ] ) {
			$query = self::query( $args );
			foreach ( [ $query->statement(), $query->count_statement() ] as $sql ) {
				preg_match_all( '/`([^`]+)`/', $sql, $tables );
				$this->assertNotEmpty( $tables[1], $name );
				foreach ( $tables[1] as $table ) {
					$this->assertContains( $table, $allowed, $name );
				}
				$this->assertStringNotContainsString( 'postmeta', $sql, $name );
			}
		}
	}

	public function test_tables_follow_the_site_prefix(): void {
		self::db()->prefix = 'wp_3_';
		$sql               = self::query(
			[
				'filters' => [
					[
						'field'    => 'featured',
						'operator' => 'is',
						'value'    => true,
					],
				],
			]
		)->statement();

		$this->assertStringContainsString( 'FROM `wp_3_posts` AS p LEFT JOIN `wp_3_wc_product_meta_lookup` AS l', $sql );
		$this->assertStringContainsString( 'FROM `wp_3_term_relationships` AS tr INNER JOIN `wp_3_term_taxonomy` AS tt', $sql );
		$this->assertStringContainsString( 'INNER JOIN `wp_3_terms` AS t', $sql );
	}

	public function test_per_page_is_clamped_to_one_hundred_and_paging_to_sane_bounds(): void {
		$cases = [
			[ [], 'LIMIT 25 OFFSET 0' ],
			[ [ 'per_page' => 500 ], 'LIMIT 100 OFFSET 0' ],
			[ [ 'per_page' => '101' ], 'LIMIT 100 OFFSET 0' ],
			[ [ 'per_page' => 100 ], 'LIMIT 100 OFFSET 0' ],
			[ [ 'per_page' => 0 ], 'LIMIT 1 OFFSET 0' ],
			[ [ 'per_page' => -5 ], 'LIMIT 1 OFFSET 0' ],
			[ [ 'page' => 0 ], 'LIMIT 25 OFFSET 0' ],
			[
				[
					'page'     => 3,
					'per_page' => 500,
				],
				'LIMIT 100 OFFSET 200',
			],
			[ [ 'page' => PHP_INT_MAX ], 'LIMIT 25 OFFSET ' . ( ( Criteria::MAX_PAGE - 1 ) * 25 ) ],
		];
		foreach ( $cases as [ $args, $limit ] ) {
			$this->assertStringEndsWith( ' ' . $limit, self::query( $args )->statement(), (string) wp_json_encode( $args ) );
		}
	}

	public function test_rows_and_total_run_the_statements(): void {
		$query                                   = self::query( [ 'mode' => 'products_and_variations' ] );
		self::db()->responses['/^SELECT p\.ID/'] = [
			[
				'id'           => '11',
				'parent_id'    => '0',
				'date_created' => '2026-09-01 00:00:00',
			],
			[
				'id'           => '12',
				'parent_id'    => '11',
				'date_created' => '2026-08-01 00:00:00',
			],
		];
		self::db()->responses['/^SELECT COUNT/'] = '1284';

		$this->assertSame(
			[
				[
					'id'        => 11,
					'parent_id' => 0,
				],
				[
					'id'        => 12,
					'parent_id' => 11,
				],
			],
			$query->rows()
		);
		$this->assertSame( 1284, $query->total() );
		$this->assertSame( [ $query->statement(), $query->count_statement() ], array_slice( self::db()->queries, 0, 2 ) );

		self::db()->responses = [];
		$this->assertSame( [], $query->rows() );
		$this->assertSame( 0, $query->total() );
	}

	public function test_from_array_builds_the_same_query(): void {
		$args = [
			'search'  => 'mug',
			'orderby' => 'sku',
		];
		$this->assertSame( self::query( $args )->count_statement(), ProductQuery::from_array( $args )->count_statement() );
	}

	public function test_invalid_input_never_reaches_sql(): void {
		try {
			ProductQuery::from_array( [ 'orderby' => 'post_title' ] );
			$this->fail( 'An unknown sort key was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [], self::db()->prepared );
			$this->assertSame( [], self::db()->queries );
		}
	}
}
