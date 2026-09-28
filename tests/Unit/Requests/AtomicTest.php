<?php
/**
 * A broken connection must not replay transactional statements in autocommit mode.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Data\FoundationStorage;
use PHPUnit\Framework\TestCase;
final class AtomicTest extends TestCase {
	public function test_transaction_disables_reconnection_and_restores_it_after_failure(): void {
		bulkcat_test_reset();
		$GLOBALS['wpdb']->reconnect_retries = 5;
		try {
			FoundationStorage::atomic(
				function (): void {
					$this->assertSame( 0, $GLOBALS['wpdb']->reconnect_retries, 'wpdb must not reconnect/replay a statement outside its lost transaction.' );
					throw new \RuntimeException( 'body failed' );
				}
			);
			$this->fail( 'Body failure was swallowed.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'body failed', $error->getMessage() );
		}
		$this->assertSame( 5, $GLOBALS['wpdb']->reconnect_retries );
		$this->assertSame( [ 'START TRANSACTION', 'ROLLBACK' ], $GLOBALS['wpdb']->queries );
	}
}
