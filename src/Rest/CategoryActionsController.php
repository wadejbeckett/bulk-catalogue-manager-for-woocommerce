<?php
/**
 * `POST /bulkcat/v1/categories/actions/preview` (docs/EXTENSIONS-v2.md
 * §5.3): what an add-on's category action would do to the chosen
 * categories, computed by Engine\CategoryPreview without writing anything.
 * The body carries `operations` (one provider's category steps),
 * `categories` (the chosen term ids, 1 to 1,000) and
 * `include_subcategories` (off by default). The answer lists each category
 * record by path with its outcome and the add-on's own descriptions, the
 * exact captured set (`categories.ids`) with its `scope_hash`, the
 * normalised `operations` and the `hash` an Apply sends back through the
 * durable `POST /requests` and `POST /batches` routes (intent
 * `{ source: bulk, operations, scope_hash, categories: { chosen,
 * subcategories, ids } }`).
 *
 * A category-family route: it needs the category right
 * (Access::can_manage_terms) and, since an Apply follows through the
 * durable write routes, the write right too (Access::can_write); each
 * category is then checked for the requester as the writer does.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Engine\CategoryPreview;
use BulkCatalogueManager\Engine\CategorySelection;
use BulkCatalogueManager\Operations\Registry as Actions;

defined( 'ABSPATH' ) || exit;

/**
 * The category action preview route.
 */
final class CategoryActionsController implements Controller {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/categories/actions/preview';

	/**
	 * Build it.
	 *
	 * @param \Closure|null $actions fn(): Actions, the registry; the shared one by default.
	 */
	public function __construct( private readonly ?\Closure $actions = null ) {}

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
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ Access::class, 'can_manage_terms' ],
					'args'                => [
						'operations'            => [
							'description' => __( 'The add-on\'s category steps, as `{ action, args }`.', 'bulk-catalogue-manager-for-woocommerce' ),
							'type'        => 'array',
							'required'    => true,
						],
						'categories'            => [
							'description' => __( 'The chosen category ids.', 'bulk-catalogue-manager-for-woocommerce' ),
							'type'        => 'array',
							'items'       => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'minItems'    => 1,
							'maxItems'    => CategorySelection::MAX,
							'required'    => true,
						],
						'include_subcategories' => [
							'description' => __( 'Whether to include every subcategory of the chosen categories.', 'bulk-catalogue-manager-for-woocommerce' ),
							'type'        => 'boolean',
							'default'     => false,
						],
					],
				],
			]
		);
	}

	/**
	 * Preview the change.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! RequestsController::known( $request, [ 'operations', 'categories', 'include_subcategories' ] ) ) {
			return RequestsController::unknown();
		}
		$write = Access::can_write( $request );
		if ( true !== $write ) {
			return $write;
		}
		$actions = null === $this->actions ? Actions::instance() : ( $this->actions )();
		try {
			$answer = ( new CategoryPreview( $actions ) )->run( $request->get_param( 'operations' ), $request->get_param( 'categories' ), true === (bool) $request->get_param( 'include_subcategories' ) );
		} catch ( \LengthException $e ) {
			return CategorySelection::too_large();
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid( 'bulkcat_invalid_selection', __( 'These categories could not be read. Choose the categories again and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		return is_wp_error( $answer ) ? $answer : new \WP_REST_Response( $answer );
	}
}
