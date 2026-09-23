<?php
/**
 * What every action shares (SPEC §6): the descriptor built from the field
 * registry, argument validation driven by one argument list, and the default
 * apply() through the field's setter.
 *
 * An action names its id, group, label, description and edit kinds; the
 * fields it accepts are every registry field allowing one of those kinds
 * (narrowed by accepts_field()), so a field gaining an edit kind in the
 * registry reaches the action without touching it. Its own arguments come
 * from arguments(); the common `field` and `skip_already` (T29) are added
 * here. validate() refuses an unknown argument, a field the action does not
 * accept, and any argument its spec refuses, then hands the normalised
 * arguments to normalise() for the rules that span arguments.
 *
 * An argument spec is an array: `type` (boolean, text, integer, decimal,
 * ids, choice, or value for "whatever the field accepts"), `label`, `rule`
 * (the §4.8 sentence a refusal shows), and optionally `required`, `default`,
 * `choices` (value => label), `min`, `max`, `exclusive_min`, `exclusive_max`,
 * `max_length`, `allow_empty`, `min_items` and `max_items`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Shared action behaviour.
 */
abstract class BaseOperation implements Operation {
	/**
	 * The refusal code for arguments that are missing, malformed or out of range.
	 */
	public const INVALID = 'bcm_invalid_operation';

	/**
	 * The refusal code for a field the action does not accept.
	 */
	public const FIELD_NOT_ACCEPTED = 'bcm_field_not_accepted';

	/**
	 * The descriptor, built once.
	 *
	 * @var Descriptor|null
	 */
	private ?Descriptor $descriptor = null;

	/**
	 * Build over the field registry.
	 *
	 * @param Fields $fields Field registry the accepted fields are resolved from.
	 */
	public function __construct( protected readonly Fields $fields ) {}

	/**
	 * The action id.
	 */
	abstract public function id(): string;

	/**
	 * The primary group, one of Group::ALL.
	 */
	abstract protected function group(): string;

	/**
	 * The plain label.
	 */
	abstract protected function label(): string;

	/**
	 * The plain description.
	 */
	abstract protected function description(): string;

	/**
	 * The field edit kinds it performs.
	 *
	 * @return string[]
	 */
	abstract protected function edit_kinds(): array;

	/**
	 * The action's own arguments, name => spec (see the file comment).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function arguments(): array;

	/**
	 * Whether a registry field is one this action changes. By default, any
	 * editable field allowing one of the action's edit kinds.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return [] !== array_intersect( $this->edit_kinds(), $field->edit_kinds() );
	}

	/**
	 * Rules spanning arguments, after each argument passed its own spec:
	 * the arguments to keep, or the sentence saying what to fix.
	 *
	 * @param array<string, mixed> $args  Arguments, each normalised, `field` included.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		unset( $field );
		return $args;
	}

	/**
	 * The descriptor, built from the field registry on first use.
	 */
	public function describe(): Descriptor {
		if ( null === $this->descriptor ) {
			$fields = $this->accepted_fields();
			$groups = array_values( array_intersect( Group::ALL, array_map( static fn( Field $field ): string => $field->group(), $fields ) ) );

			$this->descriptor = new Descriptor(
				$this->id(),
				$this->group(),
				$this->label(),
				$this->description(),
				$this->edit_kinds(),
				array_keys( $fields ),
				$groups,
				$this->schema()
			);
		}
		return $this->descriptor;
	}

	/**
	 * Check and normalise arguments. Reads no object.
	 *
	 * @param array<string, mixed> $args Arguments as submitted.
	 */
	public function validate( array $args ): Validation {
		$specs   = $this->specs();
		$unknown = array_diff_key( $args, $specs );
		if ( [] !== $unknown ) {
			return $this->refuse(
				sprintf(
					/* translators: 1: an action's name, such as "Round prices", 2: the name of a setting it was sent. */
					__( '%1$s has no setting called "%2$s". Choose the action again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$this->label(),
					(string) array_key_first( $unknown )
				),
				(string) array_key_first( $unknown )
			);
		}

		$key = $args['field'] ?? null;
		if ( ! is_string( $key ) || '' === $key ) {
			return $this->refuse( $specs['field']['rule'], 'field' );
		}
		$field = $this->fields->get( $key );
		if ( null === $field || ! $this->describe()->accepts( $key ) ) {
			return Validation::refused( $this->field_refusal( $key ) );
		}

		$clean = [ 'field' => $key ];
		foreach ( $specs as $name => $spec ) {
			if ( 'field' === $name ) {
				continue;
			}
			if ( ! array_key_exists( $name, $args ) ) {
				if ( ! empty( $spec['required'] ) ) {
					return $this->refuse( $spec['rule'], $name );
				}
				if ( array_key_exists( 'default', $spec ) ) {
					$clean[ $name ] = $spec['default'];
				}
				continue;
			}
			$value = self::normalise_value( $args[ $name ], $spec );
			if ( null === $value ) {
				return $this->refuse( $spec['rule'], $name );
			}
			$clean[ $name ] = $value;
		}

		$normalised = $this->normalise( $clean, $field );
		if ( is_string( $normalised ) ) {
			return $this->refuse( $normalised );
		}
		return Validation::passed( $normalised );
	}

	/**
	 * Set a computed change through the field's setter; nothing for any
	 * other state. Never saves.
	 *
	 * @param Result $result  What compute() returned for this object.
	 * @param object $subject The same product or variation.
	 * @throws \LogicException When the result is for a field this action does not change.
	 * @throws WriteRefused    When the field refuses the value.
	 */
	public function apply( Result $result, object $subject ): void {
		if ( ! $result->is_changed() ) {
			return;
		}
		$field = $this->fields->get( $result->field() );
		if ( null === $field || ! $this->describe()->accepts( $result->field() ) ) {
			throw new \LogicException( esc_html( sprintf( 'Action "%s" cannot apply a result for field "%s".', $this->id(), $result->field() ) ) );
		}
		$written = $field->write( $subject, $result->new() );
		if ( $written instanceof \WP_Error ) {
			throw new WriteRefused( $written ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WriteRefused escapes the message it carries.
		}
	}

	/**
	 * The refusal for a field the action does not accept (SPEC §4.8).
	 *
	 * @param string $key The field key asked for.
	 */
	public function field_refusal( string $key ): \WP_Error {
		$field = $this->fields->get( $key );
		return Refusal::invalid(
			self::FIELD_NOT_ACCEPTED,
			null === $field
				? sprintf(
					/* translators: %s: an action's name, such as "Round prices". */
					__( '%s was asked to change a field that does not exist. Choose one of the fields it offers and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$this->label()
				)
				: sprintf(
					/* translators: 1: an action's name, such as "Round prices", 2: a field's name, such as "Status". */
					__( '%1$s cannot change %2$s. Choose one of the fields it offers and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$this->label(),
					$field->label()
				),
			[
				'action' => $this->id(),
				'field'  => $key,
			]
		);
	}

	/**
	 * The field an action's normalised arguments name.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @throws \LogicException When the arguments name no accepted field.
	 */
	protected function field( array $args ): Field {
		$field = $this->fields->get( (string) ( $args['field'] ?? '' ) );
		if ( null === $field || ! $this->describe()->accepts( $field->key() ) ) {
			throw new \LogicException( esc_html( sprintf( 'Action "%s" was given arguments that were not validated.', $this->id() ) ) );
		}
		return $field;
	}

	/**
	 * Stand-in for compute() until its ticket lands.
	 *
	 * @param string $ticket The ticket that implements it, such as "T25".
	 * @throws \LogicException Always.
	 */
	protected function not_yet( string $ticket ): never {
		throw new \LogicException( esc_html( sprintf( 'compute() for action "%s" is implemented in %s.', $this->id(), $ticket ) ) );
	}

	/**
	 * Every argument spec: `field`, the action's own, then `skip_already`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function specs(): array {
		$choices = [];
		foreach ( $this->accepted_fields() as $key => $field ) {
			$choices[ $key ] = $field->label();
		}
		return [
			'field' => [
				'type'     => 'choice',
				'label'    => __( 'Field', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => sprintf(
					/* translators: %s: an action's name, such as "Round prices". */
					__( 'Choose the field %s should change, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$this->label()
				),
				'required' => true,
				'choices'  => $choices,
			],
		] + $this->arguments() + [
			'skip_already' => [
				'type'    => 'boolean',
				'label'   => __( 'Skip rows where the value is already correct', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Skip rows where the value is already correct has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
				'default' => true,
			],
		];
	}

	/**
	 * Registry fields this action changes, keyed by field key, in registry order.
	 *
	 * @return array<string, Field>
	 */
	private function accepted_fields(): array {
		return array_filter( $this->fields->editable(), fn( Field $field ): bool => $this->accepts_field( $field ) );
	}

	/**
	 * The arguments as a JSON Schema object.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$properties = [];
		foreach ( $this->specs() as $name => $spec ) {
			$properties[ $name ] = self::property_schema( $spec );
		}
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/**
	 * One argument spec as a JSON Schema property.
	 *
	 * @param array<string, mixed> $spec Argument spec.
	 * @return array<string, mixed>
	 */
	private static function property_schema( array $spec ): array {
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
			case 'ids':
				$schema['type']        = 'array';
				$schema['items']       = [
					'type'    => 'integer',
					'minimum' => 1,
				];
				$schema['minItems']    = $spec['min_items'] ?? 0;
				$schema['maxItems']    = $spec['max_items'] ?? Values::MAX_TERMS;
				$schema['uniqueItems'] = true;
				break;
			case 'value':
				$schema['type'] = [ 'string', 'number', 'boolean', 'null' ];
				break;
			case 'choice':
				$schema['type']    = 'string';
				$schema['enum']    = array_map( 'strval', array_keys( $spec['choices'] ) );
				$schema['choices'] = [];
				foreach ( $spec['choices'] as $value => $label ) {
					$schema['choices'][] = [
						'value' => (string) $value,
						'label' => $label,
					];
				}
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
	 * One submitted argument in normalised form, or null when its spec refuses it.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $spec  Argument spec.
	 */
	private static function normalise_value( mixed $value, array $spec ): mixed {
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
			case 'ids':
				$ids = Values::id_list( is_array( $value ) ? $value : null );
				if ( null === $ids || count( $ids ) < ( $spec['min_items'] ?? 0 ) || count( $ids ) > ( $spec['max_items'] ?? Values::MAX_TERMS ) ) {
					return null;
				}
				return $ids;
			case 'choice':
				return is_string( $value ) && array_key_exists( $value, $spec['choices'] ) ? $value : null;
			default:
				return $value;
		}
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

	/**
	 * A refusal of these arguments.
	 *
	 * @param string $message  Plain sentence.
	 * @param string $argument The argument at fault, or ''.
	 */
	private function refuse( string $message, string $argument = '' ): Validation {
		$data = [ 'action' => $this->id() ];
		if ( '' !== $argument ) {
			$data['argument'] = $argument;
		}
		return Validation::refused( Refusal::invalid( self::INVALID, $message, $data ) );
	}
}
