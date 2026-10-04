<?php
/** Strict server-side provenance serialization; never a browser argument. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
/** Immutable originals and actual directional observations have separate slots. */
final class HistoryCodec {
	/** Encode the complete ordered immutable history. */
	public static function encode( API\FieldHistory $history ): array {
		$out                  = [ 'serializer' => 1 ] + get_object_vars( $history );
		$out['target']        = $history->target->to_array();
		$out['contributions'] = array_map(
			static fn( API\Contribution $c ): array => [
				'step'   => [
					'index'      => $c->step->index,
					'action'     => $c->step->action,
					'field'      => $c->step->field,
					'args'       => $c->step->args,
					'validation' => get_object_vars( $c->step->validation ),
				],
				'state'  => $c->state,
				'origin' => get_object_vars( $c->origin ),
			],
			$history->contributions
		);
		Rules::json( $out, 524288 );
		return $out;
	}
	/** Decode trusted storage defensively, without casting malformed versions. */
	public static function decode( array $wire ): API\FieldHistory|\WP_Error {
		try {
			Rules::json( $wire, 524288 );
			self::keys( $wire, [ 'serializer', 'provider', 'identity', 'contract', 'field', 'format', 'target', 'before', 'after', 'contributions' ] );
			Rules::ensure( 1 === $wire['serializer'] && is_array( $wire['contributions'] ) && array_is_list( $wire['contributions'] ), 'Invalid history serializer.' );
			$contributions = [];
			foreach ( $wire['contributions'] as $c ) {
				self::keys( $c, [ 'step', 'state', 'origin' ] );
				self::keys( $c['step'], [ 'index', 'action', 'field', 'args', 'validation' ] );
				$step            = $c['step'];
				$contributions[] = new API\Contribution( new API\ValidatedStep( $step['index'], $step['action'], $step['field'], $step['args'], self::snapshot( $step['validation'] ) ), $c['state'], self::snapshot( $c['origin'] ) );
			}
			return new API\FieldHistory( $wire['provider'], $wire['identity'], $wire['contract'], $wire['field'], $wire['format'], API\TargetRef::from_array( $wire['target'] ), $wire['before'], $wire['after'], $contributions );
		} catch ( \Throwable $error ) {
			return self::refusal();
		}
	}
	/**
	 * Format a storage record. The later writer must supply verified closure/readback;
	 * these flags describe its evidence and never manufacture execution authority.
	 */
	public static function journal( API\FieldHistory $original, mixed $before, mixed $after, string $direction, string $outcome, bool $closed, bool $verified, array $descriptions = [
		'before' => '',
		'after'  => '',
	] ): array {
		Rules::ensure( in_array( $direction, [ 'apply', 'undo', 'redo' ], true ) && in_array( $outcome, [ 'stored', 'partial', 'unknown', 'unchanged', 'refused' ], true ), 'Invalid directional history.' );
		Rules::json( $before, 65536 );
		Rules::json( $after, 65536 );
		self::keys( $descriptions, [ 'before', 'after' ] );
		foreach ( $descriptions as $text ) {
			Rules::text( $text, 255, true ); }
		$out = [
			'serializer'   => 1,
			'original'     => self::encode( $original ),
			'before'       => $before,
			'after'        => $after,
			'direction'    => $direction,
			'outcome'      => $outcome,
			'closed'       => $closed,
			'verified'     => $verified,
			'descriptions' => $descriptions,
		];
		Rules::json( $out, 524288 );
		return $out;
	}
	/** Invert actual observed source values, keeping original ownership unchanged. */
	public static function reversal( array $record, bool $force, int $actor, int $batch ): RecoveryState|\WP_Error {
		try {
			Rules::json( $record, 524288 );
			self::keys( $record, [ 'serializer', 'original', 'before', 'after', 'direction', 'outcome', 'closed', 'verified', 'descriptions' ] );
			Rules::ensure( 1 === $record['serializer'] && true === $record['closed'] && true === $record['verified'] && in_array( $record['outcome'], [ 'stored', 'partial' ], true ), 'Unproved or unchanged source write.' );
			$original = self::decode( $record['original'] );
			Rules::ensure( $original instanceof API\FieldHistory, 'Invalid original history.' );
			// Validate the complete envelope, not only the fields restoration consumes.
			self::journal( $original, $record['before'], $record['after'], $record['direction'], $record['outcome'], $record['closed'], $record['verified'], $record['descriptions'] );
			return new RecoveryState( $original, $record['after'], $record['before'], $force, 'undo' === $record['direction'] ? 'redo' : 'undo', $actor, $batch );
		} catch ( \Throwable $error ) {
			return self::refusal(); }
	}
	private static function snapshot( array $wire ): API\Snapshot {
		self::keys( $wire, [ 'version', 'data' ] );
		return new API\Snapshot( $wire['version'], $wire['data'] );
	}
	private static function keys( array $wire, array $keys ): void {
		Rules::ensure( count( $wire ) === count( $keys ) && [] === array_diff( array_keys( $wire ), $keys ), 'Invalid history shape.' );
	}
	private static function refusal(): \WP_Error {
		return Refusals::incompatible( Inspector::unavailable() ); }
}
