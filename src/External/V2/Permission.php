<?php
/**
 * Native permission checks for a passed actor and exact target kind.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\TargetRef;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
/** Labels and entry locations never confer rights. */
final class Permission {
	/**
	 * Check family, native identity and parent before any provider data read.
	 * @param int $actor Recorded actor.
	 * @param TargetRef $ref Full native tuple.
	 * @return bool|\WP_Error
	 */
	public static function check( int $actor, TargetRef $ref ): bool|\WP_Error {
		$access = Access::user_can_family( $actor, 'term' === $ref->kind ? 'category' : 'product' );
		if ( true !== $access ) {
			return $access;}
		if ( 'term' === $ref->kind ) {
			$term = get_term( $ref->id, $ref->taxonomy );
			return is_object( $term ) && ! is_wp_error( $term ) && ( $term->taxonomy ?? null ) === $ref->taxonomy && user_can( $actor, 'edit_term', $ref->id ) ? true : Refusals::forbidden();
		}
		$post = get_post( $ref->id );
		$type = 'variation' === $ref->kind ? 'product_variation' : 'product';
		if ( ! is_object( $post ) || $post->post_type !== $type || ! in_array( $post->post_status, [ 'publish','private','draft','pending','future' ], true ) ) {
			return Refusals::forbidden();}
		$parent = 'variation' === $ref->kind ? (int) $post->post_parent : 0;
		if ( 'variation' === $ref->kind ) {
			$p = get_post( $parent );
			if ( $parent < 1 || ! is_object( $p ) || 'product' !== $p->post_type || ! in_array( $p->post_status, [ 'publish','private','draft','pending','future' ], true ) ) {
				return Refusals::forbidden();}
		}
		return Access::user_can_edit_object( $actor, $ref->id, $parent );
	}
}
