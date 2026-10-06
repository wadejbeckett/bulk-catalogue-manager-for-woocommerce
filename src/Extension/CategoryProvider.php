<?php
/**
 * What a provider adds when it offers category actions
 * (docs/EXTENSIONS-v2.md §5.4). The registrar refuses a provider with an
 * action targeting categories unless it implements this. For a category run
 * the core calls these instead of Provider::prime() and Provider::flush(),
 * with term ids, so a provider never mistakes a term id for a product id.
 * Contract version 2, feature `category_target`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Category priming and flushing, contract version 2.
 */
interface CategoryProvider {
	/**
	 * Batch-load what reads of these categories need; may be a no-op. Never writes.
	 *
	 * @param string    $taxonomy The taxonomy, `product_cat`.
	 * @param list<int> $term_ids Term ids, ascending.
	 */
	public function prime_terms( string $taxonomy, array $term_ids ): void;

	/**
	 * Once per run that wrote categories (a chunk, an undo chunk, a replay),
	 * inside `finally`, with the term ids written: clear the provider's own
	 * caches. An exception is logged and never changes reported results.
	 *
	 * @param string    $taxonomy The taxonomy, `product_cat`.
	 * @param list<int> $term_ids Term ids written, ascending.
	 * @param Context   $context  The run.
	 */
	public function flush_terms( string $taxonomy, array $term_ids, Context $context ): void;
}
