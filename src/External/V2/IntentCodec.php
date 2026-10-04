<?php
/**
 * Canonical serializer2 shapes, independent of provider and lease liveness.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Requests\RequestFamily;
use BulkCatalogueManager\Selection\{SnapshotToken, TypedMembers};
use BulkCatalogueManager\Rest\RequestsController;
defined( 'ABSPATH' ) || exit;
/** The outer signed grant version remains one. */
final class IntentCodec {
	/**
	 * Check external identity without loading its provider.
	 * @param mixed $external External discriminator.
	 */
	public static function external( mixed $external ): void {
		Rules::ensure( is_array( $external ) && count( $external ) === 3 && 2 === ( $external['contract'] ?? null ) && is_string( $external['provider'] ?? null ) && is_string( $external['identity'] ?? null ), 'Invalid external identity.' );
		Rules::provider( $external['provider'] );
		Rules::identity( $external['identity'] );
	}
	/**
	 * Check exact step shapes without provider discovery.
	 * @param mixed $steps Ordered operations.
	 */
	public static function steps( mixed $steps ): void {
		Rules::ensure( is_array( $steps ) && array_is_list( $steps ) && count( $steps ) >= 1 && count( $steps ) <= 20, 'Invalid steps.' );
		foreach ( $steps as $step ) {
			Rules::ensure( is_array( $step ) && count( $step ) === 2 && is_string( $step['action'] ?? null ) && is_array( $step['args'] ?? null ), 'Invalid step.' );
			Rules::key( $step['action'] );
			Rules::json( $step['args'], 65536 );}
	}
	/**
	 * Validate a canonical intent before signed fingerprint verification.
	 * @param string $operation Signed operation.
	 * @param array $input Canonical payload.
	 * @param int $owner Request owner.
	 * @return array|\WP_Error
	 */
	public static function canonical( string $operation, array $input, int $owner ): array|\WP_Error {
		try {
			Rules::json( $input, 65536 );
			Rules::ensure( 1 === ( $input['version'] ?? null ) && 2 === ( $input['serializer'] ?? null ) && in_array( $input['family'] ?? null, [ 'product','category' ], true ), 'Invalid intent discriminator.' );
			if ( 'batch.undo' === $operation ) {
				self::keys( $input, [ 'version','serializer','family','source','batch_id','plan_hash','force' ] );
				Rules::ensure( 'undo' === $input['source'] && is_int( $input['batch_id'] ) && $input['batch_id'] > 0 && is_bool( $input['force'] ), 'Invalid typed undo.' );
				Rules::hex( $input['plan_hash'], 64 );
				return $input;
			}
			Rules::ensure( 'batch.apply' === $operation, 'Unsupported typed operation.' );
			self::keys( $input, [ 'version','serializer','family','source','entry','external','selection','operations','scope_hash' ] );
			Rules::ensure( 'bulk' === $input['source'] && in_array( $input['entry'], [ $input['family'] . '.row',$input['family'] . '.bulk' ], true ), 'Invalid typed entry.' );
			self::external( $input['external'] );
			self::steps( $input['operations'] );
			Rules::hex( $input['scope_hash'], 64 );
			Rules::ensure( is_array( $input['selection'] ) && count( $input['selection'] ) === 1, 'Invalid selection identity.' );
			$key   = array_key_first( $input['selection'] );
			$value = $input['selection'][ $key ];
			if ( 'targets' === $key ) {
				Rules::ensure( is_array( $value ) && count( $value ) >= 1 && count( $value ) <= 1000 && TypedMembers::normalize( $value ) === $value, 'Noncanonical targets.' );
				Rules::ensure( RequestFamily::from_scope( new TypedScope( $value ) ) === $input['family'], 'False family.' );
			} else {
				Rules::ensure( in_array( $key, [ 'token','typed_token' ], true ) && is_string( $value ) && str_starts_with( $value, 'token' === $key ? 'v2.' : 'v3.' ), 'Invalid credential shape.' );
				$identity = SnapshotToken::identify( $value, $owner );
				if ( is_wp_error( $identity ) ) {
							return $identity;}
				Rules::ensure( 'token' !== $key || 'product' === $input['family'], 'False legacy snapshot family.' );
			}
			foreach ( $input['operations'] as $step ) {
				Rules::key( $step['action'], $input['external']['provider'] );
				Rules::ensure( is_string( $step['args']['field'] ?? null ), 'Missing field.' );
				Rules::key( $step['args']['field'], $input['external']['provider'] );}
			return $input;
		} catch ( \InvalidArgumentException $error ) {
			return RequestsController::unknown();}
	}
	/**
	 * Require the exact canonical map shape.
	 * @param array $input Value.
	 * @param array $keys Expected keys.
	 */
	private static function keys( array $input, array $keys ): void {
		$actual = array_keys( $input );
		sort( $actual );
		sort( $keys );
		Rules::ensure( $actual === $keys, 'Unknown canonical key.' );}
	/**
	 * Construct an intent from server-prepared preview metadata, never client snapshots.
	 * @param array $prepared Server preparation.
	 * @return array Canonical payload.
	 */
	public static function from_prepared( array $prepared ): array {
		$input     = $prepared['input'];
		$selection = array_intersect_key( $input, array_flip( [ 'targets','token','typed_token' ] ) );
		if ( isset( $selection['targets'] ) ) {
			$selection['targets'] = TypedMembers::normalize( $selection['targets'] );}
		return [
			'version'    => 1,
			'serializer' => 2,
			'family'     => $input['family'],
			'source'     => 'bulk',
			'entry'      => $input['entry'],
			'external'   => $input['external'],
			'selection'  => $selection,
			'operations' => $prepared['operations'],
			'scope_hash' => $prepared['scope']->signature(),
		];
	}
}
