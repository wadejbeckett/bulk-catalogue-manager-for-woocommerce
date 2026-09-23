<?php
/**
 * One validated filter of a catalogue read (SPEC §4.1): a field key from the
 * query allow-list, one of the operators that field allows, and a value
 * already normalised to the shape the operator needs. Built only by Criteria.
 *
 * A category filter also carries `include_children` (T62, SPEC §7): true,
 * the default, when each chosen category stands for its whole branch; false
 * for "this category only". Every other filter carries true, which means
 * nothing to it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable filter.
 */
final class Filter {
	/**
	 * Build a filter. Criteria checks every part first.
	 *
	 * @param string $field    Field key, a key of Criteria::FILTERS.
	 * @param string $operator One of that field's operators.
	 * @param mixed  $value    Normalised value: a list of ints or strings, a bool, a number, a two-number list, a date string, or [ value, unit ].
	 * @param bool   $include_children For a category filter, whether each chosen category includes the categories below it.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $operator,
		public readonly mixed $value,
		public readonly bool $include_children = true
	) {}

	/**
	 * The filter as a plain array, for hashing a view (T19). The children
	 * flag appears only when it is off, so a filter written before the flag
	 * existed hashes, counts and round-trips through a selection token as it did.
	 *
	 * @return array{field: string, operator: string, value: mixed, include_children?: false}
	 */
	public function to_array(): array {
		$filter = [
			'field'    => $this->field,
			'operator' => $this->operator,
			'value'    => $this->value,
		];
		if ( ! $this->include_children ) {
			$filter[ Criteria::INCLUDE_CHILDREN ] = false;
		}
		return $filter;
	}
}
