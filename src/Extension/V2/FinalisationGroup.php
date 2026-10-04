<?php
/**
 * FinalisationGroup for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** FinalisationGroup immutable contract value. */
final class FinalisationGroup {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $id Contract value.
	 * @param string $chunk_id Contract value.
	 * @param string $provider Contract value.
	 * @param string $identity Contract value.
	 * @param int $contract Contract value.
	 * @param string $provider_version Contract value.
	 * @param string $manifest_hash Contract value.
	 * @param string $family Contract value.
	 * @param string $origin_mode Contract value.
	 * @param array $entries Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $id, public readonly string $chunk_id, public readonly string $provider, public readonly string $identity, public readonly int $contract, public readonly string $provider_version, public readonly string $manifest_hash, public readonly string $family, public readonly string $origin_mode, public readonly array $entries ) {
		Rules::hex( $id, 32 );
		Rules::hex( $chunk_id, 32 );
		Rules::hex( $manifest_hash, 64 );
		Rules::provider( $provider );
		Rules::identity( $identity );
		Rules::text( $provider_version, 40 );
		Rules::ensure( 2 === $contract && in_array( $family, [ 'product', 'category' ], true ) && in_array( $origin_mode, [ 'apply', 'replay', 'undo', 'redo' ], true ) && array_is_list( $entries ) && count( $entries ) >= 1 && count( $entries ) <= 500, 'Invalid complete finalisation group.' );
		$targets = [];
		$intents = [];
		$pairs   = [];
		$wire    = [];
		foreach ( array_keys( $entries ) as $position ) {
			Rules::ensure( null === \ReflectionReference::fromArrayElement( $entries, $position ), 'Mutable references are not contract values.' );
		}
		foreach ( $entries as $entry ) {
			Rules::ensure( $entry instanceof Finalisation, 'Invalid group entry.' );
			Rules::key( $entry->field, $provider );
			Rules::ensure( ( 'category' === $family ) === ( 'term' === $entry->target->kind ), 'Mixed finalisation families.' );
			$key  = $entry->target->key();
			$pair = $key . ':' . $entry->field;
			Rules::ensure( ! isset( $intents[ $entry->id ] ) && ! isset( $pairs[ $pair ] ), 'Duplicate finalisation membership.' );
			$intents[ $entry->id ] = true;
			$pairs[ $pair ]        = true;
			$targets[ $key ]       = ( $targets[ $key ] ?? 0 ) + 1;
			Rules::ensure( $targets[ $key ] <= 5 && count( $targets ) <= 100, 'Finalisation target bound exceeded.' );
			$wire[] = [
				'id'         => $entry->id,
				'intent_id'  => $entry->intent_id,
				'generation' => $entry->generation,
				'target'     => $entry->target->to_array(),
				'field'      => $entry->field,
				'format'     => $entry->format,
				'outcome'    => $entry->outcome,
			];
		}
		// Reserve the largest possible Context integers: the accepted signature carries Context separately.
		Rules::json(
			[
				'id'               => $id,
				'chunk_id'         => $chunk_id,
				'provider'         => $provider,
				'identity'         => $identity,
				'contract'         => $contract,
				'provider_version' => $provider_version,
				'manifest_hash'    => $manifest_hash,
				'family'           => $family,
				'origin_mode'      => $origin_mode,
				'context'          => [
					'mode'     => 'finalise',
					'user_id'  => PHP_INT_MAX,
					'batch_id' => PHP_INT_MAX,
				],
				'entries'          => $wire,
			],
			262144
		);
	}
	/** Count distinct complete native tuples. */
	public function target_count(): int {
		return count( array_unique( array_map( static fn( Finalisation $entry ): string => $entry->target->key(), $this->entries ) ) );
	}
}
