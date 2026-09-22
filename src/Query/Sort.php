<?php
/**
 * One validated sort key of a catalogue read: a sortable field key from the
 * query allow-list and a direction. Built only by Criteria.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable sort key.
 */
final class Sort {
	public const ASC  = 'asc';
	public const DESC = 'desc';

	/**
	 * Build a sort key. Criteria checks both parts first.
	 *
	 * @param string $field     Field key, a key of Criteria::SORTS.
	 * @param string $direction ASC or DESC.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $direction
	) {}

	/**
	 * The sort key as a plain array.
	 *
	 * @return array{field: string, direction: string}
	 */
	public function to_array(): array {
		return [
			'field'     => $this->field,
			'direction' => $this->direction,
		];
	}
}
