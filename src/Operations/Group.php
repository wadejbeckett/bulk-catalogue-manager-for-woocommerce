<?php
/**
 * The five groups the bulk action panel lists actions under (SPEC §4.3):
 * Pricing, Stock, Organisation, Shipping and tax, Publishing. Each editable
 * field belongs to one group (declared in the field registry), and each
 * action has a primary group plus the groups of the fields it accepts, so
 * the panel can offer an action under every group it reaches, restricted to
 * that group's fields.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Action groups.
 */
final class Group {
	public const PRICING      = 'pricing';
	public const STOCK        = 'stock';
	public const ORGANISATION = 'organisation';
	public const SHIPPING_TAX = 'shipping_tax';
	public const PUBLISHING   = 'publishing';

	/**
	 * Every group, in the order the panel lists them.
	 */
	public const ALL = [ self::PRICING, self::STOCK, self::ORGANISATION, self::SHIPPING_TAX, self::PUBLISHING ];

	/**
	 * Each group's plain-English, translated label.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::PRICING      => __( 'Pricing', 'bulk-catalogue-manager-for-woocommerce' ),
			self::STOCK        => __( 'Stock', 'bulk-catalogue-manager-for-woocommerce' ),
			self::ORGANISATION => __( 'Organisation', 'bulk-catalogue-manager-for-woocommerce' ),
			self::SHIPPING_TAX => __( 'Shipping and tax', 'bulk-catalogue-manager-for-woocommerce' ),
			self::PUBLISHING   => __( 'Publishing', 'bulk-catalogue-manager-for-woocommerce' ),
		];
	}

	/**
	 * The groups as the client reads them, in panel order.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public static function to_client(): array {
		$groups = [];
		foreach ( self::labels() as $id => $label ) {
			$groups[] = [
				'id'    => $id,
				'label' => $label,
			];
		}
		return $groups;
	}
}
