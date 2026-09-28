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

use BulkCatalogueManager\Requests\BatchRecovery;
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
}
