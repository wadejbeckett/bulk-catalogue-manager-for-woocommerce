<?php
/**
 * Accepted typed binding and required formats in the existing batch manifest.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

defined( 'ABSPATH' ) || exit;
/** Kept server-side; history must never project the opaque binding. */
final class Manifest {
	/**
	 * Record only fields/actions used by this accepted request.
	 * @param array $binding Server-generated validation binding.
	 * @param string $family Derived target family.
	 * @param string $entry Validated location.
	 */
	public static function build( array $binding, string $family, string $entry ): array {
		$fields            = array_flip( array_column( $binding['steps'], 'field' ) );
		$binding['fields'] = array_intersect_key( $binding['fields'], $fields );
		$caps              = [ 'category' === $family ? 'category-subjects' : 'typed-product-subjects' => 1 ];
		foreach ( $binding['actions'] as $action ) {
			foreach ( $action['arguments'] as $spec ) {
				if ( 'lookup' === $spec['type'] ) {
					$caps['choice-search'] = 1;
					if ( [] !== $spec['depends_on'] || isset( $spec['active_when'] ) ) {
							$caps['dependent-arguments'] = 1;}
				}
			}
		}
		if ( str_ends_with( $entry, '.row' ) ) {
			$caps['row-actions'] = 1;}
		return array_replace(
			$binding,
			[
				'family'       => $family,
				'entry'        => $entry,
				'capabilities' => $caps,
			]
		);
	}
	/**
	 * Refuse unsupported identities and versions, preserving the stored original.
	 * @param array $manifest Stored manifest.
	 * @param Registry $registry Current qualified providers.
	 * @return bool|\WP_Error
	 */
	public static function supported( array $manifest, Registry $registry ): bool|\WP_Error {
		$refusal = \BulkCatalogueManager\External\Refusals::incompatible( Inspector::unavailable() );
		try {
			Rules::immutable( $manifest );
			$family = $registry->family( $manifest['provider'] ?? '' );
			if ( 2 !== ( $manifest['serializer'] ?? null ) || 2 !== ( $manifest['contract'] ?? null ) || null === $family || ( $manifest['identity'] ?? null ) !== $family['identity'] || ! in_array( $manifest['family'] ?? null, [ 'product','category' ], true ) || ! in_array( $manifest['entry'] ?? null, [ $manifest['family'] . '.row',$manifest['family'] . '.bulk' ], true ) || ! is_int( $manifest['actor'] ?? null ) || $manifest['actor'] < 1 || ! is_array( $manifest['capabilities'] ?? null ) || ! is_array( $manifest['steps'] ?? null ) || [] === $manifest['steps'] ) {
				return $refusal;}
			foreach ( $manifest['capabilities'] as $name => $version ) {
				if ( 1 !== $version || ! in_array( $name, [ 'typed-product-subjects','category-subjects','choice-search','dependent-arguments','row-actions' ], true ) ) {
							return $refusal;}
			}
			$required = self::build( $manifest, $manifest['family'], $manifest['entry'] )['capabilities'];
			if ( \BulkCatalogueManager\Engine\Preview::canonical( $required ) !== \BulkCatalogueManager\Engine\Preview::canonical( $manifest['capabilities'] ) ) {
				return $refusal;}
			foreach ( Binding::validated( $manifest ) as $step ) {
				$field      = $family['typed']['fields'][ $step->field ] ?? null;
				$descriptor = $family['typed']['described'][ $step->action ]['descriptor'] ?? null;
				if ( null === $field || null === $descriptor || ! isset( $family['typed']['actions'][ $step->action ] ) || $descriptor->field !== $step->field || ! in_array( $manifest['entry'], $descriptor->locations, true ) || $field->format() !== ( $manifest['fields'][ $step->field ]['format'] ?? null ) ) {
					return $refusal;}
				$context = \BulkCatalogueManager\External\Guard::run( $family['id'], 'upgrade_context', fn()=> $field->upgrade_context( $step->validation ) );
				if ( ! $context instanceof \BulkCatalogueManager\Extension\V2\Snapshot ) {
					return $refusal;}
			}
			return true;
		} catch ( \Throwable $error ) {
			return $refusal;}
	}
}
