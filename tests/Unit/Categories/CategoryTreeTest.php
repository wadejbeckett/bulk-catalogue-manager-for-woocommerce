<?php
/**
 * The category tree service (T21; SPEC §7, §12.2): one get_terms() call and
 * one grouped statement per uncached request, none when warm; the grouped
 * statement reuses ProductQuery's conditions and leaves the category filter
 * out; the `bcm_category_tree_` transient is keyed by the conditions, the
 * version the term hooks renew and, with a persistent object cache only, the
 * last-changed values; each hook busts it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Cache;
use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\Counts;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';

final class CategoryTreeTest extends TestCase {
	/**
	 * Matches the grouped statement.
	 */
	private const GROUPED = '/^SELECT g\.terms, COUNT\(\*\) AS products FROM /';

	protected function setUp(): void {
		bcm_test_reset();
		CatalogueFixture::seed();
		$GLOBALS['wpdb']->responses[ self::GROUPED ] = CatalogueFixture::rows();
	}

	/**
	 * Criteria with a status filter.
	 *
	 * @param string[] $statuses Statuses.
	 */
	private static function with_status( array $statuses ): Criteria {
		return CategoryTree::criteria(
			[
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'isAny',
						'value'    => $statuses,
					],
				],
			]
		);
	}

	public function test_the_grouped_statement_reuses_the_product_query_conditions(): void {
		$criteria  = CategoryTree::criteria(
			[
				'mode'    => Criteria::MODE_PRODUCTS_AND_VARIATIONS,
				'search'  => "50% off_'s",
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'isAny',
						'value'    => [ 'draft' ],
					],
				],
			]
		);
		$count     = ( new ProductQuery( $criteria ) )->count_statement();
		$body      = substr( $count, strlen( 'SELECT COUNT(*) ' ), -strlen( ' LIMIT 1' ) );
		$statement = ( new Counts( $criteria ) )->statement();

		$this->assertStringStartsWith( 'FROM `wp_posts` AS p LEFT JOIN `wp_wc_product_meta_lookup` AS l ON l.product_id = p.ID WHERE ', $body );
		$this->assertSame(
			"SELECT g.terms, COUNT(*) AS products FROM (SELECT cr.object_id, GROUP_CONCAT(DISTINCT ct.term_id ORDER BY ct.term_id SEPARATOR ',') AS terms FROM `wp_term_relationships` AS cr INNER JOIN `wp_term_taxonomy` AS ct ON ct.term_taxonomy_id = cr.term_taxonomy_id WHERE ct.taxonomy = 'product_cat' AND cr.object_id IN (SELECT (CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END) "
			. $body
			. ') GROUP BY cr.object_id) AS g GROUP BY g.terms LIMIT ' . Counts::MAX_GROUPS,
			$statement
		);
		$this->assertStringContainsString( "p.post_status IN ('draft')", $statement );
		$this->assertStringContainsString( "p.post_type IN ('product', 'product_variation')", $statement );
		$this->assertStringContainsString( "p.post_title LIKE '%" . addslashes( $GLOBALS['wpdb']->esc_like( "50% off_'s" ) ) . "%'", $statement, 'The search reaches SQL escaped, through ProductQuery.' );
		$this->assertSame( 1, substr_count( $statement, 'LIMIT' ), 'Only the outer statement carries a LIMIT; the inner one is dropped.' );
	}

	public function test_the_grouped_statement_is_prepared_once_over_the_conditions_accessor(): void {
		$criteria   = CategoryTree::criteria( [ 'search' => 'hat' ] );
		$conditions = ( new ProductQuery( $criteria ) )->conditions_sql();
		bcm_test_reset();
		( new Counts( $criteria ) )->statement();

		$this->assertCount( 1, $GLOBALS['wpdb']->prepared );
		[ $template, $values ] = $GLOBALS['wpdb']->prepared[0];
		$this->assertStringContainsString( ' END) ' . $conditions['sql'] . ') GROUP BY ', $template );
		$this->assertSame( $conditions['args'], array_slice( $values, 5, count( $conditions['args'] ) ) );
		$this->assertSame( Counts::MAX_GROUPS, $values[ array_key_last( $values ) ] );
	}

	public function test_the_counts_leave_out_the_category_filter_sort_and_paging(): void {
		$criteria = CategoryTree::criteria(
			[
				'mode'     => Criteria::MODE_VARIATIONS,
				'page'     => 4,
				'per_page' => 10,
				'orderby'  => 'name',
				'filters'  => [
					[
						'field'    => 'category_ids',
						'operator' => 'isAny',
						'value'    => [ 3 ],
					],
					[
						'field'    => 'stock_status',
						'operator' => 'isAny',
						'value'    => [ 'instock' ],
					],
				],
			]
		);
		$this->assertSame(
			[
				'mode'    => Criteria::MODE_VARIATIONS,
				'search'  => '',
				'filters' => [
					[
						'field'    => 'stock_status',
						'operator' => 'isAny',
						'value'    => [ 'instock' ],
					],
				],
				'parent'  => 0,
			],
			$criteria->conditions()
		);
		$this->assertStringNotContainsString( 'term_id IN', ( new Counts( $criteria ) )->statement() );
	}

	public function test_criteria_refuse_what_the_query_builder_refuses(): void {
		try {
			CategoryTree::criteria(
				[
					'filters' => [
						[
							'field'    => 'status',
							'operator' => 'like',
							'value'    => [ 'publish' ],
						],
					],
				]
			);
			$this->fail( 'An unknown operator was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_a_200_term_tree_costs_one_get_terms_and_one_query_then_none_when_warm(): void {
		$terms = [];
		foreach ( range( 1, 200 ) as $id ) {
			$terms[ $id ] = [ "Category $id", "category-$id", $id > 4 ? intdiv( $id, 4 ) : 0 ];
		}
		$GLOBALS['bcm_test']['term_list']            = CatalogueFixture::terms( $terms );
		$GLOBALS['wpdb']->responses[ self::GROUPED ] = [
			[
				'terms'    => '48,49',
				'products' => '7',
			],
		];
		$service                                     = new CategoryTree();
		$criteria                                    = CategoryTree::criteria( [] );

		$cold = $service->get( $criteria );
		$this->assertSame(
			[
				[
					'taxonomy'               => 'product_cat',
					'hide_empty'             => false,
					'update_term_meta_cache' => true,
				],
			],
			$GLOBALS['bcm_test']['get_terms_calls']
		);
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertSame( 1, preg_match( self::GROUPED, $GLOBALS['wpdb']->queries[0] ), 'The one query is the grouped count.' );
		$flat = CatalogueFixture::flatten( $cold );
		$this->assertCount( 200, $flat );
		$this->assertSame( [ 7, 7 ], [ $flat[48]['count'], $flat[49]['count'] ] );
		// 48 and 49 both sit under 12, which sits under 3: the seven products count once there, not twice.
		$this->assertSame( [ 0, 7, 7 ], [ $flat[12]['count'], $flat[12]['countWithDescendants'], $flat[3]['countWithDescendants'] ] );
		$this->assertSame( 2, $flat[48]['depth'] );

		$warm = ( new CategoryTree() )->get( CategoryTree::criteria( [] ) );
		$this->assertSame( $cold, $warm );
		$this->assertCount( 1, $GLOBALS['bcm_test']['get_terms_calls'], 'A warm read calls get_terms() no more.' );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries, 'A warm read runs no query.' );
	}

	public function test_counts_follow_a_status_filter_and_are_cached_apart(): void {
		$GLOBALS['wpdb']->responses[ self::GROUPED ] = static fn( string $sql ): array => CatalogueFixture::rows( str_contains( $sql, "p.post_status IN ('draft')" ) ? [ 'draft' ] : null );
		$service                                     = new CategoryTree();

		$all   = CatalogueFixture::flatten( $service->get( CategoryTree::criteria( [] ) ) );
		$draft = CatalogueFixture::flatten( $service->get( self::with_status( [ 'draft' ] ) ) );

		$this->assertSame( [ 2, 2 ], [ $all[8]['count'], $all[6]['countWithDescendants'] ] );
		$this->assertSame( [ 1, 1 ], [ $draft[8]['count'], $draft[6]['countWithDescendants'] ] );
		$this->assertSame( [ 1, 1 ], [ $draft[2]['count'], $draft[1]['countWithDescendants'] ] );
		foreach ( CatalogueFixture::naive( [ 'draft' ] ) as $id => [ $own, $total ] ) {
			$this->assertSame( [ $own, $total ], [ $draft[ $id ]['count'], $draft[ $id ]['countWithDescendants'] ], "Draft counts of $id." );
		}
		$this->assertCount( 2, $GLOBALS['wpdb']->queries, 'Each set of conditions has its own entry.' );
	}

	public function test_no_categories_skips_the_count_query(): void {
		$GLOBALS['bcm_test']['term_list'] = [];
		$this->assertSame( [], ( new CategoryTree() )->get( CategoryTree::criteria( [] ) ) );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}

	public function test_the_transient_is_prefixed_registered_and_short_lived(): void {
		( new CategoryTree() )->get( CategoryTree::criteria( [] ) );
		$names = array_keys( $GLOBALS['bcm_test']['transients'] );
		$this->assertCount( 2, $names, 'The version and one tree.' );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( Cache::PREFIX, $name );
			$this->assertLessThan( 173, strlen( $name ), 'Transient names are at most 172 characters.' );
		}
		$this->assertContains( Cache::PREFIX, Registry::plugin()->transient_prefixes() );
		$tree = array_values( array_diff( $names, [ Cache::VERSION ] ) )[0];
		$this->assertSame( Cache::TTL, $GLOBALS['bcm_test']['transient_ttl'][ $tree ] );
		$this->assertSame( 0, $GLOBALS['bcm_test']['transient_ttl'][ Cache::VERSION ] );
	}

	public function test_the_key_follows_conditions_version_and_last_changed_values(): void {
		$cache = new Cache();
		$base  = $cache->key( CategoryTree::criteria( [] )->conditions() );

		$this->assertSame( $base, $cache->key( CategoryTree::criteria( [] )->conditions() ) );
		$this->assertNotSame( $base, $cache->key( self::with_status( [ 'draft' ] )->conditions() ) );

		$GLOBALS['bcm_test']['last_changed']['terms'] = '2';
		$GLOBALS['bcm_test']['last_changed']['posts'] = '9';
		$this->assertSame( $base, $cache->key( CategoryTree::criteria( [] )->conditions() ), 'Without a persistent object cache no last-changed value is in the key.' );

		Cache::invalidate();
		$this->assertNotSame( $base, $cache->key( CategoryTree::criteria( [] )->conditions() ), 'The version is.' );
	}

	public function test_without_a_persistent_object_cache_a_second_request_reads_the_stored_tree(): void {
		$criteria = CategoryTree::criteria( [] );
		$first    = ( new CategoryTree() )->get( $criteria );

		// A new request: without a persistent object cache core sets fresh last-changed values.
		$GLOBALS['bcm_test']['last_changed'] = [
			'terms' => '0.5 2',
			'posts' => '0.5 2',
		];
		$this->assertSame( $first, ( new CategoryTree() )->get( $criteria ) );

		$this->assertCount( 1, $GLOBALS['wpdb']->queries, 'The grouped statement runs once.' );
		$this->assertCount( 1, $GLOBALS['bcm_test']['get_terms_calls'], 'The terms are read once.' );
		$this->assertCount( 2, $GLOBALS['bcm_test']['transients'], 'The version and one tree; no new row per request.' );
	}

	public function test_with_a_persistent_object_cache_a_last_changed_bump_misses(): void {
		$GLOBALS['bcm_test']['ext_object_cache'] = true;
		$criteria                                = CategoryTree::criteria( [] );
		$service                                 = new CategoryTree();

		$service->get( $criteria );
		$service->get( $criteria );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries, 'Unchanged, the stored tree is read.' );

		wp_cache_set_last_changed( 'terms' );
		$service->get( $criteria );
		$this->assertCount( 2, $GLOBALS['wpdb']->queries, 'A terms last-changed bump misses.' );

		wp_cache_set_last_changed( 'posts' );
		$service->get( $criteria );
		$this->assertCount( 3, $GLOBALS['wpdb']->queries, 'A posts last-changed bump misses.' );
	}

	public function test_the_invalidation_hooks_are_registered(): void {
		Cache::register_hooks();
		$this->assertSame( [ 'created_term', 'edited_term', 'delete_term', 'set_object_terms' ], array_keys( Cache::HOOKS ) );
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			$this->assertSame( [ [ [ Cache::class, 'on_term' ], 10, 3 ] ], $GLOBALS['bcm_test']['filters'][ $hook ], $hook );
		}
		$this->assertSame( [ [ [ Cache::class, 'on_object_terms' ], 10, 4 ] ], $GLOBALS['bcm_test']['filters']['set_object_terms'] );
	}

	public function test_each_term_hook_busts_the_key_for_product_categories_only(): void {
		Cache::register_hooks();
		$service  = new CategoryTree();
		$criteria = CategoryTree::criteria( [] );
		$calls    = [
			'created_term'     => [ 12, 13, 'product_cat', [] ],
			'edited_term'      => [ 12, 13, 'product_cat', [] ],
			'delete_term'      => [ 12, 13, 'product_cat', (object) [], [] ],
			'set_object_terms' => [ 101, [ 4 ], [ 13 ], 'product_cat', false, [] ],
		];
		foreach ( $calls as $hook => $args ) {
			$service->get( $criteria );
			$queries = count( $GLOBALS['wpdb']->queries );
			$key     = ( new Cache() )->key( $criteria->conditions() );

			$other = $args;
			$other[ 'set_object_terms' === $hook ? 3 : 2 ] = 'product_tag';
			do_action( $hook, ...$other );
			$this->assertSame( $key, ( new Cache() )->key( $criteria->conditions() ), "$hook on another taxonomy leaves the key." );

			do_action( $hook, ...$args );
			$this->assertNotSame( $key, ( new Cache() )->key( $criteria->conditions() ), "$hook busts the key." );
			$service->get( $criteria );
			$this->assertCount( $queries + 1, $GLOBALS['wpdb']->queries, "After $hook the tree is read again." );
		}
	}
}
