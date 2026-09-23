<?php
/**
 * `GET /bcm/v1/terms/{taxonomy}`: every term of a flat product taxonomy —
 * `product_tag` or `product_shipping_class` — as a flat JSON array
 * `[ { id, name, count } ]`, empty terms included, ordered by name, so the
 * catalogue can offer tag and shipping-class filters and values by name.
 * Names are text: stored entities are decoded as the category tree decodes
 * them. `count` is WooCommerce's stored term count. The path admits only the
 * two taxonomies, so any other one matches no route (404 `rest_no_route`).
 * Behind Access::can_read. Categories keep their own tree route.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * The flat term list route.
 */
final class TermsController implements ReadController {
	/**
	 * The taxonomies the route lists.
	 */
	public const TAXONOMIES = [ 'product_tag', 'product_shipping_class' ];

	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/terms/(?P<taxonomy>product_tag|product_shipping_class)';

	/**
	 * Add the route through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => [
						'taxonomy' => [
							'type'              => 'string',
							'enum'              => self::TAXONOMIES,
							'required'          => true,
							'validate_callback' => static fn( mixed $value, mixed $request = null, string $param = '' ): bool|\WP_Error => rest_validate_value_from_schema(
								$value,
								[
									'type' => 'string',
									'enum' => self::TAXONOMIES,
								],
								$param
							),
							'sanitize_callback' => static fn( mixed $value ): string => (string) $value,
						],
					],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * Every term of the taxonomy, by name.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$terms    = [];
		if ( in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			$terms = get_terms(
				[
					'taxonomy'               => $taxonomy,
					'hide_empty'             => false,
					'orderby'                => 'name',
					'order'                  => 'ASC',
					'update_term_meta_cache' => false,
				]
			);
		}
		$items = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( ! is_object( $term ) || ( $term->taxonomy ?? $taxonomy ) !== $taxonomy ) {
				continue;
			}
			$items[] = [
				'id'    => (int) ( $term->term_id ?? 0 ),
				'name'  => html_entity_decode( (string) ( $term->name ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'count' => (int) ( $term->count ?? 0 ),
			];
		}
		usort( $items, static fn( array $a, array $b ): int => [ strnatcasecmp( $a['name'], $b['name'] ), $a['id'] ] <=> [ 0, $b['id'] ] );
		return new \WP_REST_Response( $items, 200 );
	}

	/**
	 * The schema of one term entry.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$read_only = [
			'context'  => [ 'view', 'edit' ],
			'readonly' => true,
		];
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => 'bcm-term',
			'type'       => 'object',
			'properties' => [
				'id'    => [
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				] + $read_only,
				'name'  => [ 'type' => 'string' ] + $read_only,
				'count' => [
					'type'    => 'integer',
					'minimum' => 0,
				] + $read_only,
			],
		];
	}
}
