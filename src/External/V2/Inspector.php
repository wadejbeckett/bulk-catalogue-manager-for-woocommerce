<?php
/**
 * Validate typed fields and actions independently, preserving diagnostic entries.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\External\Guard;
use BulkCatalogueManager\External\Inspector as LegacyInspector;
use BulkCatalogueManager\Operations\Group;

defined( 'ABSPATH' ) || exit;

/** No action inspection can grant a capability that has not qualified. */
final class Inspector {
	/**
	 * Inspect the typed facet, independently of its legacy facet.
	 * @param API\Provider $provider Provider.
	 * @param string $id Frozen provider ID.
	 * @param array $capabilities Qualified versions.
	 */
	public static function inspect( API\Provider $provider, string $id, array $capabilities ): array {
		$result = [
			'fields'      => [],
			'field_meta'  => [],
			'actions'     => [],
			'described'   => [],
			'groups'      => [],
			'diagnostics' => [],
		];
		try {
			$fields  = Guard::run( $id, 'typed fields', static fn() => $provider->fields() );
			$actions = Guard::run( $id, 'typed actions', static fn() => $provider->actions() );
			$groups  = Guard::run( $id, 'typed groups', static fn() => $provider->groups() );
			Rules::ensure( array_is_list( $fields ) && count( $fields ) >= 1 && count( $fields ) <= 5 && array_is_list( $actions ) && count( $actions ) >= 1 && count( $actions ) <= 20 && array_is_list( $groups ) && count( $groups ) <= 2, 'Invalid typed facet counts.' );
		} catch ( \Throwable $error ) {
			Guard::log( 'Typed facet declarations failed: ' . $error->getMessage(), 'warning' );
			$result['diagnostics'][] = self::unavailable();
			return $result;
		}
		foreach ( $groups as $group ) {
			try {
				Rules::ensure( $group instanceof ChooserGroup, 'Invalid typed chooser group.' );
				Rules::key( $group->id(), $id );
				Rules::text( $group->label(), 80 );
				Rules::ensure( ! isset( $result['groups'][ $group->id() ] ), 'Duplicate chooser group.' );
				$result['groups'][ $group->id() ] = $group->label();
			} catch ( \Throwable $error ) {
				$result['diagnostics'][] = self::unavailable();
			}
		}
		$seen = [];
		foreach ( $fields as $field ) {
			$key = null;
			try {
				Rules::ensure( $field instanceof API\Field, 'Invalid typed field.' );
				$key = $field->key();
				Rules::key( $key, $id );
				Rules::ensure( ! isset( $seen[ $key ] ), 'Duplicate typed field.' );
				$seen[ $key ] = true;
				$kinds        = $field->target_kinds();
				Rules::kinds( $kinds );
				$label = $field->label();
				Rules::text( $label, 80 );
				$rule   = $field->variation_rule();
				$format = $field->format();
				Rules::ensure( $format > 0 && ( [ 'term' ] === $kinds || in_array( $rule, [ 'parent_only', 'per_variation' ], true ) ), 'Invalid typed field format or variation rule.' );
				$result['fields'][ $key ]     = $field;
				$result['field_meta'][ $key ] = [
					'key'          => $key,
					'label'        => $label,
					'target_kinds' => $kinds,
					'rule'         => $rule,
					'format'       => $format,
				];
			} catch ( \Throwable $error ) {
				if ( null !== $key ) {
					unset( $result['fields'][ $key ], $result['field_meta'][ $key ] );
				}
				Guard::log( 'Typed field declaration failed: ' . $error->getMessage(), 'warning' );
				$result['diagnostics'][] = self::unavailable();
			}
		}
		$seen = [];
		foreach ( $actions as $action ) {
			$descriptor = null;
			$action_id  = null;
			$label      = '';
			try {
				Rules::ensure( $action instanceof API\Action, 'Invalid typed action.' );
				$descriptor = $action->describe();
				$action_id  = $descriptor->id;
				$label      = $descriptor->label;
				Rules::key( $action_id, $id );
				Rules::key( $descriptor->field, $id );
				Rules::ensure( ! isset( $seen[ $action_id ] ), 'Duplicate typed action.' );
				$seen[ $action_id ] = true;
				$meta               = $result['field_meta'][ $descriptor->field ] ?? null;
				Rules::ensure( null !== $meta && [] === array_diff( $descriptor->target_kinds, $meta['target_kinds'] ), 'Action field target mismatch.' );
				Rules::ensure( in_array( $descriptor->group, Group::ALL, true ) || isset( $result['groups'][ $descriptor->group ] ), 'Unknown action group.' );
				Rules::arguments( $descriptor->arguments );
				$required = [ in_array( 'term', $descriptor->target_kinds, true ) ? 'category-subjects' : 'typed-product-subjects' ];
				foreach ( $descriptor->arguments as $spec ) {
					if ( 'lookup' === $spec['type'] ) {
						Rules::ensure( $action instanceof API\ChoiceSource, 'Lookup action lacks ChoiceSource.' );
						$required[] = 'choice-search';
						if ( [] !== $spec['depends_on'] || isset( $spec['active_when'] ) ) {
							$required[] = 'dependent-arguments';
						}
					}
				}
				foreach ( $descriptor->locations as $location ) {
					if ( str_ends_with( $location, '.row' ) ) {
						$required[] = 'row-actions';
					}
				}
				foreach ( array_unique( $required ) as $capability ) {
					Rules::ensure( 1 === ( $capabilities[ $capability ] ?? null ), 'Unqualified typed capability: ' . $capability );
				}
				$result['actions'][ $action_id ]   = $action;
				$result['described'][ $action_id ] = [
					'id'         => $action_id,
					'label'      => $label,
					'available'  => true,
					'reason'     => '',
					'descriptor' => $descriptor,
				];
			} catch ( \Throwable $error ) {
				if ( $error instanceof DescriptorFailure && LegacyInspector::prefixed( $error->id, $id ) ) {
					$action_id = $error->id;
					$label     = $error->label;
				}
				if ( null !== $action_id && LegacyInspector::prefixed( $action_id, $id ) ) {
					unset( $result['actions'][ $action_id ] );
					$seen[ $action_id ]                = true;
					$result['described'][ $action_id ] = [
						'id'        => $action_id,
						'label'     => $label,
						'available' => false,
						'reason'    => self::unavailable(),
					];
				}
				Guard::log( 'Typed action declaration failed: ' . $error->getMessage(), 'warning' );
				$result['diagnostics'][] = self::unavailable();
			}
		}
		return $result;
	}
	/** Plain diagnostic, never exception details or private argument data. */
	public static function unavailable(): string {
		return __( 'This action is unavailable. Update the add-on or ask its author.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
