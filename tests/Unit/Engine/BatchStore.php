<?php
/**
 * The two batch tables in memory for the T31 tests, alongside the seeded
 * catalogue: batch rows come from the recording `$wpdb`'s insert() calls,
 * item rows from the INSERT statements BatchRepository::materialise_items()
 * prepares, and DELETE and SELECT statements on either table answer from
 * them — the earlier-request lookup through the SqlEvaluator, a `SELECT *`
 * by id directly. Every write is logged in order in $log, so a test can say which
 * came first.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Tests\Support\SqlEvaluator;

final class BatchStore {
	/**
	 * Batch rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $batches = [];

	/**
	 * Item rows, in insertion order.
	 *
	 * @var list<array{batch_id: int, object_id: int, object_type: string, state: string, attempts: int, operations: ?array}>
	 */
	public array $items = [];

	/**
	 * Writes in order: 'batch:<id>', 'items:<id>:<count>', 'discard:<table>:<id>'.
	 *
	 * @var list<string>
	 */
	public array $log = [];

	/**
	 * insert() calls already turned into rows.
	 */
	private int $seen = 0;

	/**
	 * insert() calls so far: the stub's insert_id, counted from zero.
	 */
	private int $inserts = 0;

	/**
	 * Answer the batch tables' statements ahead of the catalogue's.
	 *
	 * @param SqlEvaluator $db The seeded catalogue's evaluator.
	 */
	public function __construct( private SqlEvaluator $db ) {
		global $wpdb;
		$wpdb->responses = [
			'/^INSERT INTO `wp_bulkcat_batch_items`/'      => fn( string $sql ): int => $this->insert_items( $sql ),
			'/^UPDATE `wp_bulkcat_batch_items` SET state = \'failed\', message = /' => fn( string $sql ): int => $this->fail_objects( $sql ),
			'/^DELETE FROM `(wp_bulkcat_batches|wp_bulkcat_batch_items)`/' => fn( string $sql ): int => $this->delete( $sql ),
			'/^SELECT \* FROM `wp_bulkcat_batches` WHERE batch_id = (\d+)$/' => fn( string $sql ): ?array => $this->find( $sql ),
			'/^SELECT batch_id FROM `wp_bulkcat_batches` WHERE uuid = /' => fn( string $sql ): array => $this->candidates( $sql ),
			'/^SELECT batch_id FROM `wp_bulkcat_batches`/' => fn( string $sql ): ?string => $this->lookup( $sql ),
		] + $wpdb->responses;
	}

	/**
	 * Turn new insert() calls on the batches table into rows, ids from 1 as the stub counts them.
	 */
	public function sync(): void {
		global $wpdb;
		foreach ( array_slice( $wpdb->writes, $this->seen ) as $write ) {
			++$this->seen;
			if ( 'insert' !== $write[0] ) {
				continue;
			}
			$id = ++$this->inserts;
			if ( 'wp_bulkcat_batches' !== $write[1] ) {
				continue;
			}
			$this->batches[ $id ] = [ 'batch_id' => $id ] + $write[2] + [
				'finished_gmt'    => null,
				'undo_of'         => null,
				'previewed_items' => null,
				'done_items'      => 0,
				'changed_items'   => 0,
				'failed_items'    => 0,
			];
			$this->log[]          = 'batch:' . $id;
		}
	}

	/**
	 * Object ids of a batch's items, in order.
	 *
	 * @return list<int>
	 */
	public function object_ids( int $batch_id ): array {
		$this->sync();
		return array_values( array_map( static fn( array $item ): int => $item['object_id'], array_filter( $this->items, static fn( array $item ): bool => $item['batch_id'] === $batch_id ) ) );
	}

	private function insert_items( string $sql ): int {
		$this->sync();
		// T69: an item may carry its own operation list as a sixth value.
		preg_match_all( "/\((\d+), (\d+), '([a-z]+)', 'pending', 0(?:, '((?:[^'\\\\]|\\\\.)*)')?\)/s", $sql, $rows, PREG_SET_ORDER );
		foreach ( $rows as $row ) {
			$this->items[] = [
				'batch_id'    => (int) $row[1],
				'object_id'   => (int) $row[2],
				'object_type' => $row[3],
				'state'       => 'pending',
				'attempts'    => 0,
				'operations'  => isset( $row[4] ) ? ValueCodec::decode( stripslashes( $row[4] ) ) : null,
			];
		}
		$this->log[] = 'items:' . $rows[0][1] . ':' . count( $rows );
		return count( $rows );
	}

	/**
	 * BatchRepository::fail_objects(): pending items of the named objects and kind become failed with the message.
	 */
	private function fail_objects( string $sql ): int {
		$this->sync();
		preg_match( "/message = '((?:[^'\\\\]|\\\\.)*)' WHERE batch_id = (\d+) AND state = 'pending' AND object_type = '([a-z]+)' AND object_id IN \(([\d, ]+)\)$/", $sql, $m );
		$ids    = array_map( 'intval', explode( ',', $m[4] ) );
		$marked = 0;
		foreach ( $this->items as $index => $item ) {
			if ( (int) $m[2] === $item['batch_id'] && 'pending' === $item['state'] && $m[3] === $item['object_type'] && in_array( $item['object_id'], $ids, true ) ) {
				$this->items[ $index ]['state']   = 'failed';
				$this->items[ $index ]['message'] = stripslashes( $m[1] );
				++$marked;
			}
		}
		$this->log[] = 'failed:' . $m[2] . ':' . $marked;
		return $marked;
	}

	private function delete( string $sql ): int {
		$this->sync();
		preg_match( '/^DELETE FROM `(wp_bulkcat_batches|wp_bulkcat_batch_items)` WHERE batch_id = (\d+)$/', $sql, $m );
		$id          = (int) $m[2];
		$this->log[] = 'discard:' . $m[1] . ':' . $id;
		if ( 'wp_bulkcat_batches' === $m[1] ) {
			$gone = isset( $this->batches[ $id ] ) ? 1 : 0;
			unset( $this->batches[ $id ] );
			return $gone;
		}
		$before      = count( $this->items );
		$this->items = array_values( array_filter( $this->items, static fn( array $item ): bool => $item['batch_id'] !== $id ) );
		return $before - count( $this->items );
	}

	private function find( string $sql ): ?array {
		$this->sync();
		preg_match( '/batch_id = (\d+)$/', $sql, $m );
		$row = $this->batches[ (int) $m[1] ] ?? null;
		return null === $row ? null : array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row );
	}

	/**
	 * BatchRepository::request_candidates(): the batch ids under a request id, through the SqlEvaluator.
	 *
	 * @return list<string>
	 */
	private function candidates( string $sql ): array {
		$this->sync();
		$this->db->tables['wp_bulkcat_batches'] = array_values( $this->batches );
		return array_map( static fn( array $row ): string => (string) $row['batch_id'], $this->db->rows( $sql ) );
	}

	private function lookup( string $sql ): ?string {
		$this->sync();
		$this->db->tables['wp_bulkcat_batches'] = array_values( $this->batches );
		return $this->db->value( $sql );
	}
}
