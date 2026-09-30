<?php
/**
 * External action arguments (docs/EXTENSIONS.md §5.1, §5.2): the specs a
 * provider declares, checked by the core before the provider sees them, and
 * published in `GET /fields` as JSON Schema in the shape native actions use.
 * The kinds are boolean, text, integer, decimal, choice and multichoice (a
 * list of distinct choice values, kept in the order the choices are
 * offered, bounded by `min_items` and `max_items`). The core adds `field`,
 * `skip_already` and, for a per-variation field, `apply_to_variations`,
 * exactly as it does for native actions.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Argument specs, schemas and normalisation.
 */
final class Arguments {
	/**
	 * One submitted value in normalised form, or null when its spec refuses it.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $spec  A checked argument spec.
	 */
	public static function normalise( mixed $value, array $spec ): mixed {
		switch ( $spec['type'] ) {
			case 'boolean':
				return Values::boolean( $value );
			case 'text':
				if ( ! is_string( $value ) || ( '' === $value && empty( $spec['allow_empty'] ) ) ) {
					return null;
				}
				return isset( $spec['max_length'] ) && mb_strlen( $value ) > $spec['max_length'] ? null : $value;
			case 'integer':
				$number = Values::integer( $value );
				return null !== $number && self::in_range( (float) $number, $spec ) ? $number : null;
			case 'decimal':
				$number = Values::decimal( $value );
				return null !== $number && self::in_range( (float) $number, $spec ) ? $number : null;
			case 'choice':
				return is_string( $value ) && array_key_exists( $value, $spec['choices'] ) ? $value : null;
			case 'multichoice':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					return null;
				}
				$chosen = [];
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || ! array_key_exists( $item, $spec['choices'] ) || isset( $chosen[ $item ] ) ) {
						return null;
					}
					$chosen[ $item ] = true;
				}
				if ( count( $chosen ) < ( $spec['min_items'] ?? 0 ) || count( $chosen ) > ( $spec['max_items'] ?? Inspector::MAX_ITEMS ) ) {
					return null;
				}
				// Kept in the order the choices are offered, so two lists meaning the same thing store and hash the same.
				return array_values( array_filter( array_map( 'strval', array_keys( $spec['choices'] ) ), static fn( string $choice ): bool => isset( $chosen[ $choice ] ) ) );
			default:
				return null;
		}
	}

	/**
	 * Every argument spec of an action, as validation reads them: `field`
	 * (a choice of the action's one field, required), the provider's own
	 * arguments, `apply_to_variations` for a per-variation field, and
	 * `skip_already` (on by default), in that order, as native actions have them.
	 *
	 * @param array<string, mixed> $described The action as it is kept (Registered::described()).
	 * @param array<string, mixed> $meta      Its field as it is kept (Registered::field_meta()).
	 * @return array<string, array<string, mixed>>
	 */
	public static function specs( array $described, array $meta ): array {
		$specs = [
			'field' => [
				'type'     => 'choice',
				'label'    => __( 'Field', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => sprintf(
					/* translators: %s: an action's name, such as "Round prices". */
					__( 'Choose the field %s should change, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$described['label']
				),
				'required' => true,
				'choices'  => [ $described['field'] => (string) ( $meta['label'] ?? $described['field'] ) ],
			],
		] + $described['arguments'];
		if ( 'per_variation' === ( $meta['rule'] ?? '' ) ) {
			$specs['apply_to_variations'] = [
				'type'  => 'boolean',
				'label' => __( 'Also apply to variations', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'  => __( 'Also apply to variations has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
			];
		}
		$specs['skip_already'] = [
			'type'    => 'boolean',
			'label'   => __( 'Skip rows where the value is already correct', 'bulk-catalogue-manager-for-woocommerce' ),
			'rule'    => __( 'Skip rows where the value is already correct has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
			'default' => true,
		];
		return $specs;
	}

	/**
	 * An action's arguments as a JSON Schema object, as `GET /fields` publishes them.
	 *
	 * @param array<string, mixed> $described The action as it is kept.
	 * @param array<string, mixed> $meta      Its field as it is kept.
	 * @return array<string, mixed>
	 */
	public static function schema( array $described, array $meta ): array {
		$properties = [];
		foreach ( self::specs( $described, $meta ) as $name => $spec ) {
			$properties[ $name ] = self::property( $spec );
		}
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/**
	 * One argument spec as a JSON Schema property, as native actions publish theirs.
	 *
	 * @param array<string, mixed> $spec A checked argument spec.
	 * @return array<string, mixed>
	 */
	public static function property( array $spec ): array {
		$schema = [
			'title'       => $spec['label'],
			'description' => $spec['rule'],
		];
		switch ( $spec['type'] ) {
			case 'boolean':
				$schema['type'] = 'boolean';
				break;
			case 'text':
				$schema['type'] = 'string';
				if ( empty( $spec['allow_empty'] ) ) {
					$schema['minLength'] = 1;
				}
				if ( isset( $spec['max_length'] ) ) {
					$schema['maxLength'] = $spec['max_length'];
				}
				break;
			case 'integer':
			case 'decimal':
				$schema['type'] = 'integer' === $spec['type'] ? 'integer' : [ 'string', 'number' ];
				if ( isset( $spec['min'] ) ) {
					$schema['minimum'] = $spec['min'];
					if ( ! empty( $spec['exclusive_min'] ) ) {
						$schema['exclusiveMinimum'] = true;
					}
				}
				if ( isset( $spec['max'] ) ) {
					$schema['maximum'] = $spec['max'];
					if ( ! empty( $spec['exclusive_max'] ) ) {
						$schema['exclusiveMaximum'] = true;
					}
				}
				break;
			case 'choice':
				$schema['type']    = 'string';
				$schema['enum']    = array_map( 'strval', array_keys( $spec['choices'] ) );
				$schema['choices'] = self::choices( $spec['choices'] );
				break;
			case 'multichoice':
				$schema['type']        = 'array';
				$schema['items']       = [
					'type' => 'string',
					'enum' => array_map( 'strval', array_keys( $spec['choices'] ) ),
				];
				$schema['uniqueItems'] = true;
				$schema['minItems']    = $spec['min_items'] ?? 0;
				$schema['maxItems']    = $spec['max_items'] ?? Inspector::MAX_ITEMS;
				$schema['choices']     = self::choices( $spec['choices'] );
				break;
		}
		if ( ! empty( $spec['required'] ) ) {
			$schema['required'] = true;
		}
		if ( array_key_exists( 'default', $spec ) ) {
			$schema['default'] = $spec['default'];
		}
		return $schema;
	}

	/**
	 * Choices as the client reads them.
	 *
	 * @param array<string, string> $choices Value => label.
	 * @return list<array{value: string, label: string}>
	 */
	private static function choices( array $choices ): array {
		$list = [];
		foreach ( $choices as $value => $label ) {
			$list[] = [
				'value' => (string) $value,
				'label' => $label,
			];
		}
		return $list;
	}

	/**
	 * Whether a number is inside a spec's bounds.
	 *
	 * @param float                $number Number.
	 * @param array<string, mixed> $spec   Argument spec.
	 */
	private static function in_range( float $number, array $spec ): bool {
		if ( isset( $spec['min'] ) && ( ! empty( $spec['exclusive_min'] ) ? $number <= $spec['min'] : $number < $spec['min'] ) ) {
			return false;
		}
		if ( isset( $spec['max'] ) && ( ! empty( $spec['exclusive_max'] ) ? $number >= $spec['max'] : $number > $spec['max'] ) ) {
			return false;
		}
		return true;
	}
}
