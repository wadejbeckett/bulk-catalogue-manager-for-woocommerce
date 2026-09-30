<?php
/**
 * One object a provider is asked about (docs/EXTENSIONS.md §4, §6): a
 * product or a variation the core has loaded, checked and found available.
 * The core implements it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An object, contract version 1.
 */
interface Subject {
	/**
	 * The product or variation id.
	 */
	public function id(): int;

	/**
	 * `product` or `variation`.
	 */
	public function type(): string;

	/**
	 * The variation's product, or 0 for a product.
	 */
	public function parent_id(): int;

	/**
	 * The WooCommerce object, read-only: never call a setter or save() on it.
	 */
	public function product(): \WC_Product;

	/**
	 * One of this provider's fields as the change has it so far: the value
	 * read, or what an earlier step of the same change computed.
	 *
	 * @param string $field A field key of the asking provider.
	 * @return mixed The canonical value, or an Unknown.
	 */
	public function value( string $field ): mixed;
}
