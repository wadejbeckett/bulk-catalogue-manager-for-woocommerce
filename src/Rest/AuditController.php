<?php
/**
 * `GET /bulkcat/v1/audit` (SPEC §4.6, §9.3, §12.1; T54): the audit log — field
 * changes across every batch, newest first, filtered by product (`product`,
 * the changed object's id), field (`field`, a registry key), user (`user`,
 * the batch's user) and the time the row was written (`after`, `before`).
 * The product filter matches the journal's object id exactly, so a
 * variation's id lists that variation's own changes (a parent's id lists the
 * parent's, not its variations'). To find a variation by its SKU the screen
 * searches `GET /products` in the products_and_variations or variations mode,
 * where the search matches each row's own SKU and name, and passes the
 * variation's id here.
 *
 * Each row is the changes feed's row (BatchChangesController::change():
 * `change_id, object_id, object_type, name, field, field_label, old_value,
 * new_value, result, message, detail, applied_gmt`) plus its `batch_id`, the
 * batch's `user_id` and that user's `user_name` (one get_users() call per
 * page); names come from one post-cache prime per page and
 * each field is shown by its registry label. Answers `{ items, pagination }`
 * with `per_page` capped at 100 by RouteSchema's paging arguments; the field
 * argument's choices are the registry's keys. Behind Access::can_read, like
 * the rest of the history: the log is the shop's shared record.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Fields\Registry as Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The audit log route.
 */
final class AuditController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/audit';

	/**
	 * The journal.
	 *
	 * @var ChangeRepository
	 */
	private ChangeRepository $changes;

	/**
	 * The field registry, for the field argument, labels and paging.
	 *
	 * @var Fields
	 */
	private Fields $fields;

	/**
	 * Shapes each row as the changes feed does.
	 *
	 * @var BatchChangesController
	 */
	private BatchChangesController $rows;

	/**
	 * Build a controller.
	 *
	 * @param ChangeRepository|null $changes The journal; the plugin's by default.
	 * @param Fields|null           $fields  Field registry; the shared one by default.
	 */
	public function __construct( ?ChangeRepository $changes = null, ?Fields $fields = null ) {
		$this->changes = $changes ?? new ChangeRepository();
		$this->fields  = $fields ?? Fields::instance();
		$this->rows    = new BatchChangesController( new BatchRepository(), $this->changes, $this->fields );
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
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => $this->args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The audit log's arguments: paging, product, field, user and a time range.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		return ( new RouteSchema( $this->fields ) )->paging_args() + [
			'product' => self::arg(
				[
					'description' => __( 'Only changes to this product or variation, by id.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
				],
				'intval'
			),
			'field'   => self::arg(
				[
					'description' => __( 'Only changes to this field.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					// T108: the external fields of the providers registered now can be filtered by too.
					'enum'        => [ ...$this->fields->keys(), ...\BulkCatalogueManager\External\Providers::field_keys( \BulkCatalogueManager\Operations\Registry::instance()->providers() ) ],
				],
				'strval'
			),
			'user'    => BatchesController::user_arg(),
			'after'   => BatchesController::date_arg( __( 'Only changes written at or after this time (UTC unless it names a zone).', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'before'  => BatchesController::date_arg( __( 'Only changes written before this time (UTC unless it names a zone).', 'bulk-catalogue-manager-for-woocommerce' ) ),
		];
	}

	/**
	 * A page of the audit log: `{ items, pagination }`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$page = (int) ( $request->get_param( 'page' ) ?? 1 );
		$size = (int) ( $request->get_param( 'per_page' ) ?? 25 );
		$rows = $this->rows( self::filters( $request->get_params() ), $page, $size );
		return BatchesController::paged( $rows['items'], $page, $size, $rows['total'] );
	}

	/**
	 * The audit log's filters from a request's validated parameters.
	 *
	 * @param array<string, mixed> $params Parameters: product, field, user, after, before.
	 * @return array<string, mixed> Filters for ChangeRepository::audit().
	 */
	public static function filters( array $params ): array {
		return array_filter(
			[
				'object_id' => $params['product'] ?? null,
				'prop'      => $params['field'] ?? null,
				'user_id'   => $params['user'] ?? null,
				'after'     => $params['after'] ?? null,
				'before'    => $params['before'] ?? null,
			],
			[ BatchesController::class, 'is_given' ]
		);
	}

	/**
	 * One page of audit rows and the total (the route and the audit export
	 * read through this): names from one post-cache prime, user names from
	 * one get_users() call.
	 *
	 * @param array<string, mixed> $filters Filters, as filters() returns them.
	 * @param int                  $page    Page, from 1.
	 * @param int                  $size    Rows per page.
	 * @param bool                 $count   Whether to count the total (an export counts once, on its first page); 0 when not.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function rows( array $filters, int $page, int $size, bool $count = true ): array {
		$rows = $this->changes->audit( $filters, $page, $size );
		BatchChangesController::prime_names( $rows );
		$this->rows->prime_manifests( $rows );
		$names = BatchesController::user_names( array_column( $rows, 'user_id' ) );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'batch_id'  => $row['batch_id'],
				'user_id'   => $row['user_id'],
				'user_name' => $names[ (int) $row['user_id'] ] ?? '',
			] + $this->rows->change( $row );
		}
		return [
			'items' => $items,
			'total' => $count ? $this->changes->audit_count( $filters ) : 0,
		];
	}

	/**
	 * The schema of one row: the changes feed's row with its batch and user.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$schema               = $this->rows->get_item_schema();
		$schema['title']      = 'bulkcat-audit-change';
		$integer              = [ 'type' => 'integer' ];
		$schema['properties'] = [
			'batch_id'  => $integer,
			'user_id'   => $integer,
			'user_name' => [ 'type' => 'string' ],
		] + $schema['properties'];
		return $schema;
	}

	/**
	 * An argument validated against its own schema, with a sanitiser.
	 *
	 * @param array<string, mixed> $schema   JSON Schema of the argument.
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
