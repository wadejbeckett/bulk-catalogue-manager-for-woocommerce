<?php
/**
 * Canonical Apply and undo intents for durable batch requests (T83).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotToken;

defined( 'ABSPATH' ) || exit;

/**
 * The same canonical payload is signed at issuance and compared at
 * acceptance and resolution.
 *
 * Issuance normalises a raw intent once: the operation list through
 * Operations\Registry::validate_list(), so every default the shop's settings
 * supply is written out, and a snapshot token reduced to its stable
 * identity (a renewed token keeps the same identity). What comes back, with
 * `version: 1`, is the canonical intent the client keeps with its grant.
 *
 * A canonical intent is then only checked for shape, never normalised again:
 * the settings, defaults or actions of a later day cannot change what an
 * accepted request was, so a retry always fingerprints to its receipt.
 * Whether the operations are still what the registry would make of them is
 * asked only before a new acceptance (current()), where a difference means
 * the preview is stale.
 *
 * Apply: `{ version, source: bulk, operations, scope_hash, snapshot | ids }`.
 * Undo: `{ version, source: undo, batch_id, plan_hash, force }`. Transport
 * values — the current snapshot token, the preview's hash, the nonce — are
 * never part of an intent.
 */
final class BatchIntent {
	/**
	 * Most steps an operation list holds (Operations\Registry).
	 */
	private const MAX_STEPS = 20;

	/**
	 * Normalise a raw intent, or check a canonical one's shape.
	 *
	 * @param string              $operation `batch.apply` or `batch.undo`.
	 * @param array<string,mixed> $input     Raw or canonical intent.
	 * @param int                 $owner     Current user, for a raw snapshot token.
	 * @return array<string,mixed>|\WP_Error The canonical intent, or the registry's or the token's refusal.
	 * @throws \InvalidArgumentException On a malformed intent.
	 */
	public static function normalize( string $operation, array $input, int $owner ): array|\WP_Error {
		$undo = 'batch.undo' === $operation;
		if ( ! $undo && 'batch.apply' !== $operation ) {
			throw new \InvalidArgumentException( 'Not a batch operation.' );
		}
		if ( array_key_exists( 'version', $input ) ) {
			return $undo ? self::canonical_undo( $input ) : self::canonical_apply( $input );
		}
		return $undo ? self::undo( $input ) : self::apply( $input, $owner );
	}

	/**
	 * Whether a canonical Apply intent's operations are still exactly what
	 * the registry makes of them, asked before a new acceptance only.
	 *
	 * @param array<string,mixed> $intent Canonical Apply intent.
	 * @return bool|\WP_Error False when the registry would now normalise them differently; the registry's refusal when it no longer accepts them.
	 */
	public static function current( array $intent ): bool|\WP_Error {
		$operations = Actions::instance()->validate_list( $intent['operations'] ?? null );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		return wp_json_encode( Preview::canonical( $operations ) ) === wp_json_encode( Preview::canonical( $intent['operations'] ) );
	}

	/**
	 * Sorted, unique explicit identities, without querying current membership.
	 *
	 * @param mixed $ids IDs as sent.
	 * @return list<int>
	 * @throws \InvalidArgumentException On anything but a non-empty list of positive ids within the limit.
	 */
	public static function ids( mixed $ids ): array {
		if ( ! is_array( $ids ) || count( $ids ) > Selection::MAX_OBJECTS ) {
			throw new \InvalidArgumentException( 'Invalid explicit selection.' );
		}
		$ids = Selection::of_ids( $ids )->ids;
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Whether a value is a SHA-256 hex digest, as preview and plan hashes are.
	 *
	 * @param mixed $value Input.
	 */
	public static function digest( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/**
	 * A raw Apply intent, normalised.
	 *
	 * @param array<string,mixed> $input Raw intent.
	 * @param int                 $owner Current user.
	 * @return array<string,mixed>|\WP_Error
	 * @throws \InvalidArgumentException On a malformed intent.
	 */
	private static function apply( array $input, int $owner ): array|\WP_Error {
		if ( [] !== array_diff( array_keys( $input ), [ 'source', 'operations', 'scope_hash', 'ids', 'token' ] ) || 'bulk' !== ( $input['source'] ?? 'bulk' ) ) {
			throw new \InvalidArgumentException( 'Unknown or invalid Apply intent.' );
		}
		if ( ! self::digest( $input['scope_hash'] ?? null ) ) {
			throw new \InvalidArgumentException( 'An exact preview is required.' );
		}
		if ( array_key_exists( 'ids', $input ) === array_key_exists( 'token', $input ) ) {
			throw new \InvalidArgumentException( 'Exactly one selection is required.' );
		}
		$operations = Actions::instance()->validate_list( $input['operations'] ?? null );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		$intent = [
			'version'    => 1,
			'source'     => 'bulk',
			'operations' => $operations,
			'scope_hash' => $input['scope_hash'],
		];
		if ( array_key_exists( 'ids', $input ) ) {
			$intent['ids'] = self::ids( $input['ids'] );
			return $intent;
		}
		$identity = SnapshotToken::identify( is_string( $input['token'] ) ? $input['token'] : '', $owner );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$intent['snapshot'] = $identity['id'];
		return $intent;
	}

	/**
	 * A raw undo intent, normalised.
	 *
	 * @param array<string,mixed> $input Raw intent.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a malformed intent.
	 */
	private static function undo( array $input ): array {
		if ( [] !== array_diff( array_keys( $input ), [ 'source', 'batch_id', 'plan_hash', 'force' ] ) || 'undo' !== ( $input['source'] ?? 'undo' ) ) {
			throw new \InvalidArgumentException( 'Unknown or invalid undo intent.' );
		}
		return self::canonical_undo(
			[
				'version'   => 1,
				'source'    => 'undo',
				'batch_id'  => $input['batch_id'] ?? null,
				'plan_hash' => $input['plan_hash'] ?? null,
				'force'     => $input['force'] ?? false,
			]
		);
	}

	/**
	 * A canonical Apply intent, checked for shape only.
	 *
	 * @param array<string,mixed> $input Canonical intent.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When it is not exactly a canonical Apply intent.
	 */
	private static function canonical_apply( array $input ): array {
		$selection = array_key_exists( 'ids', $input ) ? 'ids' : 'snapshot';
		$keys      = array_keys( $input );
		sort( $keys );
		$expected = [ 'operations', 'scope_hash', $selection, 'source', 'version' ];
		sort( $expected );
		$valid = $keys === $expected && 1 === $input['version'] && 'bulk' === $input['source'] && self::digest( $input['scope_hash'] ) && self::steps( $input['operations'] );
		if ( $valid && 'ids' === $selection ) {
			$valid = is_array( $input['ids'] ) && [] !== $input['ids'] && array_is_list( $input['ids'] ) && count( $input['ids'] ) <= Selection::MAX_OBJECTS;
			$valid = $valid && [] === array_filter( $input['ids'], static fn( mixed $id ): bool => ! is_int( $id ) || $id < 1 ) && self::ids( $input['ids'] ) === $input['ids'];
		} elseif ( $valid ) {
			$valid = is_string( $input['snapshot'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $input['snapshot'] );
		}
		if ( ! $valid ) {
			throw new \InvalidArgumentException( 'Not a canonical Apply intent.' );
		}
		return [
			'version'    => 1,
			'source'     => 'bulk',
			'operations' => $input['operations'],
			'scope_hash' => $input['scope_hash'],
			$selection   => $input[ $selection ],
		];
	}

	/**
	 * A canonical undo intent, checked for shape only.
	 *
	 * @param array<string,mixed> $input Canonical intent.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When it is not exactly a canonical undo intent.
	 */
	private static function canonical_undo( array $input ): array {
		$keys = array_keys( $input );
		sort( $keys );
		$valid = [ 'batch_id', 'force', 'plan_hash', 'source', 'version' ] === $keys && 1 === $input['version'] && 'undo' === $input['source'];
		if ( ! $valid || ! is_int( $input['batch_id'] ) || $input['batch_id'] < 1 || ! self::digest( $input['plan_hash'] ) || ! is_bool( $input['force'] ) ) {
			throw new \InvalidArgumentException( 'Not a canonical undo intent.' );
		}
		return [
			'version'   => 1,
			'source'    => 'undo',
			'batch_id'  => $input['batch_id'],
			'plan_hash' => $input['plan_hash'],
			'force'     => $input['force'],
		];
	}

	/**
	 * Whether a value has the shape of a normalised operation list: one to
	 * twenty steps, each exactly an action id and an argument map.
	 *
	 * @param mixed $operations The list.
	 */
	private static function steps( mixed $operations ): bool {
		if ( ! is_array( $operations ) || [] === $operations || ! array_is_list( $operations ) || count( $operations ) > self::MAX_STEPS ) {
			return false;
		}
		foreach ( $operations as $step ) {
			$keys = is_array( $step ) ? array_keys( $step ) : [];
			sort( $keys );
			if ( [ 'action', 'args' ] !== $keys || ! is_string( $step['action'] ) || '' === $step['action'] || ! is_array( $step['args'] ) ) {
				return false;
			}
		}
		return true;
	}
}
