<?php
/**
 * New acceptance of a durable Apply or undo request (T83).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ExactScope;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\ProviderFailure;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotService;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Runs inside RequestService's acceptance transaction, after the receipt's
 * unique claim and only for a request no receipt has answered. It checks
 * the request against the catalogue as it is now and records the batch
 * header and every item; RequestService records the receipt in the same
 * transaction and schedules work only after it commits. Nothing here
 * enqueues, fires hooks or writes a product.
 *
 * Apply: the operations must still be what the registry makes of them
 * (and the registry's list is what is used and recorded); the snapshot
 * credential must be the owner's live one for the intent's snapshot; the
 * exact scope is built once (ExactScope) and must carry the intent's scope
 * hash, and the preview hash must bind that scope, the credential, the
 * operations and the settings; the snapshot lease is checked again after
 * expansion; then exactly the checked scope is recorded
 * (BatchFactory::record_scope()), unavailable members as items already
 * failed.
 *
 * An external change (T106, docs/EXTENSIONS.md §7.1, §7.2) is validated with
 * its provider (409 `bulkcat_provider_unavailable` when it is not active), and
 * the preview hash must also bind the provider's identity and state token as
 * they are now (External\Binding): a stale refusal then says why in
 * `data.reason`, `scope_changed` or `provider_changed`.
 *
 * Undo: the batch undone is locked first, before this transaction reads
 * anything, then planned afresh and must still have the intent's plan hash; the undo batch is recorded and the batch undone moved
 * to undoing in the same transaction, so two undos of one batch serialise
 * and the loser records nothing.
 */
final class BatchAcceptance {
	/**
	 * Accept a request whose grant is verified and whose receipt is claimed.
	 *
	 * @param array<string,mixed> $claims      Verified grant claims.
	 * @param array<string,mixed> $intent      Canonical intent.
	 * @param array<string,mixed> $credentials Transport values: `token` (a snapshot's current credential) and `preview_hash`.
	 * @param \Closure            $clock       Current Unix time.
	 * @return array<string,mixed>|\WP_Error The recorded batch, or the refusal (the caller rolls back).
	 */
	public static function accept( array $claims, array $intent, array $credentials, \Closure $clock ): array|\WP_Error {
		$batches = new BatchRepository();
		$factory = new BatchFactory( null, $batches );
		if ( 'batch.undo' === $claims['operation'] ) {
			// Locked before this transaction reads anything, so it plans from what a concurrent undo committed.
			$batches->lock( $intent['batch_id'] );
		}
		// A batch already under this request id was not made by this grant: refuse, never make another.
		$earlier = $factory->earlier( $claims['uuid'], $claims['owner'], static fn(): bool => false );
		if ( null !== $earlier ) {
			return $earlier;
		}
		return 'batch.undo' === $claims['operation']
			? self::undo( $claims, $intent, $batches, $factory )
			: self::apply( $claims, $intent, $credentials, $clock, $factory );
	}

	/**
	 * The refusal for an Apply that no longer matches its preview, with its
	 * reason for an external change (T106).
	 *
	 * @param string $reason `scope_changed` or `provider_changed`, or '' for a native change.
	 */
	public static function stale( string $reason = '' ): \WP_Error {
		return Refusal::create( BatchFactory::STALE_PREVIEW, __( 'This change no longer matches its preview. Preview the exact selected products again, then apply it.', 'bulk-catalogue-manager-for-woocommerce' ), 409, '' === $reason ? [] : [ 'reason' => $reason ] );
	}

	/**
	 * Accept an Apply.
	 *
	 * @param array<string,mixed> $claims      Verified grant claims.
	 * @param array<string,mixed> $intent      Canonical Apply intent.
	 * @param array<string,mixed> $credentials Transport values.
	 * @param \Closure            $clock       Current Unix time.
	 * @param BatchFactory        $factory     The factory.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function apply( array $claims, array $intent, array $credentials, \Closure $clock, BatchFactory $factory ): array|\WP_Error {
		$operations = BatchIntent::current_operations( $intent );
		if ( ! is_array( $operations ) ) {
			return false === $operations ? self::stale() : $operations;
		}
		$token = $credentials['token'] ?? null;
		if ( isset( $intent['snapshot'] ) ) {
			$identity = SnapshotToken::identify( is_string( $token ) ? $token : '', $claims['owner'] );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			if ( $identity['id'] !== $intent['snapshot'] ) {
				return SnapshotToken::invalid();
			}
			$selection = ( new SnapshotService( $clock ) )->selection( $token, $claims['owner'] );
			if ( is_wp_error( $selection ) ) {
				return $selection;
			}
			$bound = $token;
		} else {
			$selection = Selection::of_ids( $intent['ids'] );
			$bound     = $selection->ids;
		}
		$scope    = ExactScope::build( $selection, $operations, $factory );
		$provider = Actions::instance()->external_provider( $operations );
		$binding  = [];
		if ( null !== $provider ) {
			$gone = array_flip( $scope['unavailable'] );
			try {
				$binding = Binding::of( $provider, $operations, array_values( array_filter( array_column( $scope['objects'], 0 ), static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) ), RunContext::apply( $claims['owner'], 0 ) );
			} catch ( ProviderFailure $e ) {
				return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
			}
		}
		$hash = Preview::hash( $bound, $operations, Settings::load(), $scope['scope_hash'], $binding );
		if ( ! hash_equals( $scope['scope_hash'], $intent['scope_hash'] ) ) {
			return self::stale( null === $provider ? '' : 'scope_changed' );
		}
		if ( ! is_string( $credentials['preview_hash'] ?? null ) || ! hash_equals( $hash, $credentials['preview_hash'] ) ) {
			return self::stale( null === $provider ? '' : 'provider_changed' );
		}
		if ( [] === $scope['objects'] ) {
			return Refusal::invalid( BatchFactory::EMPTY, __( 'No products are selected. Choose products before making a change.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( isset( $intent['snapshot'] ) ) {
			// Expansion may take a while: the lease must still be live, under the same lock.
			$live = ( new SnapshotService( $clock ) )->selection( $token, $claims['owner'] );
			if ( is_wp_error( $live ) ) {
				return $live;
			}
		}
		$summary = [
			'kind'             => isset( $intent['snapshot'] ) ? 'snapshot' : 'ids',
			'count'            => $selection->count,
			'selected'         => $selection->count,
			'materialised'     => count( $scope['objects'] ),
			'scope_hash'       => $scope['scope_hash'],
			'variations_added' => $scope['added'],
			'parents_left_out' => $scope['left_out'],
			'unavailable'      => count( $scope['unavailable'] ),
		];
		if ( isset( $intent['snapshot'] ) ) {
			$summary['snapshot_id'] = $intent['snapshot'];
		} else {
			$summary['ids'] = $selection->ids;
		}
		// T107: the provider manifest commits with the header, the items and the receipt.
		return $factory->record_scope( $claims, $operations, $scope['objects'], $summary, null, $scope['missing'], null === $provider ? null : Manifest::build( $provider, $operations ) );
	}

	/**
	 * Accept an undo.
	 *
	 * @param array<string,mixed> $claims  Verified grant claims.
	 * @param array<string,mixed> $intent  Canonical undo intent.
	 * @param BatchRepository     $batches Batches.
	 * @param BatchFactory        $factory The factory.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function undo( array $claims, array $intent, BatchRepository $batches, BatchFactory $factory ): array|\WP_Error {
		$plan = ( new Planner( $batches ) )->plan( $intent['batch_id'], $claims['owner'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( $plan->hash(), $intent['plan_hash'] ) ) {
			return Refusal::create( BatchFactory::STALE_PREVIEW, __( 'This undo no longer matches its preview: the products or the settings have changed since. Preview the undo again, then confirm it.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
		}
		$scope = $factory->undo_scope( $plan, $intent['force'] );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		[ $original, $previous, $objects, $outline, $snapshot, $manifest ] = $scope;
		$result = $factory->record_scope( $claims, array_values( $outline ), $objects, $snapshot, $original, [], $manifest );
		if ( ! $batches->transition( $original, $previous, 'undoing' ) ) {
			return Refusal::create( Planner::NOT_REVERSIBLE, Planner::undoing_sentence(), 409, [ 'reason' => Planner::UNDO_IN_PROGRESS ] );
		}
		return $result;
	}
}
