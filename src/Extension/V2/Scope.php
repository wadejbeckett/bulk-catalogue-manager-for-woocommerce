<?php
/**
 * Scope contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Scope contract. */
interface Scope {
	/**
	 * Count.
	 */
	public function count(): int;
	/**
	 * Chunks.
	 * @param int $size Contract value.
	 */
	public function chunks( int $size ): iterable;
	/**
	 * Signature.
	 */
	public function signature(): string;
}
