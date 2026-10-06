<?php
/**
 * Put a price or other decimal back exactly as it was stored (1.4.2): the
 * internal step undo writes for a money or decimal field, so a regular
 * price stored as "100" comes back as "100", not reformatted at the shop's
 * precision as "100.00".
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The internal undo step for a money or decimal value.
 *
 * Only the undo planner makes it (validate_list()'s internal mode). Its
 * arguments are `field` and `value`, the journal's value before the batch.
 * The value is checked as the field checks any value, then kept and written
 * as stored (Field::stored_form(), Field::write_exact()) when it is a plain
 * decimal; anything else is written in the field's canonical form, as Set
 * a value would. Written values are journalled as written, so a redo puts
 * back exactly what the batch had written.
 */
final class RestoreValue extends BaseOperation {
	/**
	 * The action id.
	 */
	public const ID = 'restore_value';

	/**
	 * The action id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::PRICING;
	}

	/**
	 * The plain label (never shown in the panel).
	 */
	protected function label(): string {
		return __( 'Put the value back', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Puts back a price or measurement exactly as it was saved before the change.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::SET ];
	}

	/**
	 * Money and decimal fields that take a set value.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return $field->accepts( Field::SET ) && in_array( $field->type(), [ Field::MONEY, Field::DECIMAL ], true );
	}

	/**
	 * The value to put back.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'value' => [
				'type'     => 'value',
				'label'    => __( 'Value to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'The value to put back could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
			],
		];
	}

	/**
	 * The value checked by the field, kept as stored when it is a plain decimal.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		$clean = $field->clean( $args['value'] );
		if ( $clean instanceof \WP_Error ) {
			return $clean->get_error_message();
		}
		$args['value'] = $field->stored_form( $args['value'] ) ?? $clean;
		return $args;
	}

	/**
	 * The object's value would become the stored value; already when it holds exactly that.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field  = $this->field( $args );
		$old    = $field->read( $subject );
		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::failed( $field->key(), $old, $reason, 'bulkcat_not_available' );
		}
		$target = $args['value'] ?? '';
		$clean  = $field->clean( $target );
		if ( $clean instanceof \WP_Error ) {
			return Result::failed( $field->key(), $old, $clean->get_error_message(), (string) $clean->get_error_code() );
		}
		$target = $field->stored_form( $target ) ?? $clean;
		return $old === $target ? Result::already( $field->key(), $old ) : Result::changed( $field->key(), $old, $target );
	}

	/**
	 * Set the value through the field's setter exactly as stored. Never saves.
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
		$written = $field->write_exact( $subject, $result->new() );
		if ( $written instanceof \WP_Error ) {
			throw new WriteRefused( $written ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WriteRefused escapes the message it carries.
		}
	}
}
