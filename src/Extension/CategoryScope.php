<?php
/**
 * The exact categories a category change covers, for state_token() only
 * (docs/EXTENSIONS-v2.md §5.3). chunks() yields ascending term ids of
 * taxonomy(), never product ids. Contract version 2, feature
 * `category_target`. The core implements it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A category scope, contract version 2.
 */
interface CategoryScope extends Scope {
	/**
	 * The taxonomy of every id, `product_cat`.
	 */
	public function taxonomy(): string;
}
