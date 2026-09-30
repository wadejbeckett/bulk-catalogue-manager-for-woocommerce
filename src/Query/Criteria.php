<?php
/**
 * The validated, normalised input of one catalogue read (SPEC §4.1, §12.1,
 * §12.2): mode, search, filters, sort and paging. Anything not on an
 * allow-list is refused here, before any SQL is built: an unknown argument,
 * filter field, operator or sort key, or a value of the wrong shape, throws.
 * Paging is clamped rather than refused, with `per_page` capped at 100.
 *
 * `parent` restricts a variations read to one product's variations (the
 * `/products/{id}/variations` route, T20, and the `parent` argument of
 * `/products`, `POST /selection` and the catalogue export). It is accepted
 * only with the variations mode; with any other mode it throws. Without an explicit sort such a read is in the order
 * WooCommerce keeps variations in, `menu_order` then id.
 *
 * Filter fields and their operators mirror the field registry's `filterBy`
 * declarations, and sort keys its sortable fields; a unit test holds the two
 * lists together.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Criteria for ProductQuery.
 */
final class Criteria {
	/*
	 * Grid modes (SPEC §4.1, §4.2).
	 */
	public const MODE_PRODUCTS                = 'products';
	public const MODE_PRODUCTS_AND_VARIATIONS = 'products_and_variations';
	public const MODE_VARIATIONS              = 'variations';

	/**
	 * The category filter's optional key (T62, SPEC §7): false filters by the
	 * chosen categories only, true (the default) by their whole branches.
	 */
	public const INCLUDE_CHILDREN = 'include_children';

	/**
	 * The one filter that takes INCLUDE_CHILDREN.
	 */
	public const BRANCH_FILTER = 'category_ids';

	/**
	 * Every mode.
	 */
	public const MODES = [ self::MODE_PRODUCTS, self::MODE_PRODUCTS_AND_VARIATIONS, self::MODE_VARIATIONS ];

	/**
	 * Most rows one page returns (SPEC §12.1).
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Rows per page when none is given (SPEC §4.1).
	 */
	public const DEFAULT_PER_PAGE = 25;

	/**
	 * Highest page number; keeps the offset far inside an integer.
	 */
	public const MAX_PAGE = 1000000;

	/**
	 * Longest search, in characters.
	 */
	public const MAX_SEARCH = 200;

	/**
	 * Most values one list filter takes (SPEC §12.1: 100 terms per term operation).
	 */
	public const MAX_VALUES = 100;

	/**
	 * Most sort keys.
	 */
	public const MAX_SORTS = 5;

	/**
	 * The arguments a read accepts.
	 */
	public const ARGS = [ 'mode', 'search', 'filters', 'orderby', 'order', 'page', 'per_page', 'parent' ];

	/**
	 * The arguments only code sets, never a request (RouteSchema::criteria_input()).
	 */
	public const INTERNAL_ARGS = [ 'filters' ];

	/*
	 * Filter value kinds.
	 */
	private const TERM_IDS = 'term_ids';
	private const SLUGS    = 'slugs';
	private const CHOICES  = 'choices';
	private const BOOL     = 'bool';
	private const DECIMAL  = 'decimal';
	private const INTEGER  = 'integer';
	private const DATE     = 'date';

	private const RANGE = [ 'between', 'greaterThanOrEqual', 'lessThanOrEqual' ];
	private const LIST  = [ 'isAny', 'isNone' ];
	private const DATES = [ 'before', 'after', 'inThePast' ];

	/**
	 * Filterable fields (SPEC §4.1): field key => [ value kind, operators, choices or slug pattern ].
	 */
	public const FILTERS = [
		'status'             => [ self::CHOICES, self::LIST, [ 'publish', 'future', 'draft', 'pending', 'private', 'off', 'changed_outside' ] ],
		'catalog_visibility' => [ self::CHOICES, self::LIST, [ 'visible', 'catalog', 'search', 'hidden' ] ],
		'featured'           => [ self::BOOL, [ 'is' ], null ],
		'category_ids'       => [ self::TERM_IDS, [ 'isAny', 'isNone', 'isAll' ], null ],
		'tag_ids'            => [ self::TERM_IDS, [ 'isAny', 'isNone', 'isAll' ], null ],
		'regular_price'      => [ self::DECIMAL, self::RANGE, null ],
		'stock_status'       => [ self::CHOICES, self::LIST, [ 'instock', 'outofstock', 'onbackorder' ] ],
		'manage_stock'       => [ self::BOOL, [ 'is' ], null ],
		'stock_quantity'     => [ self::INTEGER, self::RANGE, null ],
		'shipping_class_id'  => [ self::TERM_IDS, self::LIST, null ],
		'tax_class'          => [ self::SLUGS, self::LIST, '/^[a-z0-9_-]{0,200}$/' ],
		'type'               => [ self::SLUGS, self::LIST, '/^[a-z0-9_-]{1,200}$/' ],
		'date_created'       => [ self::DATE, self::DATES, null ],
		'date_modified'      => [ self::DATE, self::DATES, null ],
	];

	/**
	 * Sortable fields (the registry's sortable ones).
	 */
	public const SORTS = [ 'name', 'regular_price', 'stock_status', 'stock_quantity', 'sku', 'id', 'date_created', 'date_modified', 'units_sold' ];

	/**
	 * Units an "in the past" filter counts in.
	 */
	public const PAST_UNITS = [ 'days', 'weeks', 'months', 'years' ];

	/**
	 * Build through from_array().
	 *
	 * @param string   $mode     One of MODES.
	 * @param string   $search   Search text, '' for none.
	 * @param Filter[] $filters  Filters, combined with AND.
	 * @param Sort[]   $sorts    Sort keys, most significant first.
	 * @param int      $page     Page, from 1.
	 * @param int      $per_page Rows per page, 1 to MAX_PER_PAGE.
	 * @param int      $parent_id The product whose variations are read; 0 for no restriction.
	 */
	private function __construct(
		public readonly string $mode,
		public readonly string $search,
		public readonly array $filters,
		public readonly array $sorts,
		public readonly int $page,
		public readonly int $per_page,
		public readonly int $parent_id = 0
	) {}

	/**
	 * Validate and normalise a read's arguments.
	 *
	 * @param array<string, mixed> $args Any of ARGS. `filters` is a list of
	 *                                   [ field, operator, value ] arrays in the
	 *                                   DataViews shape; `orderby` a field key or
	 *                                   a list of them, `order` 'asc'/'desc' or a
	 *                                   list aligned with `orderby`.
	 * @throws \InvalidArgumentException On an unknown argument, field, operator or sort key, or a malformed value.
	 */
	public static function from_array( array $args ): self {
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), self::ARGS );
		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown argument "%s".', (string) reset( $unknown ) ) ) );
		}

		$mode = $args['mode'] ?? self::MODE_PRODUCTS;
		if ( ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown mode.' );
		}

		$search = $args['search'] ?? '';
		if ( ! is_string( $search ) ) {
			throw new \InvalidArgumentException( 'The search must be text.' );
		}
		$search = trim( $search );
		if ( mb_strlen( $search ) > self::MAX_SEARCH ) {
			throw new \InvalidArgumentException( 'The search is too long.' );
		}

		$filters = $args['filters'] ?? [];
		if ( ! is_array( $filters ) || ! array_is_list( $filters ) ) {
			throw new \InvalidArgumentException( 'Filters must be a list.' );
		}

		$parent = $args['parent'] ?? 0;
		$parent = is_int( $parent ) || ( is_string( $parent ) && 1 === preg_match( '/^\d{1,18}$/', $parent ) ) ? (int) $parent : -1;
		if ( $parent < 0 || ( $parent > 0 && self::MODE_VARIATIONS !== $mode ) ) {
			throw new \InvalidArgumentException( 'A parent restriction needs a product id and the variations mode.' );
		}

		// A product's variations keep WooCommerce's own order unless a sort is asked for.
		$sorts = $parent > 0 && ! isset( $args['orderby'] ) ? [] : self::sorts( $args['orderby'] ?? 'date_created', $args['order'] ?? ( isset( $args['orderby'] ) ? Sort::ASC : Sort::DESC ) );

		return new self(
			$mode,
			$search,
			array_map( [ self::class, 'filter' ], $filters ),
			$sorts,
			self::clamp( $args['page'] ?? 1, 1, self::MAX_PAGE, 'page' ),
			self::clamp( $args['per_page'] ?? self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE, 'per_page' ),
			$parent
		);
	}

	/**
	 * The conditions that decide which rows match — mode, search and filters,
	 * not sort or paging — as a plain array, so a count can be keyed on them (T19).
	 *
	 * @return array{mode: string, search: string, filters: array<int, array<string, mixed>>, parent: int}
	 */
	public function conditions(): array {
		return [
			'mode'    => $this->mode,
			'search'  => $this->search,
			'filters' => array_map( static fn( Filter $filter ): array => $filter->to_array(), $this->filters ),
			'parent'  => $this->parent_id,
		];
	}

	/**
	 * Criteria back from conditions() (T19: a selection token's filter),
	 * validated again exactly as from_array() validates a request, with the
	 * default sort and the first page. An "in the past" value comes back from
	 * its normalised `[ count, unit ]` to the `{ value, unit }` input shape.
	 *
	 * @param array<string, mixed> $conditions What conditions() returned.
	 * @throws \InvalidArgumentException When they are not valid conditions.
	 */
	public static function from_conditions( array $conditions ): self {
		if ( [ 'filters', 'mode', 'parent', 'search' ] !== array_keys( self::sorted( $conditions ) ) || ! is_array( $conditions['filters'] ) ) {
			throw new \InvalidArgumentException( 'Conditions need a mode, a search, filters and a parent.' );
		}
		$filters = [];
		foreach ( $conditions['filters'] as $filter ) {
			if ( is_array( $filter ) && 'inThePast' === ( $filter['operator'] ?? null ) && is_array( $filter['value'] ?? null ) && array_is_list( $filter['value'] ) && 2 === count( $filter['value'] ) ) {
				$filter['value'] = [
					'value' => $filter['value'][0],
					'unit'  => $filter['value'][1],
				];
			}
			$filters[] = $filter;
		}
		return self::from_array(
			[
				'mode'    => $conditions['mode'],
				'search'  => $conditions['search'],
				'filters' => $filters,
				'parent'  => $conditions['parent'],
			]
		);
	}

	/**
	 * Zero-based offset of the first row of the page.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * One filter.
	 *
	 * @param mixed $filter [ field, operator, value ], and for the category filter optionally include_children (a boolean).
	 * @throws \InvalidArgumentException When it is not allowed or malformed.
	 */
	private static function filter( mixed $filter ): Filter {
		$children = true;
		if ( is_array( $filter ) && array_key_exists( self::INCLUDE_CHILDREN, $filter ) ) {
			if ( self::BRANCH_FILTER !== ( $filter['field'] ?? null ) || ! is_bool( $filter[ self::INCLUDE_CHILDREN ] ) ) {
				throw new \InvalidArgumentException( 'Only the category filter takes include_children, as true or false.' );
			}
			$children = $filter[ self::INCLUDE_CHILDREN ];
			unset( $filter[ self::INCLUDE_CHILDREN ] );
		}
		if ( ! is_array( $filter ) || [ 'field', 'operator', 'value' ] !== array_keys( self::sorted( $filter ) ) ) {
			throw new \InvalidArgumentException( 'A filter must have a field, an operator and a value.' );
		}
		$field    = $filter['field'];
		$operator = $filter['operator'];
		if ( ! is_string( $field ) || ! isset( self::FILTERS[ $field ] ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown filter "%s".', is_scalar( $field ) ? (string) $field : '' ) ) );
		}
		[ $kind, $operators, $rule ] = self::FILTERS[ $field ];
		if ( ! is_string( $operator ) || ! in_array( $operator, $operators, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Filter "%s" does not take that operator.', $field ) ) );
		}
		$value = $filter['value'];
		$clean = match ( $kind ) {
			self::TERM_IDS => self::term_ids( $value ),
			self::SLUGS    => self::strings( $value, static fn( string $slug ): bool => 1 === preg_match( (string) $rule, $slug ) ),
			self::CHOICES  => self::strings( $value, static fn( string $choice ): bool => in_array( $choice, (array) $rule, true ) ),
			self::BOOL     => self::boolean( $value ),
			self::DECIMAL  => self::range( $operator, $value, false ),
			self::INTEGER  => self::range( $operator, $value, true ),
			default        => self::date( $operator, $value ),
		};
		if ( null === $clean ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Filter "%s" has an invalid value.', $field ) ) );
		}
		return new Filter( $field, $operator, $clean, $children );
	}

	/**
	 * An array with its keys sorted, to compare key sets.
	 *
	 * @param array<mixed> $values Array.
	 * @return array<mixed>
	 */
	private static function sorted( array $values ): array {
		ksort( $values );
		return $values;
	}

	/**
	 * A non-empty list of positive term ids, deduplicated, in ascending order.
	 *
	 * @param mixed $value Candidate.
	 * @return int[]|null
	 */
	private static function term_ids( mixed $value ): ?array {
		$ids = self::strings( $value, static fn( string $id ): bool => 1 === preg_match( '/^[1-9]\d{0,18}$/', $id ) );
		if ( null === $ids ) {
			return null;
		}
		$ids = array_map( 'intval', $ids );
		sort( $ids );
		return $ids;
	}

	/**
	 * A non-empty list of at most MAX_VALUES distinct strings (integers are
	 * read as their decimal text), each passing $valid; null otherwise. A
	 * single scalar is a list of one.
	 *
	 * @param mixed    $value Candidate.
	 * @param callable $valid Test for one string.
	 * @return string[]|null
	 */
	private static function strings( mixed $value, callable $valid ): ?array {
		$values = is_array( $value ) ? $value : [ $value ];
		if ( [] === $values || ! array_is_list( $values ) || count( $values ) > self::MAX_VALUES ) {
			return null;
		}
		$clean = [];
		foreach ( $values as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) ) {
				return null;
			}
			$item = (string) $item;
			if ( ! $valid( $item ) ) {
				return null;
			}
			$clean[ $item ] = $item;
		}
		return array_values( $clean );
	}

	/**
	 * A boolean from true, false, 1, 0, '1', '0', 'true' or 'false'.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function boolean( mixed $value ): ?bool {
		return match ( true ) {
			in_array( $value, [ true, 1, '1', 'true' ], true )  => true,
			in_array( $value, [ false, 0, '0', 'false' ], true ) => false,
			default                                              => null,
		};
	}

	/**
	 * A range bound, or for `between` an ascending pair of bounds.
	 *
	 * @param string $operator Range operator.
	 * @param mixed  $value    Candidate.
	 * @param bool   $integer  Whether bounds are whole numbers.
	 * @return int|float|array{0: int|float, 1: int|float}|null
	 */
	private static function range( string $operator, mixed $value, bool $integer ): int|float|array|null {
		if ( 'between' !== $operator ) {
			return self::number( $value, $integer );
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || 2 !== count( $value ) ) {
			return null;
		}
		$low  = self::number( $value[0], $integer );
		$high = self::number( $value[1], $integer );
		return null === $low || null === $high || $low > $high ? null : [ $low, $high ];
	}

	/**
	 * A finite number, or a whole one.
	 *
	 * @param mixed $value   Candidate.
	 * @param bool  $integer Whether it must be whole.
	 */
	private static function number( mixed $value, bool $integer ): int|float|null {
		if ( $integer ) {
			if ( is_int( $value ) ) {
				return $value;
			}
			return is_string( $value ) && 1 === preg_match( '/^-?\d{1,18}$/', $value ) ? (int) $value : null;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}(\.\d{1,10})?$/', $value ) ) ) {
			return null;
		}
		$number = (float) $value;
		return is_finite( $number ) ? $number : null;
	}

	/**
	 * A date bound: for before and after a `Y-m-d` date or a `Y-m-d H:i:s`
	 * time (GMT), for inThePast [ count, unit ].
	 *
	 * @param string $operator Date operator.
	 * @param mixed  $value    Candidate; inThePast takes [ 'value' => n, 'unit' => unit ].
	 * @return string|array{0: int, 1: string}|null
	 */
	private static function date( string $operator, mixed $value ): string|array|null {
		if ( 'inThePast' === $operator ) {
			if ( ! is_array( $value ) || [ 'unit', 'value' ] !== array_keys( self::sorted( $value ) ) ) {
				return null;
			}
			$count = self::number( $value['value'], true );
			if ( null === $count || $count < 1 || $count > 10000 || ! in_array( $value['unit'], self::PAST_UNITS, true ) ) {
				return null;
			}
			return [ (int) $count, (string) $value['unit'] ];
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		foreach ( [ '!Y-m-d', '!Y-m-d H:i:s' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, new \DateTimeZone( 'UTC' ) );
			if ( false !== $date && $date->format( substr( $format, 1 ) ) === $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Sort keys from `orderby` and `order`, deduplicated on field.
	 *
	 * @param mixed $orderby Field key or list of them.
	 * @param mixed $order   Direction or list of them aligned with $orderby; missing ones are ascending.
	 * @return Sort[]
	 * @throws \InvalidArgumentException On an unknown key or direction.
	 */
	private static function sorts( mixed $orderby, mixed $order ): array {
		$fields     = is_array( $orderby ) ? $orderby : [ $orderby ];
		$directions = is_array( $order ) ? $order : array_fill( 0, count( $fields ), $order );
		if ( [] === $fields || ! array_is_list( $fields ) || ! array_is_list( $directions ) || count( $fields ) > self::MAX_SORTS || count( $directions ) > count( $fields ) ) {
			throw new \InvalidArgumentException( 'Invalid sort.' );
		}
		$sorts = [];
		foreach ( $fields as $index => $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::SORTS, true ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Cannot sort by "%s".', is_scalar( $field ) ? (string) $field : '' ) ) );
			}
			$direction = $directions[ $index ] ?? Sort::ASC;
			$direction = is_string( $direction ) ? strtolower( $direction ) : '';
			if ( ! in_array( $direction, [ Sort::ASC, Sort::DESC ], true ) ) {
				throw new \InvalidArgumentException( 'The sort order must be asc or desc.' );
			}
			$sorts[ $field ] ??= new Sort( $field, $direction );
		}
		return array_values( $sorts );
	}

	/**
	 * A whole number clamped into a range.
	 *
	 * @param mixed  $value Candidate.
	 * @param int    $min   Lowest.
	 * @param int    $max   Highest.
	 * @param string $name  Argument name, for the error.
	 * @throws \InvalidArgumentException When it is not a whole number.
	 */
	private static function clamp( mixed $value, int $min, int $max, string $name ): int {
		$number = self::number( $value, true );
		if ( null === $number ) {
			throw new \InvalidArgumentException( esc_html( sprintf( '"%s" must be a whole number.', $name ) ) );
		}
		return max( $min, min( $max, (int) $number ) );
	}
}
