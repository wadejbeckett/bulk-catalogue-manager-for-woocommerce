<?php
/**
 * What every action shares (SPEC §6): the descriptor built from the field
 * registry, argument validation driven by one argument list, and the default
 * apply() through the field's setter.
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
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shared action behaviour.
 *
 * An action names its id, group, label, description and edit kinds; the
 * fields it accepts are every registry field allowing one of those kinds
 * (narrowed by accepts_field()), so a field gaining an edit kind in the
 * registry reaches the action without touching it. Its own arguments come
 * from arguments(); the common `field`, `apply_to_variations` (T69) and
 * `skip_already` (T29) are added here.
 *
 * `apply_to_variations` ("Also apply to variations", SPEC §4.3, §6, §8) is
 * kept only for a field a variation has a value of its own for (per
 * variation, or conditional such as status and stock quantity): given, it
 * is normalised to a boolean; absent, it takes the shop's default for the
 * field's panel group (variation_default(): Publishing reads the status
 * switch, Pricing the price switch, Stock the stock switch, any other group
 * starts off). For a parent-only or derived field it is refused when on and
 * dropped when off, so a normalised step never carries it there. It decides
 * nothing about the object a step runs on; Engine\BatchFactory reads it to
 * add each selected variable product's variations to the batch. validate() refuses an unknown argument, a field the action does not
 * accept, and any argument its spec refuses, then hands the normalised
 * arguments to normalise() for the rules that span arguments.
 *
 * The helpers actions share sit here as protected statics: value_outcome()
 * for every action ending in "write this value", and the decimal-text
 * helpers of the numeric actions (current_number(), number_outcome(),
 * refuse_below_zero(), decimal_of(), below_zero(), to_places(), settle()).
 *
 * An argument spec is an array: `type` (boolean, text, integer, decimal,
 * ids, choice, or value for "whatever the field accepts"), `label`, `rule`
 * (the §4.8 sentence a refusal shows), and optionally `required`, `default`,
 * `choices` (value => label), `min`, `max`, `exclusive_min`, `exclusive_max`,
 * `max_length`, `allow_empty`, `min_items` and `max_items`.
 */
abstract class BaseOperation implements Operation {
	/**
	 * The refusal code for arguments that are missing, malformed or out of range.
	 */
	public const INVALID = 'bulkcat_invalid_operation';

	/**
	 * The refusal code for a field the action does not accept.
	 */
	public const FIELD_NOT_ACCEPTED = 'bulkcat_field_not_accepted';

	/**
	 * The step argument for "Also apply to variations" (T69).
	 */
	public const APPLY_TO_VARIATIONS = 'apply_to_variations';

	/**
	 * Panel group => the Settings::apply_to_variations() switch its fields start from.
	 */
	private const VARIATION_DEFAULTS = [
		Group::PUBLISHING => 'status',
		Group::PRICING    => 'price',
		Group::STOCK      => 'stock',
	];

	/**
	 * Most characters of a submitted argument name a refusal repeats.
	 */
	private const ECHOED_NAME_LENGTH = 40;

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
			// The name came from the request: echo it only as a short plain key.
			$name = substr( sanitize_key( (string) array_key_first( $unknown ) ), 0, self::ECHOED_NAME_LENGTH );
			return $this->refuse(
				sprintf(
					/* translators: 1: an action's name, such as "Round prices", 2: the name of a setting it was sent. */
					__( '%1$s has no setting called "%2$s". Choose the action again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$this->label(),
					$name
				),
				$name
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
			if ( 'field' === $name || self::APPLY_TO_VARIATIONS === $name ) {
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

		$reach = self::reaches_variations( $field );
		if ( array_key_exists( self::APPLY_TO_VARIATIONS, $args ) ) {
			$on = Values::boolean( $args[ self::APPLY_TO_VARIATIONS ] );
			if ( null === $on ) {
				return $this->refuse( $specs[ self::APPLY_TO_VARIATIONS ]['rule'], self::APPLY_TO_VARIATIONS );
			}
			if ( $on && ! $reach ) {
				return $this->refuse(
					sprintf(
						/* translators: %s: a field's name, such as "Categories". */
						__( '%s belongs to the product, not to each variation, so it cannot be applied to variations. Turn off Also apply to variations and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
						$field->label()
					),
					self::APPLY_TO_VARIATIONS
				);
			}
			if ( $reach ) {
				$clean[ self::APPLY_TO_VARIATIONS ] = $on;
			}
		} elseif ( $reach ) {
			$clean[ self::APPLY_TO_VARIATIONS ] = self::variation_default( $field, Settings::load() );
		}

		$normalised = $this->normalise( $clean, $field );
		if ( is_string( $normalised ) ) {
			return $this->refuse( $normalised );
		}
		return Validation::passed( $normalised );
	}

	/**
	 * Whether "Also apply to variations" is offered for a field: a variation
	 * has a value of its own for it (SPEC §5.2 last column: per variation,
	 * or conditional). Parent-only and derived fields never reach a variation.
	 *
	 * @param Field $field A registry field.
	 */
	public static function reaches_variations( Field $field ): bool {
		return ! $field->read_only() && in_array( $field->variation_rule(), [ Field::PER_VARIATION, Field::CONDITIONAL ], true );
	}

	/**
	 * The starting position of "Also apply to variations" for a field that
	 * reaches variations (SPEC §6, §11): the shop's switch for the field's
	 * panel group — Publishing (status) the status switch, Pricing the price
	 * switch, Stock the stock switch — and off for any other group.
	 *
	 * @param Field    $field    A field reaches_variations() accepts.
	 * @param Settings $settings The settings.
	 */
	public static function variation_default( Field $field, Settings $settings ): bool {
		$setting = self::VARIATION_DEFAULTS[ $field->group() ] ?? null;
		return null !== $setting && $settings->apply_to_variations( $setting );
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
	 * What writing a target value to one object would do, without writing:
	 * failed when the field does not apply to the object or refuses the
	 * value (the field's sentence and code), already when the object holds
	 * it, otherwise a change from the value read to the canonical target.
	 *
	 * Prices and decimals compare in canonical form, so 10 and 10.00 are
	 * the same price; every other type compares exactly as read.
	 *
	 * @param Field  $field   The field written.
	 * @param object $subject A product or variation.
	 * @param mixed  $target  The value to write, before cleaning.
	 */
	protected static function value_outcome( Field $field, object $subject, mixed $target ): Result {
		$old    = $field->read( $subject );
		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::failed( $field->key(), $old, $reason, 'bulkcat_not_available' );
		}
		$clean = $field->clean( $target );
		if ( $clean instanceof \WP_Error ) {
			return Result::failed( $field->key(), $old, $clean->get_error_message(), (string) $clean->get_error_code() );
		}
		$current = $old;
		if ( Field::MONEY === $field->type() || Field::DECIMAL === $field->type() ) {
			$canonical = $field->clean( $old );
			$current   = $canonical instanceof \WP_Error ? $old : $canonical;
		}
		return $current === $clean ? Result::already( $field->key(), $old ) : Result::changed( $field->key(), $old, $clean );
	}

	/**
	 * The object's current value as a decimal, or the skipped Result saying
	 * why there is nothing to work on: the field does not apply to this
	 * object, the value is empty (never read as zero, SPEC §6), or it is not
	 * a number.
	 *
	 * @param Field  $field   The field.
	 * @param object $subject A product or variation.
	 * @param mixed  $old     The value Field::read() returned.
	 */
	protected static function current_number( Field $field, object $subject, mixed $old ): string|Result {
		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::skipped( $field->key(), $old, $reason, 'bulkcat_not_available' );
		}
		if ( Values::is_empty( $old ) ) {
			return Result::skipped(
				$field->key(),
				$old,
				sprintf(
					/* translators: %s: a field's name, such as "Regular price". */
					__( '%s is empty, so there was nothing to change.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				)
			);
		}
		$number = Values::decimal( $old );
		if ( null === $number ) {
			return Result::skipped(
				$field->key(),
				$old,
				sprintf(
					/* translators: %s: a field's name, such as "Weight". */
					__( '%s does not hold a number, so it was not changed.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				)
			);
		}
		return $number;
	}

	/**
	 * The Result for a computed value: failed when the field refuses it,
	 * already when it equals the current value, otherwise the change to the
	 * field's canonical form (prices through wc_format_decimal() at the
	 * shop's precision, stock as a whole number).
	 *
	 * @param Field  $field  The field.
	 * @param mixed  $old    The current value.
	 * @param string $number The computed value, zero or more.
	 */
	protected static function number_outcome( Field $field, mixed $old, string $number ): Result {
		$clean = $field->clean( self::settle( $field, $number ) );
		if ( $clean instanceof \WP_Error ) {
			return Result::failed( $field->key(), $old, $clean->get_error_message(), (string) $clean->get_error_code() );
		}
		return self::same( $old, $clean ) ? Result::already( $field->key(), $old ) : Result::changed( $field->key(), $old, $clean );
	}

	/**
	 * The skipped Result for a value that would go below zero: refused,
	 * never clamped (SPEC §5.4).
	 *
	 * @param Field  $field  The field.
	 * @param mixed  $old    The current value.
	 * @param string $number The computed value, below zero.
	 */
	protected static function refuse_below_zero( Field $field, mixed $old, string $number ): Result {
		return Result::skipped(
			$field->key(),
			$old,
			sprintf(
				/* translators: 1: a field's name, such as "Regular price", 2: the value it would have had, such as -2.50. */
				__( '%1$s would go below zero (%2$s), so it was not changed.', 'bulk-catalogue-manager-for-woocommerce' ),
				$field->label(),
				'-' . self::settle( $field, ltrim( $number, '-' ) )
			),
			'bulkcat_invalid_value'
		);
	}

	/**
	 * A computed float as decimal text, cut to nine places so the noise of
	 * binary arithmetic (0.1 + 0.2) never reaches a rounding decision.
	 *
	 * @param float $number The number.
	 */
	protected static function decimal_of( float $number ): string {
		$text = sprintf( '%.9F', round( $number, 9 ) );
		return str_starts_with( $text, '-' ) && 0.0 === (float) $text ? substr( $text, 1 ) : $text;
	}

	/**
	 * Whether decimal text is below zero.
	 *
	 * @param string $number Decimal text.
	 */
	protected static function below_zero( string $number ): bool {
		return str_starts_with( $number, '-' ) && 0.0 !== (float) $number;
	}

	/**
	 * Round decimal text of zero or more to a number of places, digit by
	 * digit: nearest (half up), up or down.
	 *
	 * @param string $number    Decimal text, zero or more.
	 * @param int    $places    Places to keep, 0 or more.
	 * @param string $direction nearest, up or down.
	 * @throws \InvalidArgumentException When the number is below zero.
	 */
	protected static function to_places( string $number, int $places, string $direction ): string {
		$number = ltrim( $number, '+' );
		if ( str_starts_with( $number, '-' ) ) {
			if ( self::below_zero( $number ) ) {
				throw new \InvalidArgumentException( 'Only a number of zero or more is rounded here.' );
			}
			$number = substr( $number, 1 );
		}
		$places   = max( 0, $places );
		$parts    = explode( '.', $number, 2 );
		$whole    = ltrim( $parts[0], '0' );
		$fraction = $parts[1] ?? '';
		$kept     = substr( str_pad( $fraction, $places, '0' ), 0, $places );
		$rest     = (string) substr( $fraction, $places );
		$bump     = match ( $direction ) {
			'up'    => '' !== trim( $rest, '0' ),
			'down'  => false,
			default => '' !== $rest && (int) $rest[0] >= 5,
		};
		$digits = ( '' === $whole ? '0' : $whole ) . $kept;
		if ( $bump ) {
			$digits = self::increment( $digits );
		}
		return 0 === $places ? $digits : substr( $digits, 0, -$places ) . '.' . substr( $digits, -$places );
	}

	/**
	 * A computed value in the precision the field stores: a price at the
	 * shop's decimals, a whole number for an integer field (to the nearest,
	 * half up), any other decimal at WooCommerce's rounding precision.
	 *
	 * @param Field  $field  The field.
	 * @param string $number Decimal text, zero or more.
	 */
	protected static function settle( Field $field, string $number ): string|int {
		return match ( $field->type() ) {
			Field::INTEGER => (int) self::to_places( $number, 0, 'nearest' ),
			Field::MONEY   => self::to_places( $number, wc_get_price_decimals(), 'nearest' ),
			default        => self::trim_zeros( self::to_places( $number, wc_get_rounding_precision(), 'nearest' ) ),
		};
	}

	/**
	 * Whether a computed value equals the current one as a number, so "10"
	 * and "10.00" are already the target.
	 *
	 * @param mixed $old   The current value.
	 * @param mixed $clean The computed value, canonical.
	 */
	private static function same( mixed $old, mixed $clean ): bool {
		if ( Values::is_empty( $old ) || Values::is_empty( $clean ) ) {
			return Values::is_empty( $old ) && Values::is_empty( $clean );
		}
		$a = Values::decimal( $old );
		$b = Values::decimal( $clean );
		return null !== $a && null !== $b && (float) $a === (float) $b;
	}

	/**
	 * Add one to a string of digits.
	 *
	 * @param string $digits Digits.
	 */
	private static function increment( string $digits ): string {
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
		}
		return '1' . $digits;
	}

	/**
	 * Decimal text without trailing zeros after the point.
	 *
	 * @param string $number Decimal text.
	 */
	private static function trim_zeros( string $number ): string {
		return str_contains( $number, '.' ) ? rtrim( rtrim( $number, '0' ), '.' ) : $number;
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
			self::APPLY_TO_VARIATIONS => [
				'type'  => 'boolean',
				'label' => __( 'Also apply to variations', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'  => __( 'Also apply to variations has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
			],
			'skip_already'            => [
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
