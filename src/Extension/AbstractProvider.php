<?php
/**
 * A provider's optional methods with their neutral answers (docs/EXTENSIONS.md
 * §4): no chooser groups of its own, no per-object narrowing, nothing to
 * prime and nothing to flush. A provider extends it and overrides what it needs.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * The defaults.
 */
abstract class AbstractProvider implements Provider {
	/**
	 * No chooser groups of its own.
	 *
	 * @return list<ChooserGroup>
	 */
	public function groups(): array {
		return [];
	}

	/**
	 * Every object the core allows.
	 *
	 * @param int     $user_id The user.
	 * @param Subject $subject The object.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error {
		unset( $user_id, $subject );
		return true;
	}

	/**
	 * Nothing to prime.
	 *
	 * @param list<int> $object_ids Objects.
	 */
	public function prime( array $object_ids ): void {
		unset( $object_ids );
	}

	/**
	 * Nothing to flush.
	 *
	 * @param list<int> $object_ids Objects written.
	 * @param Context   $context    The run.
	 */
	public function flush( array $object_ids, Context $context ): void {
		unset( $object_ids, $context );
	}
}
