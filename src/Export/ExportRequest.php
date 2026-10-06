<?php
/**
 * The rules of one catalogue export request (SPEC §10, §12.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Rest\RequestsController;
use BulkCatalogueManager\Rest\RouteSchema;

defined( 'ABSPATH' ) || exit;

/**
 * A `POST /export` request, checked (ticket 10): the columns, the switches
 * and exactly one row source, or the plain refusal to send before any CSV
 * byte. The checks run in this order, after core has validated the
 * arguments and the permission callback has passed:
 *
 * 1. A `selection` in the query string is refused: the token must never be
 *    placed in an address (EXACT-SELECTION). The query string is read only
 *    for that.
 * 2. A `source` other than the catalogue is refused as unknown (R10).
 * 3. The columns are checked as the GET export checks them.
 * 4. The row source: a token together with ids, or more than 1,000 ids, is
 *    refused; a token (read from the form body or the JSON body only, never
 *    through get_param(), which merges the query string: amendment 4) or
 *    ids are used as they are, and the filter, search, mode, parent and
 *    sort arguments are then ignored (R10); otherwise the filter, which
 *    Criteria refuses as it does today.
 * 5. An empty filter, decided on the parsed Criteria (no filter, a blank
 *    search, no parent, in any mode: R6, amendment 2), needs `confirm_all`,
 *    unless only the summary is asked for.
 *
 * Token resolution and presence (403, 409, 410 and the left-out count) are
 * the controller's next step, through ExportRows.
 */
final class ExportRequest {
	/**
	 * The most explicit ids one request may send.
	 */
	public const MAX_IDS = 1000;

	/**
	 * Rows from a selection token.
	 */
	public const FROM_SELECTION = 'selection';

	/**
	 * Rows from explicit ids.
	 */
	public const FROM_IDS = 'ids';

	/**
	 * Rows from the filter.
	 */
	public const FROM_FILTER = 'filter';

	/**
	 * The one source a POST exports.
	 */
	private const CATALOGUE = 'products';

	/**
	 * The `GET /products` arguments a filter export reads with (not paging or fields).
	 */
	private const READ_ARGS = [ 'search', 'orderby', 'order', 'mode', 'parent' ];

	/**
	 * Build through from().
	 *
	 * @param string               $rows        FROM_SELECTION, FROM_IDS or FROM_FILTER.
	 * @param list<string>         $columns     Registry keys, in order.
	 * @param bool                 $identifiers Whether the identifier switch is on.
	 * @param bool                 $summary     Whether only the summary is asked for.
	 * @param bool                 $confirm_all Whether the whole catalogue is confirmed.
	 * @param string               $token       The selection token, for FROM_SELECTION; '' otherwise.
	 * @param list<int>            $ids         The ids, ascending and distinct, for FROM_IDS; [] otherwise.
	 * @param Criteria|null        $criteria    The filter's criteria, for FROM_FILTER; null otherwise.
	 * @param array<string, mixed> $filter      The filter's `GET /products` read arguments, for FROM_FILTER; [] otherwise.
	 */
	private function __construct(
		public readonly string $rows,
		public readonly array $columns,
		public readonly bool $identifiers,
		public readonly bool $summary,
		public readonly bool $confirm_all,
		public readonly string $token,
		public readonly array $ids,
		public readonly ?Criteria $criteria,
		public readonly array $filter
	) {}

	/**
	 * Check a request whose arguments core has validated and sanitised
	 * against RouteSchema::export_args().
	 *
	 * @param \WP_REST_Request $request  The request.
	 * @param Registry         $registry Field registry.
	 */
	public static function from( \WP_REST_Request $request, Registry $registry ): self|\WP_Error {
		if ( array_key_exists( 'selection', $request->get_query_params() ) ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'The selected products must not be sent in the page address. Reload the catalogue and export again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'param' => 'selection' ]
			);
		}
		$params = $request->get_params();
		$source = $params['source'] ?? self::CATALOGUE;
		if ( self::CATALOGUE !== $source ) {
			return RequestsController::unknown();
		}

		$columns = RouteSchema::as_list( $params['columns'] ?? [] );
		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) || ! $registry->has( $column ) ) {
				$columns = [];
				break;
			}
		}
		if ( [] === $columns || count( array_unique( $columns ) ) !== count( $columns ) ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'These columns could not be exported. Choose the columns again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'param' => 'columns' ]
			);
		}
		$identifiers = true === RouteSchema::flag( $params['identifiers'] ?? false );
		$summary     = true === RouteSchema::flag( $params['summary'] ?? false );
		$confirm_all = true === RouteSchema::flag( $params['confirm_all'] ?? false );

		$token = self::token( $request );
		$ids   = array_key_exists( 'ids', $params ) && null !== $params['ids'] ? RouteSchema::as_list( $params['ids'] ) : null;
		if ( null !== $ids && ( null !== $token || count( $ids ) > self::MAX_IDS ) ) {
			return self::rows_refusal( null === $token );
		}
		if ( null !== $token ) {
			return new self( self::FROM_SELECTION, $columns, $identifiers, $summary, $confirm_all, $token, [], null, [] );
		}
		if ( null !== $ids ) {
			$clean = self::ids( $ids );
			if ( null === $clean ) {
				return Refusal::invalid(
					'bulkcat_invalid_value',
					__( 'These products could not be exported. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					[ 'param' => 'ids' ]
				);
			}
			return new self( self::FROM_IDS, $columns, $identifiers, $summary, $confirm_all, '', $clean, null, [] );
		}

		$schema = new RouteSchema( $registry );
		$filter = array_intersect_key( $params, array_flip( [ ...self::READ_ARGS, ...array_keys( $registry->filterable() ) ] ) );
		try {
			$criteria = Criteria::from_array( $schema->criteria_input( $filter ) );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'These filters or this sort could not be used. Clear them and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		$request = new self( self::FROM_FILTER, $columns, $identifiers, $summary, $confirm_all, '', [], $criteria, $filter );
		if ( $request->whole_catalogue() && ! $confirm_all && ! $summary ) {
			return Refusal::invalid(
				'bulkcat_export_confirm_all',
				__( 'This would export the whole catalogue. Tick "Yes, export the whole catalogue", or add a search or filter, and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		return $request;
	}

	/**
	 * The refusal for too many ids, or ids sent together with a token: a
	 * plain sentence pointing to the dialog's two choices. The route's `ids`
	 * schema returns it too for more than MAX_IDS ids (maxItems), so core's
	 * own refusal carries it in its details.
	 *
	 * @param bool $limit Whether the limit applies (too many ids), so it is named in the data.
	 */
	public static function rows_refusal( bool $limit ): \WP_Error {
		return Refusal::invalid(
			'bulkcat_invalid_value',
			__( 'More than 1,000 products, or two kinds of rows, were sent to export. Choose Selected or All matching the current filters instead.', 'bulk-catalogue-manager-for-woocommerce' ),
			$limit ? [
				'param' => 'ids',
				'limit' => self::MAX_IDS,
			] : [ 'param' => 'ids' ]
		);
	}

	/**
	 * Whether this is the whole catalogue: a filter export whose parsed
	 * conditions hold no filter, a blank search and no parent, in any mode.
	 * A selection or an ID list never is, however large.
	 */
	public function whole_catalogue(): bool {
		return self::FROM_FILTER === $this->rows && null !== $this->criteria
			&& [] === $this->criteria->filters
			&& '' === trim( $this->criteria->search )
			&& 0 === $this->criteria->parent_id;
	}

	/**
	 * The file name's rows word.
	 */
	public function name(): string {
		if ( self::FROM_FILTER !== $this->rows ) {
			return ExportName::SELECTED;
		}
		return $this->whole_catalogue() ? ExportName::ALL : ExportName::FILTERED;
	}

	/**
	 * The column plan.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function plan( Registry $registry ): ColumnPlan {
		return new ColumnPlan( $registry, $this->columns, $this->identifiers );
	}

	/**
	 * The selection token from the form body or the JSON body, never from
	 * the query string (amendment 4); null when none was sent.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private static function token( \WP_REST_Request $request ): ?string {
		$json = $request->get_json_params();
		foreach ( [ $request->get_body_params(), is_array( $json ) ? $json : [] ] as $params ) {
			if ( array_key_exists( 'selection', $params ) && null !== $params['selection'] ) {
				return is_scalar( $params['selection'] ) ? (string) $params['selection'] : '';
			}
		}
		return null;
	}

	/**
	 * Explicit ids as positive integers, distinct and ascending; null when
	 * one is not a positive whole number.
	 *
	 * @param list<mixed> $ids Ids as sent.
	 * @return list<int>|null
	 */
	private static function ids( array $ids ): ?array {
		$clean = [];
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $id ) ) {
				$id = (int) $id;
			}
			if ( ! is_int( $id ) || $id < 1 ) {
				return null;
			}
			$clean[ $id ] = $id;
		}
		ksort( $clean );
		return array_values( $clean );
	}
}
