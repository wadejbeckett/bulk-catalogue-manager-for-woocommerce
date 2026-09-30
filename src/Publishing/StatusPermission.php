<?php
/**
 * Native publication lifecycle.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Publishing;

use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/** Product publication and variation Enabled have different native gates. */
final class StatusPermission {
	public const DENIED = 'bulkcat_cannot_publish';
	/** Check object and parent, then the transition's publication capability.
	 * @param int|null $actor Recorded actor; zero never borrows ambient privileges.
	 * @param object $product Loaded product.
	 * @param string $requested Actual requested write value, including legacy restoration.
	 * @param string|null $previous Protected journal preimage on replay; never a dirty target.
	 */
	public static function check( ?int $actor, object $product, string $requested, ?string $previous = null ): ?\WP_Error {
		$variation = $product->is_type( 'variation' );
		$id        = (int) $product->get_id();
		$parent    = $variation ? (int) $product->get_parent_id( 'edit' ) : 0;
		$edit      = null === $actor ? Access::can_edit_object( $id, $parent ) : Access::user_can_edit_object( $actor, $id, $parent );
		if ( true !== $edit ) {
			return $edit;
		}
		$previous ??= (string) $product->get_status( 'edit' );
		if ( $variation || ! in_array( $requested, [ 'publish', 'future', 'private' ], true ) || $requested === $previous || ( in_array( $requested, [ 'publish', 'future' ], true ) && in_array( $previous, [ 'publish', 'future' ], true ) ) ) {
			return null;
		}
		$type    = get_post_type_object( 'product' );
		$cap     = $type->cap->publish_posts ?? '';
		$allowed = '' !== $cap && ( null === $actor ? current_user_can( $cap ) : ( $actor > 0 && user_can( $actor, $cap ) ) );
		return $allowed ? null : Refusal::create( self::DENIED, __( 'You do not have permission to publish this product or make it private. Ask an administrator for access, then try again.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
	}
}
