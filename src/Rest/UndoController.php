<?php
/**
 * `POST /bulkcat/v1/batches/{id}/undo` (SPEC §4.6, §9.4, §12.1, §12.6; T56):
 * undo a batch, through its own preview first. Registered by
 * BatchesController, behind Access::can_write (it writes products; each
 * object is checked again, against the undo batch's user, as it is written).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Progress;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * The undo route.
 *
 * With `preview: true` it plans the undo through Undo\Planner and writes
 * nothing: `{ batch_id, preview, counts, by_type, total, items,
 * pagination, plan_hash, sentence }` (`by_type` the counts of products and
 * of variations apart, T114), the items a page of the plan with the conflicted
 * ones first (each with its name, state, plain reason, the fields it puts
 * back and, when conflicted, each changed field with the value the batch
 * left and the value found now), bound to the plan's hash like any preview.
 * The plan is walked, not held (T111): the walk counts and hashes every
 * item keeping only each object's id by state, and the page's objects are
 * then planned again as they stand (Planner::items()), so a preview of a
 * whole-catalogue undo fits a web request's memory on any page.
 *
 * Applied (`preview` false, the default) it is a durable request (T83):
 * the body carries the server-issued `uuid` and `request_proof` from `POST
 * /requests` (operation `batch.undo`) and the canonical `intent` (`{ version,
 * source: undo, batch_id, plan_hash, force }`, its `batch_id` the one in the
 * path), and nothing else. Requests\RequestService resolves the receipt
 * first — an accepted undo answers 200 with its undo batch before anything
 * is planned again — and otherwise, in one transaction, locks the batch,
 * plans it again (a different hash is 409 `bulkcat_preview_stale`), records
 * the undo batch — source `undo`, `undo_of` the batch, the revertible
 * objects (and the conflicted ones with `force: true`, **Revert anyway**)
 * with their own operations — with its receipt, and moves the batch undone
 * to `undoing`; only then is its chunk queued. The chunk runner works it
 * like any other batch, through the same writer and journal. It answers
 * 201 `{ batch_id, status, total_items, replayed, undo }`, `undo` being
 * Engine\Progress::undo_summary(): "Undo of #418" and how many objects were
 * left alone.
 *
 * A body with a bare `uuid`, `plan_hash` and `force` comes from a 1.0 screen
 * and never creates an undo: the one undo batch that id exactly made (same
 * batch, plan and force) answers 200, anything else 409
 * `bulkcat_legacy_request` (Engine\BatchFactory::earlier()).
 *
 * A batch the planner refuses (running, already undone or being undone,
 * pruned, outside retention) answers its 409 `bulkcat_not_reversible` with the
 * reason; one with nothing left to put back 409 `bulkcat_nothing_to_undo`; a
 * missing batch 404 `bulkcat_not_found`.
 */
final class UndoController implements Controller {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/batches/(?P<id>[\d]+)/undo';

	/**
	 * The planner, built on first use.
	 *
	 * @var Planner|null
	 */
	private ?Planner $planner;

	/**
	 * The journal.
	 *
	 * @var ChangeRepository
	 */
	private ChangeRepository $changes;

	/**
	 * Returns the Unix time, for the planner's retention check; time() when null.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $clock;

	/**
	 * The factory, built on first use.
	 *
	 * @var BatchFactory|null
	 */
	private ?BatchFactory $factory;

	/**
	 * Batches.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The field registry, for labels and the paging arguments.
	 *
	 * @var Fields
	 */
	private Fields $fields;

	/**
	 * Build a controller.
	 *
	 * @param BatchRepository|null  $batches Batches; the plugin's by default.
	 * @param ChangeRepository|null $changes The journal; the plugin's by default.
	 * @param Fields|null           $fields  Field registry; the shared one by default.
	 * @param Planner|null          $planner The planner; one over the same repositories and clock, built when first needed, by default.
	 * @param BatchFactory|null     $factory The factory; one over the same batches, built when first needed, by default.
	 * @param \Closure|null         $clock   Returns the Unix time, for the default planner; time() by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?Fields $fields = null, ?Planner $planner = null, ?BatchFactory $factory = null, ?\Closure $clock = null ) {
		$this->batches = $batches ?? new BatchRepository();
		$this->changes = $changes ?? new ChangeRepository();
		$this->fields  = $fields ?? Fields::instance();
		$this->planner = $planner;
		$this->factory = $factory;
		$this->clock   = $clock;
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
					'callback'            => [ $this, 'undo' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => $this->args(),
				],
			]
		);
	}

	/**
	 * The route's arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		$uuid                = BatchesController::args()['uuid'];
		$uuid['required']    = false;
		$uuid['description'] = __( 'A request id the screen makes once per undo, so sending it twice undoes once; required unless previewing.', 'bulk-catalogue-manager-for-woocommerce' );
		return BatchesController::id_arg() + ( new RouteSchema( $this->fields ) )->paging_args() + [
			'request_proof' => BatchesController::args()['request_proof'],
			'intent'        => BatchesController::args()['intent'],
			'uuid'          => $uuid,
			'preview'       => self::flag( __( 'Plan the undo and show it, changing nothing.', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'force'         => self::flag( __( 'Revert anyway: also put back products that were changed again after this batch.', 'bulk-catalogue-manager-for-woocommerce' ) ),
			'plan_hash'     => [
				'description'       => __( 'The hash the undo preview answered; required to undo.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'string',
				'pattern'           => '^[0-9a-f]{64}$',
				'validate_callback' => static fn( mixed $value ): bool|\WP_Error => is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $value ) ? true : Refusal::invalid( 'bulkcat_invalid_value', __( 'This undo could not be sent because its preview is missing or damaged. Preview the undo again, then confirm it.', 'bulk-catalogue-manager-for-woocommerce' ) ),
				'sanitize_callback' => static fn( mixed $value ): string => (string) $value,
			],
		];
	}

	/**
	 * Preview or apply the undo of a batch.
	 *
	 * @param \WP_REST_Request $request The validated, sanitised request.
	 */
	public function undo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request->get_param( 'id' );
		self::lift_time_limit();
		if ( true === $request->get_param( 'preview' ) ) {
			return $this->preview( $batch_id, (int) ( $request->get_param( 'page' ) ?? 1 ), (int) ( $request->get_param( 'per_page' ) ?? 25 ) );
		}
		if ( null === $request->get_param( 'request_proof' ) && null === $request->get_param( 'intent' ) ) {
			return $this->legacy( $request, $batch_id );
		}
		$intent = $request->get_param( 'intent' );
		// Force belongs in the intent; everything else a durable undo sends is named here.
		if ( ! RequestsController::known( $request, [ 'id', 'uuid', 'request_proof', 'intent', 'preview', 'force', 'page', 'per_page' ] ) || true === $request->get_param( 'force' ) ) {
			return RequestsController::unknown();
		}
		if ( ! is_array( $intent ) || ( $intent['batch_id'] ?? null ) !== $batch_id ) {
			return Refusal::invalid( 'bulkcat_invalid_value', __( 'This undo does not match the change it names. Reload the history and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$result = ( new RequestService( $this->clock ) )->undo( array_intersect_key( $request->get_params(), array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) );
		return $result instanceof \WP_Error ? $result : $this->created( $result, $result['replayed'] ? 200 : 201 );
	}

	/**
	 * Lift PHP's time limit, where the host allows it, for a request that
	 * walks a whole undo plan (T111): memory stays bounded, but planning
	 * reads every object the change touched, about two minutes of work for
	 * 100,000 (measured on the scale fixture), past PHP's default 30
	 * seconds. The preview, the accepted undo and the single batch read that
	 * counts conflicts call it; as the CSV export does.
	 */
	public static function lift_time_limit(): void {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
	}

	/**
	 * An undo from a 1.0 screen: its bare request id answers the one undo
	 * batch it exactly made, or is refused; it never plans or creates one.
	 *
	 * @param \WP_REST_Request $request  The request.
	 * @param int              $batch_id The batch to undo.
	 */
	private function legacy( \WP_REST_Request $request, int $batch_id ): \WP_REST_Response|\WP_Error {
		$uuid = $request->get_param( 'uuid' );
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return Refusal::invalid( 'bulkcat_invalid_value', __( 'This undo could not be sent because its request id is missing or damaged. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$hash  = $request->get_param( 'plan_hash' );
		$force = true === $request->get_param( 'force' );
		$same  = static fn( array $batch ): bool => is_string( $hash ) && BatchFactory::same_undo( $batch, $batch_id, $hash, $force );
		try {
			$earlier = $this->factory()->earlier( $uuid, get_current_user_id(), $same );
		} catch ( \RuntimeException $e ) {
			return Refusal::unexpected( 'bulkcat_request_storage', __( 'The request could not be resolved safely. Retry the same request; if this continues, ask an administrator to check catalogue storage.', 'bulk-catalogue-manager-for-woocommerce' ), 503, $e );
		}
		if ( null === $earlier ) {
			return BatchFactory::legacy( [] );
		}
		return $earlier instanceof \WP_Error ? $earlier : $this->created( $earlier, 200 );
	}

	/**
	 * The preview of a batch's undo: counts, a page of items with the
	 * conflicted first, and the hash. The walk keeps each object's id by
	 * state (a list of numbers), never the items; the page's objects are
	 * planned again afterwards.
	 *
	 * @param int $batch_id The batch to undo.
	 * @param int $page     Page, from 1.
	 * @param int $per_page Items per page.
	 */
	private function preview( int $batch_id, int $page, int $per_page ): \WP_REST_Response|\WP_Error {
		$this->planner ??= new Planner( $this->batches, $this->changes, null, null, $this->clock );
		$user_id         = get_current_user_id();
		$ids             = array_fill_keys( [ Plan::CONFLICTED, Plan::REVERTIBLE, Plan::SKIPPED ], [] );
		// T114: what the counts hold, products and variations apart, for the dialog's words.
		$by_type = array_fill_keys( [ 'product', 'variation' ], array_fill_keys( Plan::STATES, 0 ) );
		$summary = $this->planner->walk(
			$batch_id,
			$user_id,
			static function ( array $item ) use ( &$ids, &$by_type ): void {
				$ids[ $item['state'] ][] = $item['object_id'];
				++$by_type[ 'variation' === $item['object_type'] ? 'variation' : 'product' ][ $item['state'] ];
			}
		);
		if ( $summary instanceof \WP_Error ) {
			return $summary;
		}
		// The page's objects in conflicts-first order, then each planned as it stands now.
		$window = [];
		$skip   = ( max( 1, $page ) - 1 ) * $per_page;
		foreach ( $ids as $of_state ) {
			if ( count( $window ) >= $per_page ) {
				break;
			}
			if ( $skip >= count( $of_state ) ) {
				$skip -= count( $of_state );
				continue;
			}
			$window = array_merge( $window, array_slice( $of_state, $skip, $per_page - count( $window ) ) );
			$skip   = 0;
		}
		unset( $ids );
		$now   = [] === $window ? [] : $this->planner->items( $batch_id, $window, $user_id );
		$items = [];
		foreach ( $window as $object_id ) {
			if ( isset( $now[ $object_id ] ) ) {
				$items[] = $now[ $object_id ];
			}
		}
		BatchChangesController::prime_names( $items );
		$counts = $summary->counts();
		$total  = $summary->total();
		return new \WP_REST_Response(
			[
				'batch_id'   => $batch_id,
				'preview'    => true,
				'counts'     => $counts,
				'by_type'    => $by_type,
				'total'      => $total,
				'items'      => array_map( [ $this, 'item' ], $items ),
				'pagination' => [
					'page'       => $page,
					'perPage'    => $per_page,
					'total'      => $total,
					'totalPages' => (int) ceil( $total / max( 1, $per_page ) ),
				],
				'plan_hash'  => $summary->hash(),
				'sentence'   => $counts[ Plan::CONFLICTED ] > 0 ? sprintf(
					/* translators: 1: number of products changed since the batch, such as "12"; 2: number of products the batch changed, such as "1,284". */
					_n( '%1$s of %2$s was changed again after this batch and will be left alone unless you choose Revert anyway.', '%1$s of %2$s were changed again after this batch and will be left alone unless you choose Revert anyway.', $counts[ Plan::CONFLICTED ], 'bulk-catalogue-manager-for-woocommerce' ),
					number_format_i18n( $counts[ Plan::CONFLICTED ] ),
					number_format_i18n( $counts[ Plan::CONFLICTED ] + $counts[ Plan::REVERTIBLE ] )
				) : '',
			],
			200
		);
	}

	/**
	 * One plan item as the preview shows it.
	 *
	 * @param array<string, mixed> $item Plan item.
	 * @return array<string, mixed>
	 */
	public function item( array $item ): array {
		$fields = [];
		foreach ( $item['operations'] as $operation ) {
			$fields[ $operation['args']['field'] ] = true;
		}
		return [
			'object_id'   => $item['object_id'],
			'object_type' => $item['object_type'],
			'name'        => BatchChangesController::name( $item['object_id'], $item['object_type'] ),
			'state'       => $item['state'],
			'code'        => $item['code'],
			'reason'      => $item['reason'],
			'fields'      => array_map( fn( string $key ): array => $this->field( $key ), array_keys( $fields ) ),
			'conflicts'   => array_map(
				fn( array $conflict ): array => $this->field( $conflict['field'] ) + (
					OffRecord::FIELD === $conflict['field']
						// The Off record reads as the status it saved, never as its JSON (SPEC §9.4).
						? [
							'expected' => OffRecord::describe( $conflict['expected'] ),
							'current'  => OffRecord::describe( $conflict['current'] ),
						]
						: [
							'expected' => $conflict['expected'],
							'current'  => $conflict['current'],
						]
				),
				$item['conflicts']
			),
		];
	}

	/**
	 * A field key with its plain label.
	 *
	 * @param string $key Field key.
	 * @return array{field: string, field_label: string}
	 */
	private function field( string $key ): array {
		$field = $this->fields->get( $key );
		$label = null === $field ? $key : $field->label();
		if ( \BulkCatalogueManager\External\Providers::is_external( $key ) ) {
			// T108: an add-on's field by its label (a plan exists only while its provider is active); never its key.
			$label = \BulkCatalogueManager\Operations\Registry::instance()->providers()->owning( $key )?->field_meta( $key )['label'] ?? BatchChangesController::gone_label();
		}
		return [
			'field'       => $key,
			'field_label' => $label,
		];
	}

	/**
	 * The answer for a created or replayed undo batch.
	 *
	 * @param array{batch_id: int, status: string, total_items: int, replayed: bool} $result The factory's answer.
	 * @param int                                                                       $status HTTP status.
	 */
	private function created( array $result, int $status ): \WP_REST_Response {
		$batch = $this->batches->find( $result['batch_id'] );
		return new \WP_REST_Response(
			[
				'batch_id'    => $result['batch_id'],
				'status'      => $result['status'],
				'total_items' => $result['total_items'],
				'replayed'    => $result['replayed'],
				'undo'        => null === $batch ? null : Progress::undo_summary( $batch ),
			],
			$status
		);
	}

	/**
	 * The factory, built on first use.
	 */
	private function factory(): BatchFactory {
		$this->factory ??= new BatchFactory( null, $this->batches );
		return $this->factory;
	}

	/**
	 * A boolean argument, off by default.
	 *
	 * @param string $description What it does.
	 * @return array<string, mixed>
	 */
	private static function flag( string $description ): array {
		return [
			'description'       => $description,
			'type'              => 'boolean',
			'default'           => false,
			'validate_callback' => static fn( mixed $value, mixed $request = null, string $param = '' ): bool|\WP_Error => rest_validate_value_from_schema( $value, [ 'type' => 'boolean' ], $param ),
			'sanitize_callback' => static fn( mixed $value ): bool => true === $value || 1 === $value || in_array( is_string( $value ) ? strtolower( $value ) : '', [ '1', 'true' ], true ),
		];
	}
}
