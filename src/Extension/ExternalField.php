<?php
/**
 * A value a provider owns (docs/EXTENSIONS.md §2, §4): one per product
 * (parent_only) or one per product and per variation (per_variation). The
 * core never reads or writes it itself; it asks the field.
 *
 * Values are JSON-plain (null, a scalar, or arrays of those) in the
 * provider's canonical form, at most 64 KB encoded. The core treats them as
 * opaque and compares them only through same().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An external field, contract version 1.
 */
interface ExternalField {
	/**
	 * One value per product; a selected variation is skipped.
	 */
	public const PARENT_ONLY = 'parent_only';

	/**
	 * One value per product and per variation.
	 */
	public const PER_VARIATION = 'per_variation';

	/**
	 * The error code write() returns when the stored value is neither the
	 * target nor the expected value (a compare-and-set miss).
	 */
	public const CONFLICT = 'bulkcat_changed_during_write';

	/**
	 * The key: `x_<provider id>_<name>`, at most 64 characters.
	 */
	public function key(): string;

	/**
	 * The field's name, translated plain text (at most 80 characters).
	 */
	public function label(): string;

	/**
	 * PARENT_ONLY or PER_VARIATION.
	 */
	public function variation_rule(): string;

	/**
	 * The version of the value's shape, from 1; journalled with every value.
	 */
	public function format(): int;

	/**
	 * The object's current value in canonical form, or an Unknown when it
	 * cannot be read (never an empty value standing for "unknown"). Writes nothing.
	 *
	 * @param Subject $subject The object.
	 * @return mixed The value, or an Unknown.
	 */
	public function read( Subject $subject ): mixed;

	/**
	 * Whether two canonical values are the same value: reflexive and symmetric.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 */
	public function same( mixed $a, mixed $b ): bool;

	/**
	 * A value as plain text for people (at most 1,000 characters are kept).
	 *
	 * @param mixed   $value   A canonical value.
	 * @param Subject $subject The object it belongs to.
	 */
	public function describe( mixed $value, Subject $subject ): string;

	/**
	 * What a change does, as plain text, or '' to let the old and new values speak.
	 *
	 * @param mixed   $old       The value before.
	 * @param mixed   $new_value The value after.
	 * @param Subject $subject   The object.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string;

	/**
	 * Set the value to $target absolutely, compare-and-set: true without
	 * writing when it already holds $target; write when it holds $expected;
	 * otherwise a WP_Error with code CONFLICT and nothing written. Idempotent,
	 * and it touches only this field's storage.
	 *
	 * @param Subject $subject  The object.
	 * @param mixed   $target   The value to set.
	 * @param mixed   $expected The value it should hold now.
	 * @param Context $context  The run.
	 * @return bool|\WP_Error True, or the reason (CONFLICT on a compare-and-set miss).
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error;

	/**
	 * A journalled value of an older format() converted to the current one,
	 * or a WP_Error when it cannot be converted (that undo item is refused).
	 *
	 * @param mixed $value       The journalled value.
	 * @param int   $from_format Its format.
	 * @return mixed The value in format(), or a WP_Error.
	 */
	public function upgrade( mixed $value, int $from_format ): mixed;
}
