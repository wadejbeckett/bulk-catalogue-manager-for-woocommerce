<?php
/**
 * Signed, non-renewable acceptance windows for durable request identities.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/** Keeps signature verification separate from acceptance-expiry checks. */
final class RequestGrant {
	/** Signed first-acceptance window, in seconds. */
	public const WINDOW = 86400;

	/**
	 * Signing context; site and installation are independent boundaries.
	 *
	 * @param string $secret Signing secret.
	 * @param string $site Site identity.
	 * @param string $epoch Installation identity.
	 */
	public function __construct( private string $secret, private string $site, private string $epoch ) {}

	/**
	 * Create a new identity; callers cannot provide an old UUID.
	 *
	 * @param string $operation Operation kind.
	 * @param string $fingerprint Canonical intent hash.
	 * @param int    $owner Current user.
	 * @param int    $now Issuance time.
	 * @return array<string,mixed>
	 */
	public function issue( string $operation, string $fingerprint, int $owner, int $now ): array {
		$uuid   = wp_generate_uuid4();
		$claims = [
			'version'      => 1,
			'uuid'         => $uuid,
			'operation'    => $operation,
			'fingerprint'  => $fingerprint,
			'owner'        => $owner,
			'site'         => $this->site,
			'epoch'        => $this->epoch,
			'issued_at'    => $now,
			'accept_until' => $now + self::WINDOW,
		];
		$body   = bin2hex( (string) wp_json_encode( $claims ) );
		return [
			'uuid'          => $uuid,
			'request_proof' => $body . '.' . $this->sign( $body ),
			'accept_until'  => $claims['accept_until'],
		];
	}

	/**
	 * Authenticate original intent, including after acceptance expiry.
	 * The receipt resolver must run before deciding whether time permits new work.
	 *
	 * @param string $uuid Request identity.
	 * @param string $proof Signed claims.
	 * @param string $operation Operation kind.
	 * @param string $fingerprint Canonical intent hash.
	 * @param int    $owner Current user.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function verify( string $uuid, string $proof, string $operation, string $fingerprint, int $owner ): array|\WP_Error {
		if ( strlen( $proof ) > 4096 || 1 !== preg_match( '/^([a-f0-9]+)\.([a-f0-9]{64})$/D', $proof, $parts ) || 0 !== strlen( $parts[1] ) % 2 || ! hash_equals( $this->sign( $parts[1] ), $parts[2] ) ) {
			return self::invalid();
		}
		$claims = json_decode( (string) hex2bin( $parts[1] ), true );
		if ( ! is_array( $claims ) || 1 !== ( $claims['version'] ?? null ) || ( $claims['uuid'] ?? null ) !== $uuid || $owner < 1 || ( $claims['owner'] ?? null ) !== $owner || ( $claims['site'] ?? null ) !== $this->site || ( $claims['epoch'] ?? null ) !== $this->epoch || ! is_int( $claims['issued_at'] ?? null ) || ! is_int( $claims['accept_until'] ?? null ) || self::WINDOW !== $claims['accept_until'] - $claims['issued_at'] ) {
			return self::invalid();
		}
		if ( ( $claims['operation'] ?? null ) !== $operation || ( $claims['fingerprint'] ?? null ) !== $fingerprint ) {
			return Refusal::create( 'bulkcat_request_conflict', __( 'This request belongs to a different change. Resolve the original change before starting another.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
		}
		return $claims;
	}

	/**
	 * Sign an encoded claim, with a dedicated domain.
	 *
	 * @param string $body Encoded claims.
	 */
	private function sign( string $body ): string {
		return hash_hmac( 'sha256', 'bulk-catalogue-manager-for-woocommerce/request/1/' . $body, $this->secret );
	}

	/** Uniform refusal for proofs outside this user's installation. */
	private static function invalid(): \WP_Error {
		return Refusal::create( 'bulkcat_invalid_request', __( 'This request cannot be verified. Reload the catalogue before starting a new change.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
	}
}
