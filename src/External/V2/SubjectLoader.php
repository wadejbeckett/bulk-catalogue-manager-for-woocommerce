<?php
/**
 * Resolve native product identities before provider authorization or values.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\{Subject, TargetRef};
use BulkCatalogueManager\External\Refusals;
defined( 'ABSPATH' ) || exit;
/** Category resolution deliberately remains with C1/C2. */
final class SubjectLoader {
	/**
	 * Resolve the exact native kind, returning a metadata-only subject.
	 * @param TargetRef $ref Full tuple.
	 * @param int $actor Recorded actor.
	 * @return Subject|\WP_Error
	 */
	public static function load( TargetRef $ref, int $actor ): Subject|\WP_Error {
		$access = Permission::check( $actor, $ref );
		if ( true !== $access ) {
			return $access;}
		if ( 'term' === $ref->kind ) {
			return Refusals::incompatible( Inspector::unavailable() );}
		$post   = get_post( $ref->id );
		$parent = 'variation' === $ref->kind ? new TargetRef( 'product', null, (int) $post->post_parent ) : null;
		return new class($ref,$parent) implements Subject {
			/** Native identity and parent, with no domain values. */
			public function __construct( private readonly TargetRef $ref, private readonly ?TargetRef $parent_ref ) {}
			/** Exact tuple. */
			public function reference(): TargetRef {
				return $this->ref;}
			/** Native parent. */
			public function parent(): ?TargetRef {
				return $this->parent_ref;}
			/**
			 * Only Computer's authorized overlay supplies field values.
			 * @param string $field Field key.
			 */
			public function value( string $field ): mixed {
				unset( $field );
				throw new \LogicException( 'Field value has not been loaded.' );}
		};
	}
}
