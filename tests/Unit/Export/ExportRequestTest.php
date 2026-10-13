<?php
/**
 * The rules of a catalogue export request (T99, SPEC §10): exactly one row
 * source (a selection token from the body, at most 1,000 ids, or the filter
 * arguments); a token in the query string refused; the whole catalogue,
 * decided on the parsed conditions in every mode, needs `confirm_all`
 * unless the request only asks for the summary (R6, amendment 2); only the
 * catalogue is posted (R10). Requests go through `RouteSchema::export_args()`
 * exactly as core dispatches them, on a test route: the export route itself
 * is registered in T101.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\ExportName;
use BulkCatalogueManager\Export\ExportRequest;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\RouteSchema;
use PHPUnit\Framework\TestCase;

final class ExportRequestTest extends TestCase {
	/**
	 * A well-formed v2 token shape; its signature is never checked here.
	 */
	private const TOKEN = 'v2.0123456789abcdef0123456789abcdef.1.0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                  = true;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		$registry = new Registry();
		register_rest_route(
			Access::REST_NAMESPACE,
			'/export-request',
			[
				'methods'             => 'POST',
				'callback'            => static fn( \WP_REST_Request $request ): mixed => ExportRequest::from( $request, $registry ),
				'permission_callback' => [ Access::class, 'can_read' ],
				'args'                => ( new RouteSchema( $registry ) )->export_args(),
			]
		);
	}

	/**
	 * Post a request: a form body, a query string, or a JSON body.
	 *
	 * @param array<string, mixed>      $body  Form body.
	 * @param array<string, mixed>      $query Query string.
	 * @param array<string, mixed>|null $json  JSON body instead of the form.
	 */
	private static function send( array $body, array $query = [], ?array $json = null ): ExportRequest|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/bulkcat/v1/export-request' );
		$request->set_body_params( $body );
		$request->set_query_params( $query );
		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $json ) );
		}
		$result = rest_get_server()->dispatch( $request );
		return $result instanceof \WP_Error ? $result : $result->get_data();
	}

	/**
	 * Assert a plain 400 refusal before anything else happens.
	 *
	 * @param mixed  $result Result.
	 * @param string $code   Expected code.
	 */
	private function refused( mixed $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $result->get_error_message() );
		return $result;
	}

	public function test_ids_and_a_selection_together_are_refused(): void {
		$refusal = $this->refused(
			self::send(
				[
					'columns'   => 'name',
					'selection' => self::TOKEN,
					'ids'       => '1,2',
				]
			),
			'bulkcat_invalid_value'
		);
		$this->assertSame( 'More than 1,000 products, or two kinds of rows, were sent to export. Choose Selected or All matching the current filters instead.', $refusal->get_error_message() );
		$this->assertSame( 'ids', $refusal->get_error_data()['param'] );
		$this->refused(
			self::send(
				[],
				[],
				[
					'columns'   => [ 'name' ],
					'selection' => self::TOKEN,
					'ids'       => [ 3 ],
				]
			),
			'bulkcat_invalid_value'
		);
	}

	public function test_more_than_1000_ids_are_refused_and_1000_are_accepted(): void {
		// The schema caps the list (lead review minor): core refuses it before the handler, carrying the plain refusal.
		$this->assertSame( 1000, ( new RouteSchema( new Registry() ) )->export_args()['ids']['maxItems'] );
		foreach ( [ implode( ',', range( 1, 1001 ) ), range( 1, 1001 ) ] as $ids ) {
			$refusal = $this->refused(
				self::send(
					[
						'columns' => 'name',
						'ids'     => $ids,
					]
				),
				'rest_invalid_param'
			);
			$this->assertSame(
				[
					'code'    => 'bulkcat_invalid_value',
					'message' => 'More than 1,000 products, or two kinds of rows, were sent to export. Choose Selected or All matching the current filters instead.',
					'data'    => [
						'status' => 400,
						'param'  => 'ids',
						'limit'  => 1000,
					],
				],
				$refusal->get_error_data()['details']['ids']
			);
		}
		// The handler keeps its own check for a caller that skips core's validation.
		$direct = new \WP_REST_Request( 'POST', '/bulkcat/v1/export-request' );
		$direct->set_body_params(
			[
				'columns' => 'name',
				'ids'     => range( 1, 1001 ),
			]
		);
		$this->assertSame(
			[
				'status' => 400,
				'param'  => 'ids',
				'limit'  => 1000,
			],
			$this->refused( ExportRequest::from( $direct, new Registry() ), 'bulkcat_invalid_value' )->get_error_data()
		);
		$accepted = self::send(
			[
				'columns' => 'name',
				'ids'     => implode( ',', array_reverse( range( 1, 1000 ) ) ),
			]
		);
		$this->assertInstanceOf( ExportRequest::class, $accepted );
		$this->assertSame( ExportRequest::FROM_IDS, $accepted->rows );
		$this->assertSame( range( 1, 1000 ), $accepted->ids, 'Sorted ascending.' );
		$this->assertSame( ExportName::SELECTED, $accepted->name() );
	}

	public function test_ids_accept_a_list_or_one_comma_separated_field(): void {
		$field = self::send(
			[
				'columns' => 'name,sku',
				'ids'     => '5,3,5,9',
			]
		);
		$this->assertSame( [ 3, 5, 9 ], $field->ids, 'Duplicates collapse.' );
		$this->assertSame( [ 'name', 'sku' ], $field->columns );
		$list = self::send(
			[
				'columns' => [ 'name' ],
				'ids'     => [ '5', 3 ],
			]
		);
		$this->assertSame( [ 3, 5 ], $list->ids );
		$json = self::send(
			[],
			[],
			[
				'columns' => [ 'sku' ],
				'ids'     => [ 9, 4 ],
				'summary' => true,
			]
		);
		$this->assertSame( [ 4, 9 ], $json->ids );
		$this->assertTrue( $json->summary );
		$this->assertSame(
			'rest_invalid_param',
			self::send(
				[
					'columns' => 'name',
					'ids'     => '5,x',
				]
			)->get_error_code()
		);
		$this->assertSame(
			'rest_invalid_param',
			self::send(
				[
					'columns' => 'name',
					'ids'     => '0',
				]
			)->get_error_code()
		);
	}

	public function test_a_selection_in_the_query_string_is_refused(): void {
		foreach ( [
			[ 'columns' => 'name' ],
			[
				'columns'   => 'name',
				'selection' => self::TOKEN,
			],
		] as $body ) {
			$refusal = $this->refused( self::send( $body, [ 'selection' => self::TOKEN ] ), 'bulkcat_invalid_value' );
			$this->assertSame( 'The selected products must not be sent in the page address. Reload the catalogue and export again.', $refusal->get_error_message() );
			$this->assertSame( 'selection', $refusal->get_error_data()['param'] );
		}
		$body = self::send(
			[
				'columns'   => 'name',
				'selection' => self::TOKEN,
			]
		);
		$this->assertSame( ExportRequest::FROM_SELECTION, $body->rows );
		$this->assertSame( self::TOKEN, $body->token );
		$json = self::send(
			[],
			[],
			[
				'columns'   => [ 'name' ],
				'selection' => self::TOKEN,
			]
		);
		$this->assertSame( self::TOKEN, $json->token, 'The summary sends it as JSON.' );
	}

	public function test_an_empty_filter_needs_confirm_all_in_every_mode(): void {
		foreach ( Criteria::MODES as $mode ) {
			$refusal = $this->refused(
				self::send(
					[
						'columns' => 'name',
						'mode'    => $mode,
					]
				),
				'bulkcat_export_confirm_all'
			);
			$this->assertSame( 'This would export the whole catalogue. Tick "Yes, export the whole catalogue", or add a search or filter, and try again.', $refusal->get_error_message() );
			$confirmed = self::send(
				[
					'columns'     => 'name',
					'mode'        => $mode,
					'confirm_all' => '1',
				]
			);
			$this->assertInstanceOf( ExportRequest::class, $confirmed, $mode );
			$this->assertTrue( $confirmed->whole_catalogue() );
			$this->assertSame( ExportName::ALL, $confirmed->name() );
			$this->assertSame( $mode, $confirmed->criteria->mode );
		}
		$this->refused(
			self::send(
				[
					'columns'     => 'name',
					'confirm_all' => '0',
				]
			),
			'bulkcat_export_confirm_all'
		);
	}

	public function test_empty_valued_arguments_are_refused_or_still_need_confirm_all(): void {
		$cases = [
			'search=%20'                     => [ 'search' => ' ' ],
			'search of tabs'                 => [ 'search' => "\t\t" ],
			'category_ids with no values'    => [
				'category_ids' => [
					'operator' => 'isAny',
					'value'    => [],
				],
			],
			'category_ids with no value key' => [ 'category_ids' => [ 'operator' => 'isAny' ] ],
			'stock_status empty list'        => [
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [],
				],
			],
			'parent=0'                       => [
				'mode'   => 'variations',
				'parent' => '0',
			],
		];
		foreach ( $cases as $case => $params ) {
			$result = self::send( [ 'columns' => 'name' ] + $params );
			$this->assertInstanceOf( \WP_Error::class, $result, $case );
			$this->assertContains( $result->get_error_code(), [ 'rest_invalid_param', 'bulkcat_invalid_value', 'bulkcat_export_confirm_all' ], $case );
			$this->assertSame( 400, $result->get_error_data()['status'], $case );
			// And confirming never turns one of them into a narrower export than the whole catalogue.
			$confirmed = self::send(
				[
					'columns'     => 'name',
					'confirm_all' => '1',
				] + $params
			);
			$this->assertTrue( $confirmed instanceof \WP_Error || $confirmed->whole_catalogue(), $case );
		}
		$this->assertSame(
			'bulkcat_export_confirm_all',
			self::send(
				[
					'columns' => 'name',
					'search'  => ' ',
				]
			)->get_error_code()
		);
	}

	public function test_a_search_filter_category_or_parent_is_not_the_whole_catalogue(): void {
		$cases = [
			[ 'search' => 'shirt' ],
			[
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [ 'instock' ],
				],
			],
			[
				'category_ids' => [
					'operator' => 'isAny',
					'value'    => [ 10 ],
				],
			],
			[
				'mode'   => 'variations',
				'parent' => '103',
			],
		];
		foreach ( $cases as $params ) {
			$request = self::send( [ 'columns' => 'name' ] + $params );
			$this->assertInstanceOf( ExportRequest::class, $request, (string) wp_json_encode( $params ) );
			$this->assertSame( ExportRequest::FROM_FILTER, $request->rows );
			$this->assertFalse( $request->whole_catalogue() );
			$this->assertSame( ExportName::FILTERED, $request->name() );
		}
		$read = self::send(
			[
				'columns'      => 'name',
				'search'       => 'shirt',
				'orderby'      => 'sku',
				'order'        => 'desc',
				'stock_status' => [
					'operator' => 'isAny',
					'value'    => [ 'instock' ],
				],
			]
		)->filter;
		$this->assertSame( 'shirt', $read['search'] );
		$this->assertSame( [ 'sku' ], $read['orderby'] );
		$this->assertSame( [ 'desc' ], $read['order'] );
		$this->assertSame( 'products', $read['mode'] );
		$this->assertSame( 'isAny', $read['stock_status']['operator'] );
		foreach ( [ 'columns', 'identifiers', 'selection', 'ids', 'confirm_all', 'summary', 'source', 'page', 'per_page', 'fields' ] as $own ) {
			$this->assertArrayNotHasKey( $own, $read, "$own is not a /products read argument." );
		}
	}

	public function test_a_selection_or_ids_never_need_confirm_all(): void {
		$filters   = [
			'search'       => 'shirt',
			'mode'         => 'variations',
			'stock_status' => [
				'operator' => 'isAny',
				'value'    => [ 'instock' ],
			],
		];
		$selection = self::send( [ 'columns' => 'name' ] + [ 'selection' => self::TOKEN ] );
		$this->assertInstanceOf( ExportRequest::class, $selection );
		$this->assertFalse( $selection->whole_catalogue() );
		$ids = self::send( [ 'columns' => 'name' ] + [ 'ids' => '7' ] + $filters );
		$this->assertInstanceOf( ExportRequest::class, $ids );
		$this->assertSame( ExportRequest::FROM_IDS, $ids->rows );
		$this->assertNull( $ids->criteria, 'The filter arguments are ignored: an id list is never narrowed or widened (R10).' );
		$this->assertSame( [], $ids->filter );
		$this->assertSame( ExportName::SELECTED, $ids->name() );
	}

	public function test_a_summary_does_not_need_confirm_all(): void {
		$summary = self::send(
			[
				'columns' => 'name',
				'summary' => 'true',
			]
		);
		$this->assertInstanceOf( ExportRequest::class, $summary );
		$this->assertTrue( $summary->summary );
		$this->assertTrue( $summary->whole_catalogue(), 'The dialog is told, and asks for the confirmation itself.' );
		$this->assertFalse( $summary->confirm_all );
		$this->assertFalse( $summary->identifiers );
		$on = self::send(
			[],
			[],
			[
				'columns'     => [ 'name' ],
				'summary'     => true,
				'identifiers' => true,
			]
		);
		$this->assertTrue( $on->identifiers );
	}

	public function test_a_history_source_is_refused_on_post(): void {
		foreach ( [ 'changes', 'audit', 'batches', 'anything' ] as $source ) {
			$refusal = $this->refused(
				self::send(
					[
						'columns' => 'name',
						'search'  => 'x',
						'source'  => $source,
					]
				),
				'bulkcat_invalid_value'
			);
			$this->assertSame( 'This request contains an unknown option. Reload the catalogue and try again.', $refusal->get_error_message(), $source );
		}
		$this->assertInstanceOf(
			ExportRequest::class,
			self::send(
				[
					'columns' => 'name',
					'search'  => 'x',
					'source'  => 'products',
				]
			)
		);
	}

	public function test_the_columns_are_checked_as_today(): void {
		$this->assertSame( 'rest_missing_callback_param', self::send( [ 'search' => 'x' ] )->get_error_code() );
		$this->assertSame( 'rest_invalid_param', self::send( [ 'columns' => 'name,no_such_field' ] )->get_error_code() );
		$this->assertSame( 'rest_invalid_param', self::send( [ 'columns' => 'batch_id' ] )->get_error_code(), 'History columns are not posted.' );
		$refusal = $this->refused(
			self::send(
				[
					'columns' => 'name,name',
					'search'  => 'x',
				]
			),
			'bulkcat_invalid_value'
		);
		$this->assertSame( 'columns', $refusal->get_error_data()['param'] );
	}
}
