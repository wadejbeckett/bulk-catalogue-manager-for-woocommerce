<?php
/**
 * Safe, immutable synchronous receipts and centralized executor guards.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Operations\Result;
defined( 'ABSPATH' ) || exit;

/** A closure records the original invocation, independently of later Undo/status. */
final class InlineReceipt {
	/** Known native outcome codes only, never an arbitrary reason/context or prefix match. */
	private const CODES = [ '', 'bulkcat_invalid_value', 'bulkcat_cannot_edit', 'bulkcat_cannot_publish', 'bulkcat_not_found', 'bulkcat_not_available', 'bulkcat_unavailable', 'bulkcat_changed_kind', 'bulkcat_changed_during_write', 'bulkcat_variation_password_unsupported' ];

	/**
	 * New markers survive any display source; legacy inline batches also never enqueue.
	 *
	 * @param array $batch Typed header.
	 */
	public static function is_inline( array $batch ): bool {
		return array_key_exists( 'inline_receipt', $batch['selection'] ?? [] ) || 'inline' === ( $batch['source'] ?? '' );
	}

	/**
	 * Marked batches require explicit closure; legacy unfinished bookkeeping proves nothing.
	 *
	 * @param array $batch Typed header.
	 */
	public static function unclosed( array $batch ): bool {
		if ( array_key_exists( 'inline_receipt', $batch['selection'] ?? [] ) ) {
			return 'closed' !== self::read( $batch )['receipt_state'];
		}
		return self::is_inline( $batch ) && ( empty( $batch['finished_gmt'] ) || in_array( $batch['status'], [ 'queued', 'running' ], true ) );
	}

	/**
	 * Read explicit evidence only. Legacy headers never acquire fabricated closures.
	 *
	 * @param array $batch Typed header.
	 * @return array
	 */
	public static function read( array $batch ): array {
		$result  = [
			'receipt_state' => 'unclosed',
			'batch_id'      => (int) $batch['batch_id'],
			'replayed'      => true,
		];
		$marker  = $batch['selection']['inline_receipt'] ?? null;
		$closure = is_array( $marker ) && 1 === ( $marker['version'] ?? null ) ? ( $marker['closure'] ?? null ) : null;
		if ( is_array( $closure ) && in_array( $closure['write_status'] ?? null, [ 'complete', 'partial', 'failed' ], true ) && is_array( $closure['steps'] ?? null ) ) {
			$result['receipt_state'] = 'closed';
			$result['write_status']  = $closure['write_status'];
			$result['steps']         = self::project( $closure['steps'], true );
		}
		return $result;
	}

	/**
	 * Public evidence never contains values, internal fields, arbitrary codes or context.
	 *
	 * @param WriteOutcome $outcome Confirmed native outcome.
	 * @return array
	 */
	public static function safe_steps( WriteOutcome $outcome ): array {
		return self::project(
			array_map(
				static fn( Result $r ): array => [
					'field' => $r->field(),
					'state' => $r->state(),
					'code'  => $r->code(),
				],
				$outcome->results()
			)
		);
	}

	/**
	 * Project again at disclosure, including malformed or older stored metadata.
	 *
	 * @param array $steps Stored public evidence.
	 * @param bool $stored Already projected before closure; do not reinterpret through a newer registry.
	 * @return array
	 */
	private static function project( array $steps, bool $stored = false ): array {
		$safe = [];
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || ! is_string( $step['field'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $step['field'] ) || ! in_array( $step['state'] ?? null, Result::STATES, true ) ) {
				continue;
			}
			$field = $stored ? null : Registry::instance()->get( $step['field'] );
			if ( ! $stored && ( null === $field || $field->internal() ) ) {
				continue;
			}
			$safe[] = [
				'field' => $step['field'],
				'state' => $step['state'],
				'code'  => in_array( $step['code'] ?? null, self::CODES, true ) ? $step['code'] : '',
			];
		}
		return $safe;
	}

	/** Shared REST shape for PATCH and History. @return array */
	public static function schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'receipt_state' => [
					'type' => 'string',
					'enum' => [ 'not_accepted', 'unclosed', 'closed', 'refused' ],
				],
				'batch_id'      => [ 'type' => 'integer' ],
				'write_status'  => [
					'type' => 'string',
					'enum' => [ 'complete', 'partial', 'failed' ],
				],
				'replayed'      => [ 'type' => 'boolean' ],
				'steps'         => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'field' => [ 'type' => 'string' ],
							'state' => [
								'type' => 'string',
								'enum' => Result::STATES,
							],
							'code'  => [
								'type' => 'string',
								'enum' => self::CODES,
							],
						],
						'additionalProperties' => false,
					],
				],
			],
		];
	}
}
