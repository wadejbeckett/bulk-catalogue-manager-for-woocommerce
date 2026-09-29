<?php
/**
 * Selection token rows (T100, SPEC §10, R11): a `v2.` token resolves to its
 * snapshot's members exactly as preview resolves it — inside one storage
 * transaction, the row locked for that one statement, with the current
 * lease — and nothing is renewed, extended or released; a 1.0 token gets
 * the reselect refusal; another user's or a tampered token the uniform 403;
 * a lapsed lease 409; a payload past its retention 410. The token is read
 * from the body or JSON only (amendment 4). The Selection object is dropped
 * once its ids are read, and presence is kept as an integer map
 * (amendment 7). Storage answers through the recording `$wpdb`; presence
 * runs the real SQL over the seeded catalogue.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Export\ExportRequest;
use BulkCatalogueManager\Export\ExportRows;
use BulkCatalogueManager\Export\IdRows;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

final class ExportRowsTokenTest extends TestCase {
	/**
	 * The owner.
	 */
	private const OWNER = 7;

	/**
	 * The snapshot row the store holds, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $row = null;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = self::OWNER;
		SeededCatalogue::seed(
			[
				// A variation whose product is binned (108): left out as Review's "No longer available".
				204 => [
					[
						'post_type'         => 'product_variation',
						'post_title'        => 'Old Scarf - Red',
						'post_status'       => 'publish',
						'post_parent'       => 108,
						'menu_order'        => 0,
						'comment_status'    => 'closed',
						'post_date_gmt'     => '2026-09-01 08:00:00',
						'post_modified_gmt' => '2026-09-01 08:00:00',
					],
					null,
					[],
					[],
				],
			]
		);
		$this->ready();
	}

	/**
	 * A complete, transactional schema 7 as FoundationStorage::ready() reads
	 * it, and the snapshot store answering its locked read.
	 */
	private function ready(): void {
		global $wpdb;
		update_option( Migrator::VERSION_OPTION, Migrator::SCHEMA_VERSION );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'e', 64 ) );
		$columns         = static function ( string $table ): array {
			$rows = [];
			foreach ( explode( "\n", Schema::TABLES[ $table ] ) as $line ) {
				if ( 1 === preg_match( '/^([a-z_]+) (.+?) (NOT NULL|NULL)[, ]/', $line, $match ) ) {
					$rows[] = [
						'Field' => $match[1],
						'Type'  => $match[2],
						'Null'  => 'NULL' === $match[3] ? 'YES' : 'NO',
					];
				}
			}
			return $rows;
		};
		$index           = static fn( string ...$names ): array => array_map(
			static fn( string $name, int $seq ): array => [
				'Column_name'  => $name,
				'Seq_in_index' => $seq + 1,
				'Non_unique'   => 0,
				'Sub_part'     => null,
			],
			$names,
			array_keys( $names )
		);
		$mine            = [
			'/information_schema\.TABLES/'                 => static fn(): array => array_map(
				static fn( string $name ): array => [
					'TABLE_NAME' => 'wp_' . $name,
					'ENGINE'     => 'InnoDB',
				],
				array_keys( Schema::TABLES )
			),
			'/^SHOW COLUMNS FROM `wp_bulkcat_selections`/' => $columns( Schema::SELECTIONS ),
			'/^SHOW COLUMNS FROM `wp_bulkcat_requests`/'   => $columns( Schema::REQUESTS ),
			'/^SHOW INDEX FROM `wp_bulkcat_selections`/'   => $index( 'id' ),
			'/^SHOW INDEX FROM `wp_bulkcat_requests`/'     => $index( 'user_id', 'uuid' ),
			'/^SELECT \* FROM `wp_bulkcat_selections` WHERE id/' => function ( string $sql ): ?array {
				$this->assertStringEndsWith( 'LIMIT 1 FOR UPDATE', $sql, 'The snapshot row is locked for its one statement, as preview reads it.' );
				return null !== $this->row && str_contains( $sql, "id = '" . $this->row['id'] . "' AND user_id = " . $this->row['user_id'] . ' ' ) ? $this->row : null;
			},
		];
		$wpdb->responses = $mine + $wpdb->responses;
	}

	/**
	 * Store a snapshot and answer its current token.
	 *
	 * @param array<string, list<int>> $members  Frozen membership.
	 * @param int                      $lease    Seconds of authorization left (negative: lapsed).
	 * @param int                      $retained Seconds of retention left (negative: past).
	 */
	private function snapshot( array $members, int $lease = 900, int $retained = 604800 ): string {
		$now       = time();
		$this->row = [
			'id'                       => str_repeat( 'a', 32 ),
			'user_id'                  => self::OWNER,
			'members'                  => (string) wp_json_encode( $members ),
			'object_count'             => count( $members['products'] ) + count( $members['variations'] ),
			'created_at'               => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			'authorization_epoch'      => 1,
			'authorization_expires_at' => gmdate( 'Y-m-d H:i:s', $now + $lease ),
			'retained_until'           => gmdate( 'Y-m-d H:i:s', $now + $retained ),
			'released_at'              => null,
		];
		return SnapshotToken::issue( $this->row );
	}

	/**
	 * Assert a refusal and that no row was read.
	 *
	 * @param mixed  $result Result.
	 * @param string $code   Code.
	 * @param int    $status Status.
	 */
	private function refused( mixed $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $result->get_error_message() );
		$presence = array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'p.post_parent AS parent_id' ) );
		$this->assertSame( [], $presence, 'No catalogue row is read after a refusal.' );
	}

	public function test_a_token_exports_exactly_its_members_in_ascending_order_including_off_screen_ones(): void {
		$token = $this->snapshot(
			[
				'products'   => [ 106, 101, 103 ],
				'variations' => [ 202, 201 ],
			]
		);
		$rows  = ExportRows::token( $token, self::OWNER, new Registry() );
		$this->assertInstanceOf( IdRows::class, $rows );
		$this->assertSame( [ 101, 103, 106, 201, 202 ], $rows->ids(), 'Whatever the view shows, every member, in ascending id order.' );
		$this->assertSame( 0, $rows->left_out() );
		$items = $rows->page( [ 'fields' => [ 'id', 'name' ] ], 1, 100 )['items'];
		$this->assertSame( [ 101, 103, 106, 201, 202 ], array_column( $items, 'id' ) );
	}

	public function test_a_product_that_matched_after_capture_is_not_exported(): void {
		// 102 exists and would match "Shirt" now; it was not captured, so it is never exported.
		$token = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			]
		);
		$this->assertSame( [ 101 ], ExportRows::token( $token, self::OWNER, new Registry() )->ids() );
	}

	public function test_a_v1_token_gets_the_reselect_refusal(): void {
		foreach ( [ 'abc123.0123456789abcdef', 'v1.x.y', '', 'V2.upper' ] as $token ) {
			$result = ExportRows::token( $token, self::OWNER, new Registry() );
			$this->refused( $result, 'bulkcat_invalid_selection', 403 );
			$this->assertSame( 'This selection has expired or can no longer be used. Choose the products again and try again.', $result->get_error_message() );
		}
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'bulkcat_selections' ) ), 'A 1.0 token never reaches storage.' );
	}

	public function test_another_users_or_a_tampered_token_gets_the_uniform_invalid_selection_refusal(): void {
		$token = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			]
		);
		$other = ExportRows::token( $token, 8, new Registry() );
		$this->refused( $other, 'bulkcat_invalid_selection', 403 );
		$tampered = ExportRows::token( substr( $token, 0, -1 ) . ( str_ends_with( $token, 'a' ) ? 'b' : 'a' ), self::OWNER, new Registry() );
		$this->refused( $tampered, 'bulkcat_invalid_selection', 403 );
		$this->assertSame( SnapshotToken::invalid()->get_error_message(), $other->get_error_message() );
		$this->assertSame( $other->get_error_message(), $tampered->get_error_message(), 'One sentence, revealing nothing about another user.' );
	}

	public function test_a_lapsed_lease_is_409_renew_required_and_nothing_is_renewed(): void {
		$token  = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			],
			-60
		);
		$result = ExportRows::token( $token, self::OWNER, new Registry() );
		$this->refused( $result, 'bulkcat_selection_renew_required', 409 );
		$this->assertSame( [], $GLOBALS['wpdb']->writes );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => 1 === preg_match( '/^(UPDATE|INSERT|DELETE|REPLACE)\b/', $sql ) ), 'Export never renews.' );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries, 'The refusal leaves the transaction without a change.' );
	}

	public function test_retention_past_its_limit_is_410(): void {
		$token  = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			],
			900,
			-60
		);
		$result = ExportRows::token( $token, self::OWNER, new Registry() );
		$this->refused( $result, 'bulkcat_selection_expired', 410 );
		$this->row = null;
		$this->refused( ExportRows::token( $token, self::OWNER, new Registry() ), 'bulkcat_selection_expired', 410 );
	}

	public function test_resolution_writes_nothing_to_the_selection_row(): void {
		$token  = $this->snapshot(
			[
				'products'   => [ 101, 103 ],
				'variations' => [ 201 ],
			]
		);
		$before = $this->row;
		$this->assertInstanceOf( IdRows::class, ExportRows::token( $token, self::OWNER, new Registry() ) );
		$this->assertSame( $before, $this->row );
		$this->assertSame( [], $GLOBALS['wpdb']->writes, 'No grant, receipt, lease, retention or release is written.' );
		$storage = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => in_array( $sql, [ 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ], true ) || str_contains( $sql, 'FROM `wp_bulkcat_selections` WHERE id' ) || 1 === preg_match( '/^(UPDATE|INSERT|DELETE|REPLACE)\b/', $sql ) ) );
		$this->assertSame( 'START TRANSACTION', $storage[0] );
		$this->assertStringContainsString( 'FOR UPDATE', $storage[1] );
		$this->assertSame( 'COMMIT', $storage[2] );
		$this->assertCount( 3, $storage, 'One locked read inside one transaction; the epoch, expiry, retention and release stay as they were.' );
	}

	public function test_left_out_counts_unavailable_members_as_review_does(): void {
		$token = $this->snapshot(
			[
				'products'   => [ 101, 108, 99999 ],
				'variations' => [ 201, 203, 204 ],
			]
		);
		$rows  = ExportRows::token( $token, self::OWNER, new Registry() );
		$this->assertSame( [ 101, 201 ], $rows->ids() );
		$this->assertSame( 4, $rows->left_out(), 'Binned 108, gone 99999, binned variation 203 and 204, whose product is binned.' );
	}

	public function test_a_presence_failure_is_not_a_selection_storage_refusal(): void {
		// Main Build L2: only the selection's own read is storage (503 bulkcat_request_storage, as preview answers);
		// a failure reading the catalogue afterwards is the export's own, answered by the controller.
		global $wpdb;
		$token                         = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			]
		);
		$page                          = $wpdb->responses['/^SELECT /'];
		$wpdb->responses['/^SELECT /'] = static function ( string $sql ) use ( $page ): mixed {
			if ( str_contains( $sql, 'p.post_parent AS parent_id' ) ) {
				$GLOBALS['wpdb']->last_error = 'Lost connection to the catalogue.';
				return [];
			}
			return $page( $sql );
		};
		try {
			$result = ExportRows::token( $token, self::OWNER, new Registry() );
			$this->fail( 'Answered ' . ( $result instanceof \WP_Error ? $result->get_error_code() : get_class( $result ) ) . ' for a presence failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'The selected products could not be read.', $error->getMessage() );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['logged'] ?? [], 'Nothing logged as a storage failure.' );
	}

	public function test_storage_that_is_not_ready_is_refused_before_any_row_is_read(): void {
		$token = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			]
		);
		update_option( Migrator::VERSION_OPTION, '6' );
		$result = ExportRows::token( $token, self::OWNER, new Registry() );
		$this->refused( $result, 'bulkcat_request_storage', 503 );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'FROM `wp_bulkcat_selections` WHERE id' ) ) );
	}

	public function test_the_token_is_read_from_the_body_or_json_only(): void {
		$body  = $this->snapshot(
			[
				'products'   => [ 101 ],
				'variations' => [],
			]
		);
		$other = 'v2.' . str_repeat( 'b', 32 ) . '.1.' . str_repeat( 'c', 64 );
		// A request double: its merged parameters carry a different token than its body, and it records get_param().
		$request = new class( $body, $other ) extends \WP_REST_Request {
			/**
			 * Keys asked for through get_param().
			 *
			 * @var list<string>
			 */
			public array $asked = [];

			public function __construct( private string $body_token, private string $merged_token ) {
				parent::__construct( 'POST', '/bulkcat/v1/export' );
				$this->set_body_params(
					[
						'columns'   => 'name',
						'selection' => $this->body_token,
					]
				);
			}

			public function get_param( string $key ): mixed {
				$this->asked[] = $key;
				return 'selection' === $key ? $this->merged_token : parent::get_param( $key );
			}

			public function get_params(): array {
				return [ 'selection' => $this->merged_token ] + parent::get_params();
			}
		};
		$parsed  = ExportRequest::from( $request, new Registry() );
		$this->assertInstanceOf( ExportRequest::class, $parsed );
		$this->assertSame( $body, $parsed->token, 'The body\'s token, not the merged parameters\' one.' );
		$this->assertNotContains( 'selection', $request->asked, 'get_param() is never asked for the token.' );

		// A token in the query string, alone or beside one in the body, is refused and never resolved.
		$resolved = 0;
		$resolve  = static function () use ( &$resolved ): Selection {
			++$resolved;
			return Selection::of_members(
				[
					'products'   => [ 101 ],
					'variations' => [],
				]
			);
		};
		foreach ( [
			[ 'columns' => 'name' ],
			[
				'columns'   => 'name',
				'selection' => $body,
			],
		] as $params ) {
			$query = new \WP_REST_Request( 'POST', '/bulkcat/v1/export' );
			$query->set_body_params( $params );
			$query->set_query_params( [ 'selection' => $body ] );
			$refused = ExportRequest::from( $query, new Registry() );
			$this->assertInstanceOf( \WP_Error::class, $refused );
			$this->assertSame( 'bulkcat_invalid_value', $refused->get_error_code() );
			if ( $refused instanceof ExportRequest ) {
				ExportRows::token( $refused->token, self::OWNER, new Registry(), $resolve );
			}
		}
		$this->assertSame( 0, $resolved );
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'bulkcat_selections' ) ) );
	}

	public function test_the_selection_object_is_released_once_its_ids_are_read(): void {
		global $wpdb;
		$weak    = null;
		$resolve = static function ( string $token, int $owner ) use ( &$weak ): Selection {
			unset( $token, $owner );
			$selection = Selection::of_members(
				[
					'products'   => [ 103, 101 ],
					'variations' => [ 202 ],
				]
			);
			$weak      = \WeakReference::create( $selection );
			return $selection;
		};
		$seen    = [];
		$page    = $wpdb->responses['/^SELECT /'];
		// Presence is the first catalogue statement: by then the Selection must be gone.
		$wpdb->responses['/^SELECT /'] = static function ( string $sql ) use ( &$weak, &$seen, $page ): mixed {
			if ( str_contains( $sql, 'p.post_parent AS parent_id' ) ) {
				$seen[] = null === $weak?->get();
			}
			return $page( $sql );
		};
		$rows                          = ExportRows::token( 'v2.' . str_repeat( 'a', 32 ) . '.1.' . str_repeat( 'f', 64 ), self::OWNER, new Registry(), $resolve );
		$this->assertInstanceOf( IdRows::class, $rows );
		$this->assertSame( [ true ], $seen, 'Dropped before presence was resolved.' );
		$this->assertNull( $weak->get() );
		$this->assertSame( [ 101, 103, 202 ], $rows->ids() );
	}

	public function test_presence_is_kept_as_an_integer_map(): void {
		$token   = $this->snapshot(
			[
				'products'   => [ 103, 101 ],
				'variations' => [ 202, 201 ],
			]
		);
		$present = ExportRows::token( $token, self::OWNER, new Registry() )->present();
		$this->assertSame(
			[
				101 => 0,
				103 => 0,
				201 => 103,
				202 => 103,
			],
			$present
		);
		foreach ( $present as $id => $parent ) {
			$this->assertTrue( is_int( $id ) && is_int( $parent ), 'Integers, not a list of small arrays.' );
		}
	}
}
