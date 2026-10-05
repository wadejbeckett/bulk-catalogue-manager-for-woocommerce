<?php
/**
 * The run a provider is called in (docs/EXTENSIONS.md §4, §9): what the core
 * is doing, for whom and in which batch. The core implements it. A provider
 * never relies on the current user: user_id() is the only identity, and
 * checks use user_can().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A run, contract version 1.
 */
interface Context {
	/**
	 * Previewing: nothing is written.
	 */
	public const PREVIEW = 'preview';

	/**
	 * Accepting or applying a change.
	 */
	public const APPLY = 'apply';

	/**
	 * Finishing an object a stopped run left half written.
	 */
	public const REPLAY = 'replay';

	/**
	 * Planning or running an undo.
	 */
	public const UNDO = 'undo';

	/**
	 * Listing or searching choices for a `lookup` argument (contract version 2).
	 */
	public const LOOKUP = 'lookup';

	/**
	 * One of PREVIEW, APPLY, REPLAY, UNDO and, in contract version 2, LOOKUP.
	 */
	public function mode(): string;

	/**
	 * The user the work is done for: the requester, or the batch's recorded
	 * user in background work.
	 */
	public function user_id(): int;

	/**
	 * The batch, or 0 while previewing.
	 */
	public function batch_id(): int;
}
