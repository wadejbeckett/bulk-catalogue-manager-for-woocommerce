<?php
/**
 * What an external action says about itself (docs/EXTENSIONS.md §4, §5.1):
 * its id, the one field it changes, the chooser group it sits in, a plain
 * label and description, its edit kind, its argument specs and the
 * WordPress capability a user needs to use it. The core checks all of it
 * when the provider registers, trims long strings and adds the common
 * arguments (`field`, `skip_already` and, for a per-variation field,
 * `apply_to_variations`).
 *
 * An argument spec is an array: `type` (boolean, text, integer, decimal,
 * choice or multichoice), `label`, `rule` (the sentence a refusal shows),
 * and optionally `required`, `default`, `choices` (value => label), `min`,
 * `max`, `exclusive_min`, `exclusive_max`, `max_length`, `allow_empty`,
 * `min_items` and `max_items`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable external action descriptor.
 */
final class ActionDescriptor {
	/**
	 * Describe an external action. Nothing is checked here: the core checks
	 * the whole provider when it registers, and refuses it naming the first problem.
	 *
	 * @param string                              $id             Action id: `x_<provider id>_<name>`.
	 * @param string                              $field          One ExternalField key of the same provider.
	 * @param string                              $group          A native group id (Operations\Group::ALL) or one of the provider's ChooserGroup ids.
	 * @param string                              $label          Plain, translated label (at most 80 characters).
	 * @param string                              $description    Plain, translated one-line description (at most 300 characters).
	 * @param string                              $edit_kind      One of Fields\Field::EDIT_KINDS, such as add, remove, set or clear.
	 * @param array<string, array<string, mixed>> $arguments      Argument name => spec.
	 * @param string                              $capability     A WordPress capability, checked with user_can().
	 * @param bool                                $always_confirm Whether Apply always needs the second click.
	 * @param string                              $confirm_reason The sentence that second click restates.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $field,
		private readonly string $group,
		private readonly string $label,
		private readonly string $description,
		private readonly string $edit_kind,
		private readonly array $arguments,
		private readonly string $capability,
		private readonly bool $always_confirm = false,
		private readonly string $confirm_reason = ''
	) {}

	/**
	 * The action id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The field it changes.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * The chooser group.
	 */
	public function group(): string {
		return $this->group;
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
	 * The edit kind.
	 */
	public function edit_kind(): string {
		return $this->edit_kind;
	}

	/**
	 * The argument specs, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function arguments(): array {
		return $this->arguments;
	}

	/**
	 * The capability.
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Whether Apply always needs the second click.
	 */
	public function always_confirm(): bool {
		return $this->always_confirm;
	}

	/**
	 * The sentence the second click restates.
	 */
	public function confirm_reason(): string {
		return $this->confirm_reason;
	}
}
