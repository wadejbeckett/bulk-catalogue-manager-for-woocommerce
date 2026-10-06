<?php
/**
 * Canonical composition names stable snapshots, not changing lease tokens.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Selection\SnapshotToken;
defined( 'ABSPATH' ) || exit;

/** Only one bounded mutation may accompany a base snapshot. */
final class ComposeIntent {
	/**
	 * Normalize new input or validate the saved canonical intent.
	 *
	 * @param array<string,mixed> $input Intent.
	 * @param int $owner Current user.
	 * @return array<string,mixed>|\WP_Error
	 * @throws \InvalidArgumentException On malformed intent.
	 */
	public static function normalize( array $input, int $owner ): array|\WP_Error {
		$canonical  = array_key_exists( 'version', $input );
		$keys       = array_keys( $input );
		$operations = $canonical ? [ 'add_ids', 'remove_ids', 'add_snapshot', 'remove_snapshot' ] : [ 'add_ids', 'remove_ids', 'add_token', 'remove_token' ];
		$chosen     = array_values( array_intersect( $keys, $operations ) );
		if ( 1 !== count( $chosen ) || count( $input ) !== ( $canonical ? 3 : 2 ) || ( $canonical && 1 !== $input['version'] ) ) {
			throw new \InvalidArgumentException( 'Choose exactly one selection mutation.' );
		}
		$key = $chosen[0];
		if ( $canonical ) {
			$base = $input['base'] ?? null;
			if ( ! is_string( $base ) || ! preg_match( '/^[a-f0-9]{32}$/D', $base ) ) {
				throw new \InvalidArgumentException( 'Invalid base identity.' );
			}
		} else {
			$identity = SnapshotToken::identify( is_string( $input['token'] ?? null ) ? $input['token'] : '', $owner );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$base = $identity['id'];
		}
		$value = $input[ $key ];
		if ( str_ends_with( $key, '_ids' ) ) {
			$value = CaptureIntent::normalize( [ 'ids' => $value ] )['ids'];
		} elseif ( $canonical ) {
			if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
				throw new \InvalidArgumentException( 'Invalid operand identity.' );
			}
		} else {
			$identity = SnapshotToken::identify( is_string( $value ) ? $value : '', $owner );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$value = $identity['id'];
			$key   = str_replace( '_token', '_snapshot', $key );
		}
		return [
			'version' => 1,
			'base'    => $base,
			$key      => $value,
		];
	}
}
