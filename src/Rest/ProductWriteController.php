<?php
/**
 * `PATCH /bcm/v1/products/{id}` (SPEC §12.1, §9.3, §8, §13; T37): write any
 * subset of the field registry's editable fields to one product or
 * variation, recorded as a batch of one.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Engine\InlineWrite;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Query\Catalogue;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Query\ProductQuery;

defined( 'ABSPATH' ) || exit;

/**
 * The single-object route: its read and its write.
 *
 * Every field argument is the field's own Field::rest_arg() — its schema,
 * validator and sanitiser — so a malformed value is refused 400
 * `rest_invalid_param` by core, naming the field with its sentence in
 * `data.params`, before the handler runs. `source` records the batch as
 * `inline` (the default) or `bulk`; `fields[]` chooses the columns of the
 * row returned, as on `/products`.
 *
 * The permission callback is Access::can_write (the screen capability plus
 * `edit_products`); Engine\InlineWrite then refuses 404 `bcm_not_found` for
 * an id that is not a listed product or variation, 403 `bcm_cannot_edit` for
 * an object the user may not edit, and 400 naming the field for a value the
 * object cannot take, with nothing written. Otherwise the change is written
 * through the same operations, Validator, Writer and Journal as a bulk batch,
 * synchronously, and never queued.
 *
 * `GET /bcm/v1/products/{id}` on the same route (Access::can_read, args
 * RouteSchema::row_args(): the id and `fields[]`) reads one product or
 * variation through Query\Catalogue::row(), the list's row builder, and
 * answers `{ item }`; an id that is not a listed product or variation is
 * 404 `bcm_not_found`.
 *
 * The PATCH answer is `{ item, batch_id, status, results }`: `item` is the object
 * re-read through Query\Catalogue after its caches are cleared, in the same
 * shape as a `/products` item, so the grid can replace the row; `status` is
 * the batch's final status (complete, partial or failed); `results` holds
 * one outcome per step (field, old, new, state, reason, code).
 */
final class ProductWriteController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/products/(?P<id>[\d]+)';

	/**
	 * The response schema's title.
	 */
	public const TITLE = 'bcm-product-write';

	/**
	 * Arguments and schema generator.
	 *
	 * @var RouteSchema
	 */
	private RouteSchema $schema;

	/**
	 * The row reader.
	 *
	 * @var Catalogue
	 */
	private Catalogue $catalogue;

	/**
	 * The write.
	 *
	 * @var InlineWrite
	 */
	private InlineWrite $writes;

	/**
	 * Build over a field registry and the operation registry built on it.
	 *
	 * @param Registry     $registry Field registry.
	 * @param Actions|null $actions  Operation registry; the shared one by default.
	 */
	public function __construct( private readonly Registry $registry, ?Actions $actions = null ) {
		$this->schema    = new RouteSchema( $registry );
		$this->catalogue = new Catalogue( new Hydrator( $registry ) );
		$this->writes    = new InlineWrite( $actions ?? Actions::instance() );
	}

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
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => $this->args(),
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => $this->schema->row_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The route's arguments: the id, the source, the returned columns, and
	 * one argument per editable field from the registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		$args = [
			'id'     => self::arg(
				[
					'description' => __( 'The product or variation to change.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'required'    => true,
				],
				static fn( mixed $value ): int => max( 0, (int) $value )
			),
			'source' => self::arg(
				[
					'description' => __( 'How the change is recorded in the history: an edit in the list, or a change made in bulk.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => InlineWrite::SOURCES,
					'default'     => InlineWrite::SOURCES[0],
				],
				'strval'
			),
		] + $this->schema->fields_arg();
		foreach ( $this->registry->editable() as $key => $field ) {
			$args[ $key ] = $field->rest_arg();
		}
		return $args;
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

	/**
	 * One product or variation as the list shows it: `{ item }`, the row
	 * Query\Catalogue::row() hydrates, narrowed by `fields[]` as on
	 * `/products`. An id that is not a listed product or variation (missing,
	 * another post type, trashed or an auto-draft) is 404 `bcm_not_found`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$post   = $id > 0 ? get_post( $id ) : null;
		$fields = $request->get_param( 'fields' );
		$item   = is_object( $post )
			&& in_array( $post->post_type ?? '', ProductQuery::POST_TYPES[ Criteria::MODE_PRODUCTS_AND_VARIATIONS ], true )
			&& in_array( $post->post_status ?? '', ProductQuery::STATUSES, true )
			? $this->catalogue->row( $id, is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : [] )
			: null;
		if ( null === $item ) {
			return Refusal::create(
				Writer::NOT_FOUND,
				__( 'That product could not be found. It may have been deleted; reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				404
			);
		}
		return new \WP_REST_Response( [ 'item' => $item ], 200 );
	}

	/**
	 * Write the submitted fields and answer the updated row.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_params();
		$values = array_intersect_key( $params, $this->registry->editable() );
		$source = (string) ( $request->get_param( 'source' ) ?? InlineWrite::SOURCES[0] );

		$written = $this->writes->write( $id, $values, $source, get_current_user_id() );
		if ( $written instanceof \WP_Error ) {
			return $written;
		}

		clean_post_cache( $id );
		$fields = $request->get_param( 'fields' );
		return new \WP_REST_Response(
			[
				'item'     => $this->catalogue->row( $id, is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : [] ),
				'batch_id' => $written['batch_id'],
				'status'   => $written['status'],
				'results'  => array_map( static fn( Result $result ): array => $result->to_array(), $written['outcome']->results() ),
			],
			200
		);
	}

	/**
	 * The schema of the answer: the row as `/products` items carry it, the
	 * batch and one outcome per step. A GET answers `item` alone.
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
			'title'      => self::TITLE,
			'type'       => 'object',
			'properties' => [
				'item'     => array_diff_key( $this->schema->item_schema( ProductsController::TITLE, RouteSchema::row_extras() ), [ '$schema' => 0 ] ) + $read_only,
				'batch_id' => [
					'description' => __( 'The batch of one this change is recorded as.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
				] + $read_only,
				'status'   => [
					'description' => __( 'How the change ended: complete, partial or failed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => [ 'complete', 'partial', 'failed' ],
				] + $read_only,
				'results'  => [
					'description' => __( 'What happened to each field.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'field'    => [ 'type' => 'string' ],
							'old'      => [],
							'new'      => [],
							'state'    => [
								'type' => 'string',
								'enum' => Result::STATES,
							],
							'reason'   => [ 'type' => 'string' ],
							'code'     => [ 'type' => 'string' ],
							'replaces' => [ 'type' => 'boolean' ],
						],
					],
				] + $read_only,
			],
		];
	}
}
