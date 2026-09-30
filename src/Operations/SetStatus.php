<?php
/**
 * New native Status edits, separate from accepted legacy SetValue work.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/** Requests the chosen native status without restoring a remembered target. */
final class SetStatus extends BaseOperation {
	/** The semantics identity stored in new batches. */
	public function id(): string {
		return 'set_status';
	}

	/** The panel group. */
	protected function group(): string {
		return Group::PUBLISHING;
	}

	/** The translated label. */
	protected function label(): string {
		return __( 'Set Status', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/** The translated description. */
	protected function description(): string {
		return __( 'Sets the native publication status of the chosen products.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/** @return string[] Supported edit kinds. */
	protected function edit_kinds(): array {
		return [ Field::SET ];
	}

	/**
	 * Accept only the native post_status field.
	 *
	 * @param Field $field Registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return $field->accepts( Field::SET ) && StatusSwitch::governs( $field );
	}

	/** @return array<string, array<string, mixed>> The requested target. */
	protected function arguments(): array {
		return [
			'value' => [
				'type'     => 'value',
				'label'    => __( 'New value', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Enter the new value, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
			],
		];
	}

	/**
	 * Canonicalise through the native field's rules.
	 *
	 * @param array<string, mixed> $args Submitted arguments.
	 * @param Field                $field Status field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		$value = $field->clean( $args['value'] );
		if ( $value instanceof \WP_Error ) {
			return $value->get_error_message();
		}
		$args['value'] = $value;
		return $args;
	}

	/**
	 * Compute the requested value without legacy restoration.
	 *
	 * @param object               $subject Product or variation.
	 * @param array<string, mixed> $args Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		return self::value_outcome( $this->field( $args ), $subject, $args['value'] ?? null );
	}

	/**
	 * Clear an owned legacy record only with an actual, validated change.
	 * Malformed/foreign records and no-ops are left untouched.
	 *
	 * @param object               $subject Product as compute saw it.
	 * @param Result               $checked Validated status result.
	 * @param array<string, mixed> $args Normalised arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		if ( ! $checked->is_changed() || ! StatusSwitch::applies( $this->field( $args ), $subject ) ) {
			return [];
		}
		$record = OffRecord::raw( $subject );
		return '' === $record ? [] : [ Result::changed( OffRecord::FIELD, $record, '' ) ];
	}
}
