<?php
/**
 * The explicit row sources of a catalogue export (SPEC §10): an ID list,
 * and a selection token.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Hydrator;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use BulkCatalogueManager\Selection\SnapshotService;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an export's explicit rows into IdRows.
 *
 * A selection token is resolved exactly as preview resolves it
 * (`Requests\RequestService::preview()`, ticket 10 R11): inside one
 * FoundationStorage transaction, through SnapshotService::selection() for
 * the current user, which locks the snapshot row for its one statement and
 * requires the current authorization lease. Export only reads: it never
 * renews, extends or releases a selection. Refusals pass through unchanged:
 * another user's or an altered token 403 `bulkcat_invalid_selection`, a
 * lapsed lease 409 `bulkcat_selection_renew_required`, a payload past its
 * retention 410 `bulkcat_selection_expired`; a 1.0 token (anything not
 * beginning `v2.`) gets SelectionToken::refusal(), as `POST /preview` gives
 * it. Only the Selection's ids are kept: the object, and the members it
 * decoded, are dropped before presence is resolved (amendment 7).
 */
final class ExportRows {
	/**
	 * The rows of an explicit ID list (at most 1,000, validated by ExportRequest).
	 *
	 * @param array<int, int> $ids      Positive ids; duplicates collapse.
	 * @param Registry        $registry Field registry, for hydration.
	 * @throws \RuntimeException On a database error.
	 */
	public static function ids( array $ids, Registry $registry ): IdRows {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return IdRows::resolve( $ids, new Hydrator( $registry ) );
	}

	/**
	 * The rows of a selection token, or its refusal.
	 *
	 * @param string        $token    The token, as the body sent it (ExportRequest never reads it from the address).
	 * @param int           $owner    The current user.
	 * @param Registry      $registry Field registry, for hydration.
	 * @param \Closure|null $resolve  ( token, owner ) => Selection|\WP_Error; the stored snapshot when null.
	 */
	public static function token( string $token, int $owner, Registry $registry, ?\Closure $resolve = null ): IdRows|\WP_Error {
		if ( ! str_starts_with( $token, 'v2.' ) ) {
			// A 1.0 token: choose the products again, as preview answers it.
			return SelectionToken::refusal();
		}
		try {
			$ids = self::member_ids( $resolve ?? self::stored( ... ), $token, $owner );
			return $ids instanceof \WP_Error ? $ids : IdRows::resolve( $ids, new Hydrator( $registry ) );
		} catch ( \Throwable $error ) {
			return Refusal::unexpected(
				'bulkcat_request_storage',
				__( 'The selected products could not be read safely. Export again; if this continues, ask an administrator to check catalogue storage.', 'bulk-catalogue-manager-for-woocommerce' ),
				503,
				$error
			);
		}
	}

	/**
	 * A snapshot's member ids, ascending; the Selection goes out of scope
	 * when this returns, so it is gone before presence is read.
	 *
	 * @param \Closure $resolve The resolver.
	 * @param string   $token   Token.
	 * @param int      $owner   Current user.
	 * @return list<int>|\WP_Error
	 */
	private static function member_ids( \Closure $resolve, string $token, int $owner ): array|\WP_Error {
		$selection = $resolve( $token, $owner );
		if ( ! $selection instanceof Selection ) {
			return $selection instanceof \WP_Error ? $selection : SelectionToken::refusal();
		}
		$ids = $selection->ids;
		unset( $selection );
		return $ids;
	}

	/**
	 * The stored snapshot, read as preview reads it: one transaction, the
	 * row locked for one statement, the current lease required, nothing
	 * written.
	 *
	 * @param string $token Token.
	 * @param int    $owner Current user.
	 * @throws \RuntimeException When selection storage is incomplete or not transactional.
	 */
	private static function stored( string $token, int $owner ): Selection|\WP_Error {
		if ( ! FoundationStorage::ready() ) {
			throw new \RuntimeException( 'Storage needs a complete migration and transactional tables.' );
		}
		return FoundationStorage::atomic( static fn(): Selection|\WP_Error => ( new SnapshotService() )->selection( $token, $owner ) );
	}
}
