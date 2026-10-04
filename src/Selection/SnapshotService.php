<?php
/**
 * Frozen capture and opaque result envelopes; never rerun a filter on replay.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Selection;

use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Rest\Refusal;
defined( 'ABSPATH' ) || exit;

/** Capture is separate from receipt transactions, which RequestService owns. */
final class SnapshotService {
	/** Clock sampled after row locks, never before a potentially long wait. */
	private \Closure $clock;

	/**
	 * Construct without storage effects.
	 *
	 * @param \Closure|null $clock Unix clock.
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Compose inside the receipt owner's transaction, locking sources in ID order.
	 *
	 * @param array<string,mixed> $intent Canonical stable identities and mutation.
	 * @param array<string,mixed> $request Current authorization tokens.
	 * @param int $owner Owner.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function compose( array $intent, array $request, int $owner ): array|\WP_Error {
		if ( ! str_starts_with( (string) ( $request['token'] ?? '' ), 'v2.' ) ) {
			return SnapshotToken::invalid(); }
		$tokens    = [ $request['token'] ?? '' ];
		$expected  = [ $intent['base'] ];
		$operation = array_values( array_diff( array_keys( $intent ), [ 'version', 'base' ] ) )[0];
		if ( str_ends_with( $operation, '_snapshot' ) ) {
			$tokens[]   = $request[ str_replace( '_snapshot', '_token', $operation ) ] ?? '';
			$expected[] = $intent[ $operation ];
		}
		$loaded = $this->load( $tokens, $owner, true );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		if ( array_column( $loaded, 'id' ) !== $expected ) {
			return SnapshotToken::invalid();
		}
		foreach ( $loaded as $row ) {
			if ( isset( json_decode( $row['members'], true )['version'] ) ) {
				return SnapshotToken::invalid(); }
		}
		$base  = json_decode( $loaded[0]['members'], true );
		$value = $intent[ $operation ];
		if ( 'add_ids' === $operation ) {
			$criteria = Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] );
			$added    = ( new ProductQuery( $criteria ) )->snapshot_members( $value );
			if ( count( $value ) !== count( $added['products'] ) + count( $added['variations'] ) ) {
				throw new \InvalidArgumentException( 'Some added identities are unavailable.' );
			}
			$members = Membership::union( $base, $added );
		} elseif ( 'remove_ids' === $operation ) {
			$members = Membership::subtract( $base, $value );
		} else {
			$operand = json_decode( $loaded[1]['members'], true );
			$members = 'add_snapshot' === $operation ? Membership::union( $base, $operand ) : Membership::subtract( $base, array_merge( $operand['products'], $operand['variations'] ) );
		}
		// Type lookup/arithmetic may take time; check leases again before storing.
		$now = ( $this->clock )();
		foreach ( $loaded as $row ) {
			$valid = $this->valid( $row, $row['_epoch'], $now, true );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return ( new SnapshotRepository() )->create( $members, $owner, $now );
	}

	/**
	 * Bounded idempotent handle operations own their lifecycle transaction.
	 *
	 * @param string $operation inspect, renew or release.
	 * @param array<string,mixed> $input Validated request parameters.
	 * @param int $owner Owner.
	 * @return array<string,mixed>|\WP_Error
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function handle( string $operation, array $input, int $owner ): array|\WP_Error {
		$allowed = match ( $operation ) {
			'inspect' => [ 'token', 'page', 'per_page', 'probe_ids' ],
			'renew' => [ 'token' ],
			'release' => [ 'tokens' ],
			default => throw new \InvalidArgumentException( 'Unknown lifecycle operation.' ),
		};
		if ( [] !== array_diff( array_keys( $input ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown lifecycle input.' );
		}
		$tokens = 'release' === $operation ? ( $input['tokens'] ?? null ) : [ $input['token'] ?? '' ];
		if ( ! is_array( $tokens ) || ! array_is_list( $tokens ) || count( $tokens ) < 1 || count( $tokens ) > 4 ) {
			throw new \InvalidArgumentException( 'Release one to four unreferenced handles.' );
		}
		return FoundationStorage::atomic(
			function () use ( $operation, $input, $tokens, $owner ): array|\WP_Error {
				$loaded = $this->load( $tokens, $owner, 'renew' !== $operation );
				if ( is_wp_error( $loaded ) ) {
						return $loaded;
				}
				$now = ( $this->clock )();
				foreach ( $loaded as $checked ) {
					$valid = $this->valid( $checked, $checked['_epoch'], $now, 'renew' !== $operation );
					if ( is_wp_error( $valid ) ) {
						return $valid; }
				}
				foreach ( $loaded as $checked ) {
					$members = json_decode( $checked['members'], true, 512, JSON_THROW_ON_ERROR );
					$family  = isset( $members['version'] ) ? \BulkCatalogueManager\Requests\RequestFamily::from_scope( new \BulkCatalogueManager\External\V2\TypedScope( TypedMembers::decode( $members ), true ) ) : 'product';
					$access  = is_wp_error( $family ) ? $family : \BulkCatalogueManager\Rest\Access::user_can_family( $owner, $family );
					if ( true !== $access ) {
						return $access; }
				}
				$repository = new SnapshotRepository();
				$row        = $loaded[0];
				if ( 'renew' === $operation ) {
					$expired = $row['authorization_expires_at'] <= gmdate( 'Y-m-d H:i:s', $now );
					$preview = $expired || $row['_epoch'] !== (int) $row['authorization_epoch'];
					if ( $expired ) {
						++$row['authorization_epoch'];
					}
					$row['authorization_expires_at'] = gmdate( 'Y-m-d H:i:s', $now + SelectionToken::ttl() );
					$row['retained_until']           = gmdate( 'Y-m-d H:i:s', $now + 7 * 86400 );
					$row['released_at']              = null;
					$repository->retain( $row );
					$result                     = $this->envelope( $row, $now, false );
					$result['requires_preview'] = $preview;
					return $result;
				}
				if ( 'inspect' === $operation ) {
					return $this->envelope( $row, $now, false ) + ( 3 === ( json_decode( $row['members'], true )['version'] ?? null ) ? $this->inspect_typed( $row, $input, $owner ) : ( new SnapshotInspector() )->inspect( $row, $input ) );
				}
				$released = [];
				foreach ( $loaded as $row ) {
					if ( null === $row['released_at'] ) {
						$row['released_at']    = gmdate( 'Y-m-d H:i:s', $now );
						$row['retained_until'] = min( $row['retained_until'], gmdate( 'Y-m-d H:i:s', $now + 3600 ) );
						$repository->retain( $row );
					}
					$released[ $row['id'] ] = [
						'token'          => SnapshotToken::issue( $row ),
						'retained_until' => str_replace( ' ', 'T', $row['retained_until'] ) . 'Z',
					];
				}
				return [ 'released' => array_values( $released ) ];
			}
		);
	}

	/**
	 * Authenticate all handles before locking; sample time only after all locks.
	 *
	 * @param array<mixed> $tokens Submitted handles in semantic order.
	 * @param int $owner Owner.
	 * @param bool $current Require live current authorization.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function load( array $tokens, int $owner, bool $current ): array|\WP_Error {
		$identities = [];
		foreach ( $tokens as $token ) {
			$identity = SnapshotToken::identify( is_string( $token ) ? $token : '', $owner );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$identities[] = $identity;
		}
		$ids = array_unique( array_column( $identities, 'id' ) );
		sort( $ids, SORT_STRING );
		$locked = [];
		foreach ( $ids as $id ) {
			$locked[ $id ] = ( new SnapshotRepository() )->read( $id, $owner, true );
		}
		$now    = ( $this->clock )();
		$result = [];
		foreach ( $identities as $position => $identity ) {
			$row   = $locked[ $identity['id'] ];
			$valid = $this->valid( $row, $identity['epoch'], $now, $current );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$members = json_decode( $row['members'], true, 512, JSON_THROW_ON_ERROR );
			if ( str_starts_with( $tokens[ $position ], 'v3.' ) !== ( 3 === ( $members['version'] ?? null ) ) ) {
				return SnapshotToken::invalid(); }
			$row['_epoch'] = $identity['epoch'];
			$result[]      = $row;
		}
		return $result;
	}

	/**
	 * Retention is authoritative even when physical cleanup has not run.
	 *
	 * @param array<string,mixed>|null $row Locked snapshot.
	 * @param int $epoch Submitted epoch.
	 * @param int $now Fresh clock.
	 * @param bool $current Require current live lease.
	 * @return true|\WP_Error
	 */
	private function valid( ?array $row, int $epoch, int $now, bool $current ): bool|\WP_Error {
		if ( null === $row || $row['retained_until'] <= gmdate( 'Y-m-d H:i:s', $now ) ) {
			return self::gone();
		}
		if ( $epoch > (int) $row['authorization_epoch'] ) {
			return SnapshotToken::invalid();
		}
		if ( $current && ( $epoch !== (int) $row['authorization_epoch'] || $row['authorization_expires_at'] <= gmdate( 'Y-m-d H:i:s', $now ) ) ) {
			return Refusal::create( 'bulkcat_selection_renew_required', __( 'Your selected products are still saved. Restore your selection, then preview the change again.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
		}
		return true;
	}

	/**
	 * Resolve exact typed membership under the caller's transaction.
	 * Never renews authorization or replays a catalogue query.
	 *
	 * @param string $token Current credential.
	 * @param int $owner Current owner.
	 */
	public function selection( string $token, int $owner ): Selection|\WP_Error {
		if ( ! str_starts_with( $token, 'v2.' ) ) {
			return SnapshotToken::invalid(); }
		$rows = $this->load( [ $token ], $owner, true );
		return is_wp_error( $rows ) ? $rows : Selection::of_members( json_decode( $rows[0]['members'], true, 512, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * Read typed references through the same owner, retention and epoch checks.
	 * @param string $token Credential.
	 * @param int $owner Actor.
	 * @return array|\WP_Error
	 */
	public function typed_members( string $token, int $owner ): array|\WP_Error {
		$rows = $this->load( [ $token ], $owner, true );
		if ( is_wp_error( $rows ) ) {
			return $rows;}
		$members = json_decode( $rows[0]['members'], true, 512, JSON_THROW_ON_ERROR );
		if ( str_starts_with( $token, 'v3.' ) !== ( 3 === ( $members['version'] ?? null ) ) ) {
			return SnapshotToken::invalid();}
		return TypedMembers::decode( $members );
	}

	/**
	 * Read and store one set inside the caller's acceptance transaction.
	 *
	 * @param array<string,mixed> $intent Canonical capture intent.
	 * @param int $owner Owner.
	 * @param int $now Clock.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When an explicit identity is unavailable.
	 */
	public function capture( array $intent, int $owner, int $now ): array {
		$explicit = array_key_exists( 'ids', $intent );
		$criteria = $explicit ? Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ) : Criteria::from_conditions( $intent['conditions'] );
		$members  = ( new ProductQuery( $criteria ) )->snapshot_members( $explicit ? $intent['ids'] : null );
		if ( $explicit && count( $intent['ids'] ) !== count( $members['products'] ) + count( $members['variations'] ) ) {
			throw new \InvalidArgumentException( 'Some requested identities are not available catalogue objects.' );
		}
		return ( new SnapshotRepository() )->create( $members, $owner, $now );
	}

	/**
	 * Return the original identity without granting a new lease or leaking IDs.
	 *
	 * @param array<string,mixed>|null $row Stored result.
	 * @param int $now Clock.
	 * @param bool $replayed Whether this is the existing receipt's result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function envelope( ?array $row, int $now, bool $replayed ): array|\WP_Error {
		if ( null === $row || $row['retained_until'] <= gmdate( 'Y-m-d H:i:s', $now ) ) {
			return self::gone();
		}
		$members = json_decode( $row['members'], true );
		if ( 3 === ( $members['version'] ?? null ) ) {
			$scope  = new \BulkCatalogueManager\External\V2\TypedScope( TypedMembers::decode( $members ), true );
			$family = \BulkCatalogueManager\Requests\RequestFamily::from_scope( $scope );
			$access = is_wp_error( $family ) ? $family : \BulkCatalogueManager\Rest\Access::user_can_family( (int) $row['user_id'], $family );
			if ( true !== $access ) {
				return $access; }
			$counts = [];
			foreach ( $scope->chunks( 500 ) as $chunk ) {
				foreach ( $chunk as $ref ) {
					$counts[ $ref->kind ] = 1 + ( $counts[ $ref->kind ] ?? 0 ); }
			}
			return [
				'typed_token'              => SnapshotToken::issue( $row ),
				'key'                      => $row['id'],
				'family'                   => $family,
				'kind'                     => 'snapshot',
				'count'                    => $scope->count(),
				'by_type'                  => $counts,
				'scope_hash'               => $scope->signature(),
				'authorization_epoch'      => (int) $row['authorization_epoch'],
				'authorization_expires_at' => str_replace( ' ', 'T', $row['authorization_expires_at'] ) . 'Z',
				'retained_until'           => str_replace( ' ', 'T', $row['retained_until'] ) . 'Z',
				'requires_preview'         => $row['authorization_expires_at'] <= gmdate( 'Y-m-d H:i:s', $now ),
				'replayed'                 => $replayed,
			];
		}
		return [
			'token'                    => SnapshotToken::issue( $row ),
			'kind'                     => 'snapshot',
			'count'                    => (int) $row['object_count'],
			'by_type'                  => [
				'products'   => count( $members['products'] ),
				'variations' => count( $members['variations'] ),
			],
			'authorization_epoch'      => (int) $row['authorization_epoch'],
			'authorization_expires_at' => str_replace( ' ', 'T', $row['authorization_expires_at'] ) . 'Z',
			'retained_until'           => str_replace( ' ', 'T', $row['retained_until'] ) . 'Z',
			'requires_preview'         => $row['authorization_expires_at'] <= gmdate( 'Y-m-d H:i:s', $now ),
			'replayed'                 => $replayed,
		];
	}

	/** Retention expiry never represents empty or completed work. */
	public static function gone(): \WP_Error {
		return Refusal::create( 'bulkcat_selection_expired', __( 'These selected products are no longer retained. Choose them again before starting a new change.', 'bulk-catalogue-manager-for-woocommerce' ), 410 );
	}
	/**
	 * Normalize typed selection intent without resolving live leases on canonical replay.
	 * @param string $operation Selection operation.
	 * @param array $input Raw or canonical body.
	 * @param int $owner Owner.
	 * @return array|\WP_Error Canonical signed shape.
	 */
	public function typed_intent( string $operation, array $input, int $owner ): array|\WP_Error {
		try {
			\BulkCatalogueManager\External\V2\Rules::json( $input, 65536 );
			$canonical = array_key_exists( 'version', $input );
			\BulkCatalogueManager\External\V2\Rules::ensure( ! $canonical || 1 === $input['version'], 'Unsupported grant version.' );
			\BulkCatalogueManager\External\V2\Rules::ensure( 2 === ( $input['serializer'] ?? null ), 'Unsupported serializer.' );
			if ( 'selection.capture_typed' === $operation ) {
				\BulkCatalogueManager\External\V2\Rules::ensure( [] === array_diff( array_keys( $input ), [ 'version', 'serializer', 'family', 'roots', 'include_descendants' ] ) && is_array( $input['roots'] ?? null ) && count( $input['roots'] ) >= 1 && count( $input['roots'] ) <= 1000 && is_bool( $input['include_descendants'] ?? false ), 'Invalid typed capture.' );
				$roots  = TypedMembers::normalize( $input['roots'] );
				$family = \BulkCatalogueManager\Requests\RequestFamily::from_scope( new \BulkCatalogueManager\External\V2\TypedScope( $roots ) );
				if ( is_wp_error( $family ) || ( isset( $input['family'] ) && $input['family'] !== $family ) ) {
					return \BulkCatalogueManager\Rest\RequestsController::unknown(); }
				$out = [
					'version'             => 1,
					'serializer'          => 2,
					'family'              => $family,
					'roots'               => $roots,
					'include_descendants' => $input['include_descendants'] ?? false,
				];
			} else {
				\BulkCatalogueManager\External\V2\Rules::ensure( 'selection.compose_typed' === $operation && [] === array_diff( array_keys( $input ), [ 'version', 'serializer', 'family', 'typed_token', 'add_targets', 'remove_targets', 'add_typed_token', 'remove_typed_token' ] ), 'Invalid typed composition.' );
				$mutations = array_intersect_key( $input, array_flip( [ 'add_targets', 'remove_targets', 'add_typed_token', 'remove_typed_token' ] ) );
				\BulkCatalogueManager\External\V2\Rules::ensure( count( $mutations ) === 1 && is_string( $input['typed_token'] ?? null ) && str_starts_with( $input['typed_token'], 'v3.' ), 'Invalid composition identity.' );
				$id = SnapshotToken::identify( $input['typed_token'], $owner );
				if ( is_wp_error( $id ) ) {
					return $id; }
				$key = array_key_first( $mutations );
				if ( str_ends_with( $key, '_targets' ) ) {
					\BulkCatalogueManager\External\V2\Rules::ensure( is_array( $mutations[ $key ] ) && count( $mutations[ $key ] ) >= 1 && count( $mutations[ $key ] ) <= 1000, 'Invalid operands.' );
					$mutations[ $key ] = TypedMembers::normalize( $mutations[ $key ] ); } else {
					$other = SnapshotToken::identify( is_string( $mutations[ $key ] ) ? $mutations[ $key ] : '', $owner );
					if ( is_wp_error( $other ) || ! str_starts_with( $mutations[ $key ], 'v3.' ) ) {
						return SnapshotToken::invalid(); }
					}
					$family = $input['family'] ?? null;
					if ( ! $canonical ) {
						$members = $this->typed_members( $input['typed_token'], $owner );
						if ( is_wp_error( $members ) ) {
										return $members;
						} $family = \BulkCatalogueManager\Requests\RequestFamily::from_scope( new \BulkCatalogueManager\External\V2\TypedScope( $members ) ); }
					\BulkCatalogueManager\External\V2\Rules::ensure( in_array( $family, [ 'product', 'category' ], true ) && ( ! isset( $input['family'] ) || $input['family'] === $family ), 'False family.' );
					$out = [
						'version'     => 1,
						'serializer'  => 2,
						'family'      => $family,
						'typed_token' => $input['typed_token'],
					] + $mutations;
			}
			if ( $canonical && \BulkCatalogueManager\Engine\Preview::canonical( $out ) !== \BulkCatalogueManager\Engine\Preview::canonical( $input ) ) {
				return \BulkCatalogueManager\Rest\RequestsController::unknown(); }
			return $out;
		} catch ( \InvalidArgumentException $error ) {
			return \BulkCatalogueManager\Rest\RequestsController::unknown(); }
	}

	/**
	 * Authorize new exact selection work. Category root expansion is owned by C1.
	 * @param string $operation Typed operation.
	 * @param array $intent Canonical intent.
	 * @param int $owner Owner.
	 * @return array|\WP_Error Exact tuples.
	 */
	public function prepare_typed( string $operation, array $intent, int $owner ): array|\WP_Error {
		$access = \BulkCatalogueManager\Rest\Access::user_can_family( $owner, $intent['family'] );
		if ( true !== $access ) {
			return $access; }
		if ( 'selection.capture_typed' === $operation ) {
			if ( 'category' === $intent['family'] || $intent['include_descendants'] ) {
				return \BulkCatalogueManager\External\Refusals::incompatible( \BulkCatalogueManager\External\V2\Inspector::unavailable() ); }
			$targets  = $intent['roots'];
			$explicit = $targets;
		} else {
			$mutations = array_intersect_key( $intent, array_flip( [ 'add_targets', 'remove_targets', 'add_typed_token', 'remove_typed_token' ] ) );
			$key       = array_key_first( $mutations );
			$tokens    = [ $intent['typed_token'] ];
			if ( str_ends_with( $key, '_token' ) ) {
				$tokens[] = $intent[ $key ]; }
			$loaded = $this->load( $tokens, $owner, true );
			if ( is_wp_error( $loaded ) ) {
				return $loaded; }
			$base    = TypedMembers::decode( json_decode( $loaded[0]['members'], true, 512, JSON_THROW_ON_ERROR ) );
			$operand = isset( $loaded[1] ) ? TypedMembers::decode( json_decode( $loaded[1]['members'], true, 512, JSON_THROW_ON_ERROR ) ) : $intent[ $key ];
			foreach ( [ $base, $operand ] as $members ) {
				if ( \BulkCatalogueManager\Requests\RequestFamily::from_scope( new \BulkCatalogueManager\External\V2\TypedScope( $members ) ) !== $intent['family'] ) {
					return SnapshotToken::invalid(); }
			}
			$targets  = str_starts_with( $key, 'add_' ) ? TypedMembers::union( $base, $operand ) : TypedMembers::subtract( $base, $operand );
			$explicit = 'add_targets' === $key ? $operand : [];
		}
		foreach ( $explicit as $wire ) {
			$access = \BulkCatalogueManager\External\V2\Permission::check( $owner, \BulkCatalogueManager\Extension\V2\TargetRef::from_array( $wire ) );
			if ( true !== $access ) {
				return $access; }
		}
		foreach ( $loaded ?? [] as $row ) {
			$valid = $this->valid( $row, $row['_epoch'], ( $this->clock )(), true );
			if ( is_wp_error( $valid ) ) {
				return $valid; }
		}
		if ( [] === $targets ) {
			return \BulkCatalogueManager\Rest\RequestsController::unknown(); }
		return $targets;
	}

	/**
	 * Commit membership only under the existing request transaction.
	 * @param string $operation Typed operation.
	 * @param array $intent Signed intent.
	 * @param array $request Credentials.
	 * @param int $owner Owner.
	 * @return array|\WP_Error Stored row.
	 */
	public function accept_typed( string $operation, array $intent, array $request, int $owner ): array|\WP_Error {
		FoundationStorage::require_transaction();
		// The signed intent stays immutable; only credentials for the same identities may renew.
		if ( 'selection.compose_typed' === $operation ) {
			foreach ( [ 'typed_token', 'add_typed_token', 'remove_typed_token' ] as $key ) {
				if ( ! array_key_exists( $key, $intent ) || ! array_key_exists( $key, $request ) ) {
					continue;
				}
				$token    = $request[ $key ];
				$expected = SnapshotToken::identify( $intent[ $key ], $owner );
				$current  = SnapshotToken::identify( is_string( $token ) ? $token : '', $owner );
				if ( is_wp_error( $expected ) || is_wp_error( $current ) || ! str_starts_with( $token, 'v3.' ) || $expected['id'] !== $current['id'] ) {
					return SnapshotToken::invalid();
				}
				$intent[ $key ] = $token;
			}
		}
		$members = $this->prepare_typed( $operation, $intent, $owner );
		return is_wp_error( $members ) ? $members : ( new SnapshotRepository() )->create(
			[
				'version' => 3,
				'targets' => $members,
			],
			$owner,
			( $this->clock )()
		);
	}

	/**
	 * Bounded identity-only disclosure; unavailable members keep their counted slots.
	 * @param array $row Verified stored selection.
	 * @param array $input Page input.
	 * @param int $owner Owner.
	 * @return array Bounded page.
	 */
	private function inspect_typed( array $row, array $input, int $owner ): array {
		if ( array_key_exists( 'probe_ids', $input ) ) {
			throw new \InvalidArgumentException( 'Typed identity probes require typed target support.' ); }
		$page  = $input['page'] ?? 1;
		$limit = $input['per_page'] ?? 25;
		if ( ! is_int( $page ) || $page < 1 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid typed page.' ); }
		$targets = TypedMembers::decode( json_decode( $row['members'], true, 512, JSON_THROW_ON_ERROR ) );
		$items   = [];
		foreach ( array_slice( $targets, ( $page - 1 ) * $limit, $limit ) as $wire ) {
			$allowed = true === \BulkCatalogueManager\External\V2\Permission::check( $owner, \BulkCatalogueManager\Extension\V2\TargetRef::from_array( $wire ) );
			$items[] = [
				'target'    => $allowed ? $wire : null,
				'available' => $allowed,
			]; }
		return [
			'items'    => $items,
			'page'     => $page,
			'per_page' => $limit,
			'total'    => count( $targets ),
		];
	}
}
