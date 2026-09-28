<?php
/**
 * Canonical capture intent: validate once, retain defaults with the signed grant.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Rest\RouteSchema;
defined( 'ABSPATH' ) || exit;

/** Reject unknown keys instead of silently broadening a capture. */
final class CaptureIntent {
	/**
	 * Normalize new input or validate a previously canonical version.
	 *
	 * @param array<string,mixed> $input IDs or registry query parameters.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On malformed intent.
	 */
	public static function normalize( array $input ): array {
		$canonical = array_key_exists( 'version', $input );
		if ( $canonical && 1 !== $input['version'] ) {
			throw new \InvalidArgumentException( 'Unsupported intent version.' );
		}
		unset( $input['version'] );
		if ( 1 !== count( $input ) ) {
			throw new \InvalidArgumentException( 'Choose IDs or a query, not both.' );
		}
		if ( array_key_exists( 'ids', $input ) ) {
			$ids = $input['ids'];
			if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > 1000 ) {
				throw new \InvalidArgumentException( 'Choose at most 1,000 explicit identities.' );
			}
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) {
					throw new \InvalidArgumentException( 'IDs must be positive integers.' );
				}
			}
			$ids = array_values( array_unique( $ids ) );
			sort( $ids, SORT_NUMERIC );
			return [
				'version' => 1,
				'ids'     => $ids,
			];
		}
		if ( $canonical && isset( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
			return [
				'version'    => 1,
				'conditions' => Criteria::from_conditions( $input['conditions'] )->conditions(),
			];
		}
		if ( ! $canonical && isset( $input['query'] ) && is_array( $input['query'] ) ) {
			$schema  = new RouteSchema( Registry::instance() );
			$allowed = $schema->selection_args();
			unset( $allowed['ids'], $allowed['uuid'], $allowed['request_proof'], $allowed['intent'] );
			if ( [] !== array_diff_key( $input['query'], $allowed ) ) {
				throw new \InvalidArgumentException( 'Unknown capture query argument.' );
			}
			foreach ( $input['query'] as $key => $value ) {
				$valid = rest_validate_value_from_schema( $value, $allowed[ $key ], $key );
				if ( is_wp_error( $valid ) ) {
					throw new \InvalidArgumentException( 'Malformed query parameter.' );
				}
			}
			return [
				'version'    => 1,
				'conditions' => Criteria::from_array( $schema->criteria_input( $input['query'] ) )->conditions(),
			];
		}
		throw new \InvalidArgumentException( 'Invalid capture intent.' );
	}

	/**
	 * Hash canonical intent, not transport fields or current settings.
	 *
	 * @param array<string,mixed> $intent Canonical intent.
	 */
	public static function fingerprint( array $intent ): string {
		return hash( 'sha256', (string) wp_json_encode( self::sort( $intent ) ) );
	}

	/**
	 * Order map keys recursively while preserving semantic list order.
	 *
	 * @param array<mixed> $value JSON-compatible value.
	 * @return array<mixed>
	 */
	private static function sort( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$item = self::sort( $item );
			}
		}
		return $value;
	}
}
