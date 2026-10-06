<?php
/**
 * Canonical Apply and undo intents for durable batch requests (T83).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Engine\CategoryPreview;
use BulkCatalogueManager\Engine\CategorySelection;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\External\Refusals;
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
 * asked only before a new acceptance (current_operations()), where a
 * difference means the preview is stale, and the registry's own list is
 * what is then recorded.
 *
 * Apply: `{ version, source: bulk, operations, scope_hash, snapshot | ids |
 * categories }`; `categories` (contract version 2, a category change) is
 * `{ chosen, subcategories, ids }`: the term ids chosen, whether
 * subcategories were included, and the exact set the preview captured, each
 * list sorted and distinct.
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
	 * The operations a new acceptance records, asked before a new
	 * acceptance only: the registry's own normalised list, and only when it
	 * is exactly the intent's (the signed fingerprint cannot tell a list
	 * from a map with the same numbered keys, so what is recorded is always
	 * what the registry produced, never the shape a client sent).
	 *
	 * @param array<string,mixed> $intent Canonical Apply intent.
	 * @return list<array<string,mixed>>|false|\WP_Error The normalised list; false when the registry would now normalise the intent differently; the registry's refusal when it no longer accepts it.
	 */
	public static function current_operations( array $intent ): array|false|\WP_Error {
		$operations = Actions::instance()->validate_list( $intent['operations'] ?? null );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		return wp_json_encode( Preview::canonical( $operations ) ) === wp_json_encode( Preview::canonical( $intent['operations'] ) ) ? $operations : false;
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
		if ( [] !== array_diff( array_keys( $input ), [ 'source', 'operations', 'scope_hash', 'ids', 'token', 'categories' ] ) || 'bulk' !== ( $input['source'] ?? 'bulk' ) ) {
			throw new \InvalidArgumentException( 'Unknown or invalid Apply intent.' );
		}
		if ( ! self::digest( $input['scope_hash'] ?? null ) ) {
			throw new \InvalidArgumentException( 'An exact preview is required.' );
		}
		if ( 1 !== count( array_intersect( array_keys( $input ), [ 'ids', 'token', 'categories' ] ) ) ) {
			throw new \InvalidArgumentException( 'Exactly one selection is required.' );
		}
		$operations = Actions::instance()->validate_list( $input['operations'] ?? null );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}
		// Contract version 2: a category change goes with categories only, and products with product changes only.
		$category = true === Actions::instance()->external_provider( $operations )?->category_change( $operations );
		$chosen   = array_key_exists( 'categories', $input );
		if ( $chosen !== $category ) {
			return $category ? CategoryPreview::not_categories() : Refusals::mixed();
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
		if ( array_key_exists( 'categories', $input ) ) {
			$categories = is_array( $input['categories'] ) ? $input['categories'] : [];
			if ( [] !== array_diff( array_keys( $categories ), [ 'chosen', 'subcategories', 'ids' ] ) || ! is_bool( $categories['subcategories'] ?? false ) ) {
				throw new \InvalidArgumentException( 'Unknown or invalid category selection.' );
			}
			$intent['categories'] = [
				'chosen'        => CategorySelection::ids( $categories['chosen'] ?? null ),
				'subcategories' => (bool) ( $categories['subcategories'] ?? false ),
				'ids'           => CategorySelection::ids( $categories['ids'] ?? null ),
			];
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
		$selection = array_key_exists( 'ids', $input ) ? 'ids' : ( array_key_exists( 'categories', $input ) ? 'categories' : 'snapshot' );
		$keys      = array_keys( $input );
		sort( $keys );
		$expected = [ 'operations', 'scope_hash', $selection, 'source', 'version' ];
		sort( $expected );
		$valid = $keys === $expected && 1 === $input['version'] && 'bulk' === $input['source'] && self::digest( $input['scope_hash'] ) && self::steps( $input['operations'] );
		if ( $valid && 'ids' === $selection ) {
			$valid = is_array( $input['ids'] ) && [] !== $input['ids'] && array_is_list( $input['ids'] ) && count( $input['ids'] ) <= Selection::MAX_OBJECTS;
			$valid = $valid && [] === array_filter( $input['ids'], static fn( mixed $id ): bool => ! is_int( $id ) || $id < 1 ) && self::ids( $input['ids'] ) === $input['ids'];
		} elseif ( $valid && 'categories' === $selection ) {
			$valid = self::category_shape( $input['categories'] );
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
	 * Whether a canonical intent's category selection has its exact shape:
	 * `chosen`, `subcategories` and `ids`, each list sorted, distinct,
	 * positive and at most CategorySelection::MAX long.
	 *
	 * @param mixed $categories The selection.
	 */
	private static function category_shape( mixed $categories ): bool {
		if ( ! is_array( $categories ) ) {
			return false;
		}
		$keys = array_keys( $categories );
		sort( $keys );
		if ( [ 'chosen', 'ids', 'subcategories' ] !== $keys || ! is_bool( $categories['subcategories'] ) ) {
			return false;
		}
		foreach ( [ 'chosen', 'ids' ] as $list ) {
			$ids = $categories[ $list ];
			if ( ! is_array( $ids ) || [] === $ids || ! array_is_list( $ids ) || [] !== array_filter( $ids, static fn( mixed $id ): bool => ! is_int( $id ) || $id < 1 ) ) {
				return false;
			}
			try {
				if ( CategorySelection::ids( $ids ) !== $ids ) {
					return false;
				}
			} catch ( \InvalidArgumentException | \LengthException $e ) {
				return false;
			}
		}
		return true;
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
			// An external step is checked for shape only (T106): its provider may since have gone.
			if ( [ 'action', 'args' ] !== $keys || ! is_string( $step['action'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $step['action'] ) || ! is_array( $step['args'] ) ) {
				return false;
			}
		}
		return true;
	}
}
