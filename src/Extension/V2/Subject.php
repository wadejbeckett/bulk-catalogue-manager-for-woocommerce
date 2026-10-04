<?php
/**
 * Subject contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Subject contract. */
interface Subject {
	/**
	 * Reference.
	 */
	public function reference(): TargetRef;
	/**
	 * Parent.
	 */
	public function parent(): ?TargetRef;
	/**
	 * Value.
	 * @param string $field Contract value.
	 */
	public function value( string $field ): mixed;
}
