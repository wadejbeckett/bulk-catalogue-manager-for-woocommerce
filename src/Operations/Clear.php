<?php
/**
 * Clear (SPEC §5.4, §6): empty one nullable field, such as ending a sale
 * by clearing the sale price. Clearing writes an empty value, which is
 * distinct from zero. compute() lands in T25.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The clear action.
 */
final class Clear extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'clear';
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::PRICING;
	}

	/**
	 * The plain label.
	 */
	protected function label(): string {
		return __( 'Clear a value', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Empties one field on every chosen product, such as ending a sale by clearing the sale price. Empty is not the same as zero.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::CLEAR ];
	}

	/**
	 * No arguments of its own.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [];
	}

	/**
	 * Implemented in T25.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		unset( $subject, $args );
		$this->not_yet( 'T25' );
	}
}
