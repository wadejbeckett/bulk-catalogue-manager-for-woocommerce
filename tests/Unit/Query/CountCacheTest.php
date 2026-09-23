<?php
/**
 * The filtered count (T19; SPEC §12.2): `total` is a separate `COUNT(*)`
 * over the page query's conditions, kept 60 seconds in a `bcm_count_`
 * transient keyed by a hash of the normalised conditions. Two identical reads
 * in separate requests inside the window issue the `COUNT(*)` once; another
 * sort, page or page size of the same view shares it; any change to the
 * conditions is another key; a product change (the version its hooks renew,
 * or with a persistent object cache the posts' last-changed value) is
 * another key; and `SQL_CALC_FOUND_ROWS` appears in no generated statement.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Catalogue;
use BulkCatalogueManager\Query\CountCache;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class CountCacheTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		SeededCatalogue::seed();
	}

	/**
	 * A view with a filter and a search.
	 *
	 * @param array<string, mixed> $extra Further arguments.
	 */
	private static function criteria( array $extra = [] ): Criteria {
		return Criteria::from_array(
			$extra + [
				'search'  => 'shirt',
				'filters' => [
					[
						'field'    => 'stock_status',
						'operator' => 'isAny',
						'value'    => [ 'instock', 'outofstock' ],
					],
				],
			]
		);
	}

	/**
	 * How many times a criteria's exact `COUNT(*)` statement has run.
	 *
	 * @param Criteria $criteria Criteria.
	 */
	private static function counts_run( Criteria $criteria ): int {
		$statement = ( new ProductQuery( $criteria ) )->count_statement();
		return count( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => $sql === $statement ) );
	}

	/**
	 * A read through Catalogue as a fresh request: the non-persistent object cache emptied first.
	 *
	 * @param Criteria $criteria Criteria.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	private static function request( Criteria $criteria ): array {
		$GLOBALS['bcm_test']['cache'] = [];
		return ( new Catalogue( new Hydrator( Registry::instance() ) ) )->page( $criteria, [ 'name' ] );
	}

	public function test_two_identical_requests_inside_the_window_count_once(): void {
		$criteria = self::criteria();
		$first    = self::request( $criteria );
		$this->assertSame( 2, $first['total'], 'Blue Shirt and Formal Shirt match.' );
		$this->assertSame( 1, self::counts_run( $criteria ) );

		$second = self::request( $criteria );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, self::counts_run( $criteria ), 'The second request inside 60 seconds ran the COUNT(*) again.' );

		$key = ( new CountCache() )->key( $criteria );
		$this->assertStringStartsWith( CountCache::PREFIX, $key );
		$this->assertSame( [ 'total' => 2 ], $GLOBALS['bcm_test']['transients'][ $key ] );
		$this->assertSame( 60, $GLOBALS['bcm_test']['transient_ttl'][ $key ] );
	}

	public function test_sort_page_and_page_size_share_the_count_of_their_view(): void {
		$counts = new CountCache();
		$key    = $counts->key( self::criteria() );
		foreach ( [
			[ 'page' => 2 ],
			[ 'per_page' => 100 ],
			[
				'orderby' => 'name',
				'order'   => 'desc',
			],
		] as $variant ) {
			$this->assertSame( $key, $counts->key( self::criteria( $variant ) ), (string) wp_json_encode( $variant ) );
		}
		self::request( self::criteria() );
		self::request(
			self::criteria(
				[
					'page'     => 2,
					'per_page' => 1,
				]
			)
		);
		$this->assertSame( 1, count( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT COUNT(*) FROM' ) ) ) );
	}

	public function test_any_change_to_the_conditions_changes_the_key(): void {
		$counts = new CountCache();
		$keys   = [
			'base'     => $counts->key( self::criteria() ),
			'search'   => $counts->key( self::criteria( [ 'search' => 'shirts' ] ) ),
			'mode'     => $counts->key( self::criteria( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ) ),
			'value'    => $counts->key(
				self::criteria(
					[
						'filters' => [
							[
								'field'    => 'stock_status',
								'operator' => 'isAny',
								'value'    => [ 'instock' ],
							],
						],
					]
				)
			),
			'operator' => $counts->key(
				self::criteria(
					[
						'filters' => [
							[
								'field'    => 'stock_status',
								'operator' => 'isNone',
								'value'    => [ 'instock', 'outofstock' ],
							],
						],
					]
				)
			),
			'none'     => $counts->key( self::criteria( [ 'filters' => [] ] ) ),
		];
		$this->assertCount( count( $keys ), array_unique( $keys ) );

		$changed = self::criteria( [ 'search' => 'tote' ] );
		self::request( self::criteria() );
		$this->assertSame( 0, self::request( $changed )['total'] );
		$this->assertSame( 1, self::counts_run( $changed ), 'A changed filter must count afresh.' );
	}

	public function test_a_product_change_busts_the_count(): void {
		$criteria = self::criteria();
		self::request( $criteria );

		// Without a persistent object cache the last-changed values are new on every request,
		// so they are left out of the key and the version the product hooks renew stands in.
		wp_cache_set_last_changed( 'posts' );
		self::request( $criteria );
		$this->assertSame( 1, self::counts_run( $criteria ) );
		CountCache::on_post( 101, (object) [ 'post_type' => 'product' ] );
		self::request( $criteria );
		$this->assertSame( 2, self::counts_run( $criteria ), 'A product save must bust the count.' );

		// With a persistent object cache the posts' last-changed value is in the key.
		$GLOBALS['bcm_test']['ext_object_cache'] = true;
		$before                                  = ( new CountCache() )->key( $criteria );
		self::request( $criteria );
		$this->assertSame( 3, self::counts_run( $criteria ) );
		self::request( $criteria );
		$this->assertSame( 3, self::counts_run( $criteria ) );
		wp_cache_set_last_changed( 'posts' );
		$this->assertNotSame( $before, ( new CountCache() )->key( $criteria ) );
		self::request( $criteria );
		$this->assertSame( 4, self::counts_run( $criteria ), 'A posts last-changed bump must bust the count.' );
		wp_cache_set_last_changed( 'terms' );
		self::request( $criteria );
		$this->assertSame( 5, self::counts_run( $criteria ), 'A terms last-changed bump must bust the count.' );
	}

	public function test_a_stored_count_that_is_not_a_count_is_recounted(): void {
		$criteria = self::criteria();
		$GLOBALS['bcm_test']['transients'][ ( new CountCache() )->key( $criteria ) ] = [ 'total' => '99' ];
		$this->assertSame( 2, self::request( $criteria )['total'] );
		$this->assertSame( 1, self::counts_run( $criteria ) );
	}

	public function test_no_generated_statement_uses_sql_calc_found_rows(): void {
		$reads = [
			[],
			[ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ],
			[ 'mode' => Criteria::MODE_VARIATIONS ],
			[
				'mode'   => Criteria::MODE_VARIATIONS,
				'parent' => 103,
			],
			[
				'search'  => 'jacket',
				'orderby' => [ 'regular_price', 'name' ],
				'order'   => [ 'desc', 'asc' ],
				'filters' => [
					[
						'field'    => 'category_ids',
						'operator' => 'isAny',
						'value'    => [ 10, 20 ],
					],
					[
						'field'    => 'regular_price',
						'operator' => 'between',
						'value'    => [ 1, 100 ],
					],
					[
						'field'    => 'date_created',
						'operator' => 'inThePast',
						'value'    => [
							'value' => 2,
							'unit'  => 'years',
						],
					],
				],
			],
		];
		foreach ( $reads as $args ) {
			self::request( Criteria::from_array( $args ) );
		}
		$statements = [ ...$GLOBALS['wpdb']->queries, ...array_column( $GLOBALS['wpdb']->prepared, 0 ) ];
		$this->assertGreaterThan( count( $reads ), count( $statements ) );
		$this->assertNotEmpty( array_filter( $statements, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT COUNT(*) FROM' ) ), 'The walk must see the separate COUNT(*).' );
		foreach ( $statements as $sql ) {
			$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', strtoupper( $sql ) );
			$this->assertStringNotContainsString( 'FOUND_ROWS(', strtoupper( $sql ) );
		}
	}
}
