<?php
/**
 * The seeded catalogue plus the three batch tables in memory for the T37
 * tests: a single-object write creates its batch row (insert()), writes its
 * item (the INSERT BatchRepository::materialise_items() prepares), moves the
 * batch to running and on to its final status (update() with a status
 * condition), claims the item with the runner's one-UPDATE claim and reads
 * it back by token, marks it, adds the counters and journals (insert() on
 * the changes table). Every statement the write makes on those tables is
 * answered here; everything else falls through to the seeded catalogue's
 * SqlEvaluator. update() and insert() calls are folded in before each read.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';
require_once __DIR__ . '/StoredProduct.php';

final class InlineStore {
	/**
	 * Batch rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $batches = [];

	/**
	 * Item rows by item id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $items = [];

	/**
	 * Journal rows, in order, values decoded.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $changes = [];

	/**
	 * $wpdb->writes already folded in.
	 */
	private int $seen = 0;

	/**
	 * insert() calls so far, as the stub numbers insert_id.
	 */
	private int $inserts = 0;

	/**
	 * Seed the catalogue and answer the batch tables ahead of it.
	 */
	public function __construct() {
		global $wpdb;
		SeededCatalogue::seed();
		$items           = '`wp_bulkcat_batch_items`';
		$batches         = '`wp_bulkcat_batches`';
		$changes         = '`wp_bulkcat_changes`';
		$wpdb->responses = [
			"/^SELECT \\* FROM $batches WHERE batch_id = (\\d+)$/" => fn( string $sql ): ?array => $this->find( $sql ),
			"/^INSERT INTO $items/"                => fn( string $sql ): int => $this->insert_items( $sql ),
			"/^UPDATE $items SET claim_token = '/" => fn( string $sql ): int => $this->claim( $sql ),
			"/^SELECT item_id, object_id, object_type, attempts FROM $items/" => fn( string $sql ): array => $this->claimed( $sql ),
			"/^UPDATE $items SET state = '\\w+', message = .* AND item_id IN /s" => fn( string $sql ): int => $this->mark( $sql ),
			"/^UPDATE $batches SET done_items = /" => fn( string $sql ): int => $this->count_up( $sql ),
			"/^SELECT change_id FROM $changes WHERE batch_id = (\\d+) AND object_id = (\\d+) LIMIT 1$/" => fn( string $sql ): ?string => $this->has_object( $sql ),
			'/^DELETE FROM `(wp_bulkcat_batches|wp_bulkcat_batch_items)`/' => fn(): int => 1,
		] + $wpdb->responses;
	}


	/** Optional transactional request store, used only by receipt transport tests. */
	public array $receipts          = [];
	public array $faults            = [];
	public int $commits             = 0;
	public ?\Closure $before_commit = null;
	public ?\Closure $after_claim   = null;
	private ?array $transaction     = null;

	public static function ready(): void {
		update_option( \BulkCatalogueManager\Data\Migrator::VERSION_OPTION, \BulkCatalogueManager\Data\Migrator::SCHEMA_VERSION );
		update_option( \BulkCatalogueManager\Data\FoundationStorage::EPOCH_OPTION, str_repeat( 'e', 64 ) );
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
			array_keys( \BulkCatalogueManager\Data\Schema::TABLES )
		);
		foreach ( [
			\BulkCatalogueManager\Data\Schema::SELECTIONS => [ 'id' ],
			\BulkCatalogueManager\Data\Schema::REQUESTS   => [ 'user_id', 'uuid' ],
		] as $table => $keys ) {
			$columns = [];
			foreach ( explode( "\n", \BulkCatalogueManager\Data\Schema::TABLES[ $table ] ) as $line ) {
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


	public function enable_receipts(): void {
		global $wpdb;
		$catalogue_responses = $wpdb->responses;
		$wpdb->responses     = [];
		self::ready();
		$wpdb->responses += [
			'/^SELECT COUNT\(\*\) FROM `wp_bulkcat_batch_items` WHERE batch_id/' => fn(): int => count( $this->items ),
			'/^SELECT (MAX\(claimed_gmt\)|claimed_gmt) FROM/' => null,
			'/^SELECT .* FROM `wp_bulkcat_changes` .*GROUP BY/' => [],
			'/^SELECT .* FROM `wp_bulkcat_batch_items`(?: AS i)? .*GROUP BY/' => [],
			'/^SELECT .* FROM `wp_bulkcat_batches` .*undo_of/' => [],
		];
		$wpdb->responses += $catalogue_responses;
		$wpdb->responses  = [
			'/^SELECT batch_id FROM `wp_bulkcat_batches` WHERE batch_id = (\d+) LIMIT 1 FOR UPDATE$/' => 1,
			'START TRANSACTION'                       => function (): int {
				$this->sync();
				$this->transaction = [ $this->receipts, $this->batches, $this->items, $this->changes, $GLOBALS['wpdb']->writes, $this->seen, $this->inserts, $GLOBALS['wpdb']->insert_id ];
				return 1;
			},
			'COMMIT'                                  => function (): int|bool {
				++$this->commits;
				if ( null !== $this->before_commit ) {
					( $this->before_commit )( $this->commits );
				}
				if ( in_array( 'commit_before:' . $this->commits, $this->faults, true ) ) {
					return false; }
				$this->sync();
				$this->transaction = null;
				return in_array( 'commit_after:' . $this->commits, $this->faults, true ) ? false : 1;
			},
			'ROLLBACK'                                => function (): int {
				if ( null !== $this->transaction ) {
					[ $this->receipts, $this->batches, $this->items, $this->changes, $GLOBALS['wpdb']->writes, $this->seen, $this->inserts, $GLOBALS['wpdb']->insert_id ] = $this->transaction;
					$this->transaction = null;
				}
				return 1;
			},
			'/^INSERT INTO `wp_bulkcat_requests`/'    => function (): int|bool {
				if ( in_array( 'receipt', $this->faults, true ) ) {
					return false; }
				$args = end( $GLOBALS['wpdb']->prepared )[1];
				[ , $owner, $uuid, $operation, $fingerprint, $issued, $until, $retain ] = $args;
				$this->receipts[ $uuid ] ??= [
					'user_id'      => $owner,
					'uuid'         => $uuid,
					'operation'    => $operation,
					'fingerprint'  => $fingerprint,
					'issued_at'    => $issued,
					'accept_until' => $until,
					'retain_until' => $retain,
					'state'        => 'accepted',
					'result_kind'  => '',
					'result_id'    => '',
				];
				if ( null !== $this->after_claim ) {
					( $this->after_claim )( $uuid ); }
				return 1;
			},
			'/^SELECT \* FROM `wp_bulkcat_requests`/' => function (): ?array {
				[ , $owner, $uuid ] = end( $GLOBALS['wpdb']->prepared )[1];
				$row                = $this->receipts[ $uuid ] ?? null;
				return null !== $row && (int) $row['user_id'] === $owner ? $row : null;
			},
			'/^UPDATE `wp_bulkcat_requests` SET result_kind/' => function ( string $sql ): int|bool {
				if ( in_array( 'accept', $this->faults, true ) ) {
					return false; }
				[ , $id, $owner, $uuid ] = end( $GLOBALS['wpdb']->prepared )[1];
				if ( ! isset( $this->receipts[ $uuid ] ) || '' !== $this->receipts[ $uuid ]['result_id'] ) {
					return 0; }
				$refused                                = str_contains( $sql, 'inline_refusal' );
				$this->receipts[ $uuid ]['result_id']   = (string) $id;
				$this->receipts[ $uuid ]['result_kind'] = $refused ? 'inline_refusal' : 'batch';
				$this->receipts[ $uuid ]['state']       = $refused ? 'refused' : 'accepted';
				if ( ! $refused ) {
					$this->receipts[ $uuid ]['retain_until'] = null; }
				return 1;
			},
			'/^SELECT batch_id FROM `wp_bulkcat_batches` WHERE uuid/' => function (): array {
				$this->sync();
				[ , $uuid, $owner ] = end( $GLOBALS['wpdb']->prepared )[1];
				return array_keys( array_filter( $this->batches, static fn( array $b ): bool => $b['uuid'] === $uuid && $b['user_id'] === $owner ) );
			},
		] + $wpdb->responses;
	}

	/** A separate observer sees committed data while the original is at COMMIT. */
	public function committed_view( callable $observe ): mixed {
		$pending = [ $this->receipts, $this->batches, $this->items ];
		[ $this->receipts, $this->batches, $this->items ] = array_slice( $this->transaction, 0, 3 );
		try {
			return $observe();
		} finally {
			[ $this->receipts, $this->batches, $this->items ] = $pending; }
	}

	/**
	 * Fold new insert() and update() calls into the rows.
	 */
	public function sync(): void {
		global $wpdb;
		foreach ( array_slice( $wpdb->writes, $this->seen ) as [ $method, $table, $data, , $where ] ) {
			++$this->seen;
			if ( 'insert' === $method ) {
				$id = ++$this->inserts;
				if ( 'wp_bulkcat_batches' === $table ) {
					$this->batches[ $id ] = [ 'batch_id' => $id ] + $data + [
						'finished_gmt'  => null,
						'undo_of'       => null,
						'done_items'    => 0,
						'changed_items' => 0,
						'failed_items'  => 0,
						'chunk_size'    => 0,
						'note'          => '',
					];
				} elseif ( 'wp_bulkcat_changes' === $table ) {
					$data['old_value'] = ValueCodec::decode( $data['old_value'] );
					$data['new_value'] = ValueCodec::decode( $data['new_value'] );
					$this->changes[]   = $data;
				}
			} elseif ( 'update' === $method && 'wp_bulkcat_changes' === $table ) {
				// An intent row settled (§12.3).
				foreach ( $this->changes as $index => $row ) {
					if ( [] === array_diff_assoc( $where, array_intersect_key( $row, $where ) ) ) {
						$data['new_value']       = ValueCodec::decode( $data['new_value'] );
						$this->changes[ $index ] = $data + $row;
					}
				}
			} elseif ( 'update' === $method && 'wp_bulkcat_batches' === $table && isset( $this->batches[ $where['batch_id'] ] ) ) {
				$row = $this->batches[ $where['batch_id'] ];
				if ( ! isset( $where['status'] ) || $where['status'] === $row['status'] ) {
					$this->batches[ $where['batch_id'] ] = $data + $row;
				}
			}
		}
	}

	/**
	 * A batch row after every write so far.
	 *
	 * @return array<string, mixed>
	 */
	public function batch( int $batch_id ): array {
		$this->sync();
		return $this->batches[ $batch_id ];
	}

	/**
	 * Journal rows reduced to [ object, prop, old, new, result ], as RunnerStore::journal() reports them.
	 *
	 * @return list<array{0: int, 1: string, 2: mixed, 3: mixed, 4: string}>
	 */
	public function journal(): array {
		$this->sync();
		return array_map( static fn( array $r ): array => [ $r['object_id'], $r['prop'], $r['old_value'], $r['new_value'], $r['result'] ], $this->changes );
	}

	private function find( string $sql ): ?array {
		$this->sync();
		preg_match( '/batch_id = (\d+)$/', $sql, $m );
		$row = $this->batches[ (int) $m[1] ] ?? null;
		return null === $row ? null : array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row );
	}

	private function insert_items( string $sql ): int {
		preg_match_all( "/\((\d+), (\d+), '([a-z]+)', 'pending', 0\)/", $sql, $rows, PREG_SET_ORDER );
		foreach ( $rows as [ , $batch, $object, $type ] ) {
			$id                 = count( $this->items ) + 1;
			$this->items[ $id ] = [
				'item_id'     => $id,
				'batch_id'    => (int) $batch,
				'object_id'   => (int) $object,
				'object_type' => $type,
				'state'       => 'pending',
				'attempts'    => 0,
				'claim_token' => null,
				'claimed_gmt' => null,
				'message'     => '',
			];
		}
		return count( $rows );
	}

	private function claim( string $sql ): int {
		preg_match( "/SET claim_token = '([0-9a-f]{32})', claimed_gmt = '([^']+)', attempts = attempts \\+ 1 WHERE batch_id = (\\d+) AND state = 'pending' .* LIMIT (\\d+)$/", $sql, $m );
		$claimed = 0;
		foreach ( $this->items as $id => $item ) {
			if ( $claimed < (int) $m[4] && (int) $m[3] === $item['batch_id'] && 'pending' === $item['state'] && null === $item['claim_token'] ) {
				$this->items[ $id ]['claim_token'] = $m[1];
				$this->items[ $id ]['claimed_gmt'] = $m[2];
				++$this->items[ $id ]['attempts'];
				++$claimed;
			}
		}
		return $claimed;
	}

	private function claimed( string $sql ): array {
		preg_match( "/WHERE batch_id = (\\d+) AND state = 'pending' AND claim_token = '([0-9a-f]+)'/", $sql, $m );
		$rows = [];
		foreach ( $this->items as $item ) {
			if ( (int) $m[1] === $item['batch_id'] && 'pending' === $item['state'] && $m[2] === $item['claim_token'] ) {
				$rows[] = array_map( 'strval', array_intersect_key( $item, array_flip( [ 'item_id', 'object_id', 'object_type', 'attempts' ] ) ) );
			}
		}
		return $rows;
	}

	private function mark( string $sql ): int {
		preg_match( "/SET state = '(\\w+)', message = '(.*)' WHERE batch_id = (\\d+) AND item_id IN \\(([\\d, ]+)\\)$/s", $sql, $m );
		$marked = 0;
		foreach ( array_map( 'intval', explode( ', ', $m[4] ) ) as $id ) {
			if ( isset( $this->items[ $id ] ) ) {
				$this->items[ $id ]['state']   = $m[1];
				$this->items[ $id ]['message'] = stripslashes( $m[2] );
				++$marked;
			}
		}
		return $marked;
	}

	private function count_up( string $sql ): int {
		$this->sync();
		preg_match( '/done_items \+ (\d+), changed_items = changed_items \+ (\d+), failed_items = failed_items \+ (\d+) WHERE batch_id = (\d+)$/', $sql, $m );
		$id                                     = (int) $m[4];
		$this->batches[ $id ]['done_items']    += (int) $m[1];
		$this->batches[ $id ]['changed_items'] += (int) $m[2];
		$this->batches[ $id ]['failed_items']  += (int) $m[3];
		return 1;
	}

	private function has_object( string $sql ): ?string {
		$this->sync();
		preg_match( '/batch_id = (\d+) AND object_id = (\d+)/', $sql, $m );
		foreach ( $this->changes as $row ) {
			if ( (int) $m[1] === $row['batch_id'] && (int) $m[2] === $row['object_id'] ) {
				return '1';
			}
		}
		return null;
	}
}
