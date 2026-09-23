<?php
/**
 * What an action says about itself (SPEC §4.3, §6): its id, the group the
 * panel lists it under, a plain label and description, the edit kinds it
 * performs, the field keys it accepts — resolved from the field registry by
 * edit kind, never hard-coded — the groups those fields sit in, and the JSON
 * Schema of its arguments. Shipped to the client inside `GET /fields`, so
 * the client hard-codes no action list (SPEC §12.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable action descriptor.
 */
final class Descriptor {
	/**
	 * Describe an action.
	 *
	 * @param string               $id          Action id: lower case, as stored in a batch's operations JSON.
	 * @param string               $group       Primary group, one of Group::ALL.
	 * @param string               $label       Plain-English, translated label.
	 * @param string               $description Plain-English, translated description of what it does.
	 * @param string[]             $edit_kinds  Field edit kinds it performs (Field::EDIT_KINDS).
	 * @param string[]             $fields      Accepted field keys, in registry order.
	 * @param string[]             $groups      Groups of the accepted fields, in Group::ALL order.
	 * @param array<string, mixed> $args        JSON Schema (an object) of its arguments.
	 * @throws \InvalidArgumentException When the description is inconsistent.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $group,
		private readonly string $label,
		private readonly string $description,
		private readonly array $edit_kinds,
		private readonly array $fields,
		private readonly array $groups,
		private readonly array $args
	) {
		$problem = match ( true ) {
			1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $id )   => 'its id is not a lower-case action id',
			! in_array( $group, Group::ALL, true )                => 'its group is unknown',
			'' === trim( $label )                                 => 'it has no label',
			'' === trim( $description )                           => 'it has no description',
			[] === $edit_kinds || [] !== array_diff( $edit_kinds, Field::EDIT_KINDS ) => 'it names no edit kind or an unknown one',
			[] !== array_diff( $groups, Group::ALL )              => 'a field group is unknown',
			'object' !== ( $args['type'] ?? '' )                  => 'its argument schema is not an object',
			default                                               => '',
		};
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Action "%s" is invalid: %s.', $id, $problem ) ) );
		}
	}

	/**
	 * The action id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The primary group.
	 */
	public function group(): string {
		return $this->group;
	}

	/**
	 * The groups of the fields it accepts.
	 *
	 * @return string[]
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * The plain label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * The plain description.
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	public function edit_kinds(): array {
		return $this->edit_kinds;
	}

	/**
	 * Accepted field keys.
	 *
	 * @return string[]
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Whether it accepts a field key.
	 *
	 * @param string $key Field key.
	 */
	public function accepts( string $key ): bool {
		return in_array( $key, $this->fields, true );
	}

	/**
	 * The JSON Schema of its arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function args(): array {
		return $this->args;
	}

	/**
	 * Everything the client needs about the action.
	 *
	 * @return array<string, mixed>
	 */
	public function to_client(): array {
		return [
			'id'          => $this->id,
			'label'       => $this->label,
			'description' => $this->description,
			'group'       => $this->group,
			'groups'      => $this->groups,
			'editKinds'   => $this->edit_kinds,
			'fields'      => $this->fields,
			'args'        => $this->args,
		];
	}
}
