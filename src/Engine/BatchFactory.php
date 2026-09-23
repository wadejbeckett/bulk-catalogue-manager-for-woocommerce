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
	 * Refusal code for a batch the database would not take.
	 */
	public const NOT_RECORDED = 'bcm_batch_not_recorded';

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
	 * @return array{batch_id: int, status: string, total_items: int, replayed: bool}|\WP_Error
	 */
	public function create( string $uuid, int $user_id, mixed $operations, Selection $selection, string $source = 'bulk', ?int $undo_of = null ): array|\WP_Error {
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

		$now = ( $this->clock )();
		try {
			$batch_id = $this->batches->create( $uuid, $user_id, $source, $operations, self::snapshot( $selection, count( $objects ) ), count( $objects ), $undo_of );
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
			return Refusal::create(
				self::NOT_RECORDED,
				__( 'The change could not be saved, so nothing was changed. Try again in a moment.', 'bulk-catalogue-manager-for-woocommerce' ),
				500,
				[ 'details' => $e->getMessage() ]
			);
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
			'batch_id'    => $batch_id,
			'status'      => 'queued',
			'total_items' => count( $objects ),
			'replayed'    => false,
		];
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
	 * @return array{batch_id: int, status: string, total_items: int, replayed: bool}
	 */
	public function replay( int $batch_id ): array {
		$batch = $this->batches->find( $batch_id );
		return [
			'batch_id'    => $batch_id,
			'status'      => null === $batch ? 'queued' : $batch['status'],
			'total_items' => null === $batch ? 0 : $batch['total_items'],
			'replayed'    => true,
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
				__( 'This selection holds %1$s products, more than the %2$s one change can include. Add a filter to narrow it and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
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
