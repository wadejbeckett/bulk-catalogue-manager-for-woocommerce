<?php
/**
 * One product category a provider is asked about (docs/EXTENSIONS-v2.md
 * §5.2). The core passes one, instead of a product Subject, to every method
 * taking a Subject when the action's target is ActionDescriptor::TARGET_CATEGORY.
 * Contract version 2, feature `category_target`. The core implements it.
 *
 * Inherited methods answer as follows: id() is the term id; type() is
 * `product_cat`; parent_id() is always 0, so no code written for products
 * reads a parent category as a parent product (the parent category is
 * term()->parent); product() throws a LogicException; value() is as for
 * products. Check `$subject instanceof CategorySubject`, or ref(), before
 * using id().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A category, contract version 2.
 */
interface CategorySubject extends Subject {
	/**
	 * The typed reference: ObjectRef::term( 'product_cat', id ).
	 */
	public function ref(): ObjectRef;

	/**
	 * The taxonomy, `product_cat`.
	 */
	public function taxonomy(): string;

	/**
	 * The native term, read-only: never update or delete it.
	 */
	public function term(): \WP_Term;
}
