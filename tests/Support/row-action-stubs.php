<?php
/** Native row navigation test boundaries. @package BulkCatalogueManager */
declare( strict_types = 1 );
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Native API fixtures share one explicit support file.
if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
	class WC_Admin_Duplicate_Product {
		public function duplicate_product_action(): void {
			$GLOBALS['bulkcat_test']['native_duplicates'] = ( $GLOBALS['bulkcat_test']['native_duplicates'] ?? 0 ) + 1;
		}
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}
if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
	define( 'EMPTY_TRASH_DAYS', 30 );
}
if ( ! class_exists( 'WP_Post' ) ) {
	#[AllowDynamicProperties]
	class WP_Post {
		public function __construct( object $post ) {
			foreach ( get_object_vars( $post ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$parts = explode( '?', $url, 2 );
		parse_str( $parts[1] ?? '', $query );
		return $parts[0] . '?' . http_build_query( array_replace( $query, $args ) );
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( string $url, string $action ): string {
		return htmlspecialchars( add_query_arg( [ '_wpnonce' => wp_create_nonce( $action ) ], $url ), ENT_QUOTES );
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( int $id, string $context = 'display' ): string {
		return apply_filters( 'get_edit_post_link', admin_url( 'post.php?post=' . $id . '&action=edit' ), $id, $context );
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $id ): string {
		return apply_filters( 'post_type_link', 'https://example.test/product/' . $id . '/', get_post( $id ) );
	}
}
if ( ! function_exists( 'get_delete_post_link' ) ) {
	function get_delete_post_link( int $id, string $deprecated = '', bool $force_delete = false ): string {
		return apply_filters( 'get_delete_post_link', wp_nonce_url( admin_url( 'post.php?post=' . $id . '&action=trash' ), 'trash-post_' . $id ), $id, $force_delete );
	}
}
if ( ! function_exists( 'wp_check_post_lock' ) ) {
	function wp_check_post_lock( int $id ): int|false {
		return $GLOBALS['bulkcat_test']['row_locks'][ $id ] ?? false;
	}
}
if ( ! function_exists( 'is_post_type_viewable' ) ) {
	function is_post_type_viewable( object $type ): bool {
		return 'product' === $type->name;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): array|string|int|null|false {
		return parse_url( $url, $component );
	}
}
