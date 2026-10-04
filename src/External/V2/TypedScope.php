<?php
/**
 * Exact native reference scope; never recaptures a filter.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\{Scope, TargetRef};
use BulkCatalogueManager\Selection\{TypedMembers, SnapshotService};
use BulkCatalogueManager\Rest\RequestsController;
defined( 'ABSPATH' ) || exit;
/** The complete scope signature is streamed over canonical full tuples. */
final class TypedScope implements Scope {
	private readonly array $targets;
	/**
	 * Retain immutable membership and whether unavailable members were captured.
	 * @param array $targets Native wire tuples.
	 * @param bool $captured Whether this is a saved snapshot.
	 * @param array|null $step_indices Core-owned applicability for this expanded scope.
	 */
	public function __construct( array $targets, public readonly bool $captured = false, private readonly ?array $step_indices = null ) {
		$this->targets = TypedMembers::normalize( $targets );
		if ( null !== $step_indices ) {
			Rules::ensure( count( $step_indices ) === count( $this->targets ), 'Incomplete step applicability.' );
			foreach ( $this->targets as $wire ) {
				$indices = $step_indices[ TargetRef::from_array( $wire )->key() ] ?? null;
				Rules::ensure( is_array( $indices ) && array_is_list( $indices ) && [] !== $indices, 'Missing target step applicability.' );
				foreach ( $indices as $index ) {
					Rules::ensure( is_int( $index ) && $index >= 0, 'Invalid step index.' );
				}
				Rules::ensure( count( array_unique( $indices ) ) === count( $indices ), 'Duplicate step applicability.' );
			}
		}
	}
	/**
	 * Authenticate one explicit or owner-bound stored selection.
	 * @param array $selection Exactly one selection key.
	 * @param int $actor Requester.
	 * @return Scope|\WP_Error
	 */
	public static function from_request( array $selection, int $actor ): Scope|\WP_Error {
		try {
			Rules::ensure( count( $selection ) === 1, 'Exactly one selection is required.' );
			$key   = array_key_first( $selection );
			$value = $selection[ $key ];
			if ( 'targets' === $key ) {
				Rules::ensure( is_array( $value ) && count( $value ) >= 1 && count( $value ) <= 1000, 'Invalid explicit scope.' );
				return new self( $value );}
			Rules::ensure( in_array( $key, [ 'token','typed_token' ], true ) && is_string( $value ) && str_starts_with( $value, 'token' === $key ? 'v2.' : 'v3.' ), 'Invalid snapshot credential.' );
			$members = ( new SnapshotService() )->typed_members( $value, $actor );
			return is_wp_error( $members ) ? $members : new self( $members, true );
		} catch ( \InvalidArgumentException $error ) {
			return RequestsController::unknown();}
	}
	/** Exact membership count. */
	public function count(): int {
		return count( $this->targets );}
	/**
	 * Iterate without materializing all TargetRef objects.
	 * @param int $size Chunk size.
	 * @return iterable Reference chunks.
	 */
	public function chunks( int $size ): iterable {
		Rules::ensure( $size >= 1, 'Invalid chunk size.' );
		$chunk = [];
		foreach ( $this->targets as $wire ) {
			$chunk[] = TargetRef::from_array( $wire );
			if ( count( $chunk ) === $size ) {
				yield $chunk;
					$chunk = [];}
		}
		if ( [] !== $chunk ) {
			yield $chunk;}
	}
	/** Stable exact tuple identity, including unavailable members. */
	public function signature(): string {
		$hash = hash_init( 'sha256' );
		foreach ( $this->chunks( 500 ) as $chunk ) {
			foreach ( $chunk as $ref ) {
				hash_update( $hash, $ref->key() . "\n" );
			}
		}return hash_final( $hash );
	}
	/** Server-only materialization; never sent as a snapshot response. */
	public function targets(): array {
		return $this->targets;}

	/**
	 * Core-only projection of authoritative expansion; the SDK Scope interface is unchanged.
	 * @param int $index Original ordered step index.
	 * @param int $step_count Validated operation count.
	 */
	public function for_step( int $index, int $step_count ): self {
		Rules::ensure( $index >= 0 && $index < $step_count, 'Unknown step.' );
		if ( null === $this->step_indices ) {
			return $this;
		}
		$targets = [];
		foreach ( $this->targets as $wire ) {
			$indices = $this->step_indices[ TargetRef::from_array( $wire )->key() ];
			foreach ( $indices as $assigned ) {
				Rules::ensure( $assigned < $step_count, 'Unknown assigned step.' );
			}
			if ( in_array( $index, $indices, true ) ) {
				$targets[] = $wire;
			}
		}
		return new self( $targets, $this->captured );
	}
}
