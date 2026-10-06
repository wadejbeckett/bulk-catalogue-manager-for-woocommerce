<?php
/** Native product destinations, projected for the current actor only. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Admin;

use BulkCatalogueManager\Rest\{Access, Refusal};
defined( 'ABSPATH' ) || exit;

/** No mutation, shared cache, product hydration or provider dependency. */
final class ProductRowActions {
	/** Decorate after the shared catalogue read, never inside its cache. */
	public static function decorate( array $rows ): array {
		$ids = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) ) );
		if ( [] === $ids ) {
			return $rows;
		}
		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );
		foreach ( $rows as &$row ) {
			$post                  = get_post( (int) $row['id'] );
			$row['native_actions'] = $post instanceof \WP_Post ? self::for_post( $post ) : self::empty_actions();
		}
		return $rows;
	}

	/** Native availability is checked again by the marked-link execution guard. */
	public static function authorise( string $action, int $id ): bool|\WP_Error {
		$read = Access::can_read();
		if ( true !== $read ) {
			return $read;
		}
		$post = $id > 0 ? get_post( $id ) : null;
		if ( null === $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return self::refuse( __( 'That product is no longer available. Reload the product list.', 'bulk-catalogue-manager-for-woocommerce' ), 404 );
		}
		if ( ! in_array( $action, [ 'details', 'editor', 'view', 'duplicate', 'trash' ], true ) || ( 'details' !== $action && 'product' !== $post->post_type ) ) {
			return self::refuse( __( 'This action is not available for this row. Use Edit details for a variation.', 'bulk-catalogue-manager-for-woocommerce' ), 400 );
		}
		if ( 'view' === $action ) {
			$type = get_post_type_object( $post->post_type );
			return $type && is_post_type_viewable( $type ) && ( 'publish' === $post->post_status || ( 'private' === $post->post_status && current_user_can( 'read_post', $id ) ) )
				? true : self::refuse( __( 'This product has no live page you can open. Use the product editor.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
		}
		if ( 'trash' === $action ) {
			if ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS <= 0 ) {
				return self::refuse( __( 'Trash is disabled on this site. This action will not permanently delete products.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return Refusal::forbidden( __( 'Your account cannot move this product to Trash.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			if ( ! function_exists( 'wp_check_post_lock' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			return wp_check_post_lock( $id ) ? self::refuse( __( 'Someone is editing this product. Reload the list when they have finished.', 'bulk-catalogue-manager-for-woocommerce' ), 409 ) : true;
		}
		$edit = Access::can_edit_object( $id, 'product_variation' === $post->post_type ? (int) $post->post_parent : 0 );
		if ( true !== $edit ) {
			return $edit;
		}
		if ( 'duplicate' === $action ) {
			$cap  = apply_filters( 'woocommerce_duplicate_product_capability', 'manage_woocommerce' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Native WooCommerce duplicate capability parity.
			$type = get_post_type_object( 'product' );
			if ( ! is_string( $cap ) || '' === $cap || ! current_user_can( $cap ) || ! isset( $type->cap->create_posts ) || ! current_user_can( $type->cap->create_posts ) ) {
				return Refusal::forbidden( __( 'Your account cannot duplicate this product.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
		}
		return true;
	}

	/** Build native URLs without product values or user-dependent caching. */
	public static function for_post( \WP_Post $post ): array {
		$actions            = self::empty_actions();
		$id                 = (int) $post->ID;
		$actions['details'] = true === self::authorise( 'details', $id );
		if ( 'product_variation' === $post->post_type ) {
			$actions['unavailable'] = __( 'Use Edit details for variations. These native product actions apply to the parent product.', 'bulk-catalogue-manager-for-woocommerce' );
			return $actions;
		}
		foreach ( [ 'editor', 'view', 'duplicate', 'trash' ] as $action ) {
			$allowed = self::authorise( $action, $id );
			if ( true !== $allowed ) {
				if ( 'trash' === $action ) {
					$actions['unavailable'] = $allowed->get_error_message();
				}
				continue;
			}
			$url = match ( $action ) {
				'editor' => get_edit_post_link( $id, 'raw' ),
				'view' => get_permalink( $id ),
				'duplicate' => wp_nonce_url(
					add_query_arg(
						[
							'post_type'          => 'product',
							'action'             => 'duplicate_product',
							'post'               => $id,
							'bulkcat_row_action' => 'duplicate',
						],
						admin_url( 'edit.php' )
					),
					'woocommerce-duplicate-product_' . $id
				),
				'trash' => self::trash_url( $id ),
			};
			$url = is_string( $url ) ? esc_url_raw( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), [ 'http', 'https' ] ) : '';
			if ( '' !== $url ) {
				$actions[ $action ] = [ 'url' => $url ];
				if ( 'trash' === $action ) {
					$actions[ $action ]['confirm'] = self::needs_sales_warning( $post ) ? __( 'This product has produced sales and may be linked to existing orders. Are you sure you want to delete it?', 'bulk-catalogue-manager-for-woocommerce' ) : '';
				}
			}
		}
		return $actions;
	}

	/** Native published-product warning predicate; meta is primed once for a page. */
	public static function needs_sales_warning( \WP_Post $post ): bool {
		return 'publish' === $post->post_status && (int) get_post_meta( (int) $post->ID, 'total_sales', true ) > 0;
	}

	/** Refuse filtered permanent-delete, other-row and foreign admin destinations. */
	private static function trash_url( int $id ): string {
		$url   = html_entity_decode( (string) get_delete_post_link( $id, '', false ), ENT_QUOTES, 'UTF-8' );
		$parts = wp_parse_url( $url );
		$admin = wp_parse_url( admin_url( 'post.php' ) );
		if ( ! is_array( $parts ) || ! is_array( $admin ) ) {
			return '';
		}
		foreach ( [ 'scheme', 'host', 'port', 'path', 'user', 'pass', 'fragment' ] as $key ) {
			if ( ( $parts[ $key ] ?? null ) !== ( $admin[ $key ] ?? null ) ) {
				return '';
			}
		}
		parse_str( $parts['query'] ?? '', $query );
		if ( ( $query['post'] ?? null ) !== (string) $id || 'trash' !== ( $query['action'] ?? null ) || ! is_string( $query['_wpnonce'] ?? null ) || '' === $query['_wpnonce'] ) {
			return '';
		}
		$flag = self::needs_sales_warning( get_post( $id ) ) ? '1' : '0';
		return add_query_arg(
			[
				'bulkcat_row_action' => 'trash',
				'bulkcat_row_sales'  => $flag,
				'bulkcat_row_nonce'  => wp_create_nonce( 'bulkcat_row_trash_' . $id . '_sales_' . $flag ),
				'_wp_http_referer'   => admin_url( 'edit.php?post_type=product' ),
			],
			$url
		);
	}

	/** Stable read-only shape, including a missing/deleted row. */
	private static function empty_actions(): array {
		return [
			'details'     => false,
			'editor'      => null,
			'view'        => null,
			'duplicate'   => null,
			'trash'       => null,
			'unavailable' => '',
		];
	}

	/** Internal refusal, no stored identifier. */
	private static function refuse( string $message, int $status ): \WP_Error {
		return Refusal::create( 'bulkcat_row_action_unavailable', $message, $status );
	}
}
