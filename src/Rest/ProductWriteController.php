<?php
/**
 * `PATCH /bulkcat/v1/products/{id}` (SPEC §12.1, §9.3, §8, §13; T37): write any
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
 * Native fields retain their raw typed values until the signed product.inline
 * envelope is verified and its receipt is read. Live field validation, cleaning
 * and native dry-run occur only under a new unique claim. A duplicate returns
 * only the immutable safe receipt, without reading a current product row.
 * source retains its inline/bulk display meaning; fields is a read projection.
 * New writes require current write rights, and all disclosures require current
 * screen and object/parent rights. Execution remains synchronous and unqueued.
 *
 * `GET /bulkcat/v1/products/{id}` on the same route (Access::can_read, args
 * RouteSchema::row_args(): the id and `fields[]`) reads one product or
 * variation through Query\Catalogue::row(), the list's row builder, and
 * answers `{ item }`; an id that is not a listed product or variation is
 * 404 `bulkcat_not_found`.
 *
 * `keep_changed_status` (T89, SPEC §5.5) is Keep current status for a
 * product changed elsewhere while Off, accepted only with `status`.
 *
 * The PATCH answer is `{ item, batch_id, status, results }`: `item` is the object
 * re-read through Query\Catalogue after its caches are cleared, in the same
 * shape as a `/products` item, so the grid can replace the row; `status` is
 * the batch's final status (complete, partial or failed); `results` holds
 * one outcome per step (field, old, new, state, reason, code, and the Status
 * switch's note and context). The internal Off record a Status step writes
 * with it is journalled but never part of the answer.
 */
final class ProductWriteController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/products/(?P<id>[\d]+)';

	/**
	 * The response schema's title.
	 */
	public const TITLE = 'bulkcat-product-write';

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
					'permission_callback' => [ Access::class, 'can_read' ],
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
		] + $this->schema->fields_arg() + [
			'keep_changed_status' => self::arg(
				[
					'description' => __( 'For a product changed by someone else while Off: keep its current status and clear its Off record. Only with a Status change.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'boolean',
					'default'     => false,
				],
				static fn( mixed $value ): bool => true === \BulkCatalogueManager\Fields\Values::boolean( $value )
			),
		];
		foreach ( $this->registry->editable() as $key => $field ) {
			// Identity comparison needs the original typed bytes, before live validation/sanitising.
			$args[ $key ] = [
				'validate_callback' => static fn(): bool => true,
				'sanitize_callback' => static fn( mixed $value ): mixed => $value,
			];
		}
		$args['keep_changed_status']['validate_callback'] = static fn(): bool => true;
		$args['keep_changed_status']['sanitize_callback'] = static fn( mixed $value ): mixed => $value;
		$args['fields']                                   = [
			'validate_callback' => static fn(): bool => true,
			'sanitize_callback' => static fn( mixed $value ): mixed => $value,
		];
		return $args + [
			'uuid'          => [],
			'request_proof' => [],
			'intent'        => [],
		];
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
	 * another post type, trashed or an auto-draft) is 404 `bulkcat_not_found`.
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
		if ( array_key_exists( 'keep_changed_status', $params ) && ! is_bool( $params['keep_changed_status'] ) ) {
			return \BulkCatalogueManager\Requests\InlineIntent::conflict(); }
		$values = array_diff_key( $params, array_flip( [ 'id', 'source', 'fields', 'keep_changed_status', 'uuid', 'request_proof', 'intent', '_locale', '_wpnonce', 'rest_route' ] ) );
		$source = (string) ( $request->get_param( 'source' ) ?? InlineWrite::SOURCES[0] );

		$written = ( new \BulkCatalogueManager\Requests\RequestService( inline: $this->writes ) )->inline( array_intersect_key( $params, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ), $id, $values, $source, true === $request->get_param( 'keep_changed_status' ) );
		if ( $written instanceof \WP_Error ) {
			return $written;
		}

		if ( ! isset( $written['outcome'] ) ) {
			return new \WP_REST_Response( $written, 200 ); }
		clean_post_cache( $id );
		$fields = $request->get_param( 'fields' );
		return new \WP_REST_Response(
			[
				'item'     => $this->catalogue->row( $id, is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : [] ),
				'batch_id' => $written['batch_id'],
				'status'   => $written['status'],
				'results'  => array_values(
					array_map(
						static fn( Result $result ): array => $result->to_array(),
						array_filter( $written['outcome']->results(), fn( Result $result ): bool => ! ( $this->registry->get( $result->field() )?->internal() ?? false ) )
					)
				),
			] + array_intersect_key( $written, array_flip( [ 'receipt_state', 'write_status', 'steps', 'replayed' ] ) ),
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
			'properties' => \BulkCatalogueManager\Requests\InlineReceipt::schema()['properties'] + [
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
							'note'     => [ 'type' => 'string' ],
							'context'  => [ 'type' => 'object' ],
						],
					],
				] + $read_only,
			],
		];
	}
}
