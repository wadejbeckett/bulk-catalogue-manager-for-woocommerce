<?php
/** Contextual typed descriptors, without provider values. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\{Refusals, Guard, Arguments as Scalars};
use BulkCatalogueManager\Operations\{Registry as Actions, Group};
use BulkCatalogueManager\Requests\RequestFamily;
use BulkCatalogueManager\Rest\Access;
defined( 'ABSPATH' ) || exit;
final class Discovery {
	public function __construct( private readonly ?Registry $registry = null, private readonly ?\Closure $loader = null ) {}
	/** Check every native member before any provider sees the scope. */
	public static function native_access( API\Scope $scope, int $actor ): bool|\WP_Error {
		$family = RequestFamily::from_scope( $scope );
		if ( is_wp_error( $family ) || true !== Access::user_can_family( $actor, $family ) ) {
			return Refusals::forbidden(); }
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			foreach ( $chunk as $ref ) {
				if ( true !== Permission::check( $actor, $ref ) ) {
					return Refusals::forbidden(); }
			}
		}
		return true;
	}
	/** Shared runtime action guard; provider authorization is metadata-only. */
	public function authorize( array $family, API\ActionDescriptor $descriptor, API\Scope $scope, int $actor, array $args ): bool|\WP_Error {
		if ( true !== self::native_access( $scope, $actor ) || ! user_can( $actor, $descriptor->capability ) ) {
			return Refusals::forbidden();}
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			foreach ( $chunk as $ref ) {
				if ( ! in_array( $ref->kind, $descriptor->target_kinds, true ) ) {
					return Refusals::forbidden();}
				$subject = null === $this->loader ? SubjectLoader::load( $ref, $actor ) : ( $this->loader )( $ref, $actor );
				if ( is_wp_error( $subject ) ) {
					return $subject;}
				if ( true !== Guard::run( $family['id'], 'authorise', fn()=>$family['provider']->authorise( $actor, $subject ) ) ) {
					return Refusals::forbidden();}
			}
		}
		$provider = $family['provider'];
		if ( $provider->identity() !== $family['identity'] || true !== Guard::run( $family['id'], 'availability', fn()=>$provider->availability() ) || true !== Guard::run( $family['id'], 'availability', fn()=>$family['typed']['actions'][ $descriptor->id ]->availability( $scope, $args, new RunContext( 'preview', $actor, 0 ) ) ) ) {
			return Refusals::missing();}
		return true;
	}
	/** Dependency closure includes the predicate edge validated by Rules::arguments.
	 * @param API\ActionDescriptor $descriptor Registered declaration.
	 * @param string $name Lookup argument.
	 * @return array Names in declaration order.
	 */
	public static function dependencies( API\ActionDescriptor $descriptor, string $name ): array {
		$needed  = [];
		$pending = [ $name ];
		while ( [] !== $pending ) {
			$spec = $descriptor->arguments[ array_pop( $pending ) ];
			foreach ( array_filter( [ ...( $spec['depends_on'] ?? [] ),$spec['active_when']['argument'] ?? null ] ) as $dependency ) {
				if ( ! isset( $needed[ $dependency ] ) ) {
					$needed[ $dependency ] = true;
					$pending[]             = $dependency;}
			}
		}
		return array_keys( array_intersect_key( $descriptor->arguments, $needed ) );
	}

	/**
	 * Validate a partial form without requiring unrelated, not-yet-entered values.
	 *
	 * @param API\ActionDescriptor $descriptor Registered action.
	 * @param array                $args Submitted form values.
	 * @param array                $required Lookup dependency closure that must be complete.
	 * @return array|\WP_Error
	 */
	public static function form_arguments( API\ActionDescriptor $descriptor, array $args, array $required = [] ): array|\WP_Error {
		$specs = $descriptor->arguments;
		foreach ( $specs as $name => &$spec ) {
			if ( in_array( $name, $required, true ) ) {
				continue;
			}
			if ( 'lookup' === $spec['type'] ) {
				$spec['min_items'] = 0;
			} elseif ( ! array_key_exists( $name, $args ) && ! array_key_exists( 'default', $spec ) ) {
				// Keep supplied values strict; only absent form inputs may be incomplete.
				$spec['required'] = false;
			}
		}
		unset( $spec );
		$form = new API\ActionDescriptor( $descriptor->id, $descriptor->field, $descriptor->group, $descriptor->label, $descriptor->description, $descriptor->edit_kind, $specs, $descriptor->capability, $descriptor->locations, $descriptor->target_kinds, $descriptor->always_confirm, $descriptor->confirm_reason );
		return Arguments::normalize( $form, [ 'field' => $descriptor->field ] + $args );
	}
	/** Descriptors permitted in this location and exact native scope. */
	public function for_scope( string $location, API\Scope $scope, array $args, int $actor ): array|\WP_Error {
		try {
			Rules::ensure( in_array( $location, [ 'product.row','product.bulk','category.row','category.bulk' ], true ), 'Invalid location.' );
			Rules::json( $args, 65536 );
			$access = self::native_access( $scope, $actor );
			if ( true !== $access ) {
					return $access;}
			$family_name = RequestFamily::from_scope( $scope );
			Rules::ensure( str_starts_with( $location, $family_name . '.' ) && ( ! str_ends_with( $location, '.row' ) || $scope->count() === 1 ), 'Wrong location.' );
			$result = [
				'family'  => $family_name,
				'count'   => $scope->count(),
				'actions' => [],
				'fields'  => [],
				'groups'  => [],
			];
			$groups = [];
			$fields = [];
			foreach ( ( $this->registry ?? Actions::instance()->providers()->typed() )->families() as $family ) {
				foreach ( $family['typed']['described'] ?? [] as $row ) {
					$d = $row['descriptor'] ?? null;
					if ( ! $d instanceof API\ActionDescriptor || ! in_array( $location, $d->locations, true ) ) {
						continue;}
					$input = $args[ $d->id ] ?? [];
					Rules::ensure( is_array( $input ), 'Invalid action arguments.' );
					$normal = self::form_arguments( $d, $input );
					if ( is_wp_error( $normal ) ) {
						return Refusals::invalid_lookup();}
					$access = $this->authorize( $family, $d, $scope, $actor, $normal );
					if ( is_wp_error( $access ) ) {
						// No schemas, defaults, choice data or private provider reasons on denial.
						$result['actions'][] = [
							'id'                => $d->id,
							'label'             => $d->label,
							'available'         => false,
							'unavailableReason' => Refusals::no_permission(),
						];
						continue;
					}
					$meta       = $family['typed']['field_meta'][ $d->field ];
					$properties = Scalars::schema( get_object_vars( $d ), $meta )['properties'];
					foreach ( $d->arguments as $key => $spec ) {
						if ( 'lookup' === $spec['type'] ) {
							$properties[ $key ] = [
								'type'        => 'lookup',
								'title'       => $spec['label'],
								'description' => $spec['rule'],
								'default'     => $spec['multiple'] ? [] : null,
							] + $spec;}
					}
					$result['actions'][] = [
						'id'            => $d->id,
						'label'         => $d->label,
						'description'   => $d->description,
						'group'         => $d->group,
						'groups'        => [ $d->group ],
						'fields'        => [ $d->field ],
						'editKinds'     => [ $d->edit_kind ],
						'provider'      => $family['id'],
						'identity'      => $family['identity'],
						'contract'      => 2,
						'family'        => $family_name,
						'arguments'     => $d->arguments,
						'args'          => [
							'type'                 => 'object',
							'properties'           => $properties,
							'additionalProperties' => false,
						],
						'available'     => true,
						'alwaysConfirm' => $d->always_confirm,
						'confirmReason' => $d->confirm_reason,
					];
					$fields[ $d->field ] = [
						'key'        => $d->field,
						'label'      => $meta['label'],
						'provider'   => $family['id'],
						'group'      => $d->group,
						'editKinds'  => [ $d->edit_kind ],
						'variations' => str_ends_with( $location, '.row' ) ? 'parent_only' : $meta['rule'],
						'type'       => 'external',
					];
					$groups[ $d->group ] = $family['typed']['groups'][ $d->group ] ?? null;
				}
			}
			$result['fields'] = array_values( $fields );
			foreach ( Group::to_client() as $group ) {
				if ( array_key_exists( $group['id'], $groups ) ) {
						$result['groups'][] = $group;
						unset( $groups[ $group['id'] ] );}
			}
			foreach ( $groups as $id => $label ) {
				$result['groups'][] = [
					'id'    => $id,
					'label' => $label,
				];}
			return $result;
		} catch ( \Throwable $error ) {
			return Refusals::invalid_lookup();}
	}
}
