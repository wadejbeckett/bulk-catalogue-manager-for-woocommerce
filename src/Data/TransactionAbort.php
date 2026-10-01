<?php
/**
 * Native conflict identity and confirmed rollback, without a retry policy.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Data;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.RestrictedFunctions -- wpdb exposes no native error number or connection identity; these read mysqli metadata only, never execute SQL.

/** Internal signal for the supported pre-commit database conflict. */
final class TransactionAbort extends \RuntimeException {
	private bool $rolled_back = false;

	/**
	 * Only capture() can establish the native error and guarded connection.
	 *
	 * @param string $message Existing storage diagnostic.
	 * @param array $connection Native handle and server connection id.
	 */
	private function __construct( string $message, private array $connection ) {
		parent::__construct( $message, 1020 );
	}

	/**
	 * Read identity without queries/reconnection; an unsupported/closed driver is unknown.
	 *
	 * @return array|null Native handle and server connection id.
	 */
	public static function connection(): ?array {
		global $wpdb;
		try {
			$handle = $wpdb->dbh ?? null;
			$id     = mysqli_thread_id( $handle );
			return $id > 0 ? [ $handle, $id ] : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Capture the actual error before ROLLBACK clears it; never infer from text.
	 *
	 * @param string $message Existing storage diagnostic.
	 * @return \RuntimeException Typed only for the supported guarded conflict.
	 */
	public static function capture( string $message ): \RuntimeException {
		global $wpdb;
		$connection = self::connection();
		try {
			if ( null !== $connection && 0 === $wpdb->reconnect_retries && 1020 === mysqli_errno( $connection[0] ) ) {
				return new self( $message, $connection );
			}
		} catch ( \Throwable $error ) {
			// Unknown connection state must never authorize repetition.
			return new \RuntimeException( $message );
		}
		return new \RuntimeException( $message );
	}

	/**
	 * Storage alone supplies the rollback outcome, including failures on reused errors.
	 *
	 * @param bool $confirmed Successful pre-commit rollback on the guarded connection.
	 * @param array|null $connection Connection at transaction entry.
	 */
	public function acknowledge_rollback( bool $confirmed, ?array $connection ): void {
		$this->rolled_back = $confirmed && null !== $connection && $connection === $this->connection;
	}

	/** The caller may choose a bounded retry only after storage acknowledges rollback. */
	public function rollback_confirmed(): bool {
		return $this->rolled_back;
	}
}
