<?php
/**
 * What an external change would do to one object (Computer::run()): one
 * Result per step, the value of each field read before the first step, and
 * each changed field's final value.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Operations\Result;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable per-object computation.
 */
final class Computation {
	/**
	 * Keep the parts.
	 *
	 * @param list<Result>         $results One per step, in step order.
	 * @param array<string, mixed> $before  Field key => the value read before the first step.
	 * @param array<string, mixed> $finals  Field key => the final value, for each field a step changes.
	 */
	public function __construct(
		public readonly array $results,
		public readonly array $before,
		public readonly array $finals
	) {}
}
