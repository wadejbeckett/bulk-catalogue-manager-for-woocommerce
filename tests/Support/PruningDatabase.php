<?php
/**
 * Transactional storage double beneath the real pruning repository/service.
 * Records committed membership/receipts separately from each attempt's writes.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Schema;

final class PruningDatabase {
	public WpdbStub $db;
	public array $committed            = [
		'rows'     => [
			'aaaa' => 50,
			'bbbb' => 50,
		],
		'receipts' => [
			'aaaa' => 'accepted',
			'bbbb' => 'accepted',
		],
	];
	public array $working              = [];
	public array $attempt_starts       = [];
	public array $cutoffs              = [];
	public array $transaction_commands = [];
	public array $conflicts            = [ 1 ];
	public string $conflict_id         = 'aaaa';
	public int $error_code             = 1020;
	public string $rollback            = 'success';
	public bool $commit_ambiguous      = false;
	public bool $renew_on_conflict     = false;
	public bool $guard_lost_on_error   = false;
	public int $attempt                = 0;
	public int $deleted                = 0;

	public function __construct() {
		bulkcat_test_reset();
		$this->db      = $GLOBALS['wpdb'];
		$this->db->dbh = new TransactionConnection();
		update_option( Migrator::VERSION_OPTION, Migrator::SCHEMA_VERSION );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'e', 64 ) );
		$this->db->responses['/information_schema\\.TABLES/'] = array_map(
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
			$this->db->responses[ '/^SHOW COLUMNS FROM `wp_' . $table . '`/' ] = $columns;
			$this->db->responses[ '/^SHOW INDEX FROM `wp_' . $table . '`/' ]   = array_map(
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
		$this->db->responses['START TRANSACTION']                         = function (): bool {
			$this->clear_error();
			++$this->attempt;
			$this->transaction_commands[] = 'START';
			$this->working                = $this->committed;
			$this->attempt_starts[]       = $this->working;
			return true;
		};
		$this->db->responses['ROLLBACK']                                  = function (): bool {
			$this->transaction_commands[] = 'ROLLBACK';
			$this->clear_error();
			if ( 'failed' === $this->rollback ) {
				return false;
			}
			if ( 'lost' === $this->rollback ) {
				$this->db->dbh->closed = true;
				return false;
			}
			if ( 'replaced' === $this->rollback ) {
				$this->db->dbh = new TransactionConnection();
			}
			if ( 'reconnected' === $this->rollback ) {
				++$this->db->dbh->thread_id;
			}
			if ( 'unguarded' === $this->rollback ) {
				$this->db->reconnect_retries = 1;
			}
			$this->working = $this->committed;
			return true;
		};
		$this->db->responses['COMMIT']                                    = function (): bool {
			$this->clear_error();
			$this->transaction_commands[] = 'COMMIT';
			$this->committed              = $this->working;
			// The server committed but its reply was lost: repeating is unsafe.
			return ! $this->commit_ambiguous;
		};
		$this->db->responses['/^SELECT id FROM `wp_bulkcat_selections`/'] = function ( string $sql ): array {
			$this->clear_error();
			preg_match( "/retained_until <= '([^']+)'/", $sql, $match );
			$this->cutoffs[] = $match[1];
			$limit           = str_ends_with( $sql, 'LIMIT 100' ) ? 100 : PHP_INT_MAX;
			return array_slice( array_keys( array_filter( $this->working['rows'], static fn( int $until ): bool => $until <= strtotime( $match[1] . ' UTC' ) ) ), 0, $limit );
		};
		$this->db->responses['/^SELECT retained_until FROM `wp_bulkcat_selections`/'] = function ( string $sql ): ?string {
			$this->clear_error();
			preg_match( "/WHERE id = '([^']+)'/", $sql, $match );
			$id = $match[1];
			if ( $id === $this->conflict_id && in_array( $this->attempt, $this->conflicts, true ) ) {
				if ( $this->renew_on_conflict ) {
					$this->committed['rows'][ $id ] = 200;
				}
				$this->db->last_error = 'Record has changed since last read';
				$this->db->dbh->errno = $this->error_code;
				if ( $this->guard_lost_on_error ) {
					$this->db->reconnect_retries = 1;
				}
				return null;
			}
			return isset( $this->working['rows'][ $id ] ) ? gmdate( 'Y-m-d H:i:s', $this->working['rows'][ $id ] ) : null;
		};
		$this->db->responses["/^UPDATE `wp_bulkcat_requests` SET state = 'gone'/"]    = function ( string $sql ): int {
			$this->clear_error();
			preg_match( "/result_id = '([^']+)'/", $sql, $match );
			$this->working['receipts'][ $match[1] ] = 'gone';
			return 1;
		};
		$this->db->responses['/^DELETE FROM `wp_bulkcat_selections`/']                = function ( string $sql ): int {
			$this->clear_error();
			preg_match( "/WHERE id = '([^']+)'/", $sql, $match );
			unset( $this->working['rows'][ $match[1] ] );
			++$this->deleted;
			return 1;
		};
	}

	private function clear_error(): void {
		$this->db->last_error = '';
		$this->db->dbh->errno = 0;
	}
}
