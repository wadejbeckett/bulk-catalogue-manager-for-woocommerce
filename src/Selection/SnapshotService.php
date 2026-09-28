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
					return $this->envelope( $row, $now, false ) + ( new SnapshotInspector() )->inspect( $row, $input );
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
		foreach ( $identities as $identity ) {
			$row   = $locked[ $identity['id'] ];
			$valid = $this->valid( $row, $identity['epoch'], $now, $current );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
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
		$rows = $this->load( [ $token ], $owner, true );
		return is_wp_error( $rows ) ? $rows : Selection::of_members( json_decode( $rows[0]['members'], true, 512, JSON_THROW_ON_ERROR ) );
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
}
