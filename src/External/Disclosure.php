<?php
/**
 * Who may read an external step's own arguments in a batch report
 * (docs/EXTENSIONS.md §8.3, §18.20). The values a provider's arguments carry
 * are its own, and may name the site's data. A batch report sends them only
 * to a user who holds the step's capability now: for an action, its
 * descriptor's capability; for `restore_external`, the capability of every
 * action on that field, as for undo. When the provider is not registered
 * its capability is unknown, and without a user nobody holds it. In both
 * cases, and for anyone else, only the core's own keys are kept (`field`,
 * `skip_already`, `apply_to_variations`), which History needs to describe
 * the change. Native arguments are kept except protected internal password preimages.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

defined( 'ABSPATH' ) || exit;

/**
 * Filters external steps' arguments for a reader.
 */
final class Disclosure {
	/**
	 * The arguments the core adds itself, always kept.
	 */
	public const CORE_KEYS = [ 'field', 'skip_already', 'apply_to_variations' ];

	/**
	 * A batch's steps as this reader may see them.
	 *
	 * @param mixed     $operations The batch's steps.
	 * @param int       $user_id    The reader; 0 for none.
	 * @param Providers $providers  The providers registered now.
	 * @return mixed The same shape, with withheld arguments removed.
	 */
	public static function operations( mixed $operations, int $user_id, Providers $providers ): mixed {
		if ( ! is_array( $operations ) ) {
			return $operations;
		}
		$operations = \BulkCatalogueManager\Publishing\PublicationPassword::operations( $operations );
		foreach ( $operations as $index => $step ) {
			if ( ! is_array( $step ) || ! is_string( $step['action'] ?? null ) ) {
				continue;
			}
			$action = $step['action'];
			$args   = is_array( $step['args'] ?? null ) ? $step['args'] : [];
			$field  = is_string( $args['field'] ?? null ) ? $args['field'] : '';
			if ( RestoreExternal::ID !== $action && ! str_starts_with( $action, 'x_' ) ) {
				continue;
			}
			if ( ! self::may_read( $user_id, $providers->owning( RestoreExternal::ID === $action ? $field : $action ), $action, $field ) ) {
				$operations[ $index ]['args'] = array_intersect_key( $args, array_flip( self::CORE_KEYS ) );
			}
		}
		return $operations;
	}

	/**
	 * Whether the reader holds the step's capability now.
	 *
	 * @param int             $user_id  The reader.
	 * @param Registered|null $provider The owning provider, or null when missing.
	 * @param string          $action   The step's action.
	 * @param string          $field    The step's field.
	 */
	private static function may_read( int $user_id, ?Registered $provider, string $action, string $field ): bool {
		if ( $user_id <= 0 || null === $provider ) {
			return false;
		}
		if ( RestoreExternal::ID !== $action ) {
			$described = $provider->described( $action );
			return null !== $described && user_can( $user_id, $described['capability'] );
		}
		$any = false;
		foreach ( $provider->all_described() as $described ) {
			if ( $field === $described['field'] ) {
				if ( ! user_can( $user_id, $described['capability'] ) ) {
					return false;
				}
				$any = true;
			}
		}
		return $any;
	}
}
