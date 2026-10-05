<?php
/**
 * Put Manage stock back with the stock values WooCommerce changed with it
 * (hotfix 1.3.1): the one internal step undo writes for an object whose
 * journal holds `manage_stock`, folding in that object's stock quantity,
 * backorders, low stock threshold and stock status rows.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * The internal undo step for Manage stock.
 *
 * Only the undo planner makes it (validate_list()'s internal mode). Its
 * arguments are `field` (Manage stock), `value` (the journal's old Manage
 * stock) and, for each coupled field the batch journalled, that field's old
 * value under its own key. compute() writes Manage stock first; follow()
 * then gives the coupled values, so stock quantity is written once stock is
 * managed again and the stock status WooCommerce works out on save follows
 * the restored quantity and backorders. Putting back unmanaged stock writes
 * only the stock status: WooCommerce empties the others itself.
 */
final class RestoreStock extends BaseOperation {
	/**
	 * The action id.
	 */
	public const ID = 'restore_stock';

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
		return Group::STOCK;
	}

	/**
	 * The plain label (never shown in the panel).
	 */
	protected function label(): string {
		return __( 'Put the stock settings back', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Puts back Manage stock, and the stock quantity, backorders, low stock threshold and stock status that changed with it.', 'bulk-catalogue-manager-for-woocommerce' );
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
	 * Manage stock only.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return StockCoupling::FIELD === $field->key();
	}

	/**
	 * Manage stock and each coupled value to put back.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		$arguments = [
			'value' => [
				'type'     => 'boolean',
				'label'    => __( 'Manage stock to put back', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'The stock settings to put back could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
			],
		];
		foreach ( StockCoupling::COUPLED as $key ) {
			$arguments[ $key ] = [
				'type'  => 'value',
				'label' => $this->fields->get( $key )?->label() ?? __( 'Stock', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'  => __( 'The stock settings to put back could not be read, so this product was left alone.', 'bulk-catalogue-manager-for-woocommerce' ),
			];
		}
		return $arguments;
	}

	/**
	 * Manage stock as the journal had it.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		return self::value_outcome( $this->field( $args ), $subject, $args['value'] ?? null );
	}

	/**
	 * The coupled values to write after Manage stock, each only when it
	 * differs from what the object holds.
	 *
	 * @param object               $subject The object, before anything is applied.
	 * @param Result               $checked The checked Manage stock Result.
	 * @param array<string, mixed> $args    Normalised arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		if ( ! in_array( $checked->state(), [ Result::CHANGED, Result::ALREADY ], true ) ) {
			return [];
		}
		$managed = true === $checked->new() || ( Result::ALREADY === $checked->state() && true === $checked->old() );
		$follow  = [];
		foreach ( StockCoupling::COUPLED as $key ) {
			if ( ! array_key_exists( $key, $args ) || ( ! $managed && in_array( $key, StockCoupling::CLEARED, true ) ) ) {
				continue;
			}
			$field = $this->fields->get( $key );
			if ( null === $field ) {
				continue;
			}
			$old   = $field->read( $subject );
			$clean = $field->clean( $args[ $key ] ?? '' );
			if ( $clean instanceof \WP_Error ) {
				$follow[] = Result::failed( $key, $old, $clean->get_error_message(), (string) $clean->get_error_code() );
				continue;
			}
			if ( ! Planner::same( $field, $old, $clean ) ) {
				$follow[] = Result::changed( $key, $old, $clean );
			}
		}
		return $follow;
	}
}
