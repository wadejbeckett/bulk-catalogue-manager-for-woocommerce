<?php
/**
 * Registry-generated route arguments and item schemas (T14; SPEC §5.1,
 * §12.1): an argument per filterable field with its operators, a schema
 * property per field, the paging, search, sort and mode arguments with their
 * callbacks and defaults, and a field added to the registry adding both its
 * argument and its property with no change to route code.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\ProductsController;
use BulkCatalogueManager\Rest\RouteSchema;
use BulkCatalogueManager\Rest\Routes;
use PHPUnit\Framework\TestCase;

final class RouteSchemaTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	/**
	 * A registry double: every field plus one synthetic filterable field.
	 */
	private static function registry_with_synthetic_field(): Registry {
		return new Registry(
			[
				...array_values( ( new Registry() )->all() ),
				new Field(
					key: 'origin',
					label: 'Country of origin',
					type: Field::ENUM,
					storage: Storage::meta( '_origin' ),
					edit_kinds: [ Field::SET ],
					variation_rule: Field::PER_VARIATION,
					getter: 'get_origin',
					setter: 'set_origin',
					choices: [
						'za' => 'South Africa',
						'ie' => 'Ireland',
					],
					sortable: true,
					filter_operators: [ 'isAny', 'isNone' ]
				),
			]
		);
	}

	/**
	 * A value of the right shape for each field type, as a hydrated row carries it.
	 *
	 * @return array<string, mixed>
	 */
	private static function sample_item( Registry $registry ): array {
		$item = [];
		foreach ( $registry->all() as $key => $field ) {
			$item[ $key ] = match ( $field->type() ) {
				Field::TEXT     => 'Blue shirt',
				Field::ENUM     => (string) array_key_first( $field->choices() ),
				Field::BOOLEAN  => true,
				Field::MONEY    => '12.50',
				Field::DECIMAL  => '1.5',
				Field::INTEGER  => $field->nullable() ? null : 3,
				Field::TERMS    => [ 12, 14 ],
				Field::TERM     => 0,
				Field::ID       => 42,
				Field::IMAGE    => 0,
				Field::HTML     => '<span class="amount">R12.50</span>',
				Field::DATETIME => '2026-09-23T10:00:00+00:00',
			};
		}
		return $item;
	}

	public function test_there_is_one_argument_per_filterable_field_with_its_operators(): void {
		$registry = new Registry();
		$args     = ( new RouteSchema( $registry ) )->query_args();
		$filters  = ( new RouteSchema( $registry ) )->filter_args();

		$filterable = array_keys( array_filter( $registry->all(), static fn( Field $field ): bool => false !== $field->dataviews()['filterBy'] ) );
		$this->assertSame( $filterable, array_keys( $filters ) );
		$this->assertCount( 14, $filters );
		foreach ( $registry->all() as $key => $field ) {
			if ( ! $field->filterable() ) {
				$this->assertArrayNotHasKey( $key, $filters, "$key is not a filter." );
				continue;
			}
			$this->assertArrayHasKey( $key, $args );
			$this->assertSame( 'object', $args[ $key ]['type'] );
			$this->assertSame( $field->dataviews()['filterBy']['operators'], $args[ $key ]['properties']['operator']['enum'], $key );
			$this->assertTrue( $args[ $key ]['properties']['operator']['required'] );
			$this->assertTrue( $args[ $key ]['properties']['value']['required'] );
		}
		$builder = array_keys( Criteria::FILTERS );
		$route   = array_keys( $filters );
		sort( $builder );
		sort( $route );
		$this->assertSame( $builder, $route, 'The query builder serves exactly the generated filter arguments.' );
	}

	public function test_every_argument_has_a_type_and_both_callbacks(): void {
		foreach ( ( new RouteSchema( new Registry() ) )->query_args() as $key => $arg ) {
			$this->assertArrayHasKey( 'type', $arg, $key );
			$this->assertArrayHasKey( 'description', $arg, $key );
			$this->assertTrue( is_callable( $arg['validate_callback'] ), "$key has a validator." );
			$this->assertTrue( is_callable( $arg['sanitize_callback'] ), "$key has a sanitiser." );
		}
	}

	public function test_paging_search_sort_mode_and_fields_come_with_their_rules_and_defaults(): void {
		$registry = new Registry();
		$args     = ( new RouteSchema( $registry ) )->query_args();

		$this->assertSame( [ ...RouteSchema::PAGING, ...RouteSchema::QUERY ], array_slice( array_keys( $args ), 0, 8 ) );
		$this->assertSame( 1, $args['page']['default'] );
		$this->assertSame( 'integer', $args['per_page']['type'] );
		$this->assertSame( Criteria::DEFAULT_PER_PAGE, $args['per_page']['default'] );
		$this->assertSame( Criteria::MODE_PRODUCTS, $args['mode']['default'] );
		$this->assertSame( Criteria::MODES, $args['mode']['enum'] );
		$this->assertSame( array_keys( $registry->sortable() ), $args['orderby']['items']['enum'] );
		$this->assertSame( Criteria::SORTS, $args['orderby']['items']['enum'] );
		$this->assertSame( [ 'asc', 'desc' ], $args['order']['items']['enum'] );
		$this->assertSame( $registry->keys(), $args['fields']['items']['enum'] );
		$this->assertArrayNotHasKey( 'default', $args['orderby'], 'A default sort would change the direction an explicit sort takes.' );

		$this->assertSame( 100, call_user_func( $args['per_page']['sanitize_callback'], '500' ) );
		$this->assertSame( 25, call_user_func( $args['per_page']['sanitize_callback'], 25 ) );
		$this->assertSame( [ 'name', 'sku' ], call_user_func( $args['orderby']['sanitize_callback'], 'name,sku' ) );
		$this->assertSame( 'blue', call_user_func( $args['search']['sanitize_callback'], '  blue ' ) );
		$this->assertTrue( call_user_func( $args['per_page']['validate_callback'], '500', null, 'per_page' ) );
	}

	public function test_malformed_arguments_fail_their_validators(): void {
		$args  = ( new RouteSchema( new Registry() ) )->query_args();
		$cases = [
			'per_page'      => [ 'abc', '0', '2.5' ],
			'page'          => [ 'abc', '0' ],
			'orderby'       => [ 'DROP', 'name,DROP', [ 'name', 'post_title' ], 'name,sku,id,units_sold,regular_price,stock_status' ],
			'order'         => [ 'sideways', [ 'asc', 'up' ] ],
			'mode'          => [ 'pages', '' ],
			'search'        => [ [ 'a' ], str_repeat( 'x', 201 ) ],
			'fields'        => [ 'name,post_password' ],
			'stock_status'  => [
				'instock',
				[
					'operator' => 'is',
					'value'    => [ 'instock' ],
				],
				[
					'operator' => 'isAny',
					'value'    => [ 'sold' ],
				],
				[ 'operator' => 'isAny' ],
				[
					'operator' => 'isAny',
					'value'    => [ 'instock' ],
					'sql'      => '1=1',
				],
			],
			'category_ids'  => [
				[
					'operator' => 'isAny',
					'value'    => [ '1) OR (1=1' ],
				],
				[
					'operator' => 'isAny',
					'value'    => [],
				],
			],
			'regular_price' => [
				[
					'operator' => 'between',
					'value'    => [ 9, 1 ],
				],
				[
					'operator' => 'between',
					'value'    => 'cheap',
				],
			],
			'date_modified' => [
				[
					'operator' => 'before',
					'value'    => '23/09/2026',
				],
				[
					'operator' => 'inThePast',
					'value'    => [
						'value' => 3,
						'unit'  => 'fortnights',
					],
				],
			],
			'featured'      => [
				[
					'operator' => 'is',
					'value'    => 'yes',
				],
			],
		];
		foreach ( $cases as $key => $values ) {
			foreach ( $values as $value ) {
				$result = call_user_func( $args[ $key ]['validate_callback'], $value, null, $key );
				$this->assertInstanceOf( \WP_Error::class, $result, "$key accepted " . wp_json_encode( $value ) );
			}
		}
	}

	public function test_well_formed_filters_pass_and_become_criteria(): void {
		$schema = new RouteSchema( new Registry() );
		$args   = $schema->query_args();
		$params = [
			'page'          => 2,
			'per_page'      => 50,
			'mode'          => 'variations',
			'search'        => 'shirt',
			'orderby'       => [ 'regular_price', 'id' ],
			'order'         => [ 'desc' ],
			'stock_status'  => [
				'operator' => 'isAny',
				'value'    => [ 'outofstock' ],
			],
			'category_ids'  => [
				'operator' => 'isAll',
				'value'    => [ '12', '14' ],
			],
			'regular_price' => [
				'operator' => 'between',
				'value'    => [ '10', '99.95' ],
			],
			'featured'      => [
				'operator' => 'is',
				'value'    => '1',
			],
			'date_modified' => [
				'operator' => 'inThePast',
				'value'    => [
					'value' => '3',
					'unit'  => 'days',
				],
			],
			'fields'        => [ 'name', 'sku' ],
		];
		foreach ( $params as $key => $value ) {
			$this->assertTrue( call_user_func( $args[ $key ]['validate_callback'], $value, null, $key ), $key );
			$params[ $key ] = call_user_func( $args[ $key ]['sanitize_callback'], $value, null, $key );
		}
		$criteria = Criteria::from_array( $schema->criteria_input( $params ) );

		$this->assertSame( 'variations', $criteria->mode );
		$this->assertSame( 2, $criteria->page );
		$this->assertSame( 50, $criteria->per_page );
		$this->assertSame( [ 'featured', 'category_ids', 'regular_price', 'stock_status', 'date_modified' ], array_map( static fn( $filter ) => $filter->field, $criteria->filters ) );
		$this->assertSame( [ 12, 14 ], $criteria->filters[1]->value, 'Filters follow registry order.' );
		$this->assertSame( [ [ 'regular_price', 'desc' ], [ 'id', 'asc' ] ], array_map( static fn( $sort ) => [ $sort->field, $sort->direction ], $criteria->sorts ) );
	}

	public function test_no_filter_field_is_named_like_a_query_argument(): void {
		foreach ( array_keys( ( new Registry() )->filterable() ) as $key ) {
			$this->assertNotContains( $key, [ ...RouteSchema::PAGING, ...RouteSchema::QUERY, 'filters' ], $key );
		}
	}

	public function test_there_is_one_schema_property_per_field(): void {
		$registry = new Registry();
		$schema   = ( new RouteSchema( $registry ) )->item_schema( 'bcm-product' );

		$this->assertSame( RouteSchema::DIALECT, $schema['$schema'] );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( $registry->keys(), array_keys( $schema['properties'] ) );
		foreach ( $registry->all() as $key => $field ) {
			$expected = $field->rest_schema() + ( 'id' === $key ? [ 'required' => true ] : [] );
			$this->assertSame( $expected, $schema['properties'][ $key ], $key );
		}
		$extra = ( new RouteSchema( $registry ) )->item_schema(
			'bcm-variation',
			[
				'parent_id' => [ 'type' => 'integer' ],
				'name'      => [ 'type' => 'boolean' ],
			]
		);
		$this->assertSame( [ 'type' => 'integer' ], $extra['properties']['parent_id'] );
		$this->assertSame( 'string', $extra['properties']['name']['type'], 'A registry field wins over an extra property.' );
	}

	public function test_a_sample_item_passes_the_registered_schema(): void {
		$registry   = new Registry();
		$controller = new ProductsController( $registry );
		$item       = self::sample_item( $registry );
		$this->assertTrue( rest_validate_value_from_schema( $item, $controller->get_item_schema(), 'item' ) );

		$item['stock_quantity'] = 7;
		$this->assertTrue( rest_validate_value_from_schema( $item, $controller->get_item_schema(), 'item' ) );

		$broken = [
			'enum'     => [ 'stock_status' => 'sold' ],
			'boolean'  => [ 'featured' => 'maybe' ],
			'terms'    => [ 'category_ids' => [ 'twelve' ] ],
			'integer'  => [ 'units_sold' => null ],
			'datetime' => [ 'date_created' => 12 ],
		];
		foreach ( $broken as $case => $change ) {
			$this->assertInstanceOf( \WP_Error::class, rest_validate_value_from_schema( $change + $item, $controller->get_item_schema(), 'item' ), $case );
		}
		$without_id = $item;
		unset( $without_id['id'] );
		$result = rest_validate_value_from_schema( $without_id, $controller->get_item_schema(), 'item' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_property_required', $result->get_error_code() );
	}

	public function test_adding_a_field_adds_its_argument_and_its_property_with_no_route_code_change(): void {
		$registry = self::registry_with_synthetic_field();
		$schema   = new RouteSchema( $registry );

		$this->assertArrayNotHasKey( 'origin', ( new RouteSchema( new Registry() ) )->query_args() );
		$this->assertArrayHasKey( 'origin', $schema->query_args() );
		$this->assertSame( [ 'isAny', 'isNone' ], $schema->query_args()['origin']['properties']['operator']['enum'] );
		$this->assertSame( [ 'za', 'ie' ], $schema->query_args()['origin']['properties']['value']['items']['enum'] );
		$this->assertContains( 'origin', $schema->query_args()['orderby']['items']['enum'] );
		$this->assertContains( 'origin', $schema->query_args()['fields']['items']['enum'] );
		$this->assertSame( [ 'type', 'enum' ], array_values( array_intersect( array_keys( $schema->item_schema( 'bcm-product' )['properties']['origin'] ), [ 'type', 'enum' ] ) ) );

		// The unchanged products controller, built over the double, registers both.
		( new Routes( [ new ProductsController( $registry ) ] ) )->register();
		$route = $GLOBALS['bcm_test']['routes']['/bcm/v1/products'];
		$this->assertArrayHasKey( 'origin', $route[0]['args'] );
		$this->assertArrayHasKey( 'origin', call_user_func( $route['schema'] )['properties'] );
		$item = [ 'origin' => 'ie' ] + self::sample_item( $registry );
		$this->assertTrue( rest_validate_value_from_schema( $item, call_user_func( $route['schema'] ), 'item' ) );
	}
}
