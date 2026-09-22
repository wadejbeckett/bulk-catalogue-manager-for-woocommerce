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
		bcm_test_reset();
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
			$this->assertSame( array_keys( Registry::instance()->get( $key )->choices() ), Criteria::FILTERS[ $key ][2], $key );
		}
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
			],
			$one->conditions()
		);
		$this->assertSame( $one->conditions(), $two->conditions() );

		$base['filters'][0]['operator'] = 'isNone';
		$this->assertNotSame( $one->conditions(), Criteria::from_array( $base )->conditions() );
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
