<?php
/**
 * The category write routes (SPEC §4.7, §7, §12.1, §13; T63, T64):
 *
 * - `POST /bulkcat/v1/categories` creates a category from `name` and optionally
 *   `slug`, `description` and `parent`;
 * - `PATCH /bulkcat/v1/categories/{id}` edits any of `name`, `slug`,
 *   `description` and `parent`;
 * - `POST /bulkcat/v1/categories/{id}/move` moves a category to `parent` (0 for
 *   the top level) at `position` among its new siblings, counted from 0,
 *   last when omitted.
 *
 * Each needs Access::can_manage_terms (the screen capability plus
 * `manage_product_terms`) and runs through Categories\Writer, which writes
 * with WordPress's term functions, records the write as a batch of one with
 * source `tree`, and refuses 400 with a §4.8 sentence an empty name, a
 * duplicate name under the same parent, a slug in use, a missing parent or a
 * parent inside the category itself, and 404 an id that is not a product
 * category. The answer is `{ item, batch_id, status, results }`: the
 * category in the tree's node shape plus its description, the batch, its
 * final status and one outcome per property.
 *
 * There is deliberately no delete route: deleting a category stays on the
 * native screen, which explains what happens to its products (SPEC §2).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Categories\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * The category write routes.
 */
final class CategoriesWriteController implements ReadController {
	/**
	 * The create route, shared with CategoriesController's read.
	 */
	public const ROUTE = '/categories';

	/**
	 * The edit route.
	 */
	public const ITEM_ROUTE = '/categories/(?P<id>[\d]+)';

	/**
	 * The move route.
	 */
	public const MOVE_ROUTE = '/categories/(?P<id>[\d]+)/move';

	/**
	 * Longest name WordPress's terms table holds.
	 */
	public const NAME_LENGTH = 200;

	/**
	 * Build over a writer; the plugin's by default, built on first use so
	 * booting the REST server does not build the operation registry.
	 *
	 * @param Writer|null $writer The category writer.
	 */
	public function __construct( private ?Writer $writer = null ) {}

	/**
	 * The category writer.
	 */
	private function writer(): Writer {
		return $this->writer ??= new Writer();
	}

	/**
	 * Add the three routes through the registrar.
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
					'args'                => [ 'name' => [ 'required' => true ] + $this->term_args()['name'] ] + $this->term_args(),
				],
			]
		);
		$routes->add(
			self::ITEM_ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ Access::class, 'can_manage_terms' ],
					'args'                => [ 'id' => self::id_arg() ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ Access::class, 'can_manage_terms' ],
					'args'                => [ 'id' => self::id_arg() ] + $this->term_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
		$routes->add(
			self::MOVE_ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'move_item' ],
					'permission_callback' => [ Access::class, 'can_manage_terms' ],
					'args'                => [
						'id'       => self::id_arg(),
						'parent'   => self::arg(
							[
								'description' => __( 'The category to move it into; 0 for the top level.', 'bulk-catalogue-manager-for-woocommerce' ),
								'type'        => 'integer',
								'minimum'     => 0,
								'required'    => true,
							],
							'intval'
						),
						'position' => self::arg(
							[
								'description' => __( 'Its place among its new siblings, counted from 0; last when omitted.', 'bulk-catalogue-manager-for-woocommerce' ),
								'type'        => 'integer',
								'minimum'     => 0,
							],
							'intval'
						),
					],
				],
			]
		);
	}

	/**
	 * The name, slug, description and parent arguments, none required.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function term_args(): array {
		return [
			'name'         => self::arg(
				[
					'description' => __( 'The category name.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'maxLength'   => self::NAME_LENGTH,
				],
				'sanitize_text_field'
			),
			'slug'         => self::arg(
				[
					'description' => __( 'The category slug, used in its web address; made from the name when empty.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'maxLength'   => self::NAME_LENGTH,
				],
				'sanitize_text_field'
			),
			'description'  => self::arg(
				[
					'description' => __( 'The category description.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'strval'
			),
			'parent'       => self::arg(
				[
					'description' => __( 'The parent category; 0 for the top level.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 0,
				],
				'intval'
			),
			'display_type' => self::arg(
				[
					'description' => __( 'What the category page shows: empty for Default, products, subcategories or both.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'strval'
			),
			'thumbnail_id' => self::arg(
				[
					'description' => __( 'The category image: a media library image id, or 0 to remove it.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 0,
				],
				'intval'
			),
		];
	}

	/**
	 * The schema of one category's details.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkcat-category-details',
			'type'       => 'object',
			'properties' => [
				'id'            => [
					'description' => __( 'The category id.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'display_type'  => [
					'description' => __( 'What the category page shows: empty for Default, products, subcategories or both.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'thumbnail_id'  => [
					'description' => __( 'The category image id, 0 when there is none.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
				],
				'thumbnail_url' => [
					'description' => __( 'The address of the image\'s thumbnail, or null.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => [ 'string', 'null' ],
					'readonly'    => true,
				],
			],
		];
	}

	/**
	 * `GET /categories/{id}`: one category's details for its editor.
	 *
	 * @param \WP_REST_Request $request The validated request.
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$item = $this->writer()->details_of( (int) $request->get_param( 'id' ) );
		return $item instanceof \WP_Error ? $item : new \WP_REST_Response( [ 'item' => $item ], 200 );
	}

	/**
	 * `POST /categories`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::answer( $this->writer()->create( self::term_input( $request ), get_current_user_id() ), 201 );
	}

	/**
	 * `PATCH /categories/{id}`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::answer( $this->writer()->update( (int) $request->get_param( 'id' ), self::term_input( $request ), get_current_user_id() ), 200 );
	}

	/**
	 * `POST /categories/{id}/move`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function move_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$position = $request->get_param( 'position' );
		return self::answer(
			$this->writer()->move( (int) $request->get_param( 'id' ), (int) $request->get_param( 'parent' ), null === $position ? null : (int) $position, get_current_user_id() ),
			200
		);
	}

	/**
	 * The name, slug, description and parent a request sent.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 */
	private static function term_input( \WP_REST_Request $request ): array {
		$input = [];
		foreach ( [ 'name', 'slug', 'description', 'parent', 'display_type', 'thumbnail_id' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$input[ $key ] = $value;
			}
		}
		return $input;
	}

	/**
	 * The writer's answer as a response, or its refusal.
	 *
	 * @param array<string, mixed>|\WP_Error $written The writer's answer.
	 * @param int                            $status  The success status.
	 */
	private static function answer( array|\WP_Error $written, int $status ): \WP_REST_Response|\WP_Error {
		return $written instanceof \WP_Error ? $written : new \WP_REST_Response( $written, $status );
	}

	/**
	 * The id argument of the item routes.
	 *
	 * @return array<string, mixed>
	 */
	private static function id_arg(): array {
		return self::arg(
			[
				'description' => __( 'The category.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			],
			static fn( mixed $value ): int => max( 0, (int) $value )
		);
	}

	/**
	 * An argument: its schema, a validator checking a value against it
	 * through core, and the sanitiser given.
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
