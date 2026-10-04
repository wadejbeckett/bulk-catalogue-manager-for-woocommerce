<?php
/**
 * Server-authoritative validation and provider identity binding.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\External\{Guard, Refusals};
use BulkCatalogueManager\Rest\RequestsController;
defined( 'ABSPATH' ) || exit;
/** Revalidation never accepts a browser's snapshot or matches a label. */
final class Binding {
	/**
	 * Validate normalized arguments against an authorized exact scope.
	 * @param API\Provider $provider Registered family.
	 * @param API\Scope $scope Authorized members only.
	 * @param array $steps Raw operations.
	 * @param API\Context $context Recorded actor and mode.
	 * @return array|\WP_Error JSON-plain immutable binding.
	 */
	public static function of( API\Provider $provider, API\Scope $scope, array $steps, API\Context $context ): array|\WP_Error {
		$family = Actions::instance()->providers()->typed()->family( $provider->id() );
		if ( null === $family || $family['provider'] !== $provider || $family['identity'] !== $provider->identity() ) {
			return Refusals::incompatible( Inspector::unavailable() );}
		try {
			IntentCodec::steps( $steps );
			$normalized  = [];
			$descriptors = [];
			foreach ( $steps as $step ) {
				$descriptor = $family['typed']['described'][ $step['action'] ]['descriptor'] ?? null;
				if ( null === $descriptor ) {
					return Refusals::incompatible( Inspector::unavailable() );}
				if ( ! user_can( $context->user_id(), $descriptor->capability ) ) {
					return Refusals::forbidden();}
				$args = Arguments::normalize( $descriptor, $step['args'] );
				if ( is_wp_error( $args ) ) {
							return $args;}
				$normalized[]  = [
					'action' => $descriptor->id,
					'args'   => $args,
				];
				$descriptors[] = $descriptor;
			}
			$step_scopes = [];
			$assignments = [];
			foreach ( $descriptors as $index => $descriptor ) {
				$own                   = $scope instanceof TypedScope ? $scope->for_step( $index, count( $steps ) ) : $scope;
				$step_scopes[ $index ] = $own;
				foreach ( $own->chunks( 500 ) as $chunk ) {
					foreach ( $chunk as $ref ) {
						$subject = SubjectLoader::load( $ref, $context->user_id() );
						if ( is_wp_error( $subject ) ) {
							return $subject;
						}
						if ( ! in_array( $ref->kind, $descriptor->target_kinds, true ) || true !== Guard::run( $provider->id(), 'authorise', fn()=> $provider->authorise( $context->user_id(), $subject ) ) ) {
							return Refusals::forbidden();
						}
						$assignments[ $ref->key() ][] = $index;
					}
				}
			}
			Rules::ensure( count( $assignments ) === $scope->count(), 'Unassigned scope member.' );
			ksort( $assignments, SORT_STRING );
			if ( true !== Guard::run( $provider->id(), 'availability', fn()=> $provider->availability() ) ) {
				return Refusals::incompatible( Inspector::unavailable() );}
			$validated = [];
			$metadata  = [];
			foreach ( $normalized as $i => $step ) {
				$action = $family['typed']['actions'][ $step['action'] ];
				if ( true !== Guard::run( $provider->id(), 'availability', fn()=> $action->availability( $step_scopes[ $i ], $step['args'], $context ) ) ) {
					return Refusals::incompatible( Inspector::unavailable() );}
				$snapshot = Guard::run( $provider->id(), 'validate', fn()=> $action->validate( $step_scopes[ $i ], $step['args'], $context ) );
				if ( ! $snapshot instanceof API\Snapshot ) {
					return RequestsController::unknown();}
				$validated[]                 = [
					'index'      => $i,
					'action'     => $step['action'],
					'field'      => $descriptors[ $i ]->field,
					'args'       => $step['args'],
					'validation' => [
						'version' => $snapshot->version,
						'data'    => $snapshot->data,
					],
				];
				$metadata[ $step['action'] ] = get_object_vars( $descriptors[ $i ] );
			}
			$state = Guard::run( $provider->id(), 'state_token', fn()=> $provider->state_token( $scope, self::validated( [ 'steps' => $validated ] ), $context ) );
			Rules::ensure( is_string( $state ) && strlen( $state ) <= 1024, 'Invalid provider state token.' );
			Rules::json( $state, 8192 );
			return [
				'serializer'       => 2,
				'provider'         => $family['id'],
				'identity'         => $family['identity'],
				'contract'         => 2,
				'provider_version' => $family['version'],
				'provider_label'   => $family['label'],
				'actor'            => $context->user_id(),
				'scope_hash'       => $scope->signature(),
				'step_indices'     => $assignments,
				'state_token'      => $state,
				'steps'            => $validated,
				'actions'          => $metadata,
				'fields'           => $family['typed']['field_meta'],
			];
		} catch ( \Throwable $error ) {
			return Refusals::incompatible( Inspector::unavailable() );}
	}
	/**
	 * Reconstruct immutable steps from server-produced binding only.
	 * @param array $binding Server binding.
	 * @return array Validated steps.
	 */
	public static function validated( array $binding ): array {
		return array_map( static fn( array $step ): API\ValidatedStep=>new API\ValidatedStep( $step['index'], $step['action'], $step['field'], $step['args'], new API\Snapshot( $step['validation']['version'], $step['validation']['data'] ) ), $binding['steps'] );
	}
}
