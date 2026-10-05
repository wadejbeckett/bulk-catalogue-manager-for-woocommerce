<?php
/**
 * Choices searched and paged on demand (docs/EXTENSIONS-v2.md §4). An
 * ExternalAction implements it when its descriptor declares a `lookup`
 * argument; the registrar refuses such an action otherwise. Contract
 * version 2, feature `lookup`.
 *
 * Both methods only read. The core has already checked, before either is
 * called, that the user may use the screen and holds the action's
 * capability, and that the provider is available.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A lookup, contract version 2.
 */
interface LookupSource {
	/**
	 * One page of the choices for a lookup argument that match a search.
	 * Context::mode() is Context::LOOKUP.
	 *
	 * @param string  $argument The argument name, one of this action's `lookup` arguments.
	 * @param string  $query    Plain search text, trimmed, at most LookupPage::MAX_QUERY characters; '' lists every choice.
	 * @param int     $page     The page, from 1 to LookupPage::MAX_PAGE.
	 * @param Context $context  The run: the requester, and batch 0.
	 * @return LookupPage|\WP_Error The page (at most LookupPage::SIZE items), or the plain reason the choices cannot be listed now.
	 */
	public function lookup( string $argument, string $query, int $page, Context $context ): LookupPage|\WP_Error;

	/**
	 * Which of the given ids may be chosen now, with their current labels.
	 * An id missing from the answer is unknown, gone or not allowed for
	 * Context::user_id(), and the core refuses the request naming the
	 * argument. Called at grant issuance, preview and acceptance, with that
	 * moment's mode.
	 *
	 * @param string       $argument The argument name, one of this action's `lookup` arguments.
	 * @param list<string> $ids      Distinct ids, at most the argument's `max_items` (1 for a single choice).
	 * @param Context      $context  The run.
	 * @return list<LookupItem> The valid subset; items naming other ids are ignored.
	 */
	public function resolve( string $argument, array $ids, Context $context ): array;
}
