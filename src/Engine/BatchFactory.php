<?php
/**
 * Batch creation (SPEC §9.2, §3, §12.1, §12.6): turns a validated request
 * — an operation list, a selection, a client request id — into a queued
 * batch whose selection is materialised into pending items before any work
 * is enqueued, so a filter whose membership shifts later cannot change what
 * the batch does, and undo is exact.
 *
 * Order of work, and nothing is written until every refusal has been ruled out:
 *
 * 1. Replay. The same user sending the same `uuid` within REPLAY_TTL seconds
 *    gets the batch the first request created (a `bcm_batch_request_`
 *    transient keyed by a hash of the request id and user).
 * 2. The operation list, through Operations\Registry::validate_list() (an
 *    empty list, more than twenty operations, an unknown action or a bad
 *    argument is refused), stored normalised.
 * 3. The selection. More than Selection::MAX_OBJECTS objects is refused 400
 *    `bcm_selection_too_large`. A filter is re-resolved at this moment through
 *    ProductQuery's own conditions, PAGE ids at a time in id order, so the
 *    batch holds what matches now; an id list keeps its order and drops ids
 *    that are no longer a listable product or variation. Each object's type
 *    (product or variation) comes from its post type; the filter's mode
 *    decides whether variations match. Nothing left is refused 400
 *    `bcm_selection_empty`. Expanding a parent to its variations ("Also
 *    apply to variations") is T69.
 * 4. The batch row (status queued, operations and selection JSON,
 *    total_items, source, user), then its items. Two submissions that raced
 *    past step 1 both insert a row; the later row sees the earlier one and
 *    removes itself before writing items, answering the earlier batch id.
 *    If the items cannot be written the row is removed and the request
 *    refused 500 `bcm_batch_not_recorded`.
 * 5. The replay transient, then the `bcm_batch_created` action with the
 *    batch id: the point where the chunk runner (T34) enqueues its first
 *    chunk. Nothing is enqueued here.
 *
 * Applying a filter selection (T38, §9.1, §12.6). When the caller passes the
 * apply check (`$apply`: the token as sent, the preview's hash, the count the
 * preview showed and whether the owner confirmed a changed count), a filter
 * selection is held to its preview: after the operation list is validated,
 * Preview::hash() over the token, the normalised list and the stored
 * settings must equal the hash the request carries, or the request is
 * refused 409 `bcm_preview_stale` (a missing hash included); after
 * materialising, a count other than the previewed one (the request's, else
 * the token's own) is refused 409 `bcm_selection_changed`, naming both
 * numbers, unless the owner confirmed it. The previewed count is stored on
 * the batch as `previewed_items`, and Engine\Progress states any difference
 * from `total_items` in the batch's report and history row. The REST route
 * always passes the check for a token; an id selection ignores it.
 *
 * create_undo() (T56, §9.4) is the variant for an undo: the objects and
 * their operations come from an Undo\Plan rather than a selection, each
 * item carrying its own operation list (the object's own old values), so
 * nothing is re-resolved or re-validated beyond what the planner did. It
 * shares the replay check, the race guard, the items-before-work order and
 * `bcm_batch_created`, and moves the batch undone to `undoing` before the
 * first chunk can run (Undo\Original moves it on when the undo finishes).
 *
 * open() (T37) is the synchronous variant for a batch its caller writes in
 * the same request: validated operations, the row and its items, the
 * status moved straight to running, and no `bcm_batch_created`, so no
 * chunk is ever enqueued for it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Rest\SelectionController;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SPEC §9.2: materialisation must read the catalogue as it is at this moment, never a cached copy.

/**
 * Creates batches.
 */
final class BatchFactory {
	/**
	 * Every replay transient starts with this (Data\Registry).
	 */
	public const PREFIX = 'bcm_batch_request_';

	/**
	 * Seconds a request id keeps answering the same batch (SPEC §12.6).
	 */
	public const REPLAY_TTL = 60;

	/**
	 * Ids read per statement when materialising.
	 */
	public const PAGE = 1000;

	/**
	 * The action fired once a batch and its items are written, with the batch id.
	 */
	public const CREATED = 'bcm_batch_created';

	/**
	 * Refusal code for a selection with nothing left to change.
	 */
	public const EMPTY = 'bcm_selection_empty';

	/**
	 * Refusal code for a selection above Selection::MAX_OBJECTS.
	 */
	public const TOO_LARGE = SelectionController::TOO_LARGE;

	/**
	 * Refusal code for a filter whose count moved since the preview (T38).
	 */
	public const CHANGED = 'bcm_selection_changed';

	/**
	 * Refusal code for a filter apply whose preview hash is missing or no longer matches (T38).
	 */
	public const STALE_PREVIEW = 'bcm_preview_stale';

	/**
	 * Refusal code for a batch the database would not take.
	 */
	public const NOT_RECORDED = 'bcm_batch_not_recorded';

	/**
	 * Refusal code for an undo that would put nothing back (T56).
	 */
	public const NOTHING_TO_UNDO = 'bcm_nothing_to_undo';

	/**
	 * Most grouped reasons an undo batch keeps for the items it could not put back.
	 */
	public const MAX_UNDO_REASONS = 20;

	/**
	 * Object type per post type.
	 */
	private const TYPES = [
		'product'           => 'product',
		'product_variation' => 'variation',
	];

	/**
	 * Batches and items.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The action registry.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * Current Unix time.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Build a factory.
	 *
	 * @param Actions|null         $actions Action registry; the shared one by default.
	 * @param BatchRepository|null $batches Repository; the plugin's by default.
	 * @param \Closure|null        $clock   Returns the current Unix time; time() by default.
	 */
	public function __construct( ?Actions $actions = null, ?BatchRepository $batches = null, ?\Closure $clock = null ) {
		$this->actions = $actions ?? Actions::instance();
		$this->batches = $batches ?? new BatchRepository();
		$this->clock   = $clock ?? static fn(): int => time();
	}

	/**
	 * The batch a request id already created for a user within REPLAY_TTL, or null.
	 *
	 * @param string $uuid    Client request id.
	 * @param int    $user_id User.
	 */
	public function replayed( string $uuid, int $user_id ): ?int {
		$entry = get_transient( self::key( $uuid, $user_id ) );
		if ( ! is_array( $entry ) || ! is_int( $entry['batch_id'] ?? null ) || ! is_int( $entry['at'] ?? null ) ) {
			return null;
		}
		return ( $this->clock )() - $entry['at'] < self::REPLAY_TTL ? $entry['batch_id'] : null;
	}

	/**
	 * Create a batch, or answer the one this request already created.
	 *
	 * @param string    $uuid       Client request id (a UUID).
	 * @param int       $user_id    Initiating user.
	 * @param mixed     $operations The operation list as submitted.
	 * @param Selection $selection  The selection, from an id list or a parsed token.
	 * @param string    $source     bulk, inline, tree or undo.
	 * @param int|null  $undo_of    The batch an undo batch reverses.
	 * @param array{token: string, hash: string, previewed?: int|null, confirmed?: bool, settings?: Settings}|null $apply The apply check for a filter selection (T38), or null for none.
	 * @return array{batch_id: int, status: string, total_items: int, previewed_items: int|null, replayed: bool}|\WP_Error
	 */
	public function create( string $uuid, int $user_id, mixed $operations, Selection $selection, string $source = 'bulk', ?int $undo_of = null, ?array $apply = null ): array|\WP_Error {
		$existing = $this->replayed( $uuid, $user_id );
		if ( null !== $existing ) {
			return $this->replay( $existing );
		}

		$operations = $this->actions->validate_list( $operations );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		if ( $selection->too_large() ) {
			return self::too_large( $selection->count );
		}
		$checked = null !== $apply && $selection->is_filter();
		if ( $checked && ! self::preview_matches( $apply, $operations ) ) {
			return Refusal::create(
				self::STALE_PREVIEW,
				__( 'This change no longer matches its preview: the products, the change or the settings have moved on since. Preview the change again, then apply it.', 'bulk-catalogue-manager-for-woocommerce' ),
				409
			);
		}
		$objects = $this->materialise( $selection );
		if ( count( $objects ) > Selection::MAX_OBJECTS ) {
			return self::too_large( count( $objects ) );
		}
		if ( [] === $objects ) {
			return Refusal::invalid(
				self::EMPTY,
				__( 'None of the chosen products can be changed now: they have been deleted or no longer match the filters. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		$previewed = $checked ? (int) ( $apply['previewed'] ?? $selection->count ) : null;
		if ( null !== $previewed && count( $objects ) !== $previewed && true !== ( $apply['confirmed'] ?? false ) ) {
			return self::changed( $previewed, count( $objects ) );
		}

		$now = ( $this->clock )();
		try {
			$batch_id = $this->batches->create( $uuid, $user_id, $source, $operations, self::snapshot( $selection, count( $objects ) ), count( $objects ), $undo_of, $previewed );
			$earlier  = $this->batches->earlier_request( $uuid, $user_id, $batch_id, gmdate( 'Y-m-d H:i:s', $now - self::REPLAY_TTL ) );
			if ( null !== $earlier ) {
				$this->batches->discard( $batch_id );
				return $this->replay( $earlier );
			}
			try {
				$this->batches->materialise_items( $batch_id, $objects );
			} catch ( \RuntimeException $e ) {
				$this->batches->discard( $batch_id );
				throw $e;
			}
		} catch ( \RuntimeException $e ) {
			return Refusal::unexpected( self::NOT_RECORDED, __( 'The change could not be saved, so nothing was changed. Try again in a moment.', 'bulk-catalogue-manager-for-woocommerce' ), 500, $e );
		}

		set_transient(
			self::key( $uuid, $user_id ),
			[
				'batch_id' => $batch_id,
				'at'       => $now,
			],
			self::REPLAY_TTL
		);
		/**
		 * Fires once a batch and its pending items are written, before any
		 * work on it is queued. The chunk runner enqueues its first chunk here.
		 *
		 * @param int $batch_id The new batch.
		 */
		do_action( 'bcm_batch_created', $batch_id );
		return [
			'batch_id'        => $batch_id,
			'status'          => 'queued',
			'total_items'     => count( $objects ),
			'previewed_items' => $previewed,
			'replayed'        => false,
		];
	}

	/**
	 * Create the undo batch of a plan (T56, §9.4), or answer the one this
	 * request already created: source `undo`, `undo_of` the planned batch,
	 * one pending item per revertible object — and per conflicted object
	 * when `$force` (**Revert anyway**) — each carrying its own operations,
	 * written before any work is queued. The batch undone moves from its
	 * status to `undoing` once the items are written; if another undo moved
	 * it first, this batch is removed and the request refused 409
	 * `bcm_not_reversible` (`undo_in_progress`). The selection column keeps
	 * what the report needs: the batch undone, its status before, the plan's
	 * counts and hash, whether it was forced, how many objects were left
	 * alone because they changed since, and the grouped reasons of those
	 * that could not be put back.
	 *
	 * @param string $uuid    Client request id (a UUID).
	 * @param int    $user_id Initiating user.
	 * @param Plan   $plan    The plan, from Undo\Planner::plan() now.
	 * @param bool   $force   Whether conflicted objects are put back too.
	 * @return array{batch_id: int, status: string, total_items: int, previewed_items: int|null, replayed: bool}|\WP_Error
	 */
	public function create_undo( string $uuid, int $user_id, Plan $plan, bool $force ): array|\WP_Error {
		$existing = $this->replayed( $uuid, $user_id );
		if ( null !== $existing ) {
			return $this->replay( $existing );
		}
		$undo_of  = $plan->batch_id();
		$original = $this->batches->find( $undo_of );
		if ( null === $original ) {
			return Refusal::create( Writer::NOT_FOUND, __( 'That batch could not be found. It may have been removed. Reload the history and try again.', 'bulk-catalogue-manager-for-woocommerce' ), 404 );
		}
		$previous = $original['status'];

		$objects = [];
		$outline = [];
		$reasons = [];
		foreach ( $plan->items() as $item ) {
			$take = Plan::REVERTIBLE === $item['state'] || ( $force && Plan::CONFLICTED === $item['state'] );
			if ( Plan::SKIPPED === $item['state'] && Planner::NOT_APPLIED !== $item['code'] ) {
				$reasons[ $item['reason'] ] = ( $reasons[ $item['reason'] ] ?? 0 ) + 1;
			}
			if ( ! $take || [] === $item['operations'] ) {
				continue;
			}
			$objects[] = [ $item['object_id'], $item['object_type'], $item['operations'] ];
			foreach ( $item['operations'] as $operation ) {
				$outline[ $operation['action'] . '|' . $operation['args']['field'] ] ??= [
					'action' => $operation['action'],
					'args'   => [ 'field' => $operation['args']['field'] ],
				];
			}
		}
		$counts = $plan->counts();
		if ( [] === $objects ) {
			return Refusal::create(
				self::NOTHING_TO_UNDO,
				$counts[ Plan::CONFLICTED ] > 0 && ! $force
					? __( 'Everything this batch changed has been changed again since, so nothing was undone. Choose Revert anyway to put the old values back regardless.', 'bulk-catalogue-manager-for-woocommerce' )
					: __( 'Nothing this batch changed can be put back now, so nothing was undone. The preview lists why for each product.', 'bulk-catalogue-manager-for-woocommerce' ),
				409,
				[ 'counts' => $counts ]
			);
		}
		arsort( $reasons );
		$grouped = [];
		foreach ( array_slice( $reasons, 0, self::MAX_UNDO_REASONS, true ) as $message => $count ) {
			$grouped[] = [
				'message' => (string) $message,
				'count'   => $count,
			];
		}
		$snapshot = [
			'kind'            => 'undo',
			'undo_of'         => $undo_of,
			'previous_status' => $previous,
			'force'           => $force,
			'plan_hash'       => $plan->hash(),
			'counts'          => $counts,
			'left_alone'      => $force ? 0 : $counts[ Plan::CONFLICTED ],
			'not_undone'      => array_sum( $reasons ),
			'reasons'         => $grouped,
			'materialised'    => count( $objects ),
		];

		$now = ( $this->clock )();
		try {
			$batch_id = $this->batches->create( $uuid, $user_id, 'undo', array_values( $outline ), $snapshot, count( $objects ), $undo_of );
			$earlier  = $this->batches->earlier_request( $uuid, $user_id, $batch_id, gmdate( 'Y-m-d H:i:s', $now - self::REPLAY_TTL ) );
			if ( null !== $earlier ) {
				$this->batches->discard( $batch_id );
				return $this->replay( $earlier );
			}
			try {
				$this->batches->materialise_items( $batch_id, $objects );
			} catch ( \RuntimeException $e ) {
				$this->batches->discard( $batch_id );
				throw $e;
			}
			if ( ! $this->batches->transition( $undo_of, $previous, 'undoing' ) ) {
				// Another undo of the same batch started first; it wins.
				$this->batches->discard( $batch_id );
				return Refusal::create( Planner::NOT_REVERSIBLE, Planner::undoing_sentence(), 409, [ 'reason' => Planner::UNDO_IN_PROGRESS ] );
			}
		} catch ( \RuntimeException $e ) {
			return Refusal::unexpected( self::NOT_RECORDED, __( 'The change could not be saved, so nothing was changed. Try again in a moment.', 'bulk-catalogue-manager-for-woocommerce' ), 500, $e );
		}

		set_transient(
			self::key( $uuid, $user_id ),
			[
				'batch_id' => $batch_id,
				'at'       => $now,
			],
			self::REPLAY_TTL
		);
		/** This action is documented in create(). */
		do_action( 'bcm_batch_created', $batch_id );
		return [
			'batch_id'        => $batch_id,
			'status'          => 'queued',
			'total_items'     => count( $objects ),
			'previewed_items' => null,
			'replayed'        => false,
		];
	}

	/**
	 * Whether the hash an apply carries is the one a preview of the same
	 * token, normalised operation list and current settings gives now.
	 *
	 * @param array<string, mixed> $apply      The apply check.
	 * @param array<mixed>         $operations The normalised operation list.
	 */
	private static function preview_matches( array $apply, array $operations ): bool {
		$hash = $apply['hash'] ?? '';
		if ( ! is_string( $hash ) || '' === $hash ) {
			return false;
		}
		$settings = $apply['settings'] ?? null;
		return hash_equals( Preview::hash( (string) $apply['token'], $operations, $settings instanceof Settings ? $settings : Settings::load() ), $hash );
	}

	/**
	 * The refusal of a filter whose count moved between the preview and
	 * apply, naming both numbers so the screen can ask again (§9.1, §4.8).
	 *
	 * @param int $previewed The count the preview showed.
	 * @param int $current   The count that matches now.
	 */
	private static function changed( int $previewed, int $current ): \WP_Error {
		return Refusal::create(
			self::CHANGED,
			sprintf(
				/* translators: 1: number of products the preview showed, such as "120"; 2: number that match the filters now, such as "123". */
				_n( 'The products matching your filters have changed since the preview: it showed %1$s, and %2$s matches now. Check the change and apply it again, or preview it again first.', 'The products matching your filters have changed since the preview: it showed %1$s, and %2$s match now. Check the change and apply it again, or preview it again first.', $current, 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $previewed ),
				number_format_i18n( $current )
			),
			409,
			[
				'previewed' => $previewed,
				'current'   => $current,
			]
		);
	}

	/**
	 * Open a batch that its caller works synchronously, in the same request
	 * (T37: an inline edit or detail-panel save as a batch of one): the
	 * operation list through validate_list(), the batch row with a fresh
	 * request id, its items pending, and the batch moved from queued to
	 * running. There is no replay check, and `bcm_batch_created` is not
	 * fired, so the chunk runner never enqueues it; the caller claims the
	 * items, writes them through Writer::for_batch() and finalises the batch
	 * itself. A row or items the database will not take leave nothing written
	 * and answer 500 `bcm_batch_not_recorded`.
	 *
	 * A `tree` batch (T63, T64: a category write) holds term items and the
	 * category step its caller, Categories\Writer, has already validated;
	 * the action registry knows product actions only, so it is not consulted
	 * and the list is stored as given (tree_operations()).
	 *
	 * @param int                            $user_id    Initiating user.
	 * @param mixed                          $operations The operation list as submitted.
	 * @param list<array{0: int, 1: string}> $objects    The objects, materialised by the caller, as [ object_id, object_type ] pairs.
	 * @param string                         $source     inline, bulk or tree; never undo.
	 * @return int|\WP_Error The running batch's id, or the refusal.
	 * @throws \InvalidArgumentException When the source is undo or there are no objects: a programming error.
	 */
	public function open( int $user_id, mixed $operations, array $objects, string $source = 'inline' ): int|\WP_Error {
		if ( 'undo' === $source || [] === $objects ) {
			throw new \InvalidArgumentException( 'A synchronous batch needs objects and is never an undo.' );
		}
		$operations = 'tree' === $source ? self::tree_operations( $operations, $objects ) : $this->actions->validate_list( $operations );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$ids = array_map( static fn( array $pair ): int => (int) $pair[0], $objects );
		try {
			$batch_id = $this->batches->create( wp_generate_uuid4(), $user_id, $source, $operations, self::snapshot( Selection::of_ids( $ids ), count( $objects ) ), count( $objects ) );
			try {
				$this->batches->materialise_items( $batch_id, $objects );
				if ( ! $this->batches->transition( $batch_id, 'queued', 'running' ) ) {
					throw new \RuntimeException( 'The batch could not be started.' );
				}
			} catch ( \RuntimeException $e ) {
				$this->batches->discard( $batch_id );
				throw $e;
			}
		} catch ( \RuntimeException $e ) {
			return Refusal::unexpected( self::NOT_RECORDED, __( 'The change could not be saved, so nothing was changed. Try again in a moment.', 'bulk-catalogue-manager-for-woocommerce' ), 500, $e );
		}
		return $batch_id;
	}

	/**
	 * A tree batch's steps as given: a non-empty list of `{ action, args }`
	 * steps over term items only.
	 *
	 * @param mixed                          $operations The steps.
	 * @param list<array{0: int, 1: string}> $objects    The items.
	 * @return list<array{action: string, args: array<string, mixed>}>
	 * @throws \InvalidArgumentException On anything else: a programming error.
	 */
	private static function tree_operations( mixed $operations, array $objects ): array {
		$valid = is_array( $operations ) && [] !== $operations && array_is_list( $operations );
		foreach ( $valid ? $operations : [] as $step ) {
			$valid = $valid && is_array( $step ) && is_string( $step['action'] ?? null ) && is_array( $step['args'] ?? null );
		}
		foreach ( $objects as $pair ) {
			$valid = $valid && 'term' === ( $pair[1] ?? null );
		}
		if ( ! $valid ) {
			throw new \InvalidArgumentException( 'A tree batch holds term items and validated { action, args } steps.' );
		}
		return $operations;
	}

	/**
	 * The objects a selection covers now, in order, as [ object_id, object_type ]
	 * pairs. Stops reading once past Selection::MAX_OBJECTS.
	 *
	 * @param Selection $selection The selection.
	 * @return list<array{0: int, 1: string}>
	 */
	public function materialise( Selection $selection ): array {
		return null === $selection->criteria ? self::of_ids( $selection->ids ) : self::of_filter( new ProductQuery( $selection->criteria ) );
	}

	/**
	 * Every row a filter matches now, PAGE at a time in id order, through
	 * ProductQuery's own FROM and WHERE so it is exactly what the grid lists.
	 *
	 * @param ProductQuery $query The filter's query.
	 * @return list<array{0: int, 1: string}>
	 */
	private static function of_filter( ProductQuery $query ): array {
		global $wpdb;
		$conditions = $query->conditions_sql();
		$sql        = 'SELECT p.ID AS id, p.post_type AS post_type ' . $conditions['sql'] . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d';
		$objects    = [];
		$after      = 0;
		$more       = true;
		while ( $more ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only literals and ProductQuery's placeholders; every value is an argument.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...[ ...$conditions['args'], $after, self::PAGE ] ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];
			foreach ( $rows as $row ) {
				$after     = (int) $row['id'];
				$objects[] = [ $after, self::TYPES[ (string) $row['post_type'] ] ?? 'product' ];
			}
			// A short page is the last; past the limit the batch is refused, so reading stops.
			$more = self::PAGE === count( $rows ) && count( $objects ) <= Selection::MAX_OBJECTS;
		}
		return $objects;
	}

	/**
	 * The ids of a list that are still a listable product or variation, in
	 * the list's order, PAGE ids per statement.
	 *
	 * @param list<int> $ids Ids, each once.
	 * @return list<array{0: int, 1: string}>
	 */
	private static function of_ids( array $ids ): array {
		global $wpdb;
		$types   = array_keys( self::TYPES );
		$found   = [];
		$objects = [];
		foreach ( array_chunk( $ids, self::PAGE ) as $chunk ) {
			$sql  = 'SELECT p.ID AS id, p.post_type AS post_type FROM %i AS p WHERE p.ID IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND p.post_type IN (%s, %s) AND p.post_status IN (' . implode( ', ', array_fill( 0, count( ProductQuery::STATUSES ), '%s' ) ) . ') LIMIT %d';
			$args = [ $wpdb->prefix . ProductQuery::TABLES['posts'], ...$chunk, ...$types, ...ProductQuery::STATUSES, count( $chunk ) ];
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template holds only literals and placeholders; every value is an argument.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$found[ (int) $row['id'] ] = self::TYPES[ (string) $row['post_type'] ] ?? 'product';
			}
			foreach ( $chunk as $id ) {
				if ( isset( $found[ $id ] ) ) {
					$objects[] = [ $id, $found[ $id ] ];
				}
			}
		}
		return $objects;
	}

	/**
	 * The selection as the batch row keeps it: the filter's conditions with
	 * the count shown when it was chosen, or the id list; and how many
	 * objects were materialised.
	 *
	 * @param Selection $selection    The selection.
	 * @param int       $materialised Objects written as items.
	 * @return array<string, mixed>
	 */
	private static function snapshot( Selection $selection, int $materialised ): array {
		if ( null !== $selection->criteria ) {
			return [
				'kind'         => 'filter',
				'conditions'   => $selection->criteria->conditions(),
				'count'        => $selection->count,
				'materialised' => $materialised,
			];
		}
		return [
			'kind'         => 'ids',
			'ids'          => $selection->ids,
			'count'        => $selection->count,
			'materialised' => $materialised,
		];
	}

	/**
	 * The answer for a batch an earlier submission of the same request created.
	 *
	 * @param int $batch_id The batch.
	 * @return array{batch_id: int, status: string, total_items: int, previewed_items: int|null, replayed: bool}
	 */
	public function replay( int $batch_id ): array {
		$batch = $this->batches->find( $batch_id );
		return [
			'batch_id'        => $batch_id,
			'status'          => null === $batch ? 'queued' : $batch['status'],
			'total_items'     => null === $batch ? 0 : $batch['total_items'],
			'previewed_items' => null === $batch ? null : $batch['previewed_items'],
			'replayed'        => true,
		];
	}

	/**
	 * The refusal of a selection above Selection::MAX_OBJECTS.
	 *
	 * @param int $count Objects it holds.
	 */
	private static function too_large( int $count ): \WP_Error {
		return Refusal::invalid(
			self::TOO_LARGE,
			sprintf(
				/* translators: 1: how many products the selection holds, such as "100,001"; 2: the most one change may hold, "100,000". */
				_n( 'This selection holds %1$s product, more than the %2$s one change can include. Add a filter to narrow it and try again.', 'This selection holds %1$s products, more than the %2$s one change can include. Add a filter to narrow it and try again.', $count, 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $count ),
				number_format_i18n( Selection::MAX_OBJECTS )
			),
			[
				'count' => $count,
				'limit' => Selection::MAX_OBJECTS,
			]
		);
	}

	/**
	 * The replay transient's name for a request id and user.
	 *
	 * @param string $uuid    Client request id.
	 * @param int    $user_id User.
	 */
	private static function key( string $uuid, int $user_id ): string {
		return self::PREFIX . md5( strtolower( $uuid ) . '|' . $user_id );
	}
}
