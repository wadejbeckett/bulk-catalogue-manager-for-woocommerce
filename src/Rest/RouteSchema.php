<?php
/**
 * Route arguments and response schemas generated from the field registry
 * (SPEC §5.1, §12.1). No read route writes its own `args` or item schema:
 * paging, search, sort, mode and column arguments, one argument per
 * filterable field with that field's operators, and one schema property per
 * field all come from here, so adding a field to the registry adds its
 * argument and its property with no change to route code.
 *
 * Every argument carries a type, a `validate_callback` and a
 * `sanitize_callback`, and a default where the read has one. Core runs the
 * validators before the permission callback and the handler, so a malformed
 * argument is answered 400 `rest_invalid_param` before any query is built.
 * A filter argument is checked twice: against its schema, then by the query
 * builder's own allow-list (`Query\Criteria`), which is what finally decides
 * whether the query layer can serve it.
 *
 * A filter argument is an object `{ operator, value }` named after the
 * field, sent as `stock_status[operator]=isAny&stock_status[value][]=instock`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * Registry-generated route arguments and item schemas.
 */
final class RouteSchema {
	/**
	 * JSON Schema dialect core reads.
	 */
	public const DIALECT = 'http://json-schema.org/draft-04/schema#';

	/**
	 * The paging arguments every list route takes.
	 */
	public const PAGING = [ 'page', 'per_page' ];

	/**
	 * The query arguments besides paging and the per-field filters.
	 */
	public const QUERY = [ 'search', 'orderby', 'order', 'mode', 'fields' ];

	/**
	 * Build over a registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Paging arguments, for every list route (`/products`, and T20's variations route).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function paging_args(): array {
		return [
			'page'     => self::arg(
				[
					'description' => __( 'Page of results, from 1.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
				],
				static fn( mixed $value ): int => min( Criteria::MAX_PAGE, max( 1, (int) $value ) )
			),
			'per_page' => self::arg(
				[
					'description' => sprintf(
						/* translators: %1$d: the most rows one page returns. */
						__( 'Rows per page; more than %1$d is read as %1$d.', 'bulk-catalogue-manager-for-woocommerce' ),
						Criteria::MAX_PER_PAGE
					),
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => Criteria::DEFAULT_PER_PAGE,
				],
				static fn( mixed $value ): int => min( Criteria::MAX_PER_PAGE, max( 1, (int) $value ) )
			),
		];
	}

	/**
	 * The column argument: which fields each row carries (SPEC §12.1 `fields[]`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields_arg(): array {
		return [
			'fields' => self::arg(
				[
					'description' => __( 'Fields each row carries; every field when absent.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => $this->registry->keys(),
					],
					'uniqueItems' => true,
				],
				[ self::class, 'as_list' ]
			),
		];
	}

	/**
	 * Every argument of a catalogue read (`GET /products`): paging, search,
	 * sort, mode, fields and one argument per filterable field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function query_args(): array {
		$sortable = array_keys( $this->registry->sortable() );
		return $this->paging_args() + [
			'search'  => self::arg(
				[
					'description' => __( 'Text to find in the name or SKU.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'maxLength'   => Criteria::MAX_SEARCH,
				],
				static fn( mixed $value ): string => trim( (string) $value )
			),
			'orderby' => self::arg(
				[
					'description' => __( 'Field or fields to sort by, most significant first; newest first when absent.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => $sortable,
					],
					'maxItems'    => Criteria::MAX_SORTS,
				],
				[ self::class, 'as_list' ]
			),
			'order'   => self::arg(
				[
					'description' => __( 'Direction of each sort field, in the same order; ascending when absent.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => [ Sort::ASC, Sort::DESC ],
					],
					'maxItems'    => Criteria::MAX_SORTS,
				],
				[ self::class, 'as_list' ]
			),
			'mode'    => self::arg(
				[
					'description' => __( 'Which rows to list: products, products and variations, or variations only.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => Criteria::MODES,
					'default'     => Criteria::MODE_PRODUCTS,
				],
				static fn( mixed $value ): string => (string) $value
			),
		] + $this->fields_arg() + $this->filter_args();
	}

	/**
	 * One argument per filterable field, named after the field, taking one
	 * of the field's operators and a value shaped by the field's type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_args(): array {
		$args = [];
		foreach ( $this->registry->filterable() as $key => $field ) {
			$schema = [
				'description'          => sprintf(
					/* translators: %s: a field's name, such as "Stock status". */
					__( 'Filter on %s.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				),
				'type'                 => 'object',
				'properties'           => [
					'operator' => [
						'type'     => 'string',
						'enum'     => $field->filter_operators(),
						'required' => true,
					],
					'value'    => self::filter_value_schema( $field ) + [ 'required' => true ],
				],
				'additionalProperties' => false,
			];

			$schema['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema, $key, $field ): bool|\WP_Error {
				unset( $request );
				$valid = rest_validate_value_from_schema( $value, $schema, '' === $param ? $key : $param );
				if ( true !== $valid ) {
					return $valid;
				}
				try {
					Criteria::from_array( [ 'filters' => [ [ 'field' => $key ] + (array) $value ] ] );
				} catch ( \InvalidArgumentException $e ) {
					return Refusal::invalid(
						'bcm_invalid_value',
						sprintf(
							/* translators: %s: a field's name, such as "Stock status". */
							__( 'The %s filter could not be used. Clear it and choose it again.', 'bulk-catalogue-manager-for-woocommerce' ),
							$field->label()
						)
					);
				}
				return true;
			};
			$schema['sanitize_callback'] = static fn( mixed $value ): array => [
				'operator' => is_array( $value ) ? ( $value['operator'] ?? '' ) : '',
				'value'    => is_array( $value ) ? ( $value['value'] ?? null ) : null,
			];
			$args[ $key ]                = $schema;
		}
		return $args;
	}

	/**
	 * The schema of one row: a property per registry field, the required
	 * `id`, then any properties a route adds of its own (T20's `parent_id`
	 * and attribute summary). A registry field always wins over an extra
	 * property of the same name.
	 *
	 * @param string                              $title Schema title, such as `bcm-product`.
	 * @param array<string, array<string, mixed>> $extra Further properties.
	 * @return array<string, mixed>
	 */
	public function item_schema( string $title, array $extra = [] ): array {
		$properties = [];
		foreach ( $this->registry->all() as $key => $field ) {
			$properties[ $key ] = $field->rest_schema();
		}
		if ( isset( $properties['id'] ) ) {
			$properties['id']['required'] = true;
		}
		return [
			'$schema'    => self::DIALECT,
			'title'      => $title,
			'type'       => 'object',
			'properties' => $properties + $extra,
		];
	}

	/**
	 * The query builder's input from a request's (validated, sanitised)
	 * parameters: paging, search, sort and mode as they are, and each filter
	 * argument present as a `{ field, operator, value }` filter.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed> Input for `Criteria::from_array()`.
	 */
	public function criteria_input( array $params ): array {
		$input = [];
		foreach ( Criteria::ARGS as $arg ) {
			if ( 'filters' !== $arg && isset( $params[ $arg ] ) ) {
				$input[ $arg ] = $params[ $arg ];
			}
		}
		$filters = [];
		foreach ( array_keys( $this->registry->filterable() ) as $key ) {
			if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
				$filters[] = [
					'field'    => $key,
					'operator' => $params[ $key ]['operator'] ?? '',
					'value'    => $params[ $key ]['value'] ?? null,
				];
			}
		}
		if ( [] !== $filters ) {
			$input['filters'] = $filters;
		}
		return $input;
	}

	/**
	 * A list from an array, or from comma-separated text as core reads a
	 * list argument given once (`orderby=name,sku`).
	 *
	 * @param mixed $value Argument.
	 * @return list<mixed>
	 */
	public static function as_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( ! is_scalar( $value ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * The shape of a filter value, from the field's type: a list of choices
	 * or term ids, a switch, a bound or a pair of bounds, or a date or
	 * "in the past" span. Criteria checks the operator's exact shape.
	 *
	 * @param Field $field Field.
	 * @return array<string, mixed>
	 */
	private static function filter_value_schema( Field $field ): array {
		return match ( $field->type() ) {
			Field::ENUM                => [
				'type'     => 'array',
				'items'    => [
					'type' => 'string',
					'enum' => array_map( 'strval', array_keys( $field->choices() ) ),
				],
				'maxItems' => Criteria::MAX_VALUES,
			],
			Field::TERMS, Field::TERM  => [
				'type'     => 'array',
				'items'    => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'maxItems' => Criteria::MAX_VALUES,
			],
			Field::BOOLEAN             => [ 'type' => 'boolean' ],
			Field::MONEY, Field::DECIMAL => [
				'type'     => [ 'number', 'array' ],
				'items'    => [ 'type' => 'number' ],
				'maxItems' => 2,
			],
			Field::INTEGER, Field::ID  => [
				'type'     => [ 'integer', 'array' ],
				'items'    => [ 'type' => 'integer' ],
				'maxItems' => 2,
			],
			Field::DATETIME            => [
				'type'       => [ 'string', 'object' ],
				'properties' => [
					'value' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'unit'  => [
						'type' => 'string',
						'enum' => Criteria::PAST_UNITS,
					],
				],
			],
			default                    => [ 'type' => 'string' ],
		};
	}

	/**
	 * An argument: its schema, a validator checking a value against that
	 * schema through core, and the sanitiser given.
	 *
	 * @param array<string, mixed> $schema   Argument schema.
	 * @param callable             $sanitise Sanitiser.
	 * @return array<string, mixed>
	 */
	private static function arg( array $schema, callable $sanitise ): array {
		$schema['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema ): bool|\WP_Error {
			unset( $request );
			return rest_validate_value_from_schema( $value, $schema, $param );
		};
		$schema['sanitize_callback'] = static fn( mixed $value ): mixed => $sanitise( $value );
		return $schema;
	}
}
