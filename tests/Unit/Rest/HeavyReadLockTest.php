<?php
/**
 * SECURITY-3: the two reads that lift PHP's time limit, a change's conflict
 * count (`GET /batches/{id}?reversibility=count`) and a history export, run
 * one at a time per user. A second one from the same user while the first
 * is still working is refused with 429 instead of tying up another worker.
 * The lock is a MySQL named lock, so a request that dies releases it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Rest\BatchesController;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\HeavyReadLock;
use PHPUnit\Framework\TestCase;

final class HeavyReadLockTest extends TestCase {
	private const USER = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
	}

	private static function busy( string $kind ): void {
		$GLOBALS['wpdb']->responses[ sprintf( "SELECT GET_LOCK('%s', 0)", HeavyReadLock::name( $kind, self::USER ) ) ] = '0';
	}

	private static function released( string $kind ): bool {
		return in_array( sprintf( "SELECT RELEASE_LOCK('%s')", HeavyReadLock::name( $kind, self::USER ) ), $GLOBALS['wpdb']->queries, true );
	}

	private static function count_request( bool $count ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/batches/9' );
		$request->set_param( 'id', 9 );
		if ( $count ) {
			$request->set_param( 'reversibility', 'count' );
		}
		return $request;
	}

	public function test_a_second_conflict_count_from_the_same_user_is_refused_while_one_runs(): void {
		self::busy( HeavyReadLock::COUNT );
		$refused = ( new BatchesController() )->get_item( self::count_request( true ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_busy', $refused->get_error_code() );
		$this->assertSame( 429, $refused->get_error_data()['status'] );

		$plain = ( new BatchesController() )->get_item( self::count_request( false ) );
		$this->assertNotSame( 'bulkcat_busy', $plain instanceof \WP_Error ? $plain->get_error_code() : '', 'A read that does not count is never held back.' );
	}

	public function test_a_conflict_count_takes_and_releases_the_users_lock(): void {
		( new BatchesController() )->get_item( self::count_request( true ) );
		$this->assertTrue( self::released( HeavyReadLock::COUNT ) );
	}

	public function test_a_second_history_export_from_the_same_user_is_refused_while_one_streams(): void {
		$export  = new ExportController( new Fields() );
		$request = new \WP_REST_Request( 'GET', '/bulkcat/v1/export' );
		$request->set_param( 'source', 'batches' );
		$request->set_param( 'columns', [ 'batch_id' ] );

		$first = $export->get_items( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$this->assertFalse( self::released( HeavyReadLock::EXPORT ), 'The lock is held until the file is streamed.' );

		self::busy( HeavyReadLock::EXPORT );
		$second = ( new ExportController( new Fields() ) )->get_items( $request );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 429, $second->get_error_data()['status'] );

		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertTrue( $export->serve( false, $first, $request, null, $stream ) );
		$this->assertTrue( self::released( HeavyReadLock::EXPORT ), 'Streaming the file releases the lock.' );
	}

	public function test_without_named_locks_the_read_still_runs(): void {
		$GLOBALS['wpdb']->responses[ sprintf( "SELECT GET_LOCK('%s', 0)", HeavyReadLock::name( HeavyReadLock::COUNT, self::USER ) ) ] = null;
		$answer = ( new BatchesController() )->get_item( self::count_request( true ) );
		$this->assertNotSame( 'bulkcat_busy', $answer instanceof \WP_Error ? $answer->get_error_code() : '' );
	}

	public function test_the_lock_is_per_user_and_per_kind(): void {
		$this->assertNotSame( HeavyReadLock::name( HeavyReadLock::COUNT, 1 ), HeavyReadLock::name( HeavyReadLock::COUNT, 2 ) );
		$this->assertNotSame( HeavyReadLock::name( HeavyReadLock::COUNT, 1 ), HeavyReadLock::name( HeavyReadLock::EXPORT, 1 ) );
		$this->assertTrue( strlen( HeavyReadLock::name( HeavyReadLock::EXPORT, PHP_INT_MAX ) ) <= 64, 'MySQL caps a lock name at 64 characters.' );
	}
}
