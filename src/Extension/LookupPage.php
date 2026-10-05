<?php
/**
 * One page of a lookup (docs/EXTENSIONS-v2.md §4.2): at most SIZE items, in
 * the provider's order, and whether a later page has more.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable page of lookup items, contract version 2.
 */
final class LookupPage {
	/**
	 * Items in one page. The core keeps the first SIZE items of a longer
	 * page and then reports that more follow.
	 */
	public const SIZE = 20;

	/**
	 * The highest page number the core asks for (pages count from 1).
	 */
	public const MAX_PAGE = 500;

	/**
	 * The longest search text the core passes, in characters.
	 */
	public const MAX_QUERY = 100;

	/**
	 * The items.
	 *
	 * @var list<LookupItem>
	 */
	private readonly array $items;

	/**
	 * Build a page.
	 *
	 * @param list<LookupItem> $items    The page's items, at most SIZE.
	 * @param bool             $has_more Whether a later page has more items.
	 */
	public function __construct( array $items, private readonly bool $has_more ) {
		$this->items = array_values( array_filter( $items, static fn( mixed $item ): bool => $item instanceof LookupItem ) );
	}

	/**
	 * An empty last page.
	 */
	public static function none(): self {
		return new self( [], false );
	}

	/**
	 * The items.
	 *
	 * @return list<LookupItem>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Whether a later page has more items.
	 */
	public function has_more(): bool {
		return $this->has_more;
	}
}
