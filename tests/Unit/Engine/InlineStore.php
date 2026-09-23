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
		$items           = '`wp_bcm_batch_items`';
		$batches         = '`wp_bcm_batches`';
		$changes         = '`wp_bcm_changes`';
		$wpdb->responses = [
			"/^SELECT \\* FROM $batches WHERE batch_id = (\\d+)$/" => fn( string $sql ): ?array => $this->find( $sql ),
			"/^INSERT INTO $items/"                => fn( string $sql ): int => $this->insert_items( $sql ),
			"/^UPDATE $items SET claim_token = '/" => fn( string $sql ): int => $this->claim( $sql ),
			"/^SELECT item_id, object_id, object_type, attempts FROM $items/" => fn( string $sql ): array => $this->claimed( $sql ),
			"/^UPDATE $items SET state = '\\w+', message = .* AND item_id IN /s" => fn( string $sql ): int => $this->mark( $sql ),
			"/^UPDATE $batches SET done_items = /" => fn( string $sql ): int => $this->count_up( $sql ),
			"/^SELECT change_id FROM $changes WHERE batch_id = (\\d+) AND object_id = (\\d+) LIMIT 1$/" => fn( string $sql ): ?string => $this->has_object( $sql ),
			'/^DELETE FROM `(wp_bcm_batches|wp_bcm_batch_items)`/' => fn(): int => 1,
		] + $wpdb->responses;
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
				if ( 'wp_bcm_batches' === $table ) {
					$this->batches[ $id ] = [ 'batch_id' => $id ] + $data + [
						'finished_gmt'  => null,
						'undo_of'       => null,
						'done_items'    => 0,
						'changed_items' => 0,
						'failed_items'  => 0,
						'chunk_size'    => 0,
						'note'          => '',
					];
				} elseif ( 'wp_bcm_changes' === $table ) {
					$data['old_value'] = ValueCodec::decode( $data['old_value'] );
					$data['new_value'] = ValueCodec::decode( $data['new_value'] );
					$this->changes[]   = $data;
				}
			} elseif ( 'update' === $method && 'wp_bcm_changes' === $table ) {
				// An intent row settled (§12.3).
				foreach ( $this->changes as $index => $row ) {
					if ( [] === array_diff_assoc( $where, array_intersect_key( $row, $where ) ) ) {
						$data['new_value']       = ValueCodec::decode( $data['new_value'] );
						$this->changes[ $index ] = $data + $row;
					}
				}
			} elseif ( 'update' === $method && 'wp_bcm_batches' === $table && isset( $this->batches[ $where['batch_id'] ] ) ) {
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
