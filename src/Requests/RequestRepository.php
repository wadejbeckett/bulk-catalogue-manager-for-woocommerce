<?php
/**
 * Durable outcome receipts; each mutation participates in its caller's transaction.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Receipts and row locks must be read/written durably, not cached.

/** Stores request results without coupling their retention to membership. */
final class RequestRepository {
	/**
	 * Read one receipt, optionally locking the current committed row.
	 *
	 * @param int $owner Current owner.
	 * @param string $uuid Request identity.
	 * @param bool $lock Whether caller holds an acceptance transaction.
	 * @return array<string,mixed>|null
	 * @throws \RuntimeException On database failure.
	 */
	public function read( int $owner, string $uuid, bool $lock = false ): ?array {
		global $wpdb;
		$table = Registry::plugin()->table( Schema::REQUESTS );
		$sql   = $lock
			? $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND uuid = %s LIMIT 1 FOR UPDATE', $table, $owner, $uuid )
			: $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND uuid = %s LIMIT 1', $table, $owner, $uuid );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both branches prepare a literal query.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot resolve stored request.' );
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reserve the unique identity. An unfinished result can only exist inside this transaction.
	 * Concurrent duplicates wait on the same key, then read the committed result.
	 *
	 * @param array<string,mixed> $claims Verified grant claims.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On lock/storage failure.
	 */
	public function claim( array $claims ): array {
		global $wpdb;
		$sql = $wpdb->prepare( "INSERT INTO %i (user_id,uuid,operation,fingerprint,issued_at,accept_until,state,result_kind,result_id,retain_until) VALUES (%d,%s,%s,%s,%s,%s,'accepted','','',%s) ON DUPLICATE KEY UPDATE uuid = VALUES(uuid)", Registry::plugin()->table( Schema::REQUESTS ), $claims['owner'], $claims['uuid'], $claims['operation'], $claims['fingerprint'], gmdate( 'Y-m-d H:i:s', $claims['issued_at'] ), gmdate( 'Y-m-d H:i:s', $claims['accept_until'] ), gmdate( 'Y-m-d H:i:s', $claims['issued_at'] + 8 * 86400 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Request claim failed; retry the same identity.' );
		}
		return $this->read( $claims['owner'], $claims['uuid'], true ) ?? throw new \RuntimeException( 'Claimed request is missing.' );
	}

	/**
	 * Finish a new selection receipt in the capture transaction.
	 *
	 * @param array<string,mixed> $claims Verified claims.
	 * @param string $id Snapshot id.
	 * @throws \RuntimeException On storage failure.
	 */
	public function accepted_snapshot( array $claims, string $id ): void {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET result_kind = 'snapshot', result_id = %s WHERE user_id = %d AND uuid = %s AND result_id = ''", Registry::plugin()->table( Schema::REQUESTS ), $id, $claims['owner'], $claims['uuid'] ) ) ) {
			throw new \RuntimeException( 'Cannot record capture result.' );
		}
	}

	/**
	 * Record the batch outcome in the same transaction as its header and items.
	 *
	 * @param array $claims Verified grant.
	 * @param int $id Batch id.
	 */
	public function accepted_batch( array $claims, int $id ): void {
		global $wpdb;
		if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET result_kind = 'batch', result_id = %s, retain_until = NULL WHERE user_id = %d AND uuid = %s AND result_id = ''", Registry::plugin()->table( Schema::REQUESTS ), (string) $id, $claims['owner'], $claims['uuid'] ) ) ) {
			throw new \RuntimeException( 'Cannot record batch receipt.' );
		}
	}

	/**
	 * Committed pre-effect refusal, with no values/reasons beyond this bounded classification.
	 *
	 * @param array $claims Verified grant, already uniquely claimed in the caller's transaction.
	 * @param string $reason validation or permission.
	 */
	public function refused_inline( array $claims, string $reason ): void {
		global $wpdb;
		if ( ! in_array( $reason, [ 'validation', 'permission' ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown inline refusal.' ); }
		if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET result_kind = 'inline_refusal', result_id = %s, state = 'refused' WHERE user_id = %d AND uuid = %s AND result_id = ''", Registry::plugin()->table( Schema::REQUESTS ), $reason, $claims['owner'], $claims['uuid'] ) ) ) {
			throw new \RuntimeException( 'Cannot record inline refusal.' );
		}
	}

	/**
	 * Preserve a missing header's tombstone; an expired grant cannot recreate it.
	 *
	 * @param array $claims Verified original grant.
	 */
	public function batch_gone( array $claims ): void {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'gone', retain_until = %s WHERE user_id = %d AND uuid = %s AND result_kind = 'batch'", Registry::plugin()->table( Schema::REQUESTS ), gmdate( 'Y-m-d H:i:s', $claims['issued_at'] + 8 * 86400 ), $claims['owner'], $claims['uuid'] ) ) ) {
			throw new \RuntimeException( 'Cannot record missing batch.' );
		}
	}

	/**
	 * Tombstone before deleting a payload in the same transaction.
	 *
	 * @param string $id Snapshot id.
	 * @throws \RuntimeException On storage failure.
	 */
	public function gone( string $id ): void {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'gone' WHERE result_kind = 'snapshot' AND result_id = %s", Registry::plugin()->table( Schema::REQUESTS ), $id ) ) ) {
			throw new \RuntimeException( 'Cannot retain capture tombstone.' );
		}
	}

	/**
	 * Bounded expiry; batch receipts are never removed here.
	 *
	 * @param int $now Current clock.
	 * @return int Deleted receipts.
	 * @throws \RuntimeException On storage failure.
	 */
	public function prune( int $now ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE (result_kind = 'snapshot' OR (result_kind = 'inline_refusal' AND state = 'refused') OR (result_kind = 'batch' AND state = 'gone')) AND retain_until <= %s ORDER BY retain_until LIMIT 100", Registry::plugin()->table( Schema::REQUESTS ), gmdate( 'Y-m-d H:i:s', $now ) ) );
		return false === $result ? throw new \RuntimeException( 'Request cleanup failed.' ) : (int) $result;
	}
}
