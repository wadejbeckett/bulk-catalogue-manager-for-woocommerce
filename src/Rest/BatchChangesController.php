<?php
/**
 * `GET /bcm/v1/batches/{id}/changes` (SPEC §4.6, §9.2, §12.6; T36): one page
 * of a batch's per-row outcome from the journal, so the history screen can
 * expand a batch to what it did and to its failing rows. Each row is
 * `{ change_id, object_id, object_type, name, field, field_label, old_value,
 * new_value, result, message, detail, applied_gmt }`: `message` is the plain
 * §4.8 sentence the writer recorded, and `detail` the technical line behind
 * *Show details*. `result` narrows the page to applied, skipped or failed
 * rows; `per_page` is capped at 100 by RouteSchema's paging arguments.
 * Names come from one post-cache prime per page. Registered by
 * BatchesController, behind Access::can_read; a missing batch answers 404
 * `bcm_not_found`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Fields\Registry as Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The changes feed of a batch.
 */
final class BatchChangesController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/batches/(?P<id>[\d]+)/changes';

	/**
	 * Build over the repositories.
	 *
	 * @param BatchRepository  $batches Batches.
	 * @param ChangeRepository $changes The journal.
	 * @param Fields           $fields  Field registry, for labels and the paging arguments.
	 */
	public function __construct(
		private readonly BatchRepository $batches,
		private readonly ChangeRepository $changes,
		private readonly Fields $fields
	) {}

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
	 * The changes feed's arguments: the batch id, paging, and the results to show.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		return BatchesController::id_arg() + ( new RouteSchema( $this->fields ) )->paging_args() + [
			'result' => self::arg(
				[
					'description' => __( 'Only rows with these results: applied, skipped or failed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => Schema::CHANGE_RESULTS,
					],
					'maxItems'    => count( Schema::CHANGE_RESULTS ),
				],
				[ RouteSchema::class, 'as_list' ]
			),
		];
	}

	/**
	 * A page of a batch's per-row outcome: `{ items, pagination }`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request->get_param( 'id' );
		if ( null === $this->batches->status( $batch_id ) ) {
			return BatchesController::not_found();
		}
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 25 );
		$rows     = $this->rows( $batch_id, self::results( $request->get_param( 'result' ) ), $page, $per_page );
		return BatchesController::paged( $rows['items'], $page, $per_page, $rows['total'] );
	}

	/**
	 * Whether a batch exists (the export checks before streaming).
	 *
	 * @param int $batch_id Batch.
	 */
	public function exists( int $batch_id ): bool {
		return null !== $this->batches->status( $batch_id );
	}

	/**
	 * The `result` argument as a list of results, or null for every result.
	 *
	 * @param mixed $results The sanitised argument.
	 * @return list<string>|null
	 */
	public static function results( mixed $results ): ?array {
		return is_array( $results ) && [] !== $results ? array_values( array_map( 'strval', $results ) ) : null;
	}

	/**
	 * One page of a batch's rows as the feed answers them, and the total
	 * (the feed and the batch's export read through this).
	 *
	 * @param int               $batch_id Batch.
	 * @param list<string>|null $results  Results to keep; null for all.
	 * @param int               $page     Page, from 1.
	 * @param int               $per_page Rows per page.
	 * @param bool              $count    Whether to count the total (an export counts once, on its first page); 0 when not.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function rows( int $batch_id, ?array $results, int $page, int $per_page, bool $count = true ): array {
		$rows = $this->changes->page( $batch_id, $page, $per_page, $results );
		self::prime_names( $rows );
		return [
			'items' => array_map( [ $this, 'change' ], $rows ),
			'total' => $count ? $this->changes->page_count( $batch_id, $results ) : 0,
		];
	}

	/**
	 * Load the names of a page of journal rows' products and variations in
	 * one post-cache prime, so change() reads each from the cache (the
	 * changes feed and the audit log).
	 *
	 * @param list<array<string, mixed>> $rows Typed journal rows.
	 */
	public static function prime_names( array $rows ): void {
		$ids = array_values( array_unique( array_map( static fn( array $row ): int => $row['object_id'], array_filter( $rows, static fn( array $row ): bool => 'term' !== $row['object_type'] ) ) ) );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * One journal row as the feed shows it (and, with its batch, the audit log).
	 *
	 * @param array<string, mixed> $row Typed journal row.
	 * @return array<string, mixed>
	 */
	public function change( array $row ): array {
		$field = $this->fields->get( $row['prop'] );
		return [
			'change_id'   => $row['change_id'],
			'object_id'   => $row['object_id'],
			'object_type' => $row['object_type'],
			'name'        => self::name( $row['object_id'], $row['object_type'] ),
			'field'       => $row['prop'],
			'field_label' => null === $field ? $row['prop'] : $field->label(),
			'old_value'   => $row['old_value'],
			'new_value'   => $row['new_value'],
			'result'      => $row['result'],
			'message'     => $row['message'],
			'detail'      => sprintf(
				/* translators: 1: object type (product, variation or term), 2: its id, 3: the field key, 4: the result, 5: the object's modified time before the write, GMT. */
				__( '%1$s %2$d, field %3$s, result %4$s; last modified %5$s GMT before this change.', 'bulk-catalogue-manager-for-woocommerce' ),
				$row['object_type'],
				$row['object_id'],
				$row['prop'],
				$row['result'],
				$row['modified_gmt']
			),
			'applied_gmt' => $row['applied_gmt'],
		];
	}

	/**
	 * An object's name for the feed: its title, or a plain stand-in when it is gone.
	 *
	 * @param int    $object_id   Object.
	 * @param string $object_type product, variation or term.
	 */
	public static function name( int $object_id, string $object_type ): string {
		$post  = 'term' === $object_type ? null : get_post( $object_id );
		$title = is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '';
		if ( '' !== $title ) {
			return $title;
		}
		return sprintf(
			/* translators: %d: the id of a product, variation or category that no longer has a name. */
			'term' === $object_type ? __( 'Category %d', 'bulk-catalogue-manager-for-woocommerce' ) : __( 'Product %d', 'bulk-catalogue-manager-for-woocommerce' ),
			$object_id
		);
	}

	/**
	 * The schema of one row of the feed.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$integer = [ 'type' => 'integer' ];
		$text    = [ 'type' => 'string' ];
		$any     = [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ] ];
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => 'bcm-batch-change',
			'type'       => 'object',
			'properties' => [
				'change_id'   => $integer + [ 'required' => true ],
				'object_id'   => $integer,
				'object_type' => [
					'type' => 'string',
					'enum' => Schema::OBJECT_TYPES,
				],
				'name'        => $text,
				'field'       => $text,
				'field_label' => $text,
				'old_value'   => $any,
				'new_value'   => $any,
				'result'      => [
					'type' => 'string',
					'enum' => Schema::CHANGE_RESULTS,
				],
				'message'     => $text,
				'detail'      => $text,
				'applied_gmt' => $text,
			],
		];
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
