<?php
/**
 * The query builder's input (T15; SPEC §4.1, §12.1, §13): unknown arguments,
 * filter fields, operators and sort keys are refused, as is every malformed
 * value; the filter and sort allow-lists match the field registry; and the
 * normalised conditions ignore paging and sort.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Sort;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function refuses( array $args ): bool {
		try {
			Criteria::from_array( $args );
			return false;
		} catch ( \InvalidArgumentException $e ) {
			return true;
		}
	}

	private static function filter( string $field, string $operator, mixed $value ): array {
		return [
			'filters' => [
				[
					'field'    => $field,
					'operator' => $operator,
					'value'    => $value,
				],
			],
		];
	}

	public function test_the_filters_are_the_registry_filters_with_the_same_operators(): void {
		$registry = [];
		foreach ( Registry::instance()->all() as $key => $field ) {
			$filter_by = $field->dataviews()['filterBy'];
			if ( false !== $filter_by ) {
				$registry[ $key ] = $filter_by['operators'];
			}
		}
		$builder = array_map( static fn( array $rule ): array => $rule[1], Criteria::FILTERS );
		ksort( $registry );
		ksort( $builder );

		$this->assertSame( $registry, $builder );
	}

	public function test_the_fixed_choices_are_the_registry_choices(): void {
		foreach ( [ 'status', 'stock_status', 'catalog_visibility' ] as $key ) {
			$field = Registry::instance()->get( $key );
			$this->assertSame( [ ...array_keys( $field->choices() ), ...array_keys( $field->filter_choices() ) ], Criteria::FILTERS[ $key ][2], $key );
		}
		// T92 (SPEC §4.1): the Status filter also finds Off products and those changed elsewhere while Off.
		$this->assertSame( [ 'publish', 'draft', 'pending', 'private', 'off', 'changed_outside' ], Criteria::FILTERS['status'][2] );
		$this->assertSame( [ 'off', 'changed_outside' ], self::filter_value( Criteria::from_array( self::filter( 'status', 'isAny', [ 'off', 'changed_outside' ] ) ) ) );
	}

	/**
	 * The value of the only filter of some criteria.
	 *
	 * @param Criteria $criteria Criteria.
	 * @return mixed
	 */
	private static function filter_value( Criteria $criteria ): mixed {
		return $criteria->filters[0]->value;
	}

	public function test_the_sort_keys_are_the_registry_sortable_fields(): void {
		$sortable = array_keys( array_filter( Registry::instance()->all(), static fn( $field ): bool => $field->sortable() ) );
		$sorts    = Criteria::SORTS;
		sort( $sortable );
		sort( $sorts );

		$this->assertSame( $sortable, $sorts );
	}

	public function test_unknown_keys_are_refused(): void {
		$cases = [
			'argument'          => [ 'post_type' => 'page' ],
			'orderby'           => [ 'orderby' => 'post_title' ],
			'orderby injection' => [ 'orderby' => 'name; DROP TABLE wp_posts' ],
			'orderby meta key'  => [ 'orderby' => '_price' ],
			'one of many sorts' => [ 'orderby' => [ 'name', 'rating' ] ],
			'order'             => [
				'orderby' => 'name',
				'order'   => 'sideways',
			],
			'filter field'      => self::filter( '_stock', 'isAny', [ '1' ] ),
			'non-filter field'  => self::filter( 'weight', 'between', [ 1, 2 ] ),
			'operator'          => self::filter( 'category_ids', 'contains', [ 1 ] ),
			'range operator'    => self::filter( 'status', 'between', [ 'publish', 'draft' ] ),
			'mode'              => [ 'mode' => 'orders' ],
			'filter shape'      => [ 'filters' => [ [ 'field' => 'status' ] ] ],
			'filter extra key'  => [
				'filters' => [
					[
						'field'    => 'status',
						'operator' => 'isAny',
						'value'    => [ 'draft' ],
						'sql'      => '1=1',
					],
				],
			],
			'filters as a map'  => [ 'filters' => [ 'status' => 'draft' ] ],
		];
		foreach ( $cases as $name => $args ) {
			$this->assertTrue( self::refuses( $args ), $name );
		}
	}

	public function test_malformed_values_are_refused(): void {
		$cases = [
			'term id zero'          => self::filter( 'category_ids', 'isAny', [ 0 ] ),
			'term id text'          => self::filter( 'category_ids', 'isAny', [ '3 OR 1=1' ] ),
			'term id float'         => self::filter( 'tag_ids', 'isAny', [ 1.5 ] ),
			'no terms'              => self::filter( 'tag_ids', 'isAny', [] ),
			'too many terms'        => self::filter( 'tag_ids', 'isAny', range( 1, Criteria::MAX_VALUES + 1 ) ),
			'status not a choice'   => self::filter( 'status', 'isAny', [ 'trash' ] ),
			'visibility'            => self::filter( 'catalog_visibility', 'isAny', [ 'partly' ] ),
			'type with a quote'     => self::filter( 'type', 'isAny', [ "simple' OR '1'='1" ] ),
			'empty type'            => self::filter( 'type', 'isAny', [ '' ] ),
			'tax class upper case'  => self::filter( 'tax_class', 'isAny', [ 'Reduced' ] ),
			'boolean text'          => self::filter( 'featured', 'is', 'yes' ),
			'price text'            => self::filter( 'regular_price', 'greaterThanOrEqual', '5 dollars' ),
			'price infinite'        => self::filter( 'regular_price', 'greaterThanOrEqual', INF ),
			'reversed range'        => self::filter( 'regular_price', 'between', [ 20, 10 ] ),
			'one-sided between'     => self::filter( 'stock_quantity', 'between', [ 1 ] ),
			'stock fraction'        => self::filter( 'stock_quantity', 'lessThanOrEqual', '1.5' ),
			'impossible date'       => self::filter( 'date_modified', 'before', '2026-02-30' ),
			'date with injection'   => self::filter( 'date_modified', 'after', "2026-01-01' OR 1=1" ),
			'past without unit'     => self::filter( 'date_modified', 'inThePast', [ 'value' => 3 ] ),
			'past in fortnights'    => self::filter(
				'date_created',
				'inThePast',
				[
					'value' => 3,
					'unit'  => 'fortnights',
				]
			),
			'past of zero'          => self::filter(
				'date_created',
				'inThePast',
				[
					'value' => 0,
					'unit'  => 'days',
				]
			),
			'search array'          => [ 'search' => [ 'a' ] ],
			'search too long'       => [ 'search' => str_repeat( 'a', Criteria::MAX_SEARCH + 1 ) ],
			'page text'             => [ 'page' => 'two' ],
			'per page float'        => [ 'per_page' => 2.5 ],
			'too many sort keys'    => [ 'orderby' => [ 'name', 'sku', 'id', 'units_sold', 'date_created', 'date_modified' ] ],
			'more orders than keys' => [
				'orderby' => [ 'name' ],
				'order'   => [ 'asc', 'desc' ],
			],
		];
		foreach ( $cases as $name => $args ) {
			$this->assertTrue( self::refuses( $args ), $name );
		}
	}

	public function test_sort_defaults_directions_and_duplicates(): void {
		$sorts = static fn( array $args ): array => array_map( static fn( Sort $sort ): array => $sort->to_array(), Criteria::from_array( $args )->sorts );

		$this->assertSame(
			[
				[
					'field'     => 'date_created',
					'direction' => 'desc',
				],
			],
			$sorts( [] )
		);
		$this->assertSame(
			[
				[
					'field'     => 'name',
					'direction' => 'asc',
				],
			],
			$sorts( [ 'orderby' => 'name' ] )
		);
		$this->assertSame(
			[
				[
					'field'     => 'sku',
					'direction' => 'desc',
				],
				[
					'field'     => 'name',
					'direction' => 'asc',
				],
			],
			$sorts(
				[
					'orderby' => [ 'sku', 'name', 'sku' ],
					'order'   => [ 'DESC' ],
				]
			)
		);
	}

	public function test_conditions_ignore_paging_and_sort_but_not_filters(): void {
		$base = [
			'mode'    => 'variations',
			'search'  => ' Blue ',
			'filters' => [
				[
					'field'    => 'category_ids',
					'operator' => 'isAny',
					'value'    => [ '9', 3, 9 ],
				],
			],
		];
		$one  = Criteria::from_array( $base );
		$two  = Criteria::from_array(
			$base + [
				'page'     => 4,
				'per_page' => 100,
				'orderby'  => 'sku',
			]
		);

		$this->assertSame(
			[
				'mode'    => 'variations',
				'search'  => 'Blue',
				'filters' => [
					[
						'field'    => 'category_ids',
						'operator' => 'isAny',
						'value'    => [ 3, 9 ],
					],
				],
				'parent'  => 0,
			],
			$one->conditions()
		);
		$this->assertSame( $one->conditions(), $two->conditions() );

		$base['filters'][0]['operator'] = 'isNone';
		$this->assertNotSame( $one->conditions(), Criteria::from_array( $base )->conditions() );
	}

	public function test_the_category_filter_takes_include_children_and_keeps_its_conditions_stable(): void {
		$filter  = static fn( array $extra ): array => [
			'filters' => [
				[
					'field'    => 'category_ids',
					'operator' => 'isAny',
					'value'    => [ 3 ],
				] + $extra,
			],
		];
		$default = Criteria::from_array( $filter( [] ) );
		$this->assertTrue( $default->filters[0]->include_children, 'On by default (SPEC §7).' );
		$this->assertSame( $default->conditions(), Criteria::from_array( $filter( [ 'include_children' => true ] ) )->conditions(), 'On is the default and hashes as a filter written before the flag.' );
		$this->assertArrayNotHasKey( 'include_children', $default->conditions()['filters'][0] );

		$only = Criteria::from_array( $filter( [ 'include_children' => false ] ) );
		$this->assertFalse( $only->filters[0]->include_children );
		$this->assertFalse( $only->conditions()['filters'][0]['include_children'] );
		$this->assertNotSame( $default->conditions(), $only->conditions(), 'A count or selection token keyed on the conditions tells the two apart.' );
		$this->assertSame( $only->conditions(), Criteria::from_conditions( $only->conditions() )->conditions(), 'It round-trips through a selection token.' );

		foreach ( [ 'false', 0, null ] as $bad ) {
			try {
				Criteria::from_array( $filter( [ 'include_children' => $bad ] ) );
				$this->fail( 'Accepted include_children ' . wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'include_children', $e->getMessage() );
			}
		}
		try {
			Criteria::from_array(
				[
					'filters' => [
						[
							'field'            => 'tag_ids',
							'operator'         => 'isAny',
							'value'            => [ 3 ],
							'include_children' => false,
						],
					],
				]
			);
			$this->fail( 'A tag filter took include_children.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'include_children', $e->getMessage() );
		}
	}

	public function test_a_parent_restriction_needs_the_variations_mode_and_keeps_woocommerce_order(): void {
		$criteria = Criteria::from_array(
			[
				'mode'   => 'variations',
				'parent' => '103',
			]
		);
		$this->assertSame( 103, $criteria->parent_id );
		$this->assertSame( [], $criteria->sorts, 'No sort keys: the builder uses menu_order, then id.' );
		$this->assertSame( 103, $criteria->conditions()['parent'] );

		$sorted = Criteria::from_array(
			[
				'mode'    => 'variations',
				'parent'  => 103,
				'orderby' => 'sku',
			]
		);
		$this->assertSame( [ 'sku' ], array_map( static fn( $sort ): string => $sort->field, $sorted->sorts ) );
		$this->assertSame( 0, Criteria::from_array( [] )->parent_id );

		foreach ( [
			[ 'parent' => 103 ],
			[
				'mode'   => 'products_and_variations',
				'parent' => 103,
			],
			[
				'mode'   => 'variations',
				'parent' => -1,
			],
			[
				'mode'   => 'variations',
				'parent' => '1 OR 1',
			],
			[
				'mode'   => 'variations',
				'parent' => 1.5,
			],
		] as $args ) {
			$this->assertTrue( self::refuses( $args ), (string) wp_json_encode( $args ) );
		}
	}

	public function test_accepted_value_forms_normalise(): void {
		$value = static fn( array $args ): mixed => Criteria::from_array( $args )->filters[0]->value;

		$this->assertSame( [ 'draft' ], $value( self::filter( 'status', 'isAny', 'draft' ) ) );
		$this->assertTrue( $value( self::filter( 'featured', 'is', '1' ) ) );
		$this->assertFalse( $value( self::filter( 'manage_stock', 'is', 0 ) ) );
		$this->assertSame( [ 1.5, 20.0 ], $value( self::filter( 'regular_price', 'between', [ '1.5', 20 ] ) ) );
		$this->assertSame( -3, $value( self::filter( 'stock_quantity', 'lessThanOrEqual', '-3' ) ) );
		$this->assertSame(
			[ 3, 'months' ],
			$value(
				self::filter(
					'date_modified',
					'inThePast',
					[
						'unit'  => 'months',
						'value' => '3',
					]
				)
			)
		);
		$this->assertSame( [ '' ], $value( self::filter( 'tax_class', 'isNone', [ '' ] ) ) );
	}
}
