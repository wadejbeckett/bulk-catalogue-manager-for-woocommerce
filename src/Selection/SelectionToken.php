<?php
/**
 * Signed selection tokens (SPEC §9.1, §12.1, §13). `POST /selection` hands
 * the client a token instead of trusting it to send the same filter or ids
 * again. The selection itself stays on the server: a snapshot — a filter's
 * normalised conditions (Criteria::conditions()) with the count they
 * matched, or the id list — plus the user it was made for and when it
 * expires, stored in a `bulkcat_selection_` transient under a random id.
 *
 * Form: `id . '.' . signature`, the id 32 lower-case hex characters and the
 * signature the hex HMAC (SHA-256, keyed on `wp_salt( 'auth' )`) of the id,
 * compared with `hash_equals()`, so any altered character is refused before
 * anything is read. parse() gives back the materialised Selection, or
 * refuses 403 `bulkcat_invalid_selection` for a token that is malformed,
 * altered, expired, made for another user or whose stored selection has
 * gone. A filter comes back through Criteria::from_conditions(), validated
 * again as a request would be.
 *
 * Tokens last TTL seconds, filterable through `bulkcat_selection_ttl` (clamped
 * to one minute to one day); the transient lasts as long.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Mints and parses selection tokens.
 */
final class SelectionToken {
	/**
	 * The refusal code for a token that cannot be used.
	 */
	public const INVALID = 'bulkcat_invalid_selection';

	/**
	 * Default lifetime, in seconds.
	 */
	public const TTL = 900;

	/**
	 * Shortest and longest lifetime the filter may set, in seconds.
	 */
	public const MIN_TTL = 60;
	public const MAX_TTL = 86400;

	/**
	 * Payload format version.
	 */
	private const VERSION = 1;

	/**
	 * The transient name prefix a stored selection is kept under, followed by its id.
	 */
	public const PREFIX = 'bulkcat_selection_';

	/**
	 * A token: the stored selection's id, a dot and the hex HMAC of the id.
	 */
	private const FORM = '/^([0-9a-f]{32})\.([0-9a-f]{64})$/';

	/**
	 * Mixed into the signed text so a signature made with the same salt for
	 * another purpose can never pass here.
	 */
	private const CONTEXT = 'bulk-catalogue-manager-for-woocommerce/selection/';

	/**
	 * A token for a selection, made for one user.
	 *
	 * @param Selection $selection The selection.
	 * @param int|null  $user_id   The user it is for; the current user by default.
	 * @param int|null  $now       Current Unix time; now by default.
	 * @throws \InvalidArgumentException When there is no user.
	 */
	public static function mint( Selection $selection, ?int $user_id = null, ?int $now = null ): string {
		$user_id ??= get_current_user_id();
		if ( $user_id < 1 ) {
			throw new \InvalidArgumentException( 'A selection token is made for a logged-in user.' );
		}
		$payload = [
			'v' => self::VERSION,
			'u' => $user_id,
			'e' => ( $now ?? time() ) + self::ttl(),
			'n' => $selection->count,
		];
		if ( null !== $selection->criteria ) {
			$payload['f'] = $selection->criteria->conditions();
		} else {
			$payload['i'] = $selection->ids;
		}
		$id = bin2hex( random_bytes( 16 ) );
		set_transient( self::PREFIX . $id, $payload, self::ttl() );
		return $id . '.' . self::sign( $id );
	}

	/**
	 * The selection a token holds, or the refusal.
	 *
	 * @param mixed    $token   The token as the client sent it.
	 * @param int|null $user_id The user presenting it; the current user by default.
	 * @param int|null $now     Current Unix time; now by default.
	 */
	public static function parse( mixed $token, ?int $user_id = null, ?int $now = null ): Selection|\WP_Error {
		$user_id ??= get_current_user_id();
		if ( ! is_string( $token ) || 1 !== preg_match( self::FORM, $token, $parts ) ) {
			return self::refusal();
		}
		if ( ! hash_equals( self::sign( $parts[1] ), $parts[2] ) ) {
			return self::refusal();
		}
		$payload = get_transient( self::PREFIX . $parts[1] );
		if ( ! is_array( $payload ) || self::VERSION !== ( $payload['v'] ?? null ) || ! is_int( $payload['u'] ?? null ) || ! is_int( $payload['e'] ?? null ) || ! is_int( $payload['n'] ?? null ) ) {
			return self::refusal();
		}
		if ( $user_id < 1 || $payload['u'] !== $user_id || $payload['e'] <= ( $now ?? time() ) ) {
			return self::refusal();
		}
		try {
			if ( isset( $payload['f'] ) && is_array( $payload['f'] ) && ! isset( $payload['i'] ) ) {
				return Selection::of_filter( Criteria::from_conditions( $payload['f'] ), $payload['n'] );
			}
			if ( isset( $payload['i'] ) && is_array( $payload['i'] ) && ! isset( $payload['f'] ) ) {
				return Selection::of_ids( $payload['i'] );
			}
		} catch ( \InvalidArgumentException $e ) {
			return self::refusal();
		}
		return self::refusal();
	}

	/**
	 * How long a token lasts, in seconds: TTL through `bulkcat_selection_ttl`, clamped.
	 */
	public static function ttl(): int {
		$ttl = apply_filters( 'bulkcat_selection_ttl', self::TTL );
		return is_int( $ttl ) ? max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) ) : self::TTL;
	}

	/**
	 * The refusal for any token that cannot be used: 403 `bulkcat_invalid_selection`.
	 */
	public static function refusal(): \WP_Error {
		return Refusal::create(
			self::INVALID,
			__( 'This selection has expired or can no longer be used. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			403
		);
	}

	/**
	 * The hex signature of a stored selection's id.
	 *
	 * @param string $id The id.
	 */
	private static function sign( string $id ): string {
		return hash_hmac( 'sha256', self::CONTEXT . self::VERSION . '.' . $id, wp_salt( 'auth' ) );
	}
}
