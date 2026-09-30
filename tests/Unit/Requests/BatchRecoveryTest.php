<?php
/**
 * Scheduling after acceptance (T83): a queued batch gets one chunk, a
 * finished one none, and a failed read of the batch is an error to retry,
 * never a silent skip.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\BatchRecovery;
use BulkCatalogueManager\Requests\CaptureIntent;
use BulkCatalogueManager\Requests\RequestService;
use PHPUnit\Framework\TestCase;

final class BatchRecoveryTest extends TestCase {
	private const READ = 'SELECT * FROM `wp_bulkcat_batches` WHERE batch_id = 9';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['scheduled'] = [];
	}

	/**
	 * A batch row as the database answers it.
	 *
	 * @param string $status Its status.
	 * @return array<string, ?string>
	 */
	private static function row( string $status ): array {
		return [
			'batch_id'      => '9',
			'uuid'          => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'user_id'       => '1',
			'created_gmt'   => '2026-09-28 10:00:00',
			'finished_gmt'  => null,
			'status'        => $status,
			'source'        => 'bulk',
			'undo_of'       => null,
			'operations'    => '[]',
			'selection'     => '{}',
			'total_items'   => '1',
			'done_items'    => '0',
			'changed_items' => '0',
			'failed_items'  => '0',
		];
	}

	/**
	 * An accepted outcome.
	 *
	 * @return array<string, mixed>
	 */
	private static function accepted(): array {
		return [
			'batch_id'    => 9,
			'status'      => 'queued',
			'total_items' => 1,
			'replayed'    => true,
		];
	}

	public function test_a_queued_batch_gets_one_chunk_and_a_finished_one_none(): void {
		$GLOBALS['wpdb']->responses[ self::READ ] = self::row( 'queued' );
		BatchRecovery::finish( self::accepted() );
		BatchRecovery::finish( self::accepted() );
		$this->assertSame( [ [ 'enqueue', 'bulkcat_run_chunk', [ 9 ], 'bulk-catalogue-manager-for-woocommerce', null ] ], $GLOBALS['bulkcat_test']['scheduled'], 'Resolved twice, queued once.' );

		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['scheduled']     = [];
		$GLOBALS['wpdb']->responses[ self::READ ] = self::row( 'complete' );
		$this->assertSame( 'complete', BatchRecovery::finish( self::accepted() )['status'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'], 'A finished batch is never queued again.' );
	}

	public function test_a_failed_read_is_an_error_to_retry_not_a_silent_skip(): void {
		// Review note: a failed status read used to look like a batch with nothing to schedule.
		$GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
		try {
			BatchRecovery::finish( self::accepted() );
			$this->fail( 'A failed read was taken for a batch with nothing to schedule.' );
		} catch ( \RuntimeException $error ) {
			// PHPUnit's own failure is a RuntimeException too: the message tells them apart.
			$this->assertStringContainsString( 'could not be read', $error->getMessage() );
		}
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
	}
	/** Ready storage double; the release payloads themselves are captured, not fabricated. */
	private static function ready(): void {
		update_option( Migrator::VERSION_OPTION, Migrator::SCHEMA_VERSION );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'e', 64 ) );
		$GLOBALS['bulkcat_test']['logged_in']                        = true;
		$GLOBALS['bulkcat_test']['caps']                             = [
			'manage_woocommerce' => true,
			'edit_products'      => true,
			'edit_post'          => true,
		];
		$GLOBALS['wpdb']->responses['/information_schema\\.TABLES/'] = array_map(
			static fn( string $name ): array => [
				'TABLE_NAME' => 'wp_' . $name,
				'ENGINE'     => 'InnoDB',
			],
			array_keys( Schema::TABLES )
		);
		foreach ( [
			Schema::SELECTIONS => [ 'id' ],
			Schema::REQUESTS   => [ 'user_id', 'uuid' ],
		] as $table => $keys ) {
			$columns = [];
			foreach ( explode( "\n", Schema::TABLES[ $table ] ) as $line ) {
				if ( 1 === preg_match( '/^([a-z_]+) (.+?) (NOT NULL|NULL)[, ]/', $line, $match ) ) {
					$columns[] = [
						'Field' => $match[1],
						'Type'  => $match[2],
						'Null'  => 'NULL' === $match[3] ? 'YES' : 'NO',
					];
				}
			}
			$GLOBALS['wpdb']->responses[ '/^SHOW COLUMNS FROM `wp_' . $table . '`/' ] = $columns;
			$GLOBALS['wpdb']->responses[ '/^SHOW INDEX FROM `wp_' . $table . '`/' ]   = array_map(
				static fn( string $key, int $index ): array => [
					'Column_name'  => $key,
					'Seq_in_index' => $index + 1,
					'Non_unique'   => 0,
					'Sub_part'     => null,
				],
				$keys,
				array_keys( $keys )
			);
		}
	}

	/** Re-sign the captured identity using only the unit installation's salt/epoch. */
	private static function request( array $case ): array {
		$receipt = $case['receipt'];
		$claims  = [
			'version'      => 1,
			'uuid'         => $receipt['uuid'],
			'operation'    => $receipt['operation'],
			'fingerprint'  => $receipt['fingerprint'],
			'owner'        => (int) $receipt['user_id'],
			'site'         => (string) get_current_blog_id(),
			'epoch'        => FoundationStorage::epoch(),
			'issued_at'    => strtotime( $receipt['issued_at'] . ' UTC' ),
			'accept_until' => strtotime( $receipt['accept_until'] . ' UTC' ),
		];
		$body    = bin2hex( (string) wp_json_encode( $claims ) );
		return [
			'uuid'          => $receipt['uuid'],
			'request_proof' => $body . '.' . hash_hmac( 'sha256', 'bulk-catalogue-manager-for-woocommerce/request/1/' . $body, wp_salt( 'auth' ) ),
			'intent'        => $case['intent'],
			'operation'     => 'batch.apply',
		];
	}

	public function test_captured_release_receipt_resolves_before_current_semantics_or_expiry(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/released-status-receipts.json' ), true );
		foreach ( $fixture['batches'] as $case ) {
			bulkcat_test_reset();
			self::ready();
			$batch                               = $case['batch'];
			$receipt                             = $case['receipt'];
			$GLOBALS['bulkcat_test']['user_id']  = (int) $receipt['user_id'];
			$read                                = 'SELECT * FROM `wp_bulkcat_requests` WHERE user_id = ' . $receipt['user_id'] . " AND uuid = '" . $receipt['uuid'] . "' LIMIT 1";
			$GLOBALS['wpdb']->responses[ $read ] = $receipt;
			$GLOBALS['wpdb']->responses[ 'SELECT * FROM `wp_bulkcat_batches` WHERE batch_id = ' . $batch['batch_id'] ] = $batch;
			$request = self::request( $case );
			$service = new RequestService( static fn(): int => strtotime( $receipt['accept_until'] . ' UTC' ) + 1 );
			foreach ( [ $service->resolve( $request ), $service->apply( $request ) ] as $result ) {
				$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
				$this->assertSame(
					[
						'batch_id'    => (int) $batch['batch_id'],
						'status'      => 'complete',
						'total_items' => 1,
						'replayed'    => true,
					],
					$result
				);
			}
			$this->assertSame( $receipt['fingerprint'], CaptureIntent::fingerprint( $request['intent'] ) );
			$stored = ( new BatchRepository() )->find_strict( (int) $batch['batch_id'] );
			$this->assertSame( $case['intent']['operations'], $stored['operations'] );
			$this->assertNull( $case['items'][0]['operations'], 'Captured release item inherits the batch operations.' );
			$this->assertSame( [], ( new BatchRepository() )->item_operations( (int) $batch['batch_id'], [ (int) $case['items'][0]['item_id'] ] ) );
			$this->assertSame( [], $GLOBALS['wpdb']->writes );
			$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] ?? [] );
			$this->assertNotContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );

			// Same captured canonical grant without its receipt must not adopt the new semantics.
			unset( $GLOBALS['wpdb']->responses[ $read ], $GLOBALS['wpdb']->responses[ 'SELECT * FROM `wp_bulkcat_batches` WHERE batch_id = ' . $batch['batch_id'] ] );
			$unaccepted                = $receipt;
			$unaccepted['result_id']   = '';
			$unaccepted['result_kind'] = '';
			$GLOBALS['wpdb']->responses[ $read . ' FOR UPDATE' ] = $unaccepted;
			$service                  = new RequestService( static fn(): int => strtotime( $receipt['issued_at'] . ' UTC' ) + 1 );
			$GLOBALS['wpdb']->queries = [];
			$refused                  = $service->apply( $request );
			$this->assertInstanceOf( \WP_Error::class, $refused );
			$this->assertSame( 'bulkcat_preview_stale', $refused->get_error_code() );
			$this->assertSame( 409, $refused->get_error_data()['status'] );
			$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
			$this->assertSame( [], array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, '`wp_posts`' ) ) ), 'Operation equivalence refuses before reading current selection or products.' );
			$raw = $case['intent'];
			unset( $raw['version'] );
			$issued = $service->issue( 'batch.apply', $raw );
			$this->assertTrue( is_array( $issued ) );
			$this->assertSame( 'set_status', $issued['intent']['operations'][0]['action'] );
			$this->assertNotSame( $receipt['fingerprint'], CaptureIntent::fingerprint( $issued['intent'] ) );
			$this->assertSame( [], $GLOBALS['wpdb']->writes, 'No replacement batch or item was created.' );
		}
	}
}
