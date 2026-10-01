<?php
/**
 * Only an acknowledged pre-commit conflict permits a complete pruning retry.
 * These exercise real repository, receipt, service and transaction behavior.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Requests\RequestRepository;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\SnapshotRepository;
use BulkCatalogueManager\Tests\Support\PruningDatabase;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/transaction-connection-stubs.php';

final class PruningRetryTest extends TestCase {
	private function prune( ?int &$deleted = null, ?\Closure $clock = null ): ?\Throwable {
		try {
			$deleted = ( new RequestService( $clock ?? static fn(): int => 100 ) )->prune_selections();
			return null;
		} catch ( \Throwable $error ) {
			return $error;
		}
	}

	public function test_conflict_retries_the_complete_transaction_once_with_the_same_cutoff(): void {
		$f           = new PruningDatabase();
		$clock_calls = 0;
		$error       = $this->prune(
			$deleted,
			static function () use ( &$clock_calls ): int {
				return 100 + $clock_calls++;
			}
		);
		$this->assertNull( $error, $error?->getMessage() ?? '' );
		$this->assertSame( 2, $deleted );
		$this->assertSame( 2, $f->attempt );
		$this->assertSame( 1, $clock_calls );
		$this->assertSame( [ '1970-01-01 00:01:40', '1970-01-01 00:01:40' ], $f->cutoffs );
		$this->assertSame( [ 'START', 'ROLLBACK', 'START', 'COMMIT' ], $f->transaction_commands );
		$this->assertSame(
			[
				'rows'     => [],
				'receipts' => [
					'aaaa' => 'gone',
					'bbbb' => 'gone',
				],
			],
			$f->committed
		);
		$this->assertSame( 5, $f->db->reconnect_retries );
	}

	public function test_repeated_conflict_stops_after_two_attempts_without_committing(): void {
		$f            = new PruningDatabase();
		$f->conflicts = [ 1, 2, 3 ];
		$before       = $f->committed;
		$this->assertInstanceOf( \RuntimeException::class, $this->prune( $deleted ) );
		$this->assertSame( 2, $f->attempt );
		$this->assertSame( [ 'START', 'ROLLBACK', 'START', 'ROLLBACK' ], $f->transaction_commands );
		$this->assertSame( $before, $f->committed );
		$this->assertSame( 5, $f->db->reconnect_retries );
	}

	public function test_prior_deletion_and_tombstone_roll_back_before_rediscovery_skips_a_renewed_row(): void {
		$f                    = new PruningDatabase();
		$f->conflict_id       = 'bbbb';
		$f->renew_on_conflict = true;
		$error                = $this->prune( $deleted );
		$this->assertNull( $error, $error?->getMessage() ?? '' );
		$this->assertSame( 1, $deleted );
		$this->assertSame(
			[
				'rows'     => [
					'aaaa' => 50,
					'bbbb' => 200,
				],
				'receipts' => [
					'aaaa' => 'accepted',
					'bbbb' => 'accepted',
				],
			],
			$f->attempt_starts[1]
		);
		$this->assertSame(
			[
				'rows'     => [ 'bbbb' => 200 ],
				'receipts' => [
					'aaaa' => 'gone',
					'bbbb' => 'accepted',
				],
			],
			$f->committed
		);
	}

	public function test_one_successful_attempt_commits_at_most_one_hundred_deletions(): void {
		$f            = new PruningDatabase();
		$f->conflicts = [];
		$f->committed = [
			'rows'     => [],
			'receipts' => [],
		];
		for ( $i = 0; $i < 101; ++$i ) {
			$id                              = sprintf( '%04d', $i );
			$f->committed['rows'][ $id ]     = 50;
			$f->committed['receipts'][ $id ] = 'accepted';
		}
		$this->assertNull( $this->prune( $deleted ) );
		$this->assertSame( 100, $deleted );
		$this->assertCount( 1, $f->committed['rows'] );
		$this->assertSame( 1, $f->attempt );
	}

	public function test_unknown_error_identity_never_retries_even_with_the_same_human_message(): void {
		foreach ( [ 0, 1213, 2013 ] as $code ) {
			$f             = new PruningDatabase();
			$f->error_code = $code;
			$before        = $f->committed;
			$this->assertInstanceOf( \RuntimeException::class, $this->prune( $deleted ) );
			$this->assertSame( 1, $f->attempt );
			$this->assertSame( $before, $f->committed );
		}
	}

	public function test_failed_or_unconfirmed_rollback_never_retries(): void {
		foreach ( [ 'failed', 'lost', 'replaced', 'reconnected', 'unguarded' ] as $mode ) {
			$f           = new PruningDatabase();
			$f->rollback = $mode;
			$before      = $f->committed;
			$this->assertInstanceOf( \RuntimeException::class, $this->prune( $deleted ), $mode );
			$this->assertSame( 1, $f->attempt, $mode );
			$this->assertSame( $before, $f->committed, $mode );
			$this->assertSame( 5, $f->db->reconnect_retries, $mode );
		}
	}

	public function test_an_unguarded_connection_at_the_error_does_not_qualify(): void {
		$f                      = new PruningDatabase();
		$f->guard_lost_on_error = true;
		$this->assertInstanceOf( \RuntimeException::class, $this->prune( $deleted ) );
		$this->assertSame( 1, $f->attempt );
		$this->assertSame( 5, $f->db->reconnect_retries );
	}

	public function test_ambiguous_commit_never_replays_completed_cleanup(): void {
		$f                   = new PruningDatabase();
		$f->conflicts        = [];
		$f->commit_ambiguous = true;
		$error               = $this->prune( $deleted );
		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertStringContainsString( 'Uncertain transaction outcome', $error->getMessage() );
		$this->assertSame( [ 'START', 'COMMIT', 'ROLLBACK' ], $f->transaction_commands );
		$this->assertSame( [], $f->committed['rows'] );
		$this->assertSame( 1, $f->attempt );
		$this->assertSame( 5, $f->db->reconnect_retries );
	}

	public function test_storage_never_retries_a_callback_even_for_this_conflict(): void {
		$f     = new PruningDatabase();
		$error = null;
		try {
			FoundationStorage::atomic( static fn(): int => ( new SnapshotRepository() )->prune( 100, new RequestRepository() ) );
		} catch ( \Throwable $caught ) {
			$error = $caught;
		}
		$this->assertInstanceOf( \RuntimeException::class, $error );
		$this->assertSame( 1, $f->attempt );
		$this->assertSame( [ 'START', 'ROLLBACK' ], $f->transaction_commands );
	}

	public function test_ordinary_error_return_and_nested_transaction_behavior_are_preserved(): void {
		$f     = new PruningDatabase();
		$error = new \WP_Error( 'unchanged', 'unchanged' );
		$this->assertSame( $error, FoundationStorage::atomic( static fn() => $error ) );
		$this->assertSame( 5, $f->db->reconnect_retries );
		$caught = null;
		try {
			FoundationStorage::atomic( static fn() => FoundationStorage::atomic( static fn() => 1 ) );
		} catch ( \RuntimeException $nested ) {
			$caught = $nested;
		}
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertStringContainsString( 'Nested', $caught->getMessage() );
		$this->assertSame( 2, $f->attempt );
		$this->assertSame( 5, $f->db->reconnect_retries );
	}
}
