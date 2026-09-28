<?php
/**
 * Signed identity is independent of the current authorization lease.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Rest\Refusal;
defined( 'ABSPATH' ) || exit;

/** Tokens identify work; the service separately checks retention and rights. */
final class SnapshotToken {
	/**
	 * Sign the stored current epoch.
	 *
	 * @param array<string,mixed> $row Snapshot.
	 */
	public static function issue( array $row ): string {
		$body    = 'v2.' . $row['id'] . '.' . $row['authorization_epoch'];
		$context = implode( '/', [ 'bulk-catalogue-manager-for-woocommerce/snapshot', get_current_blog_id(), FoundationStorage::epoch(), $row['user_id'], $body ] );
		return $body . '.' . hash_hmac( 'sha256', $context, wp_salt( 'auth' ) );
	}

	/**
	 * Authenticate without requiring a live lease or accessing another owner.
	 *
	 * @param string $token Opaque token.
	 * @param int $owner Current user.
	 * @return array{id:string,epoch:int}|\WP_Error
	 */
	public static function identify( string $token, int $owner ): array|\WP_Error {
		if ( 1 !== preg_match( '/^v2\.([a-f0-9]{32})\.([1-9][0-9]{0,9})\.([a-f0-9]{64})$/D', $token, $parts ) || (int) $parts[2] > 4294967295 || $owner < 1 ) {
			return self::invalid();
		}
		$expected = self::issue(
			[
				'id'                  => $parts[1],
				'authorization_epoch' => (int) $parts[2],
				'user_id'             => $owner,
			]
		);
		return hash_equals( $expected, $token ) ? [
			'id'    => $parts[1],
			'epoch' => (int) $parts[2],
		] : self::invalid();
	}

	/** Uniform refusal without disclosing another user's retained state. */
	public static function invalid(): \WP_Error {
		return Refusal::create( 'bulkcat_invalid_selection', __( 'These selected products could not be verified. Reload the catalogue and choose them again.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
	}
}
