<?php
/**
 * `GET /bulkcat/v1/batches/users`: every user who owns at least one batch, as
 * `{ items: [ { id, name } ] }` ordered by name, so the history and the
 * audit log can offer a user filter of names rather than ids. Names come
 * from one get_users() call (BatchesController::user_names()); a removed
 * account reads "User 7". At most MAX_USERS users. Registered by
 * BatchesController, behind Access::can_read like the rest of the history.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Data\BatchRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The batch owners route.
 */
final class BatchUsersController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/batches/users';

	/**
	 * Most users listed.
	 */
	public const MAX_USERS = 500;

	/**
	 * Build over the batches.
	 *
	 * @param BatchRepository $batches Batches.
	 */
	public function __construct( private readonly BatchRepository $batches ) {}

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
					'args'                => [],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * Every batch owner, by name.
	 *
	 * @param \WP_REST_Request $request The request; unused.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$items = [];
		foreach ( BatchesController::user_names( $this->batches->user_ids( self::MAX_USERS ) ) as $id => $name ) {
			$items[] = [
				'id'   => $id,
				'name' => $name,
			];
		}
		usort( $items, static fn( array $a, array $b ): int => [ strnatcasecmp( $a['name'], $b['name'] ), $a['id'] ] <=> [ 0, $b['id'] ] );
		return new \WP_REST_Response( [ 'items' => $items ], 200 );
	}

	/**
	 * The schema of one user entry.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => 'bulkcat-batch-user',
			'type'       => 'object',
			'properties' => [
				'id'   => [
					'type'     => 'integer',
					'required' => true,
				],
				'name' => [
					'description' => __( 'The user\'s display name, or "User 7" for an account that no longer exists.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
			],
		];
	}
}
