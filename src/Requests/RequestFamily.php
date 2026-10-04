<?php
/**
 * Authoritative family derivation after identity verification.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Extension\V2\Scope;
use BulkCatalogueManager\External\V2\{IntentCodec, Rules, TypedScope};
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
/** Never infers authorization from labels or requested entry locations. */
final class RequestFamily {
	/**
	 * Inspect every validated reference.
	 * @param Scope $scope Exact members.
	 * @return string|\WP_Error
	 */
	public static function from_scope( Scope $scope ): string|\WP_Error {
		$family = null;
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			foreach ( $chunk as $ref ) {
				$current = 'term' === $ref->kind ? 'category' : 'product';
				if ( null !== $family && $family !== $current ) {
					return \BulkCatalogueManager\Rest\RequestsController::unknown();
				}$family = $current;}
		}
		return $family ?? \BulkCatalogueManager\Rest\RequestsController::unknown();
	}
	/**
	 * Read only a signed, owner/fingerprint-verified intent; no live provider or lease.
	 * @param array $intent Canonical intent.
	 * @param array $claims Verified signer claims.
	 * @param int $actor Requester.
	 * @return string|\WP_Error
	 */
	public static function from_verified_intent( array $intent, array $claims, int $actor ): string|\WP_Error {
		if ( ( $claims['owner'] ?? null ) !== $actor || ! hash_equals( $claims['fingerprint'] ?? '', CaptureIntent::fingerprint( $intent ) ) ) {
			return Refusals::forbidden();}
		$checked = str_ends_with( $claims['operation'], '_typed' ) ? ( new \BulkCatalogueManager\Selection\SnapshotService() )->typed_intent( $claims['operation'], $intent, $actor ) : IntentCodec::canonical( $claims['operation'], $intent, $actor );
		if ( is_wp_error( $checked ) ) {
			return $checked;}
		if ( isset( $intent['batch_id'] ) ) {
			$family = self::from_batch( $intent['batch_id'], $actor );
			return $family === $intent['family'] ? $family : Refusals::forbidden();}
		return $intent['family'];
	}
	/**
	 * Cross-check stored manifest, selection and materialized tuple family.
	 * @param int $batch_id Stored batch.
	 * @param int $actor Requester.
	 * @return string|\WP_Error
	 */
	public static function from_batch( int $batch_id, int $actor ): string|\WP_Error {
		$repository = new BatchRepository();
		$batch      = $repository->find_strict( $batch_id );
		if ( null === $batch ) {
			return Refusals::forbidden();}
		if ( isset( $batch['extensions']['serializer'] ) && 2 !== $batch['extensions']['serializer'] ) {
			return Refusals::incompatible( \BulkCatalogueManager\External\V2\Inspector::unavailable() );}
		if ( 2 !== ( $batch['extensions']['serializer'] ?? null ) ) {
			return true === Access::user_can_family( $actor, 'product' ) ? 'product' : Refusals::forbidden();
		}
		$family        = $batch['extensions']['family'] ?? null;
		$stored_family = $batch['selection']['family'] ?? null;
		if ( ! in_array( $family, [ 'product','category' ], true ) || $family !== $stored_family ) {
			return Refusals::forbidden();}
		$targets = $repository->typed_targets( $batch_id );
		if ( count( $targets ) !== $batch['total_items'] || self::from_scope( new TypedScope( $targets ) ) !== $family ) {
			return Refusals::forbidden();}
		$access = Access::user_can_family( $actor, $family );
		return true === $access ? $family : $access;
	}

	/**
	 * Disclose a verified accepted Apply after ordinary pruning retained its header.
	 * New work and unpruned records still require all materialized tuples.
	 * @param array $batch Receipt-owned stored header.
	 * @param array $intent Signed canonical intent.
	 * @param array $claims Verified grant claims.
	 * @param int $actor Current requester.
	 * @return string|\WP_Error
	 */
	public static function from_receipt( array $batch, array $intent, array $claims, int $actor ): string|\WP_Error {
		if ( null === $batch['pruned_gmt'] || 'batch.apply' !== $claims['operation'] ) {
			return self::from_batch( $batch['batch_id'], $actor );
		}
		$family    = self::from_verified_intent( $intent, $claims, $actor );
		$manifest  = $batch['extensions'] ?? [];
		$selection = $batch['selection'];
		if ( is_wp_error( $family ) || ! in_array( $batch['status'], [ 'complete', 'partial', 'failed', 'cancelled', 'undone' ], true ) || empty( $batch['finished_gmt'] ) || '' === $batch['pruned_gmt'] || $batch['user_id'] !== $claims['owner'] || $batch['uuid'] !== $claims['uuid'] || $batch['source'] !== $intent['source'] ) {
			return Refusals::forbidden();
		}
		foreach ( [
			'serializer' => 2,
			'family'     => $family,
			'scope_hash' => $intent['scope_hash'],
			'count'      => $batch['total_items'],
		] as $key => $expected ) {
			if ( ( $selection[ $key ] ?? null ) !== $expected ) {
				return Refusals::forbidden();
			}
		}
		foreach ( [
			'serializer' => 2,
			'family'     => $family,
			'actor'      => $actor,
			'entry'      => $intent['entry'],
			'contract'   => $intent['external']['contract'],
			'provider'   => $intent['external']['provider'],
			'identity'   => $intent['external']['identity'],
		] as $key => $expected ) {
			if ( ( $manifest[ $key ] ?? null ) !== $expected ) {
				return Refusals::forbidden();
			}
		}
		if ( \BulkCatalogueManager\Engine\Preview::canonical( $batch['operations'] ) !== \BulkCatalogueManager\Engine\Preview::canonical( $intent['operations'] ) ) {
			return Refusals::forbidden();
		}
		$access = Access::user_can_family( $actor, $family );
		return true === $access ? $family : $access;
	}
}
