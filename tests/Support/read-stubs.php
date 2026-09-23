<?php
/**
 * Recording stubs for the catalogue read path (T16, T17): the object cache
 * with last-changed values, posts, post meta and object terms read from
 * $GLOBALS['bcm_test'] fixtures, attachment addresses, WooCommerce's price
 * formatting and placeholder. The sentinel WC_Product is in
 * wc-product-sentinel.php. Under WordPress this file defines nothing.
 *
 * Query counter: every stub that would reach the database in WordPress
 * appends one statement to the stub $wpdb's `queries`, exactly when WordPress
 * would — `_prime_post_caches()` and `update_meta_cache()` only for ids not
 * yet cached, `get_post()` and `get_post_meta()` for an id not yet primed,
 * `wp_get_object_terms()` on every call — so `bcm_test_query_count()` counts
 * the $wpdb statements and the cache misses together, and a read that forgets
 * to prime shows up as a count growing with the page size.
 *
 * Fixtures: `posts` (id => post fields), `meta` (id => key => value),
 * `object_terms` (id => list of [ taxonomy, term id, slug ]). Recorded:
 * `calls` (list of [ function, arguments ]), `products_built` (WC_Product
 * constructions), plus the `cache`, `last_changed`, `primed_posts` and
 * `primed_meta` state. bcm_test_reset() clears all of it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * Statements recorded so far: $wpdb's plus the stubs' cache misses.
	 */
	function bcm_test_query_count(): int {
		return count( $GLOBALS['wpdb']->queries );
	}

	/**
	 * Record a call and, when it would reach the database, a statement.
	 *
	 * @param string $name   Function.
	 * @param array  $args   Arguments.
	 * @param bool   $query  Whether it counts as a query.
	 */
	function bcm_test_record( string $name, array $args, bool $query ): void {
		$GLOBALS['bcm_test']['calls'][] = [ $name, $args ];
		if ( $query ) {
			$GLOBALS['wpdb']->queries[] = '/* ' . $name . ' */ ' . wp_json_encode( $args );
		}
	}

	function wp_cache_get( int|string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
		$found = isset( $GLOBALS['bcm_test']['cache'][ $group ] ) && array_key_exists( $key, $GLOBALS['bcm_test']['cache'][ $group ] );
		return $found ? $GLOBALS['bcm_test']['cache'][ $group ][ $key ] : false;
	}

	function wp_cache_set( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		$GLOBALS['bcm_test']['cache'][ $group ][ $key ] = $data;
		bcm_test_record( 'wp_cache_set', [ $key, $group, $expire ], false );
		return true;
	}

	/**
	 * Core's behaviour: a group's last-changed value, set to now on first read.
	 */
	function wp_cache_get_last_changed( string $group ): string {
		$GLOBALS['bcm_test']['last_changed'][ $group ] ??= '0.00000001 1';
		return $GLOBALS['bcm_test']['last_changed'][ $group ];
	}

	/**
	 * Moves a group's last-changed value, as clean_post_cache() does for posts.
	 */
	function wp_cache_set_last_changed( string $group ): string {
		$previous                                      = $GLOBALS['bcm_test']['last_changed'][ $group ] ?? '0.00000001 1';
		$GLOBALS['bcm_test']['last_changed'][ $group ] = $previous . '+';
		return $previous;
	}

	/**
	 * Primes the posts not yet cached, in one query.
	 *
	 * @param int[] $ids Post ids.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WordPress's own name.
	function _prime_post_caches( array $ids, bool $update_term_cache = true, bool $update_meta_cache = true ): void {
		$missing = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $GLOBALS['bcm_test']['primed_posts'][ $id ] ) ) );
		bcm_test_record( '_prime_post_caches', [ $ids, $update_term_cache, $update_meta_cache ], [] !== $missing );
		foreach ( $missing as $id ) {
			$GLOBALS['bcm_test']['primed_posts'][ $id ] = true;
		}
	}

	/**
	 * Primes the meta of the objects not yet cached, in one query.
	 *
	 * @param int[] $ids Object ids.
	 */
	function update_meta_cache( string $meta_type, array $ids ): array|false {
		$missing = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $GLOBALS['bcm_test']['primed_meta'][ $id ] ) ) );
		bcm_test_record( 'update_meta_cache', [ $meta_type, $ids ], [] !== $missing );
		foreach ( $missing as $id ) {
			$GLOBALS['bcm_test']['primed_meta'][ $id ] = true;
		}
		return [];
	}

	/**
	 * A fixture post as an object, costing a query when not yet primed.
	 */
	function get_post( int $id ): ?object {
		if ( ! isset( $GLOBALS['bcm_test']['primed_posts'][ $id ] ) ) {
			bcm_test_record( 'get_post', [ $id ], true );
			$GLOBALS['bcm_test']['primed_posts'][ $id ] = true;
		}
		$post = $GLOBALS['bcm_test']['posts'][ $id ] ?? null;
		return null === $post ? null : (object) ( [ 'ID' => $id ] + $post );
	}

	/**
	 * A fixture meta value, costing a query when the object is not yet primed.
	 * Without a key, every key's values in stored order, as core returns them.
	 */
	function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
		if ( ! isset( $GLOBALS['bcm_test']['primed_meta'][ $id ] ) ) {
			bcm_test_record( 'get_post_meta', [ $id, $key ], true );
			$GLOBALS['bcm_test']['primed_meta'][ $id ] = true;
		}
		if ( '' === $key ) {
			return array_map( static fn( mixed $value ): array => [ $value ], $GLOBALS['bcm_test']['meta'][ $id ] ?? [] );
		}
		$value = $GLOBALS['bcm_test']['meta'][ $id ][ $key ] ?? '';
		return $single ? $value : [ $value ];
	}

	/**
	 * Whether a taxonomy is registered: WooCommerce's attribute taxonomies
	 * (`pa_`) and any listed in $GLOBALS['bcm_test']['taxonomies'].
	 */
	function taxonomy_exists( string $taxonomy ): bool {
		return str_starts_with( $taxonomy, 'pa_' ) || in_array( $taxonomy, $GLOBALS['bcm_test']['taxonomies'] ?? [], true );
	}

	/**
	 * An attribute taxonomy's label, as WooCommerce's registered attributes give it.
	 */
	function wc_attribute_label( string $name, mixed $product = '' ): string {
		return $GLOBALS['bcm_test']['attribute_labels'][ $name ] ?? ucfirst( (string) preg_replace( '/^pa_/', '', $name ) );
	}

	/**
	 * Every term below a term, from the parents in $GLOBALS['bcm_test']['term_list'],
	 * as core reads them from its cached hierarchy option: no query.
	 *
	 * @return int[]
	 */
	function get_term_children( int $term_id, string $taxonomy ): array {
		$GLOBALS['bcm_test']['term_children_calls'][] = [ $term_id, $taxonomy ];
		$children                                     = [];
		foreach ( $GLOBALS['bcm_test']['term_list'] ?? [] as $term ) {
			if ( $taxonomy === $term->taxonomy && $term_id === (int) $term->parent && ! in_array( (int) $term->term_id, $children, true ) ) {
				$children[] = (int) $term->term_id;
				$children   = array_merge( $children, get_term_children( (int) $term->term_id, $taxonomy ) );
			}
		}
		return $children;
	}

	/**
	 * The fixture terms of some objects in some taxonomies, in one query.
	 *
	 * @param int[]    $ids        Object ids.
	 * @param string[] $taxonomies Taxonomies.
	 */
	function wp_get_object_terms( array|int $ids, array|string $taxonomies, array $args = [] ): array {
		bcm_test_record( 'wp_get_object_terms', [ (array) $ids, (array) $taxonomies, $args ], true );
		$terms = [];
		foreach ( (array) $ids as $id ) {
			foreach ( $GLOBALS['bcm_test']['object_terms'][ $id ] ?? [] as [ $taxonomy, $term_id, $slug ] ) {
				if ( in_array( $taxonomy, (array) $taxonomies, true ) ) {
					$terms[] = (object) [
						'object_id' => $id,
						'term_id'   => $term_id,
						'slug'      => $slug,
						'taxonomy'  => $taxonomy,
					];
				}
			}
		}
		return $terms;
	}

	/**
	 * An attachment's address from its post and `_wp_attached_file`, as core
	 * reads them; false when it is not an attachment.
	 */
	function wp_get_attachment_image_url( int $attachment_id, string|array $size = 'thumbnail', bool $icon = false ): string|false {
		$post = get_post( $attachment_id );
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( null === $post || 'attachment' !== ( $post->post_type ?? '' ) || '' === $file ) {
			return false;
		}
		return 'https://example.test/wp-content/uploads/' . $file;
	}

	function wc_placeholder_img_src( string $size = 'woocommerce_thumbnail' ): string {
		bcm_test_record( 'wc_placeholder_img_src', [ $size ], false );
		return 'https://example.test/wp-content/plugins/woocommerce/assets/images/placeholder.webp';
	}

	/**
	 * WooCommerce's price markup with the stub shop's currency symbol,
	 * decimals and separators.
	 */
	function wc_price( float|int|string $price, array $args = [] ): string {
		$amount = number_format( (float) $price, (int) $GLOBALS['bcm_test']['decimals'], (string) $GLOBALS['bcm_test']['separator'], (string) ( $GLOBALS['bcm_test']['thousands'] ?? ',' ) );
		return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' . ( $GLOBALS['bcm_test']['currency'] ?? 'R' ) . '</span>' . $amount . '</bdi></span>';
	}

	function wc_format_price_range( float|int|string $from, float|int|string $to ): string {
		return wc_price( $from ) . ' &ndash; ' . wc_price( $to );
	}
}
