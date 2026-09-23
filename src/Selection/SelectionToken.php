<?php
/**
 * Signed selection tokens (SPEC §9.1, §12.1, §13). `POST /selection` hands
 * the client a token instead of trusting it to send the same filter or ids
 * again: a snapshot of the selection — a filter's normalised conditions
 * (Criteria::conditions()) with the count they matched, or the id list —
 * plus the user it was made for and when it expires, signed with an HMAC
 * (SHA-256) keyed on `wp_salt( 'auth' )`.
 *
 * Form: `base64url( JSON payload ) . '.' . base64url( signature )`, the
 * signature taken over the encoded payload text and compared as text with
 * `hash_equals()`, so any altered byte anywhere in the token is refused.
 * parse() gives back the materialised Selection, or refuses 403
 * `bcm_invalid_selection` for a token that is malformed, altered, expired or
 * made for another user. A filter comes back through
 * Criteria::from_conditions(), validated again as a request would be.
 *
 * Tokens last TTL seconds, filterable through `bcm_selection_ttl` (clamped
 * to one minute to one day). Nothing is stored: a token is its own record.
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
	public const INVALID = 'bcm_invalid_selection';

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
	 * Longest token parse() reads: 100,000 ids of up to 19 digits, encoded, with room.
	 */
	private const MAX_LENGTH = 4000000;

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
		$body = self::encode( (string) wp_json_encode( $payload ) );
		return $body . '.' . self::sign( $body );
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
		if ( ! is_string( $token ) || strlen( $token ) > self::MAX_LENGTH || 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $parts ) ) {
			return self::refusal();
		}
		if ( ! hash_equals( self::sign( $parts[1] ), $parts[2] ) ) {
			return self::refusal();
		}
		$json    = self::decode( $parts[1] );
		$payload = null === $json ? null : json_decode( $json, true );
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
	 * How long a token lasts, in seconds: TTL through `bcm_selection_ttl`, clamped.
	 */
	public static function ttl(): int {
		$ttl = apply_filters( 'bcm_selection_ttl', self::TTL );
		return is_int( $ttl ) ? max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) ) : self::TTL;
	}

	/**
	 * The refusal for any token that cannot be used: 403 `bcm_invalid_selection`.
	 */
	public static function refusal(): \WP_Error {
		return Refusal::create(
			self::INVALID,
			__( 'This selection has expired or can no longer be used. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			403
		);
	}

	/**
	 * The encoded signature of an encoded payload.
	 *
	 * @param string $body Encoded payload.
	 */
	private static function sign( string $body ): string {
		return self::encode( hash_hmac( 'sha256', self::CONTEXT . self::VERSION . '.' . $body, wp_salt( 'auth' ), true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A token's transport encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Bytes from base64url, or null.
	 *
	 * @param string $text Base64url text.
	 */
	private static function decode( string $text ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A token's transport encoding, not obfuscation.
		$bytes = base64_decode( strtr( $text, '-_', '+/' ), true );
		return false === $bytes ? null : $bytes;
	}
}
