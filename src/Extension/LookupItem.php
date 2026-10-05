<?php
/**
 * One choice a LookupSource offers (docs/EXTENSIONS-v2.md §4.2): a stable,
 * provider-owned id, a plain label and an optional plain detail line. The
 * core never reads meaning into the id: it is not a user, group or post id
 * as far as the core knows, only the string the provider recognises again.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable lookup item, contract version 2.
 */
final class LookupItem {
	/**
	 * The pattern every id matches: 1 to 64 characters, no control characters.
	 */
	public const ID = '/^[^\x00-\x1F\x7F]{1,64}$/u';

	/**
	 * Describe one choice. Nothing is checked here: the core drops an item
	 * whose id breaks LookupItem::ID or whose label is empty, and trims long
	 * labels (80 characters) and details (120 characters) with an ellipsis.
	 *
	 * @param string $id     Provider-owned stable id.
	 * @param string $label  Plain, translated label.
	 * @param string $detail Optional plain detail line, such as a secondary name.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $detail = ''
	) {}

	/**
	 * The id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * The detail line, or ''.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
