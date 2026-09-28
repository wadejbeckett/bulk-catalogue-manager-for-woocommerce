<?php
/**
 * Immutable typed membership, separate from request receipts and authorization.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Requests\RequestRepository;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable membership and retention locks belong in plugin storage, never a transient.

/** Owns only snapshot storage; callers own transactions. */
final class SnapshotRepository {
	/**
	 * Insert one exact set.
	 *
	 * @param array<string,int[]> $members Sorted product/variation identities.
	 * @param int $owner Owner.
	 * @param int $now Current time.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On storage failure.
	 */
	public function create( array $members, int $owner, int $now ): array {
		global $wpdb;
		$row = [
			'id'                       => bin2hex( random_bytes( 16 ) ),
			'user_id'                  => $owner,
			'members'                  => wp_json_encode( $members ),
			'object_count'             => count( $members['products'] ) + count( $members['variations'] ),
			'created_at'               => gmdate( 'Y-m-d H:i:s', $now ),
			'authorization_epoch'      => 1,
			'authorization_expires_at' => gmdate( 'Y-m-d H:i:s', $now + SelectionToken::ttl() ),
			'retained_until'           => gmdate( 'Y-m-d H:i:s', $now + 7 * 86400 ),
			'released_at'              => null,
		];
		if ( false === $wpdb->insert( Registry::plugin()->table( Schema::SELECTIONS ), $row, [ '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ] ) ) {
			throw new \RuntimeException( 'Cannot store selected products.' );
		}
		return $row;
	}

	/**
	 * Read only an owner's snapshot. Retention/authorization checked by service.
	 *
	 * @param string $id Snapshot identity.
	 * @param int $owner Owner.
	 * @param bool $lock Lock for lifecycle decisions inside a transaction.
	 * @return array<string,mixed>|null
	 * @throws \RuntimeException On database failure.
	 */
	public function read( string $id, int $owner, bool $lock = false ): ?array {
		global $wpdb;
		$row = $lock
			? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s AND user_id = %d LIMIT 1 FOR UPDATE', Registry::plugin()->table( Schema::SELECTIONS ), $id, $owner ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s AND user_id = %d LIMIT 1', Registry::plugin()->table( Schema::SELECTIONS ), $id, $owner ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot read selected products.' );
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update only lease/retention; never rewrite immutable membership.
	 *
	 * @param array<string,mixed> $row Locked snapshot with new lifecycle values.
	 * @throws \RuntimeException On database failure.
	 */
	public function retain( array $row ): void {
		global $wpdb;
		if ( false === $wpdb->update(
			Registry::plugin()->table( Schema::SELECTIONS ),
			[
				'authorization_epoch'      => $row['authorization_epoch'],
				'authorization_expires_at' => $row['authorization_expires_at'],
				'retained_until'           => $row['retained_until'],
				'released_at'              => $row['released_at'],
			],
			[
				'id'      => $row['id'],
				'user_id' => $row['user_id'],
			],
			[ '%d', '%s', '%s', '%s' ],
			[ '%s', '%d' ]
		) ) {
			throw new \RuntimeException( 'Cannot retain selected products.' );
		}
	}

	/**
	 * Lock, tombstone and delete at most 100 expired sets inside a transaction.
	 *
	 * @param int $now Current time.
	 * @param RequestRepository $receipts Independent receipt owner.
	 * @return int Deleted payloads.
	 * @throws \RuntimeException On storage failure.
	 */
	public function prune( int $now, RequestRepository $receipts ): int {
		global $wpdb;
		$table    = Registry::plugin()->table( Schema::SELECTIONS );
		$deadline = gmdate( 'Y-m-d H:i:s', $now );
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE retained_until <= %s ORDER BY retained_until, id LIMIT 100', $table, $deadline ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot select expired payloads.' );
		}
		// Discover without range locks, then lock primary identities in the same
		// order as renewal/composition. A secondary-index lock here would deadlock
		// with an owner holding the row while moving its retention index entry.
		sort( $ids, SORT_STRING );
		$deleted = 0;
		foreach ( $ids as $id ) {
			$retained = $wpdb->get_var( $wpdb->prepare( 'SELECT retained_until FROM %i WHERE id = %s LIMIT 1 FOR UPDATE', $table, $id ) );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Cannot lock expired payload.' );
			}
			if ( null === $retained || $retained > $deadline ) {
				continue;
			}
			$receipts->gone( $id );
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %s AND retained_until <= %s LIMIT 1', $table, $id, $deadline ) ) ) {
				throw new \RuntimeException( 'Cannot prune expired payload.' );
			}
			++$deleted;
		}
		return $deleted;
	}
}
