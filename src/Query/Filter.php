<?php
/**
 * One validated filter of a catalogue read (SPEC §4.1): a field key from the
 * query allow-list, one of the operators that field allows, and a value
 * already normalised to the shape the operator needs. Built only by Criteria.
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
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $operator,
		public readonly mixed $value
	) {}

	/**
	 * The filter as a plain array, for hashing a view (T19).
	 *
	 * @return array{field: string, operator: string, value: mixed}
	 */
	public function to_array(): array {
		return [
			'field'    => $this->field,
			'operator' => $this->operator,
			'value'    => $this->value,
		];
	}
}
