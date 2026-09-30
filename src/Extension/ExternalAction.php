<?php
/**
 * An action a provider offers (docs/EXTENSIONS.md §2, §4, §5): it changes
 * exactly one external field of its own provider. It computes a change
 * without writing; the core applies it through the field's write().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * An external action, contract version 1.
 */
interface ExternalAction {
	/**
	 * What the action is: id, field, chooser group, label, description, edit
	 * kind, argument specs and capability.
	 */
	public function describe(): ActionDescriptor;

	/**
	 * Rules spanning arguments, after the core checked each argument against
	 * its spec: Validation::passed( $args ) or Validation::refused( $error ).
	 * Checks that chosen targets still exist and may be used by the
	 * context's user.
	 *
	 * @param array<string, mixed> $args    The normalised arguments.
	 * @param Context              $context The run: preview or apply.
	 */
	public function validate( array $args, Context $context ): Validation;

	/**
	 * What the action would do to one object, without writing: a Result on
	 * the action's field (changed, already, skipped or failed with a plain
	 * reason). Reads current values through Subject::value() only, so steps
	 * chain and the preview matches the write.
	 *
	 * @param Subject              $subject The object.
	 * @param array<string, mixed> $args    The normalised arguments.
	 * @param Context              $context The run.
	 */
	public function compute( Subject $subject, array $args, Context $context ): Result;
}
