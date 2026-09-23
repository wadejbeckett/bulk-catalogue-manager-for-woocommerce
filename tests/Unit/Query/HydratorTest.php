<?php
/**
 * The read path's hydration (T16, T17; SPEC §12.2, §12.5, §4.1): a page of
 * identifiers becomes rows in a fixed number of queries whatever the page
 * size, without building a WC_Product, cached per last-changed window, with
 * thumbnails primed in two queries and the rendered price composed from the
 * lookup table's lowest and highest price.
 *
 * Queries are counted on the stub $wpdb, which also records every cache miss
 * of the read stubs (tests/Support/read-stubs.php), so a row that reached
 * the database on its own would make the count grow with the page size.
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
use PHPUnit\Framework\TestCase;

final class HydratorTest extends TestCase {
	/**
	 * Products in the seeded catalogue.
	 */
	private const CATALOGUE = 250;

	/**
	 * Attachment ids are this plus the product id.
	 */
	private const ATTACHMENTS = 100000;

	/**
	 * Variations each variable product has.
	 */
	private const VARIATIONS = 3;

	protected function setUp(): void {
		bcm_test_reset();
		self::seed();
	}

	/**
	 * Seed CATALOGUE products: every third variable with a price range and
	 * VARIATIONS variations, every even one with an image, id 7 with an
	 * attachment id whose attachment is gone.
	 */
	private static function seed(): void {
		$state = &$GLOBALS['bcm_test'];
		for ( $id = 1; $id <= self::CATALOGUE; $id++ ) {
			$variable              = 0 === $id % 3;
			$state['posts'][ $id ] = [
				'post_type'         => 'product',
				'post_title'        => 'Product ' . $id,
				'post_status'       => 'publish',
				'comment_status'    => 'open',
				'post_parent'       => 0,
				'post_date_gmt'     => '2026-09-01 08:00:00',
				'post_modified_gmt' => '2026-09-20 10:30:00',
			];
			$state['meta'][ $id ]  = [
				'_sku'           => 'SKU-' . $id,
				'_regular_price' => $variable ? '' : '10.5',
				'_stock_status'  => 'instock',
				'_manage_stock'  => 'yes',
				'_stock'         => '7',
				'total_sales'    => '3',
			];
			if ( 0 === $id % 2 ) {
				$state['meta'][ $id ]['_thumbnail_id']     = (string) ( self::ATTACHMENTS + $id );
				$state['posts'][ self::ATTACHMENTS + $id ] = [ 'post_type' => 'attachment' ];
				$state['meta'][ self::ATTACHMENTS + $id ]  = [ '_wp_attached_file' => '2026/09/p' . $id . '.jpg' ];
			}
			$state['object_terms'][ $id ] = [
				[ 'product_type', 1, $variable ? 'variable' : 'simple' ],
				[ 'product_cat', 15, 'shirts' ],
			];
		}
		$state['meta'][7]['_thumbnail_id'] = (string) ( self::ATTACHMENTS + 7 );

		$wpdb            = $GLOBALS['wpdb'];
		$wpdb->responses = [
			'/^SELECT COUNT\(\*\) /'                      => (string) self::CATALOGUE,
			'/^SELECT p\.ID AS id, p\.post_parent AS parent_id/' => static function ( string $sql ): array {
				preg_match( '/LIMIT (\d+) OFFSET (\d+)$/', $sql, $m );
				$rows = [];
				$last = min( self::CATALOGUE, (int) $m[2] + (int) $m[1] );
				for ( $id = (int) $m[2] + 1; $id <= $last; $id++ ) {
					$rows[] = [
						'id'        => (string) $id,
						'parent_id' => '0',
					];
				}
				return $rows;
			},
			'/^SELECT post_parent AS parent_id, COUNT\(\*\)/' => static function ( string $sql ): array {
				$rows = [];
				foreach ( self::in_ids( $sql ) as $id ) {
					if ( 0 === $id % 3 ) {
						$rows[] = [
							'parent_id' => (string) $id,
							'children'  => (string) self::VARIATIONS,
						];
					}
				}
				return $rows;
			},
			'/^SELECT product_id, min_price, max_price /' => static function ( string $sql ): array {
				$rows = [];
				foreach ( self::in_ids( $sql ) as $id ) {
					$rows[] = [
						'product_id' => (string) $id,
						'min_price'  => 0 === $id % 3 ? '5.0000' : '10.5000',
						'max_price'  => 0 === $id % 3 ? '20.0000' : '10.5000',
					];
				}
				return $rows;
			},
		];
	}

	/**
	 * The ids in a statement's first IN list.
	 *
	 * @return int[]
	 */
	private static function in_ids( string $sql ): array {
		preg_match( '/IN \(([\d, ]+)\)/', $sql, $m );
		return array_map( 'intval', explode( ',', $m[1] ) );
	}

	private static function catalogue(): Catalogue {
		return new Catalogue( new Hydrator( new Registry() ) );
	}

	/**
	 * Read one page and return how many queries it took.
	 *
	 * @param array<string, mixed> $args Criteria input.
	 * @param string[]             $keys Field keys.
	 */
	private static function queries_for( array $args, array $keys = [] ): int {
		$before = bcm_test_query_count();
		self::catalogue()->page( Criteria::from_array( $args ), $keys );
		return bcm_test_query_count() - $before;
	}

	/**
	 * Three filters in the request shape Criteria accepts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function three_filters(): array {
		return [
			[
				'field'    => 'stock_status',
				'operator' => 'isAny',
				'value'    => [ 'instock' ],
			],
			[
				'field'    => 'category_ids',
				'operator' => 'isAny',
				'value'    => [ 15 ],
			],
			[
				'field'    => 'regular_price',
				'operator' => 'between',
				'value'    => [ 1, 50 ],
			],
		];
	}

	public function test_the_query_count_is_the_same_at_25_50_and_100_rows(): void {
		$counts = [];
		foreach ( [ 25, 50, 100 ] as $per_page ) {
			bcm_test_reset();
			self::seed();
			$page = self::catalogue()->page( Criteria::from_array( [ 'per_page' => $per_page ] ), [] );
			$this->assertCount( $per_page, $page['items'] );
			$this->assertSame( self::CATALOGUE, $page['total'] );
			$counts[ $per_page ] = bcm_test_query_count();
		}
		$this->assertSame( $counts[25], $counts[50], 'Query count grew from 25 to 50 rows: ' . wp_json_encode( $counts ) );
		$this->assertSame( $counts[25], $counts[100], 'Query count grew from 25 to 100 rows: ' . wp_json_encode( $counts ) );
		// Count, page, posts, meta, terms, variation count, lookup, attachment posts, attachment meta.
		$this->assertSame( 9, $counts[25], implode( "\n", $GLOBALS['wpdb']->queries ) );
	}

	public function test_three_filters_stay_inside_twenty_queries_at_every_page_size(): void {
		$counts = [];
		foreach ( [ 25, 50, 100 ] as $per_page ) {
			bcm_test_reset();
			self::seed();
			$counts[ $per_page ] = self::queries_for(
				[
					'per_page' => $per_page,
					'filters'  => self::three_filters(),
				]
			);
			$this->assertLessThan( 21, $counts[ $per_page ] );
		}
		$this->assertSame( [ $counts[25], $counts[25], $counts[25] ], array_values( $counts ) );
		$this->assertStringContainsString( 'EXISTS', $GLOBALS['wpdb']->queries[0] . $GLOBALS['wpdb']->queries[1] );
	}

	public function test_each_catalogue_source_is_read_once_per_page(): void {
		self::catalogue()->page( Criteria::from_array( [ 'per_page' => 100 ] ), [] );
		$calls = array_count_values( array_column( $GLOBALS['bcm_test']['calls'], 0 ) );
		$this->assertSame( 2, $calls['_prime_post_caches'], 'Products, then attachments.' );
		$this->assertSame( 2, $calls['update_meta_cache'], 'Products, then attachments.' );
		$this->assertSame( 1, $calls['wp_get_object_terms'] );
		$this->assertArrayNotHasKey( 'get_post', $calls, 'No post was read uncached.' );
		$this->assertArrayNotHasKey( 'get_post_meta', $calls, 'No meta was read uncached.' );

		$terms = array_values( array_filter( $GLOBALS['bcm_test']['calls'], static fn( array $call ): bool => 'wp_get_object_terms' === $call[0] ) )[0][1];
		$this->assertSame( Hydrator::TAXONOMIES, $terms[1] );
		$this->assertSame( 'all_with_object_id', $terms[2]['fields'] );
		$this->assertFalse( $terms[2]['update_term_meta_cache'] );
		$this->assertSame( [ false, false ], array_slice( $GLOBALS['bcm_test']['calls'][0][1], 1 ), 'Posts are primed without terms or meta.' );

		$children = array_values( preg_grep( '/^SELECT post_parent AS parent_id, COUNT\(\*\)/', $GLOBALS['wpdb']->queries ) );
		$this->assertCount( 1, $children );
		$this->assertStringContainsString( "post_type = 'product_variation' AND post_status IN ('publish', 'private') GROUP BY post_parent LIMIT 100", $children[0] );
		$lookup = array_values( preg_grep( '/^SELECT product_id, min_price, max_price FROM `wp_wc_product_meta_lookup`/', $GLOBALS['wpdb']->queries ) );
		$this->assertCount( 1, $lookup );
		$this->assertStringEndsWith( 'LIMIT 100', $lookup[0] );
	}

	public function test_no_product_object_is_built_and_woocommerce_product_read_never_fires(): void {
		$fired = 0;
		add_action(
			'woocommerce_product_read',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$page = self::catalogue()->page( Criteria::from_array( [ 'per_page' => 100 ] ), [] );
		$this->assertCount( 100, $page['items'] );
		$this->assertSame( 0, $fired );
		$this->assertSame( 0, did_action( 'woocommerce_product_read' ) );
		$this->assertSame( 0, $GLOBALS['bcm_test']['products_built'] ?? 0 );

		// The sentinel works: building one would have been seen.
		new \WC_Product( 1 );
		$this->assertSame( 1, $fired );
	}

	public function test_the_read_path_names_no_product_constructor_or_price_html_call(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( [ ...(array) glob( $root . '/src/Query/*.php' ), $root . '/src/Rest/ProductsController.php' ] as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertSame( 0, preg_match( '/wc_get_products?\s*\(|new\s+\\\\?WC_Product|->get_price_html\s*\(|WC_Product_Factory|wc_get_price_to_display\s*\(/', $code ), basename( $file ) . ' builds or renders through a product object.' );
		}
	}

	public function test_a_second_identical_read_issues_no_query_until_a_product_changes(): void {
		$criteria = [
			'per_page' => 50,
			'filters'  => self::three_filters(),
		];
		$first    = self::catalogue()->page( Criteria::from_array( $criteria ), [] );
		$this->assertGreaterThan( 0, bcm_test_query_count() );

		$this->assertSame( 0, self::queries_for( $criteria ), 'The same read in the same window reached the database.' );
		$this->assertSame( $first, self::catalogue()->page( Criteria::from_array( $criteria ), [] ) );
		$this->assertGreaterThan( 0, self::queries_for( [ 'per_page' => 25 ] ), 'A different read is a different entry.' );
		$this->assertGreaterThan( 0, self::queries_for( $criteria, [ 'name' ] ), 'Different columns are a different entry.' );

		// A product save moves the posts' last-changed value and, through
		// clean_post_cache, renews the count cache's version.
		wp_cache_set_last_changed( 'posts' );
		CountCache::on_post( 101, (object) [ 'post_type' => 'product' ] );
		// Count, page, terms, variation count and lookup; the unchanged posts and meta stay primed in this request.
		$this->assertSame( 5, self::queries_for( $criteria ), 'A product change must invalidate the page.' );
		$this->assertSame( 0, self::queries_for( $criteria ) );

		wp_cache_set_last_changed( 'terms' );
		$this->assertGreaterThan( 0, self::queries_for( $criteria ), 'A term change must invalidate the page.' );

		$sets = array_values( array_filter( $GLOBALS['bcm_test']['calls'], static fn( array $call ): bool => 'wp_cache_set' === $call[0] ) );
		$this->assertSame( [ Catalogue::CACHE_GROUP, Catalogue::CACHE_TTL ], array_slice( $sets[0][1], 1 ) );
	}

	public function test_a_page_past_the_last_runs_only_the_count(): void {
		$page = self::catalogue()->page( Criteria::from_array( [ 'page' => 99 ] ), [] );
		$this->assertSame( [], $page['items'] );
		$this->assertSame( self::CATALOGUE, $page['total'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertStringStartsWith( 'SELECT COUNT(*) ', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_thumbnails_come_from_primed_attachments_or_the_placeholder(): void {
		$items = self::catalogue()->page( Criteria::from_array( [ 'per_page' => 10 ] ), [] )['items'];
		$by_id = array_column( $items, null, 'id' );
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/09/p2.jpg', $by_id[2][ Hydrator::THUMBNAIL_URL ] );
		$this->assertSame( self::ATTACHMENTS + 2, $by_id[2]['thumbnail'] );
		$placeholder = 'https://example.test/wp-content/plugins/woocommerce/assets/images/placeholder.webp';
		$this->assertSame( $placeholder, $by_id[1][ Hydrator::THUMBNAIL_URL ], 'No image: the placeholder.' );
		$this->assertSame( 0, $by_id[1]['thumbnail'] );
		$this->assertSame( $placeholder, $by_id[7][ Hydrator::THUMBNAIL_URL ], 'An image whose attachment is gone: the placeholder.' );

		$calls       = array_values( array_filter( $GLOBALS['bcm_test']['calls'], static fn( array $call ): bool => in_array( $call[0], [ '_prime_post_caches', 'update_meta_cache', 'wc_placeholder_img_src' ], true ) ) );
		$attachments = [ self::ATTACHMENTS + 2, self::ATTACHMENTS + 4, self::ATTACHMENTS + 6, self::ATTACHMENTS + 7, self::ATTACHMENTS + 8, self::ATTACHMENTS + 10 ];
		$this->assertSame( [ $attachments, false, false ], $calls[2][1] );
		$this->assertSame( [ 'post', $attachments ], $calls[3][1] );
		$this->assertSame( [ Hydrator::THUMBNAIL_SIZE ], $calls[4][1] );
		$this->assertCount( 5, $calls, 'The placeholder is looked up once per page.' );
	}

	public function test_without_any_image_the_attachments_are_not_primed(): void {
		foreach ( $GLOBALS['bcm_test']['meta'] as $id => $meta ) {
			unset( $GLOBALS['bcm_test']['meta'][ $id ]['_thumbnail_id'] );
		}
		$items = self::catalogue()->page( Criteria::from_array( [ 'per_page' => 25 ] ), [] )['items'];
		$this->assertSame( [ 'https://example.test/wp-content/plugins/woocommerce/assets/images/placeholder.webp' ], array_values( array_unique( array_column( $items, Hydrator::THUMBNAIL_URL ) ) ) );
		$calls = array_count_values( array_column( $GLOBALS['bcm_test']['calls'], 0 ) );
		$this->assertSame( 1, $calls['_prime_post_caches'] );
		$this->assertSame( 7, bcm_test_query_count() );
	}

	public function test_the_rendered_price_is_one_price_or_a_range_from_the_lookup_prices(): void {
		$GLOBALS['bcm_test']['currency'] = '€';
		$GLOBALS['bcm_test']['decimals'] = 2;
		$price                           = static fn( string $amount ): string => '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">€</span>' . $amount . '</bdi></span>';

		$this->assertSame( $price( '10.50' ), Hydrator::render_price( '10.5000', '10.5000' ) );
		$this->assertSame( $price( '5.00' ) . ' &ndash; ' . $price( '20.00' ), Hydrator::render_price( '5.0000', '20.0000' ) );
		$this->assertSame( $price( '7.00' ), Hydrator::render_price( '7', null ), 'No highest price: the lowest alone.' );
		$this->assertSame( '', Hydrator::render_price( null, null ), 'No lookup price: nothing rather than a zero.' );
		$this->assertSame( '', Hydrator::render_price( '', '' ) );

		$GLOBALS['bcm_test']['separator'] = ',';
		$GLOBALS['bcm_test']['thousands'] = '.';
		$this->assertSame( $price( '1.234,50' ), Hydrator::render_price( '1234.5', '1234.5' ), 'The shop\'s separators apply.' );
		$GLOBALS['bcm_test']['separator'] = '.';
		$GLOBALS['bcm_test']['thousands'] = ',';

		$by_id = array_column( self::catalogue()->page( Criteria::from_array( [ 'per_page' => 3 ] ), [] )['items'], null, 'id' );
		$this->assertSame( $price( '10.50' ), $by_id[1]['price_html'], 'A simple product shows its price.' );
		$this->assertSame( $price( '5.00' ) . ' &ndash; ' . $price( '20.00' ), $by_id[3]['price_html'], 'A variable product shows its range.' );
	}

	public function test_a_price_format_change_is_a_different_entry(): void {
		self::catalogue()->page( Criteria::from_array( [] ), [] );
		$this->assertSame( 0, self::queries_for( [] ) );
		$GLOBALS['bcm_test']['currency'] = '$';
		$this->assertGreaterThan( 0, self::queries_for( [] ) );
	}

	public function test_values_come_through_the_field_read_contract(): void {
		$GLOBALS['bcm_test']['meta'][3]                   += [
			'_sale_price'        => '4',
			'_weight'            => '1.25',
			'_tax_class'         => 'reduced-rate',
			'_sold_individually' => 'yes',
		];
		$GLOBALS['bcm_test']['object_terms'][3]            = [
			[ 'product_type', 1, 'variable' ],
			[ 'product_cat', 15, 'shirts' ],
			[ 'product_cat', 16, 'sale' ],
			[ 'product_tag', 30, 'cotton' ],
			[ 'product_visibility', 40, 'featured' ],
			[ 'product_visibility', 41, 'exclude-from-search' ],
			[ 'product_shipping_class', 50, 'bulky' ],
		];
		$GLOBALS['bcm_test']['posts'][3]['comment_status'] = 'closed';
		$GLOBALS['bcm_test']['posts'][1]['post_date_gmt']  = '0000-00-00 00:00:00';

		$by_id = array_column( self::catalogue()->page( Criteria::from_array( [ 'per_page' => 3 ] ), [] )['items'], null, 'id' );
		$item  = $by_id[3];
		$this->assertSame( array_merge( [ 'id', 'parent_id' ], array_diff( ( new Registry() )->keys(), [ 'id' ] ), [ Hydrator::THUMBNAIL_URL ] ), array_keys( $item ) );
		$expected = [
			'id'                 => 3,
			'parent_id'          => 0,
			'name'               => 'Product 3',
			'status'             => 'publish',
			'catalog_visibility' => 'catalog',
			'visible_on_site'    => 'partly',
			'featured'           => true,
			'category_ids'       => [ 15, 16 ],
			'tag_ids'            => [ 30 ],
			'regular_price'      => '',
			'sale_price'         => '4',
			'stock_status'       => 'instock',
			'manage_stock'       => true,
			'stock_quantity'     => 7,
			'sku'                => 'SKU-3',
			'weight'             => '1.25',
			'length'             => '',
			'shipping_class_id'  => 50,
			'tax_status'         => 'taxable',
			'tax_class'          => 'reduced-rate',
			'sold_individually'  => true,
			'reviews_allowed'    => false,
			'type'               => 'variable',
			'thumbnail'          => 0,
			'date_created'       => '2026-09-01T08:00:00+00:00',
			'date_modified'      => '2026-09-20T10:30:00+00:00',
			'units_sold'         => 3,
			'variation_count'    => self::VARIATIONS,
		];
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $item[ $key ], $key );
		}
		$this->assertSame( 'visible', $by_id[1]['catalog_visibility'] );
		$this->assertSame( 'on', $by_id[1]['visible_on_site'] );
		$this->assertFalse( $by_id[1]['featured'] );
		$this->assertSame( 0, $by_id[1]['shipping_class_id'] );
		$this->assertSame( 'simple', $by_id[1]['type'] );
		$this->assertSame( 0, $by_id[1]['variation_count'] );
		$this->assertNull( $by_id[1]['date_created'], 'A post never published has no creation date.' );
		$this->assertTrue( $by_id[1]['reviews_allowed'] );
	}

	public function test_a_variation_row_reads_as_a_variation(): void {
		$GLOBALS['bcm_test']['posts'][9001] = [
			'post_type'         => 'product_variation',
			'post_title'        => 'Product 3 - Blue',
			'post_status'       => 'private',
			'comment_status'    => 'closed',
			'post_parent'       => 3,
			'post_date_gmt'     => '2026-09-02 08:00:00',
			'post_modified_gmt' => '2026-09-02 08:00:00',
		];
		$GLOBALS['bcm_test']['meta'][9001]  = [ '_stock_status' => 'outofstock' ];
		$items                              = ( new Hydrator( new Registry() ) )->hydrate(
			[
				[
					'id'        => 9001,
					'parent_id' => 3,
				],
			],
			[ 'type', 'stock_status', 'name', 'status' ]
		);
		$this->assertSame(
			[
				'id'                => 9001,
				'parent_id'         => 3,
				'name'              => 'Product 3 - Blue',
				'status'            => 'private',
				'stock_status'      => 'outofstock',
				'type'              => 'variation',
				'parent_name'       => 'Product 3',
				'attribute_summary' => '',
			],
			$items[0]
		);
	}

	public function test_only_the_visible_columns_sources_are_read(): void {
		$items = self::catalogue()->page( Criteria::from_array( [ 'per_page' => 100 ] ), [ 'name', 'status' ] )['items'];
		$this->assertSame(
			[
				'id'        => 1,
				'parent_id' => 0,
				'name'      => 'Product 1',
				'status'    => 'publish',
			],
			$items[0]
		);
		$this->assertSame( [ '_prime_post_caches' ], array_values( array_diff( array_unique( array_column( $GLOBALS['bcm_test']['calls'], 0 ) ), [ 'wp_cache_set' ] ) ) );
		$this->assertSame( 3, bcm_test_query_count(), 'Count, page and posts only.' );

		bcm_test_reset();
		self::seed();
		self::catalogue()->page( Criteria::from_array( [ 'per_page' => 100 ] ), [ 'category_ids', 'sku' ] );
		$calls = array_values( array_diff( array_unique( array_column( $GLOBALS['bcm_test']['calls'], 0 ) ), [ 'wp_cache_set' ] ) );
		$this->assertSame( [ '_prime_post_caches', 'update_meta_cache', 'wp_get_object_terms' ], $calls );
		$terms = array_values( array_filter( $GLOBALS['bcm_test']['calls'], static fn( array $call ): bool => 'wp_get_object_terms' === $call[0] ) )[0][1];
		$this->assertSame( [ 'product_cat' ], $terms[1] );
	}

	public function test_unknown_keys_are_dropped_and_id_is_always_kept(): void {
		$fields = ( new Hydrator( new Registry() ) )->fields( [ 'sku', 'nonsense' ] );
		$this->assertSame( [ 'sku', 'id' ], array_keys( $fields ), 'Registry order, with id added.' );
		$this->assertSame( ( new Registry() )->keys(), array_keys( ( new Hydrator( new Registry() ) )->fields( [] ) ) );
		$this->assertSame( [], ( new Hydrator( new Registry() ) )->hydrate( [], [] ) );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}
}
