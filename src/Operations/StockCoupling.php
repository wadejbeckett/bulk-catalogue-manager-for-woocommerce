<?php
/**
 * The stock values WooCommerce changes on save when Manage stock changes
 * (hotfix 1.3.1): turning it off clears the stock quantity, backorders and
 * low stock threshold; turning it on works the stock status out again from
 * the quantity. The writer journals each such change with its value before,
 * so undo puts it back (restore_stock).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Manage stock and the values coupled to it.
 */
final class StockCoupling {
	/**
	 * The Manage stock field.
	 */
	public const FIELD = 'manage_stock';

	/**
	 * The fields WooCommerce changes on save when Manage stock changes.
	 */
	public const COUPLED = [ 'stock_quantity', 'backorders', 'low_stock_amount', 'stock_status' ];

	/**
	 * The fields WooCommerce empties whenever stock is not managed.
	 */
	public const CLEARED = [ 'stock_quantity', 'backorders', 'low_stock_amount' ];

	/**
	 * The coupled changes WooCommerce will make when this object is saved, as
	 * Results from each value before the write to the value it will hold: only
	 * when a Result in the list changes Manage stock, and only for coupled
	 * fields no Result in the list changes. Worked out on a copy through
	 * WooCommerce's own validate_props(), which save() runs; nothing is set on
	 * the object.
	 *
	 * @param Fields       $fields  Field registry.
	 * @param object       $before  The object as loaded, before any step.
	 * @param object       $product The object with every step applied, not yet saved.
	 * @param list<Result> $results Every Result of the object's steps.
	 * @return list<Result>
	 */
	public static function follow( Fields $fields, object $before, object $product, array $results ): array {
		$changed = array_map( static fn( Result $r ): string => $r->field(), array_filter( $results, static fn( Result $r ): bool => $r->is_changed() ) );
		if ( ! in_array( self::FIELD, $changed, true ) || ! method_exists( $product, 'validate_props' ) ) {
			return [];
		}
		$copy = clone $product;
		$copy->validate_props();
		$follow = [];
		foreach ( self::COUPLED as $key ) {
			$field = $fields->get( $key );
			if ( null === $field || in_array( $key, $changed, true ) ) {
				continue;
			}
			$old = $field->read( $before );
			$new = $field->read( $copy );
			if ( ! Planner::same( $field, $old, $new ) ) {
				$follow[] = Result::changed( $key, $old, $new );
			}
		}
		return $follow;
	}

	/**
	 * Whether turning stock management off would clear a stock quantity,
	 * backorders or a low stock threshold the object holds (the preview's
	 * warning).
	 *
	 * @param Fields $fields  Field registry.
	 * @param object $subject The object, before the change.
	 */
	public static function clears( Fields $fields, object $subject ): bool {
		foreach ( self::CLEARED as $key ) {
			$field = $fields->get( $key );
			$value = null === $field ? null : $field->read( $subject );
			if ( ! in_array( $value, [ null, '', 'no' ], true ) ) {
				return true;
			}
		}
		return false;
	}
}
