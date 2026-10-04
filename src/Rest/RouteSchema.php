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

use BulkCatalogueManager\Export\ExportRequest;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\Sort;
use BulkCatalogueManager\Requests\RequestService;

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
	public const QUERY = [ 'search', 'orderby', 'order', 'mode', 'parent', 'fields' ];

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
	 * Every argument of a product's variations read
	 * (`GET /products/{id}/variations`): the product id from the path,
	 * paging and fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function variation_args(): array {
		return [
			'id' => self::arg(
				[
					'description' => __( 'The product whose variations are listed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'required'    => true,
				],
				static fn( mixed $value ): int => max( 0, (int) $value )
			),
		] + $this->paging_args() + $this->fields_arg();
	}

	/**
	 * Every argument of a single row read (`GET /products/{id}`): the
	 * product or variation id from the path and fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function row_args(): array {
		return [
			'id' => self::arg(
				[
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				],
				static fn( mixed $value ): int => max( 0, (int) $value )
			),
		] + $this->fields_arg();
	}

	/**
	 * The properties a hydrated row carries besides its registry fields
	 * (Query\Hydrator), for every row route's item schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function row_extras(): array {
		$read_only                           = [
			'context'  => [ 'view', 'edit' ],
			'readonly' => true,
		];
		$native_link                         = [
			'type'                 => [ 'object', 'null' ],
			'properties'           => [
				'url' => [
					'type'   => 'string',
					'format' => 'uri',
				],
			],
			'additionalProperties' => false,
		] + $read_only;
		$trash_link                          = $native_link;
		$trash_link['properties']['confirm'] = [ 'type' => 'string' ];
		return [
			'native_actions'            => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'details'     => [ 'type' => 'boolean' ],
					'editor'      => $native_link,
					'view'        => $native_link,
					'duplicate'   => $native_link,
					'trash'       => $trash_link,
					'unavailable' => [ 'type' => 'string' ],
				],
			] + $read_only,
			Hydrator::PARENT_ID         => [
				'description' => __( 'The product a variation belongs to; 0 for a product.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'integer',
				'minimum'     => 0,
			] + $read_only,
			Hydrator::THUMBNAIL_URL     => [
				'description' => __( 'Address of the product image, or of the shop\'s placeholder image when there is none.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'string',
				'format'      => 'uri',
			] + $read_only,
			Hydrator::PARENT_NAME       => [
				'description' => __( 'On a variation, the name of its product.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'string',
			] + $read_only,
			Hydrator::ATTRIBUTE_SUMMARY => [
				'description' => __( 'On a variation, its attribute values, such as "Blue / Large".', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'string',
			] + $read_only,
			Hydrator::OFF_STATE         => [
				'description' => __( 'On a product with its own saved status before Off: whether it is Off or was changed outside this screen, the status saved and when; null otherwise.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => [ 'object', 'null' ],
				'properties'  => [
					'state'            => [
						'type' => 'string',
						'enum' => [ 'off', 'changed_outside' ],
					],
					'saved'            => [ 'type' => 'string' ],
					'saved_label'      => [ 'type' => 'string' ],
					'switched_off_gmt' => [ 'type' => 'string' ],
					'batch_id'         => [ 'type' => 'integer' ],
					'scheduled_gmt'    => [ 'type' => 'string' ],
				],
			] + $read_only,
			Hydrator::ATTRIBUTES        => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'label' => [ 'type' => 'string' ],
						'value' => [ 'type' => 'string' ],
					],
				],
			] + $read_only,
		];
	}

	/**
	 * Every argument of a catalogue read (`GET /products`): paging, search,
	 * sort, mode, parent, fields and one argument per filterable field.
	 *
	 * `parent` (a product id) lists only that product's variations. It is
	 * taken only with `mode=variations`: in any other mode Criteria refuses
	 * it, and the route answers 400 `bulkcat_invalid_value`. Without `orderby`
	 * such a read keeps WooCommerce's variation order (`menu_order`, then
	 * id); an id that is not a variable product lists nothing.
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
				'strval'
			),
			'parent'  => self::arg(
				[
					'description' => __( 'The product whose variations are listed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
				],
				static fn( mixed $value ): int => max( 0, (int) $value )
			),
		] + $this->fields_arg() + $this->filter_args();
	}

	/**
	 * The arguments of `POST /selection` (T19): the grid's conditions —
	 * search, mode, parent and one argument per filterable field, as `/products`
	 * takes them — or an explicit `ids` list of positive object ids. Sort,
	 * paging and columns do not change which rows match, so they are not taken.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function selection_args(): array {
		$args = array_intersect_key( $this->query_args(), array_flip( [ 'search', 'mode', 'parent' ] ) ) + $this->filter_args() + [
			'ids' => self::arg(
				[
					'description' => __( 'The chosen products or variations, by id, instead of a filter.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
				],
				static fn( mixed $value ): array => array_map( 'intval', self::as_list( $value ) )
			),
		];
		unset( $args['mode']['default'] );
		return $args + $this->request_args( false, false );
	}

	/**
	 * Durable request envelope; deeper immutable intent is checked by its service.
	 *
	 * @param bool $issue Whether this endpoint issues a new grant.
	 * @param bool $required Whether this standalone endpoint requires the envelope.
	 * @return array<string,array<string,mixed>>
	 */
	public function request_args( bool $issue, bool $required = true ): array {
		$args = [
			'intent' => [
				'type'              => 'object',
				'required'          => $required,
				// Canonical fingerprints use the exact nested intent, including serializer2 identity.
				'sanitize_callback' => static fn( mixed $value ): mixed => $value,
			],
		];
		if ( $issue ) {
			$args['operation'] = [
				'type'     => 'string',
				'enum'     => RequestService::OPERATIONS,
				'required' => true,
			];
		} else {
			$args['uuid']          = [
				'type'     => 'string',
				'pattern'  => '^[a-f0-9-]{36}$',
				'required' => $required,
			];
			$args['request_proof'] = [
				'type'      => 'string',
				'maxLength' => 4096,
				'required'  => $required,
			];
		}
		return $args;
	}

	/**
	 * Snapshot lifecycle/composition arguments, bounded before service work.
	 *
	 * @param string $operation Endpoint operation.
	 * @return array<string,array<string,mixed>>
	 */
	public function selection_handle_args( string $operation ): array {
		$token = [
			'type'      => 'string',
			'maxLength' => 120,
			'required'  => true,
		];
		if ( 'release' === $operation ) {
			return [
				'tokens' => [
					'type'     => 'array',
					'items'    => [
						'type'      => 'string',
						'maxLength' => 120,
					],
					'minItems' => 1,
					'maxItems' => 4,
					'required' => true,
				],
			];
		}
		$args = [ 'token' => $token ];
		if ( 'inspect' === $operation ) {
			$args += [
				'page'      => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100000,
					'default' => 1,
				],
				'per_page'  => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				],
				'probe_ids' => [
					'type'     => 'array',
					'items'    => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'maxItems' => 100,
				],
			];
		} elseif ( 'compose' === $operation ) {
			$args             += $this->request_args( false );
			$token['required'] = false;
			$args             += [
				'add_token'    => $token,
				'remove_token' => $token,
			];
		}
		return $args;
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
			if ( Criteria::BRANCH_FILTER === $key ) {
				// T62, SPEC §7: "this category only" (false) or the whole branch (true, the default).
				$schema['properties'][ Criteria::INCLUDE_CHILDREN ] = [
					'description' => __( 'Whether each chosen category includes the categories below it. On unless turned off.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'boolean',
					'default'     => true,
				];
			}

			$schema['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema, $key, $field ): bool|\WP_Error {
				unset( $request );
				$valid = rest_validate_value_from_schema( $value, $schema, '' === $param ? $key : $param );
				if ( true !== $valid ) {
					return $valid;
				}
				$filter = [ 'field' => $key ] + (array) $value;
				if ( array_key_exists( Criteria::INCLUDE_CHILDREN, $filter ) ) {
					$filter[ Criteria::INCLUDE_CHILDREN ] = self::flag( $filter[ Criteria::INCLUDE_CHILDREN ] );
				}
				try {
					Criteria::from_array( [ 'filters' => [ $filter ] ] );
				} catch ( \InvalidArgumentException $e ) {
					return Refusal::invalid(
						'bulkcat_invalid_value',
						sprintf(
							/* translators: %s: a field's name, such as "Stock status". */
							__( 'The %s filter could not be used. Clear it and choose it again.', 'bulk-catalogue-manager-for-woocommerce' ),
							$field->label()
						)
					);
				}
				return true;
			};
			$schema['sanitize_callback'] = static function ( mixed $value ) use ( $key ): array {
				$clean = [
					'operator' => is_array( $value ) ? ( $value['operator'] ?? '' ) : '',
					'value'    => is_array( $value ) ? ( $value['value'] ?? null ) : null,
				];
				if ( Criteria::BRANCH_FILTER === $key && is_array( $value ) && array_key_exists( Criteria::INCLUDE_CHILDREN, $value ) ) {
					$clean[ Criteria::INCLUDE_CHILDREN ] = self::flag( $value[ Criteria::INCLUDE_CHILDREN ] );
				}
				return $clean;
			};
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
	 * @param string                              $title Schema title, such as `bulkcat-product`.
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
		foreach ( array_diff( Criteria::ARGS, Criteria::INTERNAL_ARGS ) as $arg ) {
			if ( isset( $params[ $arg ] ) ) {
				$input[ $arg ] = $params[ $arg ];
			}
		}
		$filters = [];
		foreach ( array_keys( $this->registry->filterable() ) as $key ) {
			if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
				$filter = [
					'field'    => $key,
					'operator' => $params[ $key ]['operator'] ?? '',
					'value'    => $params[ $key ]['value'] ?? null,
				];
				if ( array_key_exists( Criteria::INCLUDE_CHILDREN, $params[ $key ] ) ) {
					$filter[ Criteria::INCLUDE_CHILDREN ] = $params[ $key ][ Criteria::INCLUDE_CHILDREN ];
				}
				$filters[] = $filter;
			}
		}
		if ( [] !== $filters ) {
			$input['filters'] = $filters;
		}
		return $input;
	}

	/**
	 * A switch as a query string or JSON sends it: true, false, 1, 0, "true",
	 * "false", "1" or "0" as a boolean; anything else unchanged, for the
	 * validator to refuse.
	 *
	 * @param mixed $value Value.
	 */
	public static function flag( mixed $value ): mixed {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$text = is_int( $value ) || is_string( $value ) ? strtolower( (string) $value ) : null;
		return match ( $text ) {
			'1', 'true' => true,
			'0', 'false' => false,
			default => $value,
		};
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
					'enum' => array_map( 'strval', array_keys( $field->choices() + $field->filter_choices() ) ),
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

	/*
	 * CSV export (ticket 10, T99): the arguments of `POST /export`.
	 */

	/**
	 * The arguments of `POST /export` (SPEC §10, §12.1): every `GET /products`
	 * argument except paging and `fields`, used only for a filter export;
	 * the columns, registry keys in order; the identifier switch; the row
	 * source, a selection token or at most 1,000 ids (a list or one
	 * comma-separated field); the whole-catalogue confirmation; the summary
	 * switch; and `source`, taken only so that anything but the catalogue
	 * can be refused in plain words. The token is checked here for shape
	 * only; Export\ExportRequest reads it from the body, never the address.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function export_args(): array {
		$columns                      = $this->fields_arg()['fields'];
		$columns['description']       = __( 'Columns to export, in order: the visible columns of the view.', 'bulk-catalogue-manager-for-woocommerce' );
		$columns['required']          = true;
		$columns['minItems']          = 1;
		$columns['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $columns ): bool|\WP_Error {
			unset( $request );
			$schema = array_diff_key( $columns, array_flip( [ 'validate_callback', 'sanitize_callback' ] ) );
			return rest_validate_value_from_schema( self::as_list( $value ), $schema, $param );
		};
		$switch                       = static fn( string $description ): array => self::arg(
			[
				'description' => $description,
				'type'        => 'boolean',
				'default'     => false,
			],
			[ self::class, 'flag' ]
		);
		return array_diff_key( $this->query_args(), array_flip( [ ...self::PAGING, 'fields' ] ) ) + [
			'columns'     => $columns,
			'identifiers' => $switch( __( 'Whether ID, SKU, Parent and Type come first, so the file can go back through WooCommerce\'s product importer.', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'selection'   => self::arg(
				[
					'description' => __( 'The selected products, as the selection routes issue them. Sent in the request body, never in the address.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'maxLength'   => 120,
				],
				'strval'
			),
			'ids'         => self::export_ids_arg(),
			'confirm_all' => $switch( __( 'Confirms an export of the whole catalogue, needed when no search, filter or category is set.', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'summary'     => $switch( __( 'Whether to answer only the row counts and the file name, without exporting.', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'source'      => self::arg(
				[
					'description' => __( 'What is exported: only the catalogue is posted.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'strval'
			),
		];
	}

	/**
	 * `POST /export`'s `ids`: at most ExportRequest::MAX_IDS positive ids, as
	 * a list or one comma-separated field. The schema's maxItems caps the list
	 * before the handler runs; past it the argument answers with the export's
	 * own plain refusal, which core carries in its `rest_invalid_param` details.
	 *
	 * @return array<string, mixed>
	 */
	private static function export_ids_arg(): array {
		$arg                      = self::arg(
			[
				'description' => __( 'At most 1,000 products or variations, by id, instead of a selection or a filter.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'array',
				'items'       => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'maxItems'    => ExportRequest::MAX_IDS,
			],
			static fn( mixed $value ): array => array_map( 'intval', self::as_list( $value ) )
		);
		$schema                   = $arg['validate_callback'];
		$arg['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema ): bool|\WP_Error {
			if ( count( self::as_list( $value ) ) > ExportRequest::MAX_IDS ) {
				return ExportRequest::rows_refusal( true );
			}
			return $schema( self::as_list( $value ), $request, $param );
		};
		return $arg;
	}

	/*
	 * End of CSV export arguments.
	 */

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
