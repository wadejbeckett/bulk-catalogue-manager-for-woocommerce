<?php
/**
 * RecoveryContext contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** RecoveryContext contract. */
interface RecoveryContext extends Context {
	/**
	 * Original.
	 */
	public function original(): FieldHistory;
	/**
	 * Expected.
	 */
	public function expected(): mixed;
	/**
	 * Target.
	 */
	public function target(): mixed;
	/**
	 * Force.
	 */
	public function force(): bool;
	/**
	 * Direction.
	 */
	public function direction(): string;
}
