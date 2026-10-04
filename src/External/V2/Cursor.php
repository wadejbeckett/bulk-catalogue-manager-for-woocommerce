<?php
/** Signed choice continuation, never authorization. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
final class Cursor {
	/** Installation secret and injectable clock for expiry proofs. */
	public function __construct( private readonly ?string $secret = null, private readonly ?\Closure $clock = null ) {}
	/** Sign complete contextual identity and bounded provider continuation. */
	public function issue( array $context, ?string $state, string $payload ): string {
		$claims = [ 'version' => 1 ] + $context + [
			'state_token' => $state,
			'payload'     => $payload,
			'expires'     => $this->now() + 300,
		];
		$json   = json_encode( $claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact signed bytes must reject malformed UTF-8 instead of repairing it.
		$body   = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Bounded URL-safe cursor transport, never executable code.
		$token  = $body . '.' . hash_hmac( 'sha256', $body, $this->secret ?? wp_salt( 'auth' ) );
		Rules::ensure( strlen( $token ) <= 8192, 'Cursor is too large.' );
		return $token;
	}
	/** Authenticate before parsing; every bound context field must still match. */
	public function read( string $token, array $context ): array|\WP_Error {
		try {
			Rules::ensure( strlen( $token ) <= 8192 && substr_count( $token, '.' ) === 1, 'Invalid cursor.' );
			[$body, $signature] = explode( '.', $token, 2 );
			Rules::ensure( hash_equals( hash_hmac( 'sha256', $body, $this->secret ?? wp_salt( 'auth' ) ), $signature ), 'Invalid signature.' );
			$claims = json_decode( base64_decode( strtr( $body, '-_', '+/' ), true ), true, 32, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode only authenticated cursor transport.
			Rules::ensure( is_array( $claims ) && array_keys( $claims ) === [ 'version',...array_keys( $context ),'state_token','payload','expires' ] && 1 === $claims['version'] && is_int( $claims['expires'] ) && $claims['expires'] > $this->now(), 'Expired cursor.' );
			foreach ( $context as $key => $value ) {
				Rules::ensure( $claims[ $key ] === $value, 'Context changed.' ); }
			Rules::ensure( is_string( $claims['payload'] ) && strlen( $claims['payload'] ) <= 2048 && ( null === $claims['state_token'] || ( is_string( $claims['state_token'] ) && strlen( $claims['state_token'] ) <= 1024 ) ), 'Invalid payload.' );
			return $claims;
		} catch ( \Throwable $error ) {
			return Refusals::choice_refresh(); }
	}
	private function now(): int {
		return null === $this->clock ? time() : ( $this->clock )(); }
}
