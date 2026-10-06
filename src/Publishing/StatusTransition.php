<?php
/**
 * Native publication lifecycle.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Publishing;

defined( 'ABSPATH' ) || exit;

/** Predicts native status without changing dates or scheduling events. */
final class StatusTransition {
	/** Stable native date inputs; a zero-GMT Draft/Pending floats to save time.
	 * @param object $product Loaded product.
	 * @return array<string, mixed>
	 */
	public static function inputs( object $product ): array {
		$id       = (int) $product->get_id();
		$gmt      = (string) get_post_field( 'post_date_gmt', $id, 'raw' );
		$local    = (string) get_post_field( 'post_date', $id, 'raw' );
		$status   = (string) $product->get_status( 'edit' );
		$date     = $product->get_date_created( 'edit' );
		$floating = '0000-00-00 00:00:00' === $gmt && in_array( $status, [ 'draft', 'pending', 'auto-draft' ], true );
		return [
			'gmt'       => $gmt,
			'local'     => $local,
			'floating'  => $floating,
			'timestamp' => $floating || ! is_object( $date ) ? null : $date->getTimestamp(),
		];
	}
	/** Native one-minute boundary; returned date is stored GMT, not cron event time.
	 * @param object $product Loaded product.
	 * @param string $requested Requested status.
	 * @param int $now Injected Unix clock.
	 * @return array{requested:string,effective:string,date_gmt:string}
	 */
	public static function predict( object $product, string $requested, int $now ): array {
		$inputs    = self::inputs( $product );
		$date      = $inputs['timestamp'] ?? $now;
		$effective = $requested;
		if ( in_array( $requested, [ 'publish', 'future' ], true ) ) {
			$effective = $date - $now >= 60 ? 'future' : 'publish';
		}
		return [
			'requested' => $requested,
			'effective' => $effective,
			'date_gmt'  => gmdate( 'Y-m-d H:i:s', $date ),
		];
	}
	/** Save once with exact-object native transport corrections.
	 * Preserve an authorised publisher's unchanged Pending slug and a canonical
	 * password WC submits without slashing. Native Private clearing and other
	 * hooks' changed values remain authoritative; business values stay raw.
	 * @param object $product Product to save.
	 * @param int|null $actor Recorded actor, or current request.
	 */
	public static function save( object $product, ?int $actor ): void {
		$id            = (int) $product->get_id();
		$slug          = (string) get_post_field( 'post_name', $id, 'raw' );
		$type          = $product->is_type( 'variation' ) ? 'product_variation' : 'product';
		$allowed       = null === $actor ? current_user_can( 'publish_post', $id ) : ( $actor > 0 && user_can( $actor, 'publish_post', $id ) );
		$preserve_slug = 'pending' === $product->get_status( 'edit' ) && '' !== $slug && $slug === $product->get_slug( 'edit' ) && $allowed;
		$password      = (string) $product->get_post_password( 'edit' );
		$filter        = static function ( array $data, array $postarr, array $raw, bool $update ) use ( $id, $slug, $type, $preserve_slug, $password ): array {
			if ( ! $update || (int) ( $postarr['ID'] ?? 0 ) !== $id || (int) ( $raw['ID'] ?? 0 ) !== $id || ( $data['post_type'] ?? '' ) !== $type || ( $raw['post_type'] ?? '' ) !== $type ) {
				return $data;
			}
			if ( $preserve_slug && 'pending' === ( $data['post_status'] ?? '' ) && '' === ( $data['post_name'] ?? null ) && ( $raw['post_name'] ?? null ) === $slug ) {
				$data['post_name'] = $slug;
			}
			// WC submits the canonical getter unchanged; core will unslash transport once.
			// Run first, and correct only untouched transport. Later hooks keep their native meaning.
			if ( 'private' !== ( $data['post_status'] ?? '' ) && ( $raw['post_password'] ?? null ) === $password && ( $data['post_password'] ?? null ) === $password ) {
				$data['post_password'] = wp_slash( $password );
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $filter, PHP_INT_MIN, 4 );
		try {
			$product->save();
		} finally {
			remove_filter( 'wp_insert_post_data', $filter, PHP_INT_MIN );
		}
	}
}
