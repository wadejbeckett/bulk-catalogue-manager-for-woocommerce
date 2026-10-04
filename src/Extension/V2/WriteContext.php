<?php
/**
 * WriteContext contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** WriteContext contract. */
interface WriteContext extends Context {
	/**
	 * Intent id.
	 */
	public function intent_id(): string;
	/**
	 * History.
	 */
	public function history(): FieldHistory;
	/**
	 * Expected.
	 */
	public function expected(): mixed;
	/**
	 * Target.
	 */
	public function target(): mixed;
	/**
	 * Recovery.
	 */
	public function recovery(): ?RecoveryContext;
}
