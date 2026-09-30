<?php
/**
 * A chooser group of a provider's own (docs/EXTENSIONS.md §5.3): listed in
 * the bulk panel after the native groups, in registration order.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable chooser group.
 */
final class ChooserGroup {
	/**
	 * A group.
	 *
	 * @param string $id    `x_<provider id>_<name>`.
	 * @param string $label Plain, translated label.
	 */
	public function __construct( private readonly string $id, private readonly string $label ) {}

	/**
	 * The group id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The plain label.
	 */
	public function label(): string {
		return $this->label;
	}
}
