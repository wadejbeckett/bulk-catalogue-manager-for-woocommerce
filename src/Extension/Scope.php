<?php
/**
 * The members of a change, for Provider::state_token() only
 * (docs/EXTENSIONS.md §6, §7.2): the available products and variations the
 * exact selection expands to. The core implements it; a provider never sees
 * a selection token or a filter.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A scope, contract version 1.
 */
interface Scope {
	/**
	 * How many members.
	 */
	public function count(): int;

	/**
	 * The member ids, ascending, products and variations together, in lists of at most $size.
	 *
	 * @param int $size Most ids in one list.
	 * @return iterable<list<int>>
	 */
	public function chunks( int $size ): iterable;
}
