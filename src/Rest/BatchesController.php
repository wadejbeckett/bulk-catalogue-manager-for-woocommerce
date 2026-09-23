<?php
/**
 * The batch routes (SPEC §4.6, §9.2, §12.1, §12.4, §12.6).
 *
 * `POST /bcm/v1/batches` (T31) queues a change. The
 * body carries the client's request id (`uuid`), the operation list
 * (`operations`, `[ { action, args } ]`), the selection — an `ids` list, or
 * the signed `token` from `POST /selection`, exactly one of them — and
 * optionally `source`, which may only be `bulk`: inline edits and category
 * writes open their own one-item batches, so a request cannot label a
 * queued batch as either (the server sets `bulk`). Undo batches are created
 * by the undo route through Engine\BatchFactory, not here.
 *
 * It writes products, so it needs the write capability (Access::can_write);
 * each object is checked again as it is written. Engine\BatchFactory does the
 * work: a new batch answers 201, a repeat of a request id within sixty
 * seconds answers 200 with the same batch, both as
 * `{ batch_id, status, total_items, replayed }`.
 *
 * Applying a selection (T38, §9.1): every body carries the preview's
 * `preview_hash` — for an id list or id token as much as for a filter, since
 * every bulk write is previewed first — and a filter token the count the
 * preview showed (`preview_count`; the token's own count when absent). BatchFactory refuses
 * 409 `bcm_preview_stale` when the hash is missing or no longer what a
 * preview gives now, and 409 `bcm_selection_changed` with `data.previewed`
 * and `data.current` when the filter now matches another number of objects,
 * unless the body carries `confirm_changed: true` — the owner's answer after
 * the screen stated both numbers.
 *
 * `GET /bcm/v1/batches` (T35, T54) pages the batches newest first, filtered
 * by status, user, creation time and action type (`action`, one id from the
 * operations registry, matched on the batch's `actions` column), for the
 * history screen. Every row, like the single read, carries `actions` and
 * `reversibility` — `{ state, conflicts, reason }` from
 * Engine\Reversibility (§4.6) — the list reading every row's state with one
 * grouped pair of reads and no undo plan, so its rows carry `conflicts:
 * null` and no product is read; only the single read counts conflicts
 * (T56). An undo batch's row carries `undo` ("Undo of #418"). The audit log
 * across batches is AuditController.
 *
 * `GET /bcm/v1/batches/{id}` (T35, T36) answers Engine\Progress::report():
 * status, counters, times, source, note, chunk size, the `stalled` flag and
 * the grouped `summary` of reasons. `POST /bcm/v1/batches/{id}/cancel` (T35)
 * cancels a queued or running batch; a batch already cancelled answers as it
 * is, a finished one 409 `bcm_not_cancellable`. `GET
 * /bcm/v1/batches/{id}/changes` (T36) is registered here from
 * BatchChangesController, which owns its row schema, and `POST
 * /bcm/v1/batches/{id}/undo` (T56) from UndoController.
 *
 * Who may read which batch: the reads sit behind Access::can_read and the
 * cancellation behind Access::can_write, and any user who passes may read or
 * cancel any batch, not only their own. The history screen of §4.6 is the
 * shop's shared record — it lists every user's batches and filters by user —
 * and the capability is what the owner grants for the screen as a whole
 * (§13); the batch's own user_id is reported, not enforced. A missing batch
 * answers 404 `bcm_not_found`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;

defined( 'ABSPATH' ) || exit;

/**
 * The batches route.
 */
final class BatchesController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/batches';

	/**
	 * Sources a request may name: only `bulk`. Inline and tree batches are
	 * opened by their own routes, and `undo` comes only from the undo route.
	 */
	public const SOURCES = [ 'bulk' ];

	/**
	 * Refusal code for a body with neither or both of `ids` and `token`.
	 */
	public const SELECTION_REQUIRED = 'bcm_selection_required';

	/**
	 * One batch, under Access::REST_NAMESPACE.
	 */
	public const ITEM_ROUTE = '/batches/(?P<id>[\d]+)';

	/**
	 * Cancelling a batch.
	 */
	public const CANCEL_ROUTE = '/batches/(?P<id>[\d]+)/cancel';

	/**
	 * Refusal code for a batch id that does not exist.
	 */
	public const NOT_FOUND = 'bcm_not_found';

	/**
	 * Refusal code for cancelling a batch that has finished.
	 */
	public const NOT_CANCELLABLE = 'bcm_not_cancellable';

	/**
	 * A request id: a UUID in its 8-4-4-4-12 hexadecimal form.
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * A time filter: a date, optionally with a time and a zone (ISO 8601 or `Y-m-d H:i:s`).
	 */
	private const DATE = '^\\d{4}-\\d{2}-\\d{2}([T ]\\d{2}:\\d{2}(:\\d{2})?(\\.\\d+)?(Z|[+-]\\d{2}:?\\d{2})?)?$';

	/**
	 * The factory.
	 *
	 * @var BatchFactory|null
	 */
	private ?BatchFactory $factory;

	/**
	 * Progress, the stalled check and cancellation.
	 *
	 * @var Progress
	 */
	private Progress $progress;

	/**
	 * Batches.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The changes feed's controller, registered with these routes.
	 *
	 * @var BatchChangesController
	 */
	private BatchChangesController $changes_controller;

	/**
	 * The undo route's controller, registered with these routes (T56).
	 *
	 * @var UndoController
	 */
	private UndoController $undo_controller;

	/**
	 * The field registry, for field labels and the paging arguments.
	 *
	 * @var Fields
	 */
	private Fields $fields;

	/**
	 * The operations registry, for the action-type filter; the shared one when null.
	 *
	 * @var Actions|null
	 */
	private ?Actions $actions;

	/**
	 * Build a controller.
	 *
	 * @param BatchFactory|null     $factory  Batch factory; built when first needed by default.
	 * @param BatchRepository|null  $batches  Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes  The journal; the plugin's by default.
	 * @param Fields|null           $fields   Field registry; the shared one by default.
	 * @param \Closure|null         $clock    Returns the Unix time, for the stalled check and retention; time() by default.
	 * @param Reversibility|null    $reversibility The reversibility state; by default Progress's, counting conflicts with the undo planner (T55).
	 * @param Actions|null          $actions  Operations registry, for the action filter; the shared one by default.
	 * @param UndoController|null   $undo     The undo route (T56); by default one over the same repositories, fields, factory and clock.
	 */
	public function __construct( ?BatchFactory $factory = null, ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Fields $fields = null, ?\Closure $clock = null, ?Reversibility $reversibility = null, ?Actions $actions = null, ?UndoController $undo = null ) {
		$this->factory            = $factory;
		$this->batches            = $batches ?? new BatchRepository();
		$changes                  = $changes ?? new ChangeRepository();
		$this->fields             = $fields ?? Fields::instance();
		$this->actions            = $actions;
		$this->progress           = new Progress( $this->batches, $changes, $clock, $reversibility );
		$this->changes_controller = new BatchChangesController( $this->batches, $changes, $this->fields );
		$this->undo_controller    = $undo ?? new UndoController( $this->batches, $changes, $this->fields, null, $factory, $clock );
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
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => self::args(),
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => $this->list_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
		$routes->add(
			self::ITEM_ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => self::id_arg(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
		$this->changes_controller->register_routes( $routes );
		$this->undo_controller->register_routes( $routes );
		$routes->add(
			self::CANCEL_ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'cancel_item' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => self::id_arg(),
				],
			]
		);
	}

	/**
	 * The schema of one batch as the history list and the progress read
	 * answer it (Engine\Progress::report_for(); the list omits `summary`).
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$integer  = [ 'type' => 'integer' ];
		$text     = [ 'type' => 'string' ];
		$time     = [ 'type' => [ 'string', 'null' ] ];
		$counters = array_fill_keys( [ 'total_items', 'done_items', 'changed_items', 'failed_items', 'pending_items', 'chunk_size' ], $integer );
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => 'bcm-batch',
			'type'       => 'object',
			'properties' => [
				'batch_id'     => $integer + [ 'required' => true ],
				'status'       => [
					'type' => 'string',
					'enum' => Schema::BATCH_STATUSES,
				],
				'source'       => [
					'type' => 'string',
					'enum' => Schema::BATCH_SOURCES,
				],
				'user_id'      => $integer,
				'undo_of'      => [ 'type' => [ 'integer', 'null' ] ],
				'created_gmt'  => $text,
				'finished_gmt' => $time,
			] + $counters + [
				'note'                 => $text,
				'operations'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'cancellable'          => [ 'type' => 'boolean' ],
				'stalled'              => [ 'type' => 'boolean' ],
				'stalled_message'      => $text,
				'last_activity_gmt'    => $time,
				'previewed_count'      => [ 'type' => [ 'integer', 'null' ] ],
				'selection_difference' => $text,
				'actions'              => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'reversibility'        => [
					'type'       => 'object',
					'properties' => [
						'state'     => [
							'type' => 'string',
							'enum' => Reversibility::STATES,
						],
						'conflicts' => [ 'type' => [ 'integer', 'null' ] ],
						'reason'    => [
							'type' => [ 'string', 'null' ],
							'enum' => [ ...Reversibility::REASONS, null ],
						],
					],
				],
				'undo'                 => [
					'type'       => [ 'object', 'null' ],
					'properties' => [
						'undo_of'    => $integer,
						'label'      => $text,
						'forced'     => [ 'type' => [ 'boolean', 'null' ] ],
						'counts'     => [ 'type' => [ 'object', 'null' ] ],
						'left_alone' => [ 'type' => [ 'integer', 'null' ] ],
						'not_undone' => [ 'type' => [ 'integer', 'null' ] ],
						'sentence'   => $text,
						'reasons'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
				],
				'summary'              => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'message'  => $text,
							'count'    => $integer,
							'sentence' => $text,
						],
					],
				],
			],
		];
	}

	/**
	 * The batch id argument from the path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function id_arg(): array {
		return [
			'id' => self::arg(
				[
					'description' => __( 'The change, by its number.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'required'    => true,
				],
				static fn( mixed $value ): int => max( 0, (int) $value )
			),
		];
	}

	/**
	 * A time filter argument, read as GMT `Y-m-d H:i:s` (the history list and the audit log).
	 *
	 * @param string $description What the argument does.
	 * @return array<string, mixed>
	 */
	public static function date_arg( string $description ): array {
		return [
			'description'       => $description,
			'type'              => 'string',
			'pattern'           => self::DATE,
			'validate_callback' => static function ( mixed $value ): bool|\WP_Error {
				if ( is_string( $value ) && 1 === preg_match( '/' . self::DATE . '/', $value ) && '' !== self::gmt( $value ) ) {
					return true;
				}
				return Refusal::invalid( 'bcm_invalid_value', __( 'Dates have to look like 2026-09-23, optionally with a time. Choose the dates again.', 'bulk-catalogue-manager-for-woocommerce' ) );
			},
			'sanitize_callback' => [ self::class, 'gmt' ],
		];
	}

	/**
	 * The user filter argument: a user id (the history list and the audit log).
	 *
	 * @return array<string, mixed>
	 */
	public static function user_arg(): array {
		return self::arg(
			[
				'description' => __( 'Only changes made by this user, by id.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'integer',
				'minimum'     => 1,
			],
			'intval'
		);
	}

	/**
	 * The history listing's arguments: paging, status, user, a creation-time
	 * range and an action type from the operations registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function list_args(): array {
		return ( new RouteSchema( $this->fields ) )->paging_args() + [
			'status' => self::arg(
				[
					'description' => __( 'Only changes with this outcome.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => Schema::BATCH_STATUSES,
				],
				'strval'
			),
			'user'   => self::user_arg(),
			'after'  => self::date_arg( __( 'Only changes made at or after this time (UTC unless it names a zone).', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'before' => self::date_arg( __( 'Only changes made before this time (UTC unless it names a zone).', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'action' => self::arg(
				[
					'description' => __( 'Only changes that used this action.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => ( $this->actions ?? Actions::instance() )->ids(),
				],
				'strval'
			),
		];
	}

	/**
	 * A page of batches, newest first: `{ items, pagination }`.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$filters  = array_filter(
			[
				'status'  => $request->get_param( 'status' ),
				'user_id' => $request->get_param( 'user' ),
				'after'   => $request->get_param( 'after' ),
				'before'  => $request->get_param( 'before' ),
				'action'  => $request->get_param( 'action' ),
			],
			[ self::class, 'is_given' ]
		);
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 25 );
		$total    = $this->batches->history_count( $filters );
		$items    = $this->progress->reports( $this->batches->history( $filters, $page, $per_page ) );
		return self::paged( $items, $page, $per_page, $total );
	}

	/**
	 * Whether a filter parameter was given (not absent, not empty).
	 *
	 * @param mixed $value The parameter.
	 */
	public static function is_given( mixed $value ): bool {
		return null !== $value && '' !== $value;
	}

	/**
	 * One batch's progress and outcome.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->progress->report( (int) $request->get_param( 'id' ) );
		return null === $report ? self::not_found() : new \WP_REST_Response( $report, 200 );
	}

	/**
	 * Cancel a queued or running batch.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function cancel_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->progress->cancel( (int) $request->get_param( 'id' ) );
		if ( 'missing' === $result ) {
			return self::not_found();
		}
		if ( 'finished' === $result ) {
			return Refusal::create(
				self::NOT_CANCELLABLE,
				__( 'This change has already finished, so it cannot be cancelled. Use Undo in the history to reverse it.', 'bulk-catalogue-manager-for-woocommerce' ),
				409
			);
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * A list response in the grid's shape.
	 *
	 * @param list<array<string, mixed>> $items    Rows.
	 * @param int                        $page     Page.
	 * @param int                        $per_page Rows per page.
	 * @param int                        $total    Rows in all.
	 */
	public static function paged( array $items, int $page, int $per_page, int $total ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'items'      => $items,
				'pagination' => [
					'page'       => $page,
					'perPage'    => $per_page,
					'total'      => $total,
					'totalPages' => (int) ceil( $total / max( 1, $per_page ) ),
				],
			],
			200
		);
	}

	/**
	 * The refusal for a batch id that does not exist.
	 */
	public static function not_found(): \WP_Error {
		return Refusal::create(
			self::NOT_FOUND,
			__( 'That change could not be found. It may have been removed from the history; reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			404
		);
	}

	/**
	 * A time argument as GMT `Y-m-d H:i:s` (UTC unless it names a zone).
	 *
	 * @param mixed $value The argument.
	 * @return string The time, or '' when it cannot be read.
	 */
	public static function gmt( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/' . self::DATE . '/', $value ) ) {
			return '';
		}
		try {
			return ( new \DateTimeImmutable( (string) $value, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * The route's arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return [
			'uuid'            => [
				'description'       => __( 'A request id the screen makes once per change, so sending it twice creates one change.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'string',
				'format'            => 'uuid',
				'required'          => true,
				'validate_callback' => static function ( mixed $value ): bool|\WP_Error {
					if ( is_string( $value ) && 1 === preg_match( self::UUID, $value ) ) {
						return true;
					}
					return Refusal::invalid( 'bcm_invalid_value', __( 'This change could not be sent because its request id is missing or damaged. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
				},
				'sanitize_callback' => static fn( mixed $value ): string => strtolower( (string) $value ),
			],
			'operations'      => [
				'description'       => __( 'The changes to make, in order: each an action and its arguments.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'array',
				'items'             => [ 'type' => 'object' ],
				'required'          => true,
				// Operations\Registry::validate_list() checks the list in full, with its own refusals.
				'validate_callback' => static fn(): bool => true,
				'sanitize_callback' => static fn( mixed $value ): mixed => $value,
			],
			'ids'             => self::arg(
				[
					'description' => __( 'The chosen products or variations, by id, instead of a selection token.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
				],
				[ self::class, 'ids' ]
			),
			'token'           => self::arg(
				[
					'description' => __( 'The selection token from the selection route, instead of ids.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'strval'
			),
			'source'          => self::arg(
				[
					'description' => __( 'Where the change was made: always the bulk panel on this route.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => self::SOURCES,
					'default'     => 'bulk',
				],
				'strval'
			),
			'preview_hash'    => self::arg(
				[
					'description' => __( 'The hash the preview answered; required, since every bulk change is previewed first.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'pattern'     => '^[0-9a-f]{64}$',
				],
				'strval'
			),
			'preview_count'   => self::arg(
				[
					'description' => __( 'How many products the preview showed for a filter selection; the selection\'s own count by default.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'minimum'     => 0,
				],
				'intval'
			),
			'confirm_changed' => self::arg(
				[
					'description' => __( 'Apply even though the filter now matches a different number of products than the preview showed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'boolean',
					'default'     => false,
				],
				static fn( mixed $value ): bool => true === $value || 1 === $value || in_array( is_string( $value ) ? strtolower( $value ) : '', [ '1', 'true' ], true )
			),
		];
	}

	/**
	 * Queue the posted change, or answer the one this request id already queued.
	 *
	 * @param \WP_REST_Request $request The request, its arguments validated and sanitised by core.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$factory = $this->factory();
		$uuid    = (string) $request->get_param( 'uuid' );
		$user_id = get_current_user_id();

		// A repeat answers before its selection is read again: the token may have expired meanwhile.
		$existing = $factory->replayed( $uuid, $user_id );
		if ( null === $existing ) {
			$selection = self::selection( $request->get_param( 'ids' ), $request->get_param( 'token' ), $user_id );
			if ( $selection instanceof \WP_Error ) {
				return $selection;
			}
			// Every bulk write is held to its preview: a filter, an id token and an id list alike.
			$token  = $request->get_param( 'token' );
			$count  = $request->get_param( 'preview_count' );
			$apply  = [
				'token'     => null === $token ? '' : (string) $token,
				'hash'      => (string) ( $request->get_param( 'preview_hash' ) ?? '' ),
				'previewed' => null === $count ? null : (int) $count,
				'confirmed' => true === $request->get_param( 'confirm_changed' ),
			];
			$result = $factory->create( $uuid, $user_id, $request->get_param( 'operations' ), $selection, 'bulk', null, $apply );
		} else {
			$result = $factory->replay( $existing );
		}
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return new \WP_REST_Response(
			[
				'batch_id'    => $result['batch_id'],
				'status'      => $result['status'],
				'total_items' => $result['total_items'],
				'replayed'    => $result['replayed'],
			],
			$result['replayed'] ? 200 : 201
		);
	}

	/**
	 * The selection a body names, from exactly one of `ids` and `token`.
	 *
	 * @param mixed $ids     The `ids` argument, sanitised.
	 * @param mixed $token   The `token` argument.
	 * @param int   $user_id The user presenting it.
	 */
	private static function selection( mixed $ids, mixed $token, int $user_id ): Selection|\WP_Error {
		if ( ( null === $ids ) === ( null === $token ) ) {
			return Refusal::invalid(
				self::SELECTION_REQUIRED,
				__( 'This change does not say which products to change. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		if ( null !== $token ) {
			return SelectionToken::parse( $token, $user_id );
		}
		try {
			return Selection::of_ids( (array) $ids );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bcm_invalid_value',
				__( 'These products could not be selected. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'details' => $e->getMessage() ]
			);
		}
	}

	/**
	 * The `ids` argument as whole numbers: a list, or comma-separated text as core accepts it.
	 *
	 * @param mixed $value The argument.
	 * @return list<int>
	 */
	public static function ids( mixed $value ): array {
		$list = is_array( $value ) ? array_values( $value ) : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		return array_map( 'intval', (array) $list );
	}

	/**
	 * The factory, built on first use.
	 */
	private function factory(): BatchFactory {
		$this->factory ??= new BatchFactory();
		return $this->factory;
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
