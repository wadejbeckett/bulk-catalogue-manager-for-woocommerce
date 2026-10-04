<?php
/**
 * Context contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Context contract. */
interface Context {
	/**
	 * Mode.
	 */
	public function mode(): string;
	/**
	 * User id.
	 */
	public function user_id(): int;
	/**
	 * Batch id.
	 */
	public function batch_id(): int;
}
