<?php
/**
 * Saved views (SPEC §4.1, §10, §12.1, §13; T52). Each user's views are JSON
 * in their own `bulkcat_views` user meta, kept per site (meta_key()), so one
 * user's views are never listed or readable by another: an id that is not in the current user's list is
 * 404, whoever else holds it.
 *
 * - `GET /bulkcat/v1/views` answers `{ items, default, max }`: the views in the
 *   owner's order, each `{ id, name, view, category, isDefault }`, the id of
 *   the view the screen opens with (or null) and the most a user may keep.
 * - `POST /views` `{ name, view, category?, isDefault? }` saves a new view
 *   (201); `PATCH /views/{id}` renames it or replaces its view and category;
 *   `DELETE /views/{id}` removes it (and the default with it);
 *   `POST /views/reorder` `{ ids }` puts every view in the given order;
 *   `POST /views/{id}/default` `{ default }` marks it the default the screen
 *   opens with, or with `default: false` clears that. Every write answers the
 *   whole list as GET does, plus `item` where one view was written.
 *
 * All six sit behind Access::can_read: a saved view is a way of reading the
 * catalogue and changes nothing in the shop. A view is sanitised against an
 * explicit schema before it is stored (§13): known keys must have their
 * documented type or the save is refused in plain words; string values are
 * cleaned with sanitize_text_field(); field keys must be registry fields; an
 * unrecognised key with a plain name is kept as plain JSON (so a later client
 * key survives a round trip) and one with any other name is dropped. Names
 * are sanitised, required, at most 60 characters and unique per user;
 * a user keeps at most 50 views.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;

defined( 'ABSPATH' ) || exit;

/**
 * The saved views routes.
 */
final class ViewsController implements ReadController {
	/**
	 * The collection route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/views';

	/**
	 * The name of the user meta holding a user's views as JSON (registered in
	 * Data\Registry). It is stored under the site's table prefix, as
	 * update_user_option() does (meta_key()), so each site of a network keeps
	 * its own views. Before 1.3.1 it was stored unprefixed, network-wide; the
	 * main site reads that old value once and moves it to its own key.
	 */
	public const META_KEY = 'bulkcat_views';

	/**
	 * The current site's user meta key for saved views: the site's table
	 * prefix plus META_KEY, the key update_user_option() would use.
	 */
	public static function meta_key(): string {
		global $wpdb;
		return $wpdb->prefix . self::META_KEY;
	}

	/**
	 * The most views one user may keep.
	 */
	public const MAX_VIEWS = 50;

	/**
	 * The longest view name, in characters.
	 */
	public const MAX_NAME = 60;

	/**
	 * The largest stored view and category, JSON-encoded, in bytes.
	 */
	public const MAX_BYTES = 20000;

	/**
	 * A view id: twelve lower-case hex digits.
	 */
	public const ID_PATTERN = '[a-f0-9]{12}';

	/**
	 * Layouts a view may use (T51).
	 */
	public const LAYOUTS = [ 'table', 'grid' ];

	/**
	 * Page sizes a view may use (SPEC §4.1).
	 */
	public const PAGE_SIZES = [ 25, 50, 100 ];

	/**
	 * Refusal code for a view, category or order that breaks the schema.
	 */
	public const INVALID = 'bulkcat_invalid_view';

	/**
	 * Refusal code for a missing or over-long name.
	 */
	public const NAME = 'bulkcat_view_name_required';

	/**
	 * Refusal code for a name another of the user's views has.
	 */
	public const EXISTS = 'bulkcat_view_exists';

	/**
	 * Refusal code for a save above MAX_VIEWS.
	 */
	public const TOO_MANY = 'bulkcat_too_many_views';

	/**
	 * Refusal code for an id not in the current user's views.
	 */
	public const NOT_FOUND = 'bulkcat_view_not_found';

	/**
	 * The view keys the schema knows; any other is kept as plain JSON or dropped.
	 */
	private const VIEW_KEYS = [ 'type', 'perPage', 'search', 'mode', 'filters', 'sort', 'fields', 'titleField', 'mediaField', 'descriptionField', 'showMedia', 'showTitle', 'showDescription', 'layout' ];

	/**
	 * Limits of plain JSON: nesting depth, entries per list or object, string length.
	 */
	private const MAX_DEPTH  = 4;
	private const MAX_LIST   = 100;
	private const MAX_STRING = 500;

	/**
	 * Build over the field registry, which says which field keys a view may name.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Add the routes through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$read = [ Access::class, 'can_read' ];
		$one  = self::ROUTE . '/(?P<id>' . self::ID_PATTERN . ')';
		$body = [
			'name'      => [
				'description' => __( 'The name the view is saved under.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'string',
			],
			// Left untyped so sanitize_view() refuses a malformed view in plain words.
			'view'      => [
				'description' => __( 'The catalogue view: layout, page size, search, filters, sort and columns.', 'bulk-catalogue-manager-for-woocommerce' ),
			],
			'category'  => [
				'description' => __( 'The category chosen in the category panel, or null for all products.', 'bulk-catalogue-manager-for-woocommerce' ),
			],
			'isDefault' => [
				'description' => __( 'Whether the screen opens with this view.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'boolean',
			],
		];
		$schema = [ $this, 'get_item_schema' ];

		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => $read,
					'args'                => [],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => $read,
					'args'                => $body,
				],
				'schema' => $schema,
			]
		);
		$routes->add(
			self::ROUTE . '/reorder',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'reorder' ],
					'permission_callback' => $read,
					'args'                => [
						'ids' => [
							'description' => __( 'Every saved view id, in the new order.', 'bulk-catalogue-manager-for-woocommerce' ),
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'required'    => true,
						],
					],
				],
				'schema' => $schema,
			]
		);
		$routes->add(
			$one,
			[
				[
					'methods'             => 'PATCH, PUT',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => $read,
					'args'                => array_diff_key( $body, [ 'isDefault' => true ] ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => $read,
					'args'                => [],
				],
				'schema' => $schema,
			]
		);
		$routes->add(
			$one . '/default',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'set_default' ],
					'permission_callback' => $read,
					'args'                => [
						'default' => [
							'description' => __( 'True to open the screen with this view, false to stop.', 'bulk-catalogue-manager-for-woocommerce' ),
							'type'        => 'boolean',
							'default'     => true,
						],
					],
				],
				'schema' => $schema,
			]
		);
	}

	/**
	 * The current user's views.
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public function get_items( mixed $request = null ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( self::payload( self::load() ), 200 );
	}

	/**
	 * Save a new view.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = self::load();
		if ( count( $store['views'] ) >= self::MAX_VIEWS ) {
			return Refusal::invalid(
				self::TOO_MANY,
				sprintf(
					/* translators: %d: the most saved views one person may keep, 50. */
					__( 'You already have %d saved views, the most you can keep. Delete one you no longer use, then save this one.', 'bulk-catalogue-manager-for-woocommerce' ),
					self::MAX_VIEWS
				)
			);
		}
		$name = $this->name( $request->get_param( 'name' ), $store, '' );
		if ( $name instanceof \WP_Error ) {
			return $name;
		}
		$view = $this->sanitize_view( $request->get_param( 'view' ) );
		if ( $view instanceof \WP_Error ) {
			return $view;
		}
		$category = $this->category( $request->get_param( 'category' ) );
		if ( $category instanceof \WP_Error ) {
			return $category;
		}
		$too_big = self::too_big( $view, $category );
		if ( null !== $too_big ) {
			return $too_big;
		}
		$id               = self::new_id( $store );
		$store['views'][] = [
			'id'       => $id,
			'name'     => $name,
			'view'     => $view,
			'category' => $category,
		];
		if ( true === $request->get_param( 'isDefault' ) ) {
			$store['default'] = $id;
		}
		self::save( $store );
		return new \WP_REST_Response( self::payload( $store, $id ), 201 );
	}

	/**
	 * Rename a view or replace its view and category.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = self::load();
		$index = self::find( $store, (string) $request->get_param( 'id' ) );
		if ( null === $index ) {
			return self::not_found();
		}
		$record = $store['views'][ $index ];
		if ( null !== $request->get_param( 'name' ) ) {
			$name = $this->name( $request->get_param( 'name' ), $store, $record['id'] );
			if ( $name instanceof \WP_Error ) {
				return $name;
			}
			$record['name'] = $name;
		}
		if ( null !== $request->get_param( 'view' ) ) {
			$view = $this->sanitize_view( $request->get_param( 'view' ) );
			if ( $view instanceof \WP_Error ) {
				return $view;
			}
			$record['view'] = $view;
		}
		if ( array_key_exists( 'category', $request->get_params() ) ) {
			$category = $this->category( $request->get_param( 'category' ) );
			if ( $category instanceof \WP_Error ) {
				return $category;
			}
			$record['category'] = $category;
		}
		$too_big = self::too_big( $record['view'], $record['category'] );
		if ( null !== $too_big ) {
			return $too_big;
		}
		$store['views'][ $index ] = $record;
		self::save( $store );
		return new \WP_REST_Response( self::payload( $store, $record['id'] ), 200 );
	}

	/**
	 * Delete a view; the default goes with it.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function delete_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = self::load();
		$id    = (string) $request->get_param( 'id' );
		$index = self::find( $store, $id );
		if ( null === $index ) {
			return self::not_found();
		}
		array_splice( $store['views'], $index, 1 );
		if ( $store['default'] === $id ) {
			$store['default'] = null;
		}
		self::save( $store );
		return new \WP_REST_Response( self::payload( $store ), 200 );
	}

	/**
	 * Put the views in a new order: `ids` must name every view once.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function reorder( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = self::load();
		$ids   = $request->get_param( 'ids' );
		$ids   = is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : [];
		$have  = array_column( $store['views'], 'id' );
		$given = $ids;
		sort( $have );
		sort( $given );
		if ( $have !== $given ) {
			return Refusal::invalid(
				self::INVALID,
				__( 'Your saved views changed while you were moving them. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'param' => 'ids' ]
			);
		}
		$by_id          = array_column( $store['views'], null, 'id' );
		$store['views'] = array_map( static fn( string $id ): array => $by_id[ $id ], $ids );
		self::save( $store );
		return new \WP_REST_Response( self::payload( $store ), 200 );
	}

	/**
	 * Mark a view the default the screen opens with, or clear that.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function set_default( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = self::load();
		$id    = (string) $request->get_param( 'id' );
		if ( null === self::find( $store, $id ) ) {
			return self::not_found();
		}
		if ( false === $request->get_param( 'default' ) ) {
			if ( $store['default'] === $id ) {
				$store['default'] = null;
			}
		} else {
			$store['default'] = $id;
		}
		self::save( $store );
		return new \WP_REST_Response( self::payload( $store, $id ), 200 );
	}

	/**
	 * The current user's stored views, repaired: a record without an id, a
	 * name or a view is dropped, and a default naming no view is cleared.
	 *
	 * @return array{default: string|null, views: list<array{id: string, name: string, view: array<string, mixed>, category: array<string, mixed>|null}>}
	 */
	public static function load(): array {
		$user = get_current_user_id();
		$raw  = get_user_meta( $user, self::meta_key(), true );
		if ( ( ! is_string( $raw ) || '' === $raw ) && is_main_site() ) {
			// Views saved before they were kept per site: the main site takes them over once.
			$legacy = get_user_meta( $user, self::META_KEY, true );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				update_user_meta( $user, self::meta_key(), wp_slash( $legacy ) );
				delete_user_meta( $user, self::META_KEY );
				$raw = $legacy;
			}
		}
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$views   = [];
		foreach ( is_array( $decoded['views'] ?? null ) ? $decoded['views'] : [] as $record ) {
			if ( is_array( $record ) && is_string( $record['id'] ?? null ) && 1 === preg_match( '/^' . self::ID_PATTERN . '$/', $record['id'] )
				&& is_string( $record['name'] ?? null ) && is_array( $record['view'] ?? null ) ) {
				$views[] = [
					'id'       => $record['id'],
					'name'     => $record['name'],
					'view'     => $record['view'],
					'category' => is_array( $record['category'] ?? null ) ? $record['category'] : null,
				];
			}
		}
		$views   = array_slice( $views, 0, self::MAX_VIEWS );
		$default = is_string( $decoded['default'] ?? null ) && in_array( $decoded['default'], array_column( $views, 'id' ), true ) ? $decoded['default'] : null;
		return [
			'default' => $default,
			'views'   => $views,
		];
	}

	/**
	 * Store the current user's views as JSON.
	 *
	 * @param array{default: string|null, views: list<array<string, mixed>>} $store Views and default.
	 */
	private static function save( array $store ): void {
		$json = wp_json_encode(
			[
				'version' => 1,
				'default' => $store['default'],
				'views'   => array_values( $store['views'] ),
			]
		);
		// update_user_meta() unslashes its value; slash it so a backslash in the JSON survives.
		update_user_meta( get_current_user_id(), self::meta_key(), wp_slash( (string) $json ) );
	}

	/**
	 * The list answer, plus `item` when one view was written.
	 *
	 * @param array{default: string|null, views: list<array<string, mixed>>} $store Views and default.
	 * @param string                                                         $item  The id of the view written, or ''.
	 * @return array<string, mixed>
	 */
	public static function payload( array $store, string $item = '' ): array {
		$items = array_map(
			static fn( array $record ): array => [
				'id'        => $record['id'],
				'name'      => $record['name'],
				'view'      => self::as_object( $record['view'] ),
				'category'  => null === $record['category'] ? null : (object) $record['category'],
				'isDefault' => $record['id'] === $store['default'],
			],
			$store['views']
		);
		$out   = [
			'items'   => $items,
			'default' => $store['default'],
			'max'     => self::MAX_VIEWS,
		];
		foreach ( $items as $one ) {
			if ( '' !== $item && $one['id'] === $item ) {
				$out['item'] = $one;
			}
		}
		return $out;
	}

	/**
	 * A stored view as the JSON object the client reads: the view and its
	 * `layout` encode as objects even when empty.
	 *
	 * @param array<string, mixed> $view Stored view.
	 */
	private static function as_object( array $view ): object {
		if ( isset( $view['layout'] ) && is_array( $view['layout'] ) ) {
			$view['layout'] = (object) $view['layout'];
		}
		return (object) $view;
	}

	/**
	 * A sanitised, required, unique name, or the refusal.
	 *
	 * @param mixed                                $value Submitted name.
	 * @param array{views: list<array<string, mixed>>} $store Views.
	 * @param string                               $own   The id of the view being renamed, or ''.
	 */
	private function name( mixed $value, array $store, string $own ): string|\WP_Error {
		$name = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		if ( '' === $name ) {
			return Refusal::invalid( self::NAME, __( 'A saved view needs a name. Type a name, such as "Out of stock shirts".', 'bulk-catalogue-manager-for-woocommerce' ), [ 'param' => 'name' ] );
		}
		if ( mb_strlen( $name ) > self::MAX_NAME ) {
			return Refusal::invalid(
				self::NAME,
				sprintf(
					/* translators: %d: the most characters a view name may have, 60. */
					__( 'A view name can be at most %d characters. Use a shorter name.', 'bulk-catalogue-manager-for-woocommerce' ),
					self::MAX_NAME
				),
				[ 'param' => 'name' ]
			);
		}
		foreach ( $store['views'] as $record ) {
			if ( $record['id'] !== $own && mb_strtolower( $record['name'] ) === mb_strtolower( $name ) ) {
				return Refusal::invalid(
					self::EXISTS,
					sprintf(
						/* translators: %s: a saved view's name. */
						__( 'You already have a view called "%s". Choose another name.', 'bulk-catalogue-manager-for-woocommerce' ),
						$name
					),
					[ 'param' => 'name' ]
				);
			}
		}
		return $name;
	}

	/**
	 * A view object sanitised against the explicit schema, or the refusal.
	 * `page` is never stored: a saved view opens on page 1.
	 *
	 * @param mixed $value Submitted view.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sanitize_view( mixed $value ): array|\WP_Error {
		if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) ) ) {
			return self::invalid_view();
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( 'page' === $key ) {
				continue;
			}
			if ( ! in_array( $key, self::VIEW_KEYS, true ) ) {
				// An unrecognised key: kept as plain JSON when its name is a plain identifier.
				if ( self::plain_key( $key ) ) {
					$out[ $key ] = self::plain( $item, 1 );
				}
				continue;
			}
			$clean = match ( $key ) {
				'type'                                         => ( in_array( $item, self::LAYOUTS, true ) ? $item : null ),
				'perPage'                                      => ( in_array( $item, self::PAGE_SIZES, true ) ? $item : null ),
				'search'                                       => ( is_string( $item ) && mb_strlen( $item ) <= Criteria::MAX_SEARCH ? sanitize_text_field( $item ) : null ),
				'mode'                                         => ( in_array( $item, Criteria::MODES, true ) ? $item : null ),
				'filters'                                      => $this->filters( $item ),
				'sort'                                         => $this->sort( $item ),
				'fields'                                       => $this->field_list( $item ),
				'titleField', 'mediaField', 'descriptionField' => ( is_string( $item ) && $this->registry->has( $item ) ? $item : null ),
				'showMedia', 'showTitle', 'showDescription'    => ( is_bool( $item ) ? $item : null ),
				default                                        => ( is_array( $item ) && ( [] === $item || ! array_is_list( $item ) ) ? (array) self::plain( $item, 1 ) : null ),
			};
			if ( null === $clean ) {
				return self::invalid_view( $key );
			}
			$out[ $key ] = $clean;
		}
		return $out;
	}

	/**
	 * A category panel value `{ id, ids?, includeChildren? }`, null for all products, or the refusal.
	 *
	 * @param mixed $value Submitted category.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function category( mixed $value ): array|\WP_Error|null {
		if ( null === $value ) {
			return null;
		}
		$refusal = Refusal::invalid( self::INVALID, __( 'The chosen category could not be saved with this view. Choose the category again and save the view.', 'bulk-catalogue-manager-for-woocommerce' ), [ 'param' => 'category' ] );
		if ( ! is_array( $value ) || ! isset( $value['id'] ) || ! is_numeric( $value['id'] ) || (int) $value['id'] < 0 ) {
			return $refusal;
		}
		$out = [ 'id' => (int) $value['id'] ];
		if ( isset( $value['ids'] ) ) {
			if ( ! is_array( $value['ids'] ) || count( $value['ids'] ) > self::MAX_LIST ) {
				return $refusal;
			}
			foreach ( $value['ids'] as $id ) {
				if ( ! is_numeric( $id ) || (int) $id < 1 ) {
					return $refusal;
				}
			}
			$out['ids'] = array_values( array_unique( array_map( 'intval', $value['ids'] ) ) );
		}
		if ( isset( $value['includeChildren'] ) ) {
			if ( ! is_bool( $value['includeChildren'] ) ) {
				return $refusal;
			}
			$out['includeChildren'] = $value['includeChildren'];
		}
		return $out;
	}

	/**
	 * DataViews filters: a list of `{ field, operator, value }`, the field a registry key.
	 *
	 * @param mixed $value Filters.
	 * @return list<array<string, mixed>>|null
	 */
	private function filters( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_LIST ) {
			return null;
		}
		$out = [];
		foreach ( $value as $filter ) {
			if ( ! is_array( $filter ) || ! is_string( $filter['field'] ?? null ) || ! $this->registry->has( $filter['field'] )
				|| ! is_string( $filter['operator'] ?? null ) || 1 !== preg_match( '/^[A-Za-z]{1,40}$/', $filter['operator'] ) ) {
				return null;
			}
			$clean = [
				'field'    => $filter['field'],
				'operator' => $filter['operator'],
			];
			if ( array_key_exists( 'value', $filter ) ) {
				$item = $filter['value'];
				if ( is_array( $item ) ) {
					if ( count( $item ) > self::MAX_LIST ) {
						return null;
					}
					foreach ( $item as $each ) {
						if ( ! is_scalar( $each ) && null !== $each ) {
							return null;
						}
					}
				} elseif ( ! is_scalar( $item ) && null !== $item ) {
					return null;
				}
				$clean['value'] = self::plain( $item, 1 );
			}
			foreach ( $filter as $extra => $item ) {
				if ( ! isset( $clean[ $extra ] ) && 'value' !== $extra && self::plain_key( (string) $extra ) && is_bool( $item ) ) {
					$clean[ (string) $extra ] = $item;
				}
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * A sort `{ field, direction }` on a registry field.
	 *
	 * @param mixed $value Sort.
	 * @return array{field: string, direction: string}|null
	 */
	private function sort( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['field'] ?? null ) || ! $this->registry->has( $value['field'] ) ) {
			return null;
		}
		$direction = $value['direction'] ?? 'asc';
		if ( ! in_array( $direction, [ 'asc', 'desc' ], true ) ) {
			return null;
		}
		return [
			'field'     => $value['field'],
			'direction' => $direction,
		];
	}

	/**
	 * The visible columns in order: registry keys, each once.
	 *
	 * @param mixed $value Field keys.
	 * @return list<string>|null
	 */
	private function field_list( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_LIST ) {
			return null;
		}
		foreach ( $value as $key ) {
			if ( ! is_string( $key ) || ! $this->registry->has( $key ) ) {
				return null;
			}
		}
		return array_values( array_unique( $value ) );
	}

	/**
	 * Whether a key is a plain identifier, safe to keep unrecognised.
	 *
	 * @param string $key Key.
	 */
	private static function plain_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key );
	}

	/**
	 * Plain JSON: scalars kept (strings cleaned and cut to 500 characters),
	 * lists and objects to four levels and 100 entries, keys plain identifiers.
	 * Anything deeper or longer is dropped.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth, from 1.
	 * @return mixed Clean value; an object as an array.
	 */
	private static function plain( mixed $value, int $depth ): mixed {
		if ( is_string( $value ) ) {
			return mb_substr( sanitize_text_field( $value ), 0, self::MAX_STRING );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_null( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
			return null;
		}
		$list = array_is_list( $value );
		$out  = [];
		foreach ( array_slice( $value, 0, self::MAX_LIST, true ) as $key => $item ) {
			if ( $list ) {
				$out[] = self::plain( $item, $depth + 1 );
			} elseif ( self::plain_key( (string) $key ) ) {
				$out[ (string) $key ] = self::plain( $item, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * The refusal for a stored view and category above MAX_BYTES, or null.
	 *
	 * @param array<string, mixed>      $view     View.
	 * @param array<string, mixed>|null $category Category.
	 */
	private static function too_big( array $view, ?array $category ): ?\WP_Error {
		$size = strlen( (string) wp_json_encode( [ $view, $category ] ) );
		return $size > self::MAX_BYTES ? self::invalid_view() : null;
	}

	/**
	 * A fresh id not in the store.
	 *
	 * @param array{views: list<array<string, mixed>>} $store Views.
	 */
	private static function new_id( array $store ): string {
		$taken = array_column( $store['views'], 'id' );
		do {
			$id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * The index of a view in the store, or null.
	 *
	 * @param array{views: list<array<string, mixed>>} $store Views.
	 * @param string                                   $id    View id.
	 */
	private static function find( array $store, string $id ): ?int {
		foreach ( $store['views'] as $index => $record ) {
			if ( $record['id'] === $id ) {
				return (int) $index;
			}
		}
		return null;
	}

	/**
	 * The refusal for a view the current user does not have.
	 */
	private static function not_found(): \WP_Error {
		return Refusal::create( self::NOT_FOUND, __( 'This saved view no longer exists. Reload the page to see your saved views.', 'bulk-catalogue-manager-for-woocommerce' ), 404 );
	}

	/**
	 * The refusal for a view that breaks the schema.
	 *
	 * @param string $key The offending key, or ''.
	 */
	private static function invalid_view( string $key = '' ): \WP_Error {
		return Refusal::invalid(
			self::INVALID,
			__( 'This view could not be saved because part of it is not valid. Clear the filters, choose the columns again and save it once more.', 'bulk-catalogue-manager-for-woocommerce' ),
			'' === $key ? [ 'param' => 'view' ] : [
				'param' => 'view',
				'key'   => $key,
			]
		);
	}

	/**
	 * The schema of the answer.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$item = [
			'type'       => 'object',
			'properties' => [
				'id'        => [
					'type'    => 'string',
					'pattern' => '^' . self::ID_PATTERN . '$',
				],
				'name'      => [
					'type'      => 'string',
					'maxLength' => self::MAX_NAME,
				],
				'view'      => [
					'type'       => 'object',
					'properties' => [
						'type'    => [
							'type' => 'string',
							'enum' => self::LAYOUTS,
						],
						'perPage' => [
							'type' => 'integer',
							'enum' => self::PAGE_SIZES,
						],
						'search'  => [ 'type' => 'string' ],
						'mode'    => [
							'type' => 'string',
							'enum' => Criteria::MODES,
						],
						'filters' => [ 'type' => 'array' ],
						'sort'    => [ 'type' => 'object' ],
						'fields'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'layout'  => [ 'type' => 'object' ],
					],
				],
				'category'  => [ 'type' => [ 'object', 'null' ] ],
				'isDefault' => [ 'type' => 'boolean' ],
			],
		];
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkcat-views',
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'  => 'array',
					'items' => $item,
				],
				'default' => [ 'type' => [ 'string', 'null' ] ],
				'max'     => [ 'type' => 'integer' ],
				'item'    => $item,
			],
		];
	}
}
