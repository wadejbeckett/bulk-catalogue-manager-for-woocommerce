<?php
/**
 * Frozen, state-independent native inline payload and opaque signed envelope.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Rest\Refusal;
defined( 'ABSPATH' ) || exit;

/** Never consult the catalogue, field registry or action normalizers here. */
final class InlineIntent {
	/** Exact UTF-8 contract bytes, including both LF characters. Bump on semantic changes. */
	private const CONTRACT = "bulkcat/native-inline-write/1\npayload=1;actions=native-single-object-v1;status=set_status;variations=off;keep=false\n";

	/** The supported native interpretation, independent of labels, products and callbacks. */
	public static function registry_revision(): string {
		return hash( 'sha256', self::CONTRACT );
	}

	/**
	 * Pure payload-v1 encoding. Scalar types and list order are preserved; maps sort bytewise.
	 *
	 * @param array $input Raw intent.
	 * @param int $payload_version Signed version, never substituted with current semantics.
	 * @return array|\WP_Error
	 */
	public static function normalize_payload( array $input, int $payload_version ): array|\WP_Error {
		if ( 1 !== $payload_version ) {
			return self::unsupported();
		}
		try {
			if ( array_diff_key( $input, array_flip( [ 'object_id', 'source', 'values', 'keep_changed_status' ] ) )
				|| ! is_int( $input['object_id'] ?? null ) || $input['object_id'] < 1
				|| ! in_array( $input['source'] ?? null, [ 'inline', 'bulk' ], true )
				|| ! is_array( $input['values'] ?? null ) || [] === $input['values']
				|| ( array_key_exists( 'keep_changed_status', $input ) && ! is_bool( $input['keep_changed_status'] ) ) ) {
				throw new \InvalidArgumentException( 'Invalid inline payload.' );
			}
			foreach ( array_keys( $input['values'] ) as $key ) {
				if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key ) ) {
					throw new \InvalidArgumentException( 'Invalid field identifier.' );
				}
			}
			$payload = [
				'object_id'           => $input['object_id'],
				'source'              => $input['source'],
				'values'              => self::ordered( $input['values'] ),
				'keep_changed_status' => $input['keep_changed_status'] ?? false,
			];
			if ( strlen( self::bytes( $payload ) ) > 65536 ) {
				throw new \InvalidArgumentException( 'Inline payload too large.' );
			}
			return $payload;
		} catch ( \InvalidArgumentException | \JsonException $error ) {
			return self::conflict();
		}
	}

	/**
	 * Bind the original typed values before any live preparation or persistence redaction.
	 *
	 * @param array $payload Pure normalized payload.
	 * @param int $owner Current owner.
	 * @param int $payload_version Payload encoding.
	 * @param string $registry_revision Native write semantics.
	 * @return array
	 */
	public static function bind( array $payload, int $owner, int $payload_version, string $registry_revision ): array {
		$body = [ 'bulkcat/inline/1', $payload_version, $registry_revision, (string) get_current_blog_id(), FoundationStorage::epoch(), $owner, $payload ];
		return [
			'version'           => 1,
			'payload_version'   => $payload_version,
			'registry_revision' => $registry_revision,
			'object_id'         => $payload['object_id'],
			'source'            => $payload['source'],
			'commitment'        => hash_hmac( 'sha256', self::bytes( $body ), wp_salt( 'auth' ) ),
		];
	}

	/**
	 * Shape only: an old payload normalizer need not exist to resolve its envelope.
	 *
	 * @param array $intent Signed envelope.
	 * @return array|\WP_Error
	 */
	public static function normalize_envelope( array $intent ): array|\WP_Error {
		$keys = [ 'version', 'payload_version', 'registry_revision', 'object_id', 'source', 'commitment' ];
		if ( count( $keys ) !== count( $intent ) || array_diff_key( $intent, array_flip( $keys ) )
			|| 1 !== ( $intent['version'] ?? null ) || ! is_int( $intent['payload_version'] ?? null ) || $intent['payload_version'] < 1
			|| ! is_int( $intent['object_id'] ?? null ) || $intent['object_id'] < 1
			|| ! in_array( $intent['source'] ?? null, [ 'inline', 'bulk' ], true ) ) {
			return self::conflict();
		}
		foreach ( [ 'registry_revision', 'commitment' ] as $key ) {
			if ( ! is_string( $intent[ $key ] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $intent[ $key ] ) ) {
				return self::conflict();
			}
		}
		return array_replace( array_fill_keys( $keys, null ), $intent );
	}

	/**
	 * Compare only frozen encodings.
	 *
	 * @param array $payload Normalized payload.
	 * @param array $intent Verified envelope.
	 * @param int $owner Current owner.
	 */
	public static function matches( array $payload, array $intent, int $owner ): bool {
		$bound = self::bind( $payload, $owner, $intent['payload_version'], $intent['registry_revision'] );
		return $bound['object_id'] === $intent['object_id'] && $bound['source'] === $intent['source'] && hash_equals( $intent['commitment'], $bound['commitment'] );
	}

	/**
	 * Recursive JSON data, with bounded nesting and no lossy scalar coercion.
	 *
	 * @param mixed $value Payload node.
	 * @param int $depth Nesting depth.
	 * @return mixed
	 */
	private static function ordered( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 16 || ( ! is_array( $value ) && ! is_scalar( $value ) && null !== $value ) ) {
			throw new \InvalidArgumentException( 'Payload is not bounded JSON.' );
		}
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as &$part ) {
				$part = self::ordered( $part, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Frozen PHP JSON flags; float 21.0 must never become integer 21.
	 *
	 * @param array $value Plain canonical data.
	 */
	private static function bytes( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Frozen protocol bytes must fail on invalid UTF-8, never substitute it.
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** No write and no refusal fence for altered or malformed payloads. */
	public static function conflict(): \WP_Error {
		return Refusal::create( 'bulkcat_request_conflict', __( 'This request belongs to a different change. Resolve the original change before starting another.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
	}

	/** Retain the original identity; payload-free resolution remains supported. */
	public static function unsupported(): \WP_Error {
		return Refusal::create( 'bulkcat_inline_version', __( 'These save instructions are no longer supported. Check the original save result before making another change.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
	}
}
