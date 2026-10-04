<?php
/**
 * ActionDescriptor for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ActionDescriptor immutable contract value. */
final class ActionDescriptor {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $id Contract value.
	 * @param string $field Contract value.
	 * @param string $group Contract value.
	 * @param string $label Contract value.
	 * @param string $description Contract value.
	 * @param string $edit_kind Contract value.
	 * @param array $arguments Contract value.
	 * @param string $capability Contract value.
	 * @param array $locations Contract value.
	 * @param array $target_kinds Contract value.
	 * @param bool $always_confirm Contract value.
	 * @param string $confirm_reason Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $id, public readonly string $field, public readonly string $group, public readonly string $label, public readonly string $description, public readonly string $edit_kind, public readonly array $arguments, public readonly string $capability, public readonly array $locations, public readonly array $target_kinds, public readonly bool $always_confirm = false, public readonly string $confirm_reason = '' ) {
		Rules::key( $id );
		Rules::text( $label, 80 );
		try {
			Rules::key( $field );
			Rules::text( $description, 300 );
			Rules::text( $confirm_reason, 255, true );
			Rules::ensure( 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $group ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,127}$/D', $capability ), 'Invalid group or capability.' );
			Rules::ensure( in_array( $edit_kind, \BulkCatalogueManager\Fields\Field::EDIT_KINDS, true ), 'Invalid edit kind.' );
			Rules::kinds( $target_kinds );
			Rules::json( $locations, 128 );
			Rules::ensure( array_is_list( $locations ) && [] !== $locations && count( array_filter( $locations, 'is_string' ) ) === count( $locations ) && count( array_unique( $locations ) ) === count( $locations ) && [] === array_diff( $locations, [ 'product.row', 'product.bulk', 'category.row', 'category.bulk' ] ), 'Invalid locations.' );
			foreach ( $locations as $location ) {
				Rules::ensure( str_starts_with( $location, 'category.' ) ? [ 'term' ] === $target_kinds : ! in_array( 'term', $target_kinds, true ), 'Mixed target families.' );
			}
			Rules::arguments( $arguments );
		} catch ( \Throwable $error ) {
			throw new \BulkCatalogueManager\External\V2\DescriptorFailure( $id, $label, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Arguments are validated metadata and a chained diagnostic, not the exception message.
		}
	}
}
