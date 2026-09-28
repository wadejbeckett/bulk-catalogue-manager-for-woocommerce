<?php
/**
 * Immutable batch semantics, separate from renewable authorization credentials.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotToken;
defined( 'ABSPATH' ) || exit;

/** The same canonical payload is used at issuance, acceptance and resolution. */
final class BatchIntent {
	/**
	 * Validate an input or its previously normalized form without loading products.
	 *
	 * @param string $operation Apply or undo.
	 * @param array $input Semantic input only.
	 * @param int $owner Current user.
	 * @return array|\WP_Error
	 */
	public static function normalize( string $operation, array $input, int $owner ): array|\WP_Error {
		$canonical = array_key_exists( 'version', $input );
		if ( $canonical && 1 !== $input['version'] ) {
			throw new \InvalidArgumentException( 'Unsupported intent version.' );}
		$undo    = 'batch.undo' === $operation;
		$allowed = $undo ? [ 'version', 'source', 'batch_id', 'plan_hash', 'force' ] : [ 'version', 'source', 'operations', 'scope_hash', 'ids', $canonical ? 'snapshot' : 'token' ];
		if ( array_diff( array_keys( $input ), $allowed ) || ( $input['source'] ?? ( $undo ? 'undo' : 'bulk' ) ) !== ( $undo ? 'undo' : 'bulk' ) ) {
			throw new \InvalidArgumentException( 'Unknown or invalid batch intent.' );}
		if ( $undo ) {
			if ( ! is_int( $input['batch_id'] ?? null ) || $input['batch_id'] < 1 || ! self::digest( $input['plan_hash'] ?? null ) || ! is_bool( $input['force'] ?? false ) ) {
				throw new \InvalidArgumentException( 'Invalid undo intent.' );}
			return [
				'version'   => 1,
				'source'    => 'undo',
				'batch_id'  => $input['batch_id'],
				'plan_hash' => $input['plan_hash'],
				'force'     => $input['force'] ?? false,
			];
		}
		if ( ! self::digest( $input['scope_hash'] ?? null ) ) {
			throw new \InvalidArgumentException( 'A scope preview is required.' );}
		$key = $canonical ? 'snapshot' : 'token';
		if ( array_key_exists( 'ids', $input ) === array_key_exists( $key, $input ) ) {
			throw new \InvalidArgumentException( 'Exactly one selection is required.' );}
		$operations = Actions::instance()->validate_list( $input['operations'] ?? null );
		if ( is_wp_error( $operations ) ) {
			return $operations;}
		$intent = [
			'version'    => 1,
			'source'     => 'bulk',
			'operations' => $operations,
			'scope_hash' => $input['scope_hash'],
		];
		if ( array_key_exists( 'ids', $input ) ) {
			$intent['ids'] = self::ids( $input['ids'] );} elseif ( $canonical ) {
			if ( ! is_string( $input['snapshot'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input['snapshot'] ) ) {
				throw new \InvalidArgumentException( 'Invalid snapshot identity.' );}
			$intent['snapshot'] = $input['snapshot'];
			} else {
				$identity = SnapshotToken::identify( is_string( $input['token'] ) ? $input['token'] : '', $owner );
				if ( is_wp_error( $identity ) ) {
					return $identity;}
				$intent['snapshot'] = $identity['id'];
			}
			return $intent;
	}
	/**
	 * Deterministic explicit identities, without querying current catalogue membership.
	 *
	 * @param mixed $ids IDs.
	 * @return int[]
	 */
	public static function ids( mixed $ids ): array {
		if ( ! is_array( $ids ) || count( $ids ) > Selection::MAX_OBJECTS ) {
			throw new \InvalidArgumentException( 'Invalid explicit selection.' );}
		$ids = Selection::of_ids( $ids )->ids;
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}
	/**
	 * A SHA256 digest supplied by preview.
	 *
	 * @param mixed $value Input.
	 */
	public static function digest( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );}
}
