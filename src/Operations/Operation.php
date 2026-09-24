<?php
/**
 * The one interface every bulk action implements (SPEC §6): describe itself,
 * validate its arguments, compute a result for one object without writing,
 * and apply that result. Preview and writer call the same compute(), so what
 * is previewed is what is written.
 *
 * Arguments are one associative array. Every action takes `field` (a key its
 * descriptor accepts) and `skip_already` (T29; on by default); the rest are
 * the action's own, described by Descriptor::args(). compute() and apply()
 * take arguments validate() has normalised.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * A bulk action.
 */
interface Operation {
	/**
	 * What the action is: id, group, plain-language label and description,
	 * the field keys it accepts (resolved against the field registry) and
	 * its argument schema.
	 */
	public function describe(): Descriptor;

	/**
	 * Check arguments before any object is read: the normalised arguments,
	 * or a refusal (`bulkcat_` code, §4.8 sentence, status 400).
	 *
	 * @param array<string, mixed> $args Arguments as submitted.
	 */
	public function validate( array $args ): Validation;

	/**
	 * What the action would do to one object. Reads through the field
	 * registry and writes nothing.
	 *
	 * @param object               $subject A WooCommerce product or variation.
	 * @param array<string, mixed> $args    Arguments validate() normalised.
	 */
	public function compute( object $subject, array $args ): Result;

	/**
	 * Set a computed change on the object through the field's setter. Does
	 * nothing for a result that is not a change, and never saves: the writer
	 * saves once after every operation on the object.
	 *
	 * @param Result $result  What compute() returned for this object.
	 * @param object $subject The same product or variation.
	 * @throws WriteRefused When the field refuses the value at write time.
	 */
	public function apply( Result $result, object $subject ): void;
}
