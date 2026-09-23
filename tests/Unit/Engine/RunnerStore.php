<?php
/**
 * One batch, its items and its journal in memory for the T34 chunk runner
 * tests. It answers the exact statements BatchRepository and
 * ChangeRepository prepare for the runner — the one-UPDATE claim and its
 * read-back by token, the stale-exhausted failure, marking with a message,
 * releasing a claim, the counters, the item counts and the journal lookups —
 * and folds the recording `$wpdb`'s update() and insert() calls into the
 * rows before every read, so each read sees the writes before it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;

final class RunnerStore {
	/**
	 * The batch row, typed.
	 *
	 * @var array<string, mixed>
	 */
	public array $batch;

	/**
	 * Item rows by item id.
	 *
	 * @var array<int, array{item_id: int, batch_id: int, object_id: int, object_type: string, state: string, attempts: int, claim_token: ?string, claimed_gmt: ?string, message: string}>
	 */
	public array $items = [];

	/**
	 * Journal rows, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $changes = [];

	/**
	 * $wpdb->writes already folded in.
	 */
	private int $seen = 0;

	/**
	 * A queued batch over these objects, its items pending.
	 *
	 * @param int                                        $batch_id   Batch id.
	 * @param list<array{0: int, 1: string}>|list<int>   $objects    Object ids (products), or [ id, type ] pairs.
	 * @param list<array<string, mixed>>                 $operations The normalised operation list.
	 * @param int                                        $user_id    The batch's user.
	 */
	public function __construct( int $batch_id, array $objects, array $operations, int $user_id = 3 ) {
		global $wpdb;
		$this->batch = [
			'batch_id'      => $batch_id,
			'uuid'          => '6f1c1a8e-8a3b-4c61-9f5e-2b7d1d2c3a40',
			'user_id'       => $user_id,
			'created_gmt'   => '2026-09-23 08:00:00',
			'finished_gmt'  => null,
			'status'        => 'queued',
			'source'        => 'bulk',
			'undo_of'       => null,
			'operations'    => ValueCodec::encode( $operations ),
			'selection'     => ValueCodec::encode( [ 'ids' => [] ] ),
			'total_items'   => count( $objects ),
			'done_items'    => 0,
			'changed_items' => 0,
			'failed_items'  => 0,
			'chunk_size'    => 0,
			'note'          => '',
		];
		$id          = 100;
		foreach ( $objects as $object ) {
			[ $object_id, $type ] = is_array( $object ) ? $object : [ $object, 'product' ];
			++$id;
			$this->items[ $id ] = [
				'item_id'     => $id,
				'batch_id'    => $batch_id,
				'object_id'   => $object_id,
				'object_type' => $type,
				'state'       => 'pending',
				'attempts'    => 0,
				'claim_token' => null,
				'claimed_gmt' => null,
				'message'     => '',
			];
		}
		$items           = '`wp_bcm_batch_items`';
		$batches         = '`wp_bcm_batches`';
		$changes         = '`wp_bcm_changes`';
		$wpdb->responses = [
			"/^SELECT \\* FROM $batches WHERE batch_id = (\\d+)$/" => fn( string $sql ): ?array => $this->find( $sql ),
			"/^SELECT status FROM $batches WHERE batch_id = (\\d+)$/" => fn( string $sql ): ?string => $this->find( $sql )['status'] ?? null,
			"/^UPDATE $items SET claim_token = '/"    => fn( string $sql ): int => $this->claim( $sql ),
			"/^SELECT item_id, object_id, object_type, attempts FROM $items/" => fn( string $sql ): array => $this->claimed( $sql ),
			"/^UPDATE $items SET claim_token = NULL/" => fn( string $sql ): int => $this->release( $sql ),
			"/^UPDATE $items SET state = 'failed', message = .* AND attempts >= /s" => fn( string $sql ): int => $this->exhaust( $sql ),
			"/^UPDATE $items SET state = '\\w+', message = .* AND item_id IN /s" => fn( string $sql ): int => $this->mark( $sql ),
			"/^UPDATE $batches SET done_items = /"    => fn( string $sql ): int => $this->count_up( $sql ),
			"/^SELECT COUNT\\(\\*\\) FROM $items WHERE batch_id = \\d+ AND state = '/" => fn( string $sql ): string => $this->count_state( $sql ),
			"/^SELECT COUNT\\(\\*\\) FROM $items WHERE batch_id = \\d+ AND state <> 'pending'/" => fn(): string => $this->count_reported(),
			"/^SELECT change_id FROM $changes WHERE batch_id = (\\d+) AND object_id = (\\d+) LIMIT 1$/" => fn( string $sql ): ?string => $this->has_object( $sql ),
			"/^SELECT COUNT\\(\\*\\) FROM $changes WHERE batch_id = (\\d+) AND result = '(\\w+)'$/" => fn( string $sql ): string => $this->count_changes( $sql ),
		] + $wpdb->responses + [
			// T35, T36: any other read of the three tables runs through the evaluator over the rows as they stand.
			"/^SELECT .* FROM ($batches|$items|$changes)/s" => fn( string $sql ): mixed => $this->evaluate( $sql ),
		];
	}

	/**
	 * Answer a read through the SQL evaluator over the current rows: a
	 * COUNT or single-column read as a value, anything else as rows.
	 */
	private function evaluate( string $sql ): mixed {
		$this->sync();
		$changes = [];
		foreach ( $this->changes as $index => $row ) {
			$changes[] = [
				'change_id' => $index + 1,
				'old_value' => ValueCodec::encode( $row['old_value'] ),
				'new_value' => ValueCodec::encode( $row['new_value'] ),
			] + $row;
		}
		$db   = new SqlEvaluator(
			[
				'wp_bcm_batches'     => [ $this->batch ],
				'wp_bcm_batch_items' => array_values( $this->items ),
				'wp_bcm_changes'     => $changes,
			]
		);
		$rows = $db->rows( $sql );
		if ( 1 === preg_match( '/^SELECT (COUNT\(\*\)|claimed_gmt) FROM/', $sql ) ) {
			return [] === $rows ? null : reset( $rows[0] );
		}
		return $rows;
	}

	/**
	 * Fold new update() and insert() calls into the rows.
	 */
	public function sync(): void {
		global $wpdb;
		foreach ( array_slice( $wpdb->writes, $this->seen ) as [ $method, $table, $data, , $where ] ) {
			++$this->seen;
			if ( 'update' === $method && 'wp_bcm_batches' === $table && $where['batch_id'] === $this->batch['batch_id'] && ( ! isset( $where['status'] ) || $where['status'] === $this->batch['status'] ) ) {
				$this->batch = $data + $this->batch;
			} elseif ( 'insert' === $method && 'wp_bcm_changes' === $table && ! in_array( 'insert', $wpdb->failing, true ) ) {
				$data['old_value'] = ValueCodec::decode( $data['old_value'] );
				$data['new_value'] = ValueCodec::decode( $data['new_value'] );
				$this->changes[]   = $data;
			}
		}
	}

	/**
	 * The batch row after every write so far.
	 *
	 * @return array<string, mixed>
	 */
	public function batch(): array {
		$this->sync();
		return $this->batch;
	}

	/**
	 * Item states in id order, as `object_id => state`.
	 *
	 * @return array<int, string>
	 */
	public function states(): array {
		$states = [];
		foreach ( $this->items as $item ) {
			$states[ $item['object_id'] ] = $item['state'];
		}
		return $states;
	}

	/**
	 * The item holding an object.
	 *
	 * @return array<string, mixed>
	 */
	public function item( int $object_id ): array {
		foreach ( $this->items as $item ) {
			if ( $object_id === $item['object_id'] ) {
				return $item;
			}
		}
		throw new \LogicException( "No item for $object_id." );
	}

	/**
	 * Journal rows reduced to [ object, prop, old, new, result ].
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
		if ( (int) $m[1] !== $this->batch['batch_id'] ) {
			return null;
		}
		return array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $this->batch );
	}

	private function claim( string $sql ): int {
		$this->sync();
		$ok = preg_match( "/SET claim_token = '([0-9a-f]{32})', claimed_gmt = '([^']+)', attempts = attempts \\+ 1 WHERE batch_id = (\\d+) AND state = 'pending' AND attempts < (\\d+) AND \\(claimed_gmt IS NULL OR claimed_gmt < '([^']+)'\\) ORDER BY item_id ASC LIMIT (\\d+)$/", $sql, $m );
		if ( 1 !== $ok ) {
			throw new \LogicException( "Unexpected claim: $sql" );
		}
		$claimed = 0;
		ksort( $this->items );
		foreach ( $this->items as $id => $item ) {
			if ( $claimed >= (int) $m[6] ) {
				break;
			}
			if ( (int) $m[3] === $item['batch_id'] && 'pending' === $item['state'] && $item['attempts'] < (int) $m[4] && ( null === $item['claimed_gmt'] || $item['claimed_gmt'] < $m[5] ) ) {
				$this->items[ $id ]['claim_token'] = $m[1];
				$this->items[ $id ]['claimed_gmt'] = $m[2];
				++$this->items[ $id ]['attempts'];
				++$claimed;
			}
		}
		return $claimed;
	}

	private function claimed( string $sql ): array {
		preg_match( "/WHERE batch_id = (\\d+) AND state = 'pending' AND claim_token = '([0-9a-f]+)' ORDER BY item_id ASC LIMIT (\\d+)$/", $sql, $m );
		$rows = [];
		foreach ( $this->items as $item ) {
			if ( (int) $m[1] === $item['batch_id'] && 'pending' === $item['state'] && $m[2] === $item['claim_token'] ) {
				$rows[] = array_map( 'strval', array_intersect_key( $item, array_flip( [ 'item_id', 'object_id', 'object_type', 'attempts' ] ) ) );
			}
		}
		return array_slice( $rows, 0, (int) $m[3] );
	}

	private function release( string $sql ): int {
		preg_match( "/WHERE batch_id = (\\d+) AND state = 'pending' AND claim_token = '([0-9a-f]+)'$/", $sql, $m );
		$released = 0;
		foreach ( $this->items as $id => $item ) {
			if ( 'pending' === $item['state'] && $m[2] === $item['claim_token'] ) {
				$this->items[ $id ]['claim_token'] = null;
				$this->items[ $id ]['claimed_gmt'] = null;
				++$released;
			}
		}
		return $released;
	}

	private function exhaust( string $sql ): int {
		preg_match( "/message = '(.*)' WHERE batch_id = (\\d+) AND state = 'pending' AND attempts >= (\\d+) AND \\(claimed_gmt IS NULL OR claimed_gmt < '([^']+)'\\)$/s", $sql, $m );
		$failed = 0;
		foreach ( $this->items as $id => $item ) {
			if ( 'pending' === $item['state'] && $item['attempts'] >= (int) $m[3] && ( null === $item['claimed_gmt'] || $item['claimed_gmt'] < $m[4] ) ) {
				$this->items[ $id ]['state']   = 'failed';
				$this->items[ $id ]['message'] = stripslashes( $m[1] );
				++$failed;
			}
		}
		return $failed;
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
		$this->batch['done_items']    += (int) $m[1];
		$this->batch['changed_items'] += (int) $m[2];
		$this->batch['failed_items']  += (int) $m[3];
		return 1;
	}

	private function count_state( string $sql ): string {
		preg_match( "/state = '(\\w+)'$/", $sql, $m );
		return (string) count( array_filter( $this->items, static fn( array $item ): bool => $m[1] === $item['state'] ) );
	}

	private function count_reported(): string {
		return (string) count( array_filter( $this->items, static fn( array $item ): bool => 'pending' !== $item['state'] && ( 'done' !== $item['state'] || '' !== $item['message'] ) ) );
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

	private function count_changes( string $sql ): string {
		$this->sync();
		preg_match( "/batch_id = (\\d+) AND result = '(\\w+)'$/", $sql, $m );
		return (string) count( array_filter( $this->changes, static fn( array $row ): bool => (int) $m[1] === $row['batch_id'] && $m[2] === $row['result'] ) );
	}
}
