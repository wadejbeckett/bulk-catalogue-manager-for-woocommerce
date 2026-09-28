<?php
/**
 * The lean catalogue read (SPEC §12.2, §13): one prepared `$wpdb` statement
 * per page, returning identifiers and sort keys only, and a companion
 * `COUNT(*)` over exactly the same conditions. No `WC_Product` is built and
 * `SQL_CALC_FOUND_ROWS` is never used.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SPEC §12.2: the catalogue read is deliberately one direct, prepared statement; the caller (T16, T19) caches results in an object-cache group keyed on the products' last-changed value.

/**
 * Builds and runs the page and count statements for one set of criteria.
 *
 * The statement reads `{prefix}posts` joined to
 * `{prefix}wc_product_meta_lookup`, which carries SKU, prices, stock and sales,
 * so no filter or sort touches `wp_postmeta`. The lookup is a LEFT JOIN so a
 * product whose lookup row is missing still lists; any lookup condition simply
 * does not match it. It is joined only when a condition or a sort key reads it
 * (T76): joined on its primary key it can never add or remove a row, and at
 * 20,000 products the unused join more than doubled the category tree's
 * count (240 ms against 100 ms).
 *
 * Join order (T76). A lookup condition in the WHERE turns the LEFT JOIN into
 * an inner one, and MySQL 8 then tends to read the lookup first through its
 * one-value `stock_status` index or its price range: every variation's row
 * too, 90,000 rows for 20,000 products, three times the work of reading the
 * products and looking each one up. In products mode, where the post type
 * narrows the rows most, the statements therefore carry the optimiser hint
 * `JOIN_PREFIX(p)`; a server without optimiser hints reads it as a comment.
 * In the modes that read variations the post type narrows nothing and
 * forcing the order measured slower; there the hint is
 * `NO_INDEX(l stock_status, min_max_price, stock_quantity)`, so the lookup is
 * read in one pass in key order rather than row by row through an index
 * whose range the optimiser misjudges (three filters on 70,000 variations:
 * 125 ms against 200 ms). A selective lookup filter loses its index and
 * costs that one pass, about 20 to 50 ms at this size: a bounded worst case
 * rather than a lucky best one.
 *
 * Taxonomy filters add correlated `EXISTS` sub-queries on
 * `{prefix}term_relationships` (with `term_taxonomy`, and `terms` for slugs),
 * and only when such a filter is present; a semi-join cannot multiply rows, so
 * neither statement needs DISTINCT or GROUP BY.
 *
 * Safety: every table is named through a `%i` placeholder from TABLES, every
 * column and SQL keyword is a literal of this class chosen from an allow-list,
 * and every value — including the class's own post types and statuses — is a
 * `$wpdb->prepare()` argument, term ids and whole numbers as `%d`. Both
 * statements always end in a LIMIT.
 *
 * In variation rows the parent-only filters (category, tag, featured and
 * catalogue visibility) resolve against the parent (`post_parent`); every
 * other filter resolves against the row itself (SPEC §4.2).
 *
 * A category filter includes the chosen categories' descendants (T18):
 * choosing a branch in the category panel means everything filed anywhere
 * under it, which is how the shop's own category pages list products. The
 * ids are expanded through `get_term_children()`, which reads WordPress's
 * cached `product_cat_children` hierarchy option rather than the database,
 * before the statement is built; "any of" and "none of" test the whole
 * expanded set, and "all of" needs each chosen category or one of its
 * descendants. With the filter's `include_children` off (T62, SPEC §7:
 * "this category only") each chosen category matches exactly, like a tag.
 * Tags and shipping classes are flat, so they match exactly.
 *
 * A read restricted to one product (`Criteria::$parent_id`, T20) lists that
 * product's variations in the statuses WooCommerce counts as its children,
 * published and private, in WooCommerce's order: `menu_order`, then id.
 */
final class ProductQuery {
	/**
	 * Tables the builder may name, alias => unprefixed name.
	 */
	public const TABLES = [
		'posts'         => 'posts',
		'lookup'        => 'wc_product_meta_lookup',
		'relationships' => 'term_relationships',
		'taxonomy'      => 'term_taxonomy',
		'terms'         => 'terms',
	];

	/**
	 * Post statuses a catalogue row may have; trash, auto-draft and
	 * inherit never list.
	 */
	public const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	/**
	 * Statuses a product's variations are listed and counted in, as
	 * WooCommerce reads a variable product's children.
	 */
	public const CHILD_STATUSES = [ 'publish', 'private' ];

	/**
	 * Post types per mode.
	 */
	public const POST_TYPES = [
		Criteria::MODE_PRODUCTS                => [ 'product' ],
		Criteria::MODE_PRODUCTS_AND_VARIATIONS => [ 'product', 'product_variation' ],
		Criteria::MODE_VARIATIONS              => [ 'product_variation' ],
	];

	/**
	 * Sort key => [ column ascending, column descending ]. A price sorts on
	 * the lowest price going up and the highest going down, as WooCommerce does.
	 */
	private const SORT_COLUMNS = [
		'id'             => [ 'p.ID', 'p.ID' ],
		'name'           => [ 'p.post_title', 'p.post_title' ],
		'sku'            => [ 'l.sku', 'l.sku' ],
		'regular_price'  => [ 'l.min_price', 'l.max_price' ],
		'stock_status'   => [ 'l.stock_status', 'l.stock_status' ],
		'stock_quantity' => [ 'l.stock_quantity', 'l.stock_quantity' ],
		'date_created'   => [ 'p.post_date_gmt', 'p.post_date_gmt' ],
		'date_modified'  => [ 'p.post_modified_gmt', 'p.post_modified_gmt' ],
		'units_sold'     => [ 'l.total_sales', 'l.total_sales' ],
	];

	/**
	 * Taxonomy per term-id filter.
	 */
	private const TERM_TAXONOMIES = [
		'category_ids'      => 'product_cat',
		'tag_ids'           => 'product_tag',
		'shipping_class_id' => 'product_shipping_class',
	];

	/**
	 * Filters that read the parent in a variation row.
	 */
	private const PARENT_FILTERS = [ 'category_ids', 'tag_ids', 'featured', 'catalog_visibility' ];

	/**
	 * Catalogue visibility => [ has exclude-from-catalog, has exclude-from-search ].
	 */
	private const VISIBILITY = [
		'visible' => [ false, false ],
		'catalog' => [ false, true ],
		'search'  => [ true, false ],
		'hidden'  => [ true, true ],
	];

	/**
	 * The type a variation row has.
	 */
	private const VARIATION_TYPE = 'variation';

	/**
	 * The criteria.
	 *
	 * @var Criteria
	 */
	private Criteria $criteria;

	/**
	 * The moment "in the past" filters count back from.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $now;

	/**
	 * The WHERE clause template, built once.
	 *
	 * @var string
	 */
	private string $where = '';

	/**
	 * Arguments for the WHERE clause's placeholders, in order.
	 *
	 * @var array<int, int|float|string>
	 */
	private array $where_args = [];

	/**
	 * Whether a condition reads the lookup table.
	 *
	 * @var bool
	 */
	private bool $lookup = false;

	/**
	 * Build a query.
	 *
	 * @param Criteria                $criteria Validated criteria.
	 * @param \DateTimeImmutable|null $now      Current time; now by default.
	 */
	public function __construct( Criteria $criteria, ?\DateTimeImmutable $now = null ) {
		$this->criteria = $criteria;
		$this->now      = ( $now ?? new \DateTimeImmutable( 'now' ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->build_where();
	}

	/**
	 * Build a query straight from a read's arguments.
	 *
	 * @param array<string, mixed> $args See Criteria::from_array().
	 * @throws \InvalidArgumentException On anything Criteria refuses.
	 */
	public static function from_array( array $args ): self {
		return new self( Criteria::from_array( $args ) );
	}

	/**
	 * The criteria this query runs.
	 */
	public function criteria(): Criteria {
		return $this->criteria;
	}

	/**
	 * The page statement, prepared: `id`, `parent_id` and each sort key, with
	 * ORDER BY the sort keys then `p.ID`, and LIMIT/OFFSET. It shares the
	 * conditions of conditions_sql(), joining the lookup table also when only
	 * a sort key reads it.
	 */
	public function statement(): string {
		global $wpdb;
		$select  = [ 'p.ID AS id', 'p.post_parent AS parent_id' ];
		$order   = [];
		$ids     = false;
		$keyword = 'ASC';
		$lookup  = $this->lookup;
		if ( [] === $this->criteria->sorts ) {
			// Only a one-product variations read has no sort keys: WooCommerce's own order.
			$order[] = 'p.menu_order ASC';
		}
		foreach ( $this->criteria->sorts as $sort ) {
			$column  = self::SORT_COLUMNS[ $sort->field ][ Sort::DESC === $sort->direction ? 1 : 0 ];
			$keyword = Sort::DESC === $sort->direction ? 'DESC' : 'ASC';
			$order[] = $column . ' ' . $keyword;
			$lookup  = $lookup || str_starts_with( $column, 'l.' );
			if ( 'id' === $sort->field ) {
				$ids = true;
			} else {
				$select[] = $column . ' AS ' . $sort->field;
			}
		}
		if ( ! $ids ) {
			// A stable tiebreak, running the same way as the last key.
			$order[] = 'p.ID ' . $keyword;
		}
		$sql  = 'SELECT ' . $this->hint( $lookup ) . implode( ', ', $select ) . ' ' . $this->from( $lookup ) . ' WHERE ' . $this->where . ' ORDER BY ' . implode( ', ', $order ) . ' LIMIT %d OFFSET %d';
		$args = [ ...$this->table_args( $lookup ), ...$this->where_args, $this->criteria->per_page, $this->criteria->offset() ];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only allow-listed literals and placeholders; every value is an argument.
		return (string) $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * The count statement, prepared: `COUNT(*)` over the same FROM and WHERE
	 * as statement(), with no ORDER BY and a LIMIT of one row.
	 */
	public function count_statement(): string {
		global $wpdb;
		$conditions = $this->conditions_sql();
		$sql        = 'SELECT ' . $this->hint( $this->lookup ) . 'COUNT(*) ' . $conditions['sql'] . ' LIMIT %d';
		$args       = [ ...$conditions['args'], 1 ];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only allow-listed literals and placeholders; every value is an argument.
		return (string) $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * The FROM and WHERE every statement over these criteria shares, not yet
	 * prepared: a template of allow-listed literals and placeholders only, and
	 * its arguments in order. For a caller that embeds the same conditions in
	 * a statement of its own and prepares the whole once (Categories\Counts),
	 * so it matches exactly the rows the grid lists. The lookup table is
	 * joined only when a condition reads it.
	 *
	 * @return array{sql: string, args: list<int|float|string>}
	 */
	public function conditions_sql(): array {
		return [
			'sql'  => $this->from( $this->lookup ) . ' WHERE ' . $this->where,
			'args' => [ ...$this->table_args( $this->lookup ), ...$this->where_args ],
		];
	}

	/**
	 * Run the page statement.
	 *
	 * @return array<int, array{id: int, parent_id: int}> Rows in order; sort keys are not returned.
	 */
	public function rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement() returns a prepared statement.
		$rows = $wpdb->get_results( $this->statement(), ARRAY_A );
		return array_map(
			static fn( array $row ): array => [
				'id'        => (int) $row['id'],
				'parent_id' => (int) $row['parent_id'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * Capture typed identities in one statement, independent of view paging.
	 *
	 * @param int[]|null $ids Optional validated explicit identities.
	 * @return array{products: int[], variations: int[]}
	 * @throws \RuntimeException On an unreadable catalogue.
	 * @throws \LengthException Above the selection ceiling.
	 */
	public function snapshot_members( ?array $ids = null ): array {
		global $wpdb;
		$conditions = $this->conditions_sql();
		$sql        = 'SELECT ' . $this->hint( $this->lookup ) . 'p.ID AS id, p.post_type AS object_type ' . $conditions['sql'];
		$args       = $conditions['args'];
		if ( null !== $ids ) {
			if ( [] === $ids ) {
				return [
					'products'   => [],
					'variations' => [],
				];
			}
			$sql .= ' AND p.ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args = [ ...$args, ...$ids ];
		}
		$sql   .= ' ORDER BY p.ID ASC LIMIT %d';
		$args[] = \BulkCatalogueManager\Selection\Selection::MAX_OBJECTS + 1;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Allow-listed conditions and identifier placeholders, bounded integer limit.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Catalogue capture failed.' );
		}
		if ( count( $rows ) > \BulkCatalogueManager\Selection\Selection::MAX_OBJECTS ) {
			throw new \LengthException( 'Selection exceeds its object limit.' );
		}
		$members = [
			'products'   => [],
			'variations' => [],
		];
		foreach ( $rows as $row ) {
			$members[ 'product' === $row['object_type'] ? 'products' : 'variations' ][] = (int) $row['id'];
		}
		return $members;
	}

	/**
	 * Run the count statement.
	 */
	public function total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- count_statement() returns a prepared statement.
		return (int) $wpdb->get_var( $this->count_statement() );
	}

	/**
	 * The full name of an allow-listed table.
	 *
	 * @param string $alias A key of TABLES.
	 */
	private static function table( string $alias ): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $alias ];
	}

	/**
	 * The expression naming the product a row belongs to: the row itself in
	 * products mode, its parent in variations mode, and either in products
	 * and variations mode; with its arguments. What a parent-only filter
	 * resolves against, and what Categories\Counts counts.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	public function product_owner(): array {
		return $this->owner( true );
	}

	/**
	 * The FROM clause template.
	 *
	 * @param bool $lookup Whether to join the lookup table.
	 */
	private function from( bool $lookup ): string {
		return 'FROM %i AS p' . ( $lookup ? ' LEFT JOIN %i AS l ON l.product_id = p.ID' : '' );
	}

	/**
	 * Arguments for the FROM clause.
	 *
	 * @param bool $lookup Whether the lookup table is joined.
	 * @return string[]
	 */
	private function table_args( bool $lookup ): array {
		return $lookup ? [ self::table( 'posts' ), self::table( 'lookup' ) ] : [ self::table( 'posts' ) ];
	}

	/**
	 * The optimiser hint for a statement that joins the lookup table (see
	 * the class comment): the posts first in products mode, the lookup in
	 * one pass in the modes that read variations; nothing without the join.
	 *
	 * @param bool $lookup Whether the statement joins the lookup table.
	 */
	private function hint( bool $lookup ): string {
		if ( ! $lookup ) {
			return '';
		}
		return Criteria::MODE_PRODUCTS === $this->criteria->mode ? '/*+ JOIN_PREFIX(p) */ ' : '/*+ NO_INDEX(l stock_status, min_max_price, stock_quantity) */ ';
	}

	/**
	 * Build the WHERE clause and its arguments from the criteria.
	 */
	private function build_where(): void {
		$types    = self::POST_TYPES[ $this->criteria->mode ];
		$statuses = $this->criteria->parent_id > 0 ? self::CHILD_STATUSES : self::STATUSES;
		$this->add( 'p.post_type IN (' . self::placeholders( $types, '%s' ) . ')', $types );
		$this->add( 'p.post_status IN (' . self::placeholders( $statuses, '%s' ) . ')', $statuses );
		if ( $this->criteria->parent_id > 0 ) {
			$this->add( 'p.post_parent = %d', [ $this->criteria->parent_id ] );
		}

		if ( '' !== $this->criteria->search ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $this->criteria->search ) . '%';
			$this->add( '(p.post_title LIKE %s OR l.sku LIKE %s)', [ $like, $like ], true );
		}

		foreach ( $this->criteria->filters as $filter ) {
			$this->filter( $filter );
		}
	}

	/**
	 * Append one AND condition.
	 *
	 * @param string                       $sql    Condition template.
	 * @param array<int, int|float|string> $args   Its arguments.
	 * @param bool                         $lookup Whether it reads the lookup table (`l.`).
	 */
	private function add( string $sql, array $args, bool $lookup = false ): void {
		$this->where      = '' === $this->where ? $sql : $this->where . ' AND ' . $sql;
		$this->where_args = [ ...$this->where_args, ...$args ];
		$this->lookup     = $this->lookup || $lookup;
	}

	/**
	 * Comma-separated placeholders, one per value.
	 *
	 * @param array<mixed> $values      Values.
	 * @param string       $placeholder %s or %d.
	 */
	private static function placeholders( array $values, string $placeholder ): string {
		return implode( ', ', array_fill( 0, count( $values ), $placeholder ) );
	}

	/**
	 * Append one filter's condition.
	 *
	 * @param Filter $filter Validated filter.
	 */
	private function filter( Filter $filter ): void {
		$value                  = $filter->value;
		[ $owner, $owner_args ] = $this->owner( in_array( $filter->field, self::PARENT_FILTERS, true ) );
		$negate                 = 'isNone' === $filter->operator;

		switch ( $filter->field ) {
			case 'status':
				$this->add( 'p.post_status ' . ( $negate ? 'NOT IN' : 'IN' ) . ' (' . self::placeholders( $value, '%s' ) . ')', $value );
				return;

			case 'stock_status':
			case 'tax_class':
				// A row without a lookup row has no value, so it is neither in nor out of the list.
				$this->add( 'l.' . $filter->field . ' ' . ( $negate ? 'NOT IN' : 'IN' ) . ' (' . self::placeholders( $value, '%s' ) . ')', $value, true );
				return;

			case 'manage_stock':
				// WooCommerce stores a quantity in the lookup exactly when stock is managed.
				$this->add( 'l.stock_quantity IS ' . ( $value ? 'NOT NULL' : 'NULL' ), [], true );
				return;

			case 'regular_price':
				$this->range( 'l.min_price', 'l.max_price', '%f', $filter );
				return;

			case 'stock_quantity':
				$this->range( 'l.stock_quantity', 'l.stock_quantity', '%d', $filter );
				return;

			case 'date_created':
			case 'date_modified':
				$this->date( 'date_created' === $filter->field ? 'p.post_date_gmt' : 'p.post_modified_gmt', $filter );
				return;

			case 'category_ids':
			case 'tag_ids':
			case 'shipping_class_id':
				$this->terms( self::TERM_TAXONOMIES[ $filter->field ], $filter, $owner, $owner_args );
				return;

			case 'featured':
				[ $sql, $args ] = self::slug_exists( $owner, $owner_args, 'product_visibility', [ 'featured' ] );
				$this->add( ( $value ? '' : 'NOT ' ) . $sql, $args );
				return;

			case 'catalog_visibility':
				$this->visibility( $filter, $owner, $owner_args );
				return;

			case 'type':
				$this->type( $filter );
				return;
		}
	}

	/**
	 * The id a filter's terms are read from: the row itself, or for a
	 * parent-only filter the row's product (a variation's parent).
	 *
	 * @param bool $from_parent Whether the filter is parent-only.
	 * @return array{0: string, 1: array<int, string>} Expression template and its arguments.
	 */
	private function owner( bool $from_parent ): array {
		if ( ! $from_parent || Criteria::MODE_PRODUCTS === $this->criteria->mode ) {
			return [ 'p.ID', [] ];
		}
		if ( Criteria::MODE_VARIATIONS === $this->criteria->mode ) {
			return [ 'p.post_parent', [] ];
		}
		return [ '(CASE WHEN p.post_type = %s THEN p.post_parent ELSE p.ID END)', [ 'product_variation' ] ];
	}

	/**
	 * A price or stock range. A product matches a price range when any of
	 * its prices falls in it, as WooCommerce's price filter does.
	 *
	 * @param string $low_column  Column holding the lowest value.
	 * @param string $high_column Column holding the highest value.
	 * @param string $placeholder %f or %d.
	 * @param Filter $filter      Range filter.
	 */
	private function range( string $low_column, string $high_column, string $placeholder, Filter $filter ): void {
		$value = $filter->value;
		if ( 'between' === $filter->operator ) {
			$this->add( '(' . $high_column . ' >= ' . $placeholder . ' AND ' . $low_column . ' <= ' . $placeholder . ')', [ $value[0], $value[1] ], true );
		} elseif ( 'greaterThanOrEqual' === $filter->operator ) {
			$this->add( $high_column . ' >= ' . $placeholder, [ $value ], true );
		} else {
			$this->add( $low_column . ' <= ' . $placeholder, [ $value ], true );
		}
	}

	/**
	 * A date filter on a GMT column. Before a date means before its first
	 * second; after a date means from the next day on; a full time compares
	 * exactly. In the past counts back from now.
	 *
	 * @param string $column Column.
	 * @param Filter $filter Date filter.
	 */
	private function date( string $column, Filter $filter ): void {
		$value = $filter->value;
		if ( 'inThePast' === $filter->operator ) {
			$since = $this->now->modify( sprintf( '-%d %s', $value[0], $value[1] ) )->format( 'Y-m-d H:i:s' );
			$this->add( $column . ' >= %s', [ $since ] );
			return;
		}
		$is_day = 10 === strlen( $value );
		if ( 'before' === $filter->operator ) {
			$this->add( $column . ' < %s', [ $is_day ? $value . ' 00:00:00' : $value ] );
			return;
		}
		if ( $is_day ) {
			$next = ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$this->add( $column . ' >= %s', [ $next . ' 00:00:00' ] );
			return;
		}
		$this->add( $column . ' > %s', [ $value ] );
	}

	/**
	 * A term-id filter: any of, none of, or all of the terms.
	 *
	 * @param string             $taxonomy   Taxonomy.
	 * @param Filter             $filter     Term filter; the value is a list of term ids.
	 * @param string             $owner      Owner expression.
	 * @param array<int, string> $owner_args Its arguments.
	 */
	private function terms( string $taxonomy, Filter $filter, string $owner, array $owner_args ): void {
		$ids = $filter->value;
		if ( 'product_cat' === $taxonomy ) {
			$this->categories( $filter, $owner, $owner_args );
			return;
		}
		[ $body, $args ] = self::term_body( $taxonomy, $ids, $owner, $owner_args );
		if ( 'isAll' === $filter->operator ) {
			$this->add( '(SELECT COUNT(DISTINCT tt.term_id) ' . $body . ') = %d', [ ...$args, count( $ids ) ] );
			return;
		}
		$this->add( ( 'isNone' === $filter->operator ? 'NOT ' : '' ) . 'EXISTS (SELECT 1 ' . $body . ')', $args );
	}

	/**
	 * A category filter, each chosen category standing for itself and every
	 * category below it (or for itself alone when the filter's
	 * include_children is off): any of or none of the whole expanded set, or for
	 * "all of" one EXISTS per chosen category over that category's branch.
	 *
	 * @param Filter             $filter     Category filter; the value is a list of term ids.
	 * @param string             $owner      Owner expression.
	 * @param array<int, string> $owner_args Its arguments.
	 */
	private function categories( Filter $filter, string $owner, array $owner_args ): void {
		$branches = array_map( static fn( int $id ): array => $filter->include_children ? self::branch( $id ) : [ $id ], $filter->value );
		if ( 'isAll' === $filter->operator ) {
			$parts = [];
			$args  = [];
			foreach ( $branches as $branch ) {
				[ $body, $body_args ] = self::term_body( 'product_cat', $branch, $owner, $owner_args );
				$parts[]              = 'EXISTS (SELECT 1 ' . $body . ')';
				$args                 = [ ...$args, ...$body_args ];
			}
			$this->add( implode( ' AND ', $parts ), $args );
			return;
		}
		$ids = array_values( array_unique( array_merge( ...$branches ) ) );
		sort( $ids );
		[ $body, $args ] = self::term_body( 'product_cat', $ids, $owner, $owner_args );
		$this->add( ( 'isNone' === $filter->operator ? 'NOT ' : '' ) . 'EXISTS (SELECT 1 ' . $body . ')', $args );
	}

	/**
	 * A category and every category below it, in ascending order. The
	 * hierarchy comes from WordPress's cached option, not a query.
	 *
	 * @param int $id Category id.
	 * @return int[]
	 */
	private static function branch( int $id ): array {
		$children = get_term_children( $id, 'product_cat' );
		$ids      = [ $id ];
		foreach ( is_array( $children ) ? $children : [] as $child ) {
			if ( is_numeric( $child ) && (int) $child > 0 ) {
				$ids[] = (int) $child;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * The FROM and WHERE of a term sub-query: the owner has one of these
	 * term ids in the taxonomy.
	 *
	 * @param string             $taxonomy   Taxonomy.
	 * @param int[]              $ids        Term ids.
	 * @param string             $owner      Owner expression.
	 * @param array<int, string> $owner_args Its arguments.
	 * @return array{0: string, 1: array<int, int|string>} Template and its arguments.
	 */
	private static function term_body( string $taxonomy, array $ids, string $owner, array $owner_args ): array {
		return [
			'FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = ' . $owner . ' AND tt.taxonomy = %s AND tt.term_id IN (' . self::placeholders( $ids, '%d' ) . ')',
			[ self::table( 'relationships' ), self::table( 'taxonomy' ), ...$owner_args, $taxonomy, ...$ids ],
		];
	}

	/**
	 * An EXISTS test for any of some term slugs in one taxonomy.
	 *
	 * @param string             $owner      Owner expression.
	 * @param array<int, string> $owner_args Its arguments.
	 * @param string             $taxonomy   Taxonomy.
	 * @param string[]           $slugs      Term slugs.
	 * @return array{0: string, 1: array<int, string>} Condition template and its arguments.
	 */
	private static function slug_exists( string $owner, array $owner_args, string $taxonomy, array $slugs ): array {
		return [
			'EXISTS (SELECT 1 FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i AS t ON t.term_id = tt.term_id WHERE tr.object_id = ' . $owner . ' AND tt.taxonomy = %s AND t.slug IN (' . self::placeholders( $slugs, '%s' ) . '))',
			[ self::table( 'relationships' ), self::table( 'taxonomy' ), self::table( 'terms' ), ...$owner_args, $taxonomy, ...$slugs ],
		];
	}

	/**
	 * Catalogue visibility: each of the four values is a combination of the
	 * two exclude terms (SPEC §5.2).
	 *
	 * @param Filter             $filter     Visibility filter; the value is a list of visibility choices.
	 * @param string             $owner      Owner expression.
	 * @param array<int, string> $owner_args Its arguments.
	 */
	private function visibility( Filter $filter, string $owner, array $owner_args ): void {
		$parts = [];
		$args  = [];
		foreach ( $filter->value as $choice ) {
			$terms = [];
			foreach ( [ 'exclude-from-catalog', 'exclude-from-search' ] as $index => $slug ) {
				[ $sql, $sql_args ] = self::slug_exists( $owner, $owner_args, 'product_visibility', [ $slug ] );
				$terms[]            = ( self::VISIBILITY[ $choice ][ $index ] ? '' : 'NOT ' ) . $sql;
				$args               = [ ...$args, ...$sql_args ];
			}
			$parts[] = '(' . implode( ' AND ', $terms ) . ')';
		}
		$this->add( ( 'isNone' === $filter->operator ? 'NOT ' : '' ) . '(' . implode( ' OR ', $parts ) . ')', $args );
	}

	/**
	 * Product type. A variation row's type is "variation"; any other type is
	 * the product's `product_type` term.
	 *
	 * @param Filter $filter Type filter; the value is a list of type slugs.
	 */
	private function type( Filter $filter ): void {
		$parts = [];
		$args  = [];
		$types = array_values( array_diff( $filter->value, [ self::VARIATION_TYPE ] ) );
		if ( count( $types ) < count( $filter->value ) ) {
			$parts[] = 'p.post_type = %s';
			$args[]  = 'product_variation';
		}
		if ( [] !== $types ) {
			[ $sql, $sql_args ] = self::slug_exists( 'p.ID', [], 'product_type', $types );
			$parts[]            = $sql;
			$args               = [ ...$args, ...$sql_args ];
		}
		$this->add( ( 'isNone' === $filter->operator ? 'NOT ' : '' ) . '(' . implode( ' OR ', $parts ) . ')', $args );
	}
}
