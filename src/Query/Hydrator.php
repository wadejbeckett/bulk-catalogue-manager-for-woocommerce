<?php
/**
 * Turns a page of identifiers from ProductQuery into rows (SPEC §12.2, §4.1,
 * §5.2) in a fixed number of queries whatever the page size, and without
 * instantiating a `WC_Product`, so `woocommerce_product_read` never fires:
 *
 * 1. `_prime_post_caches()` over the page's ids;
 * 2. one `update_meta_cache()` over them, only when a visible column is
 *    stored in meta (core primes every key of an object at once; priming
 *    only some keys would leave a cache that lies to other code);
 * 3. one `wp_get_object_terms()` across the visible columns' taxonomies —
 *    `product_cat`, `product_tag`, `product_visibility`,
 *    `product_shipping_class` and, for the type column, `product_type`;
 * 4. one grouped variation count on `post_parent`, when that column shows;
 * 5. one read of the lookup table's minimum and maximum price, when the
 *    rendered price shows, composed with `wc_price()` and the shop's
 *    formatting — never `WC_Product::get_price_html()` (SPEC §4.1);
 * 6. for thumbnails, the `_thumbnail_id` values out of the page's meta cache,
 *    then `_prime_post_caches()` and one `update_meta_cache()` over the
 *    attachment ids, falling back to WooCommerce's placeholder image.
 *
 * Each value then comes through the field registry's read contract,
 * `Field::read()`, over a Snapshot answering the CRUD getters from those
 * caches, so the grid shows exactly what journal and preview read.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Fields\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SPEC §12.2: the grouped variation count and the page's lookup rows are one direct, prepared statement each; Catalogue caches the hydrated page in an object-cache group keyed on the last-changed values.

/**
 * Hydrates one page of catalogue rows.
 */
final class Hydrator {
	/**
	 * Every taxonomy a row reads, in one `wp_get_object_terms()` call.
	 */
	public const TAXONOMIES = [ 'product_cat', 'product_tag', 'product_visibility', 'product_shipping_class', 'product_type' ];

	/**
	 * The image size a thumbnail is shown at.
	 */
	public const THUMBNAIL_SIZE = 'woocommerce_gallery_thumbnail';

	/**
	 * Extra item property: the thumbnail's address, or the placeholder's.
	 */
	public const THUMBNAIL_URL = 'thumbnail_url';

	/**
	 * Extra item property: a variation's product, 0 for a product.
	 */
	public const PARENT_ID = 'parent_id';

	/**
	 * Statuses a counted variation may have, as WooCommerce counts a product's children.
	 */
	private const CHILD_STATUSES = [ 'publish', 'private' ];

	/**
	 * Build over a registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * The fields a page carries: those named, in registry order, always with
	 * `id`; every field when none is named. Unknown keys are dropped.
	 *
	 * @param string[] $keys Field keys; empty for every field.
	 * @return array<string, Field>
	 */
	public function fields( array $keys ): array {
		$all = $this->registry->all();
		if ( [] === $keys ) {
			return $all;
		}
		return array_filter( $all, static fn( Field $field ): bool => 'id' === $field->key() || in_array( $field->key(), $keys, true ) );
	}

	/**
	 * Hydrate a page.
	 *
	 * @param array<int, array{id: int, parent_id: int}> $rows ProductQuery::rows(), in order.
	 * @param string[]                                   $keys Field keys; empty for every field.
	 * @return array<int, array<string, mixed>> One item per row, in order.
	 */
	public function hydrate( array $rows, array $keys ): array {
		if ( [] === $rows ) {
			return [];
		}
		$fields = $this->fields( $keys );
		$ids    = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) ) );

		_prime_post_caches( $ids, false, false );

		$getters = array_map( static fn( Field $field ): string => $field->getter(), $fields );
		if ( [] !== array_filter( $fields, static fn( Field $field ): bool => Storage::META === $field->storage()->kind() ) ) {
			update_meta_cache( 'post', $ids );
		}
		$terms    = $this->terms( $ids, $fields );
		$children = in_array( 'get_children', $getters, true ) ? $this->children( $ids ) : [];
		$prices   = in_array( 'get_price_html', $getters, true ) ? $this->prices( $ids ) : [];
		$images   = isset( $fields['thumbnail'] ) ? $this->thumbnails( $ids ) : null;

		$items = [];
		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$post = get_post( $id );
			if ( ! is_object( $post ) ) {
				// Deleted between the page statement and now: leave it out rather than show an empty row.
				continue;
			}
			$item     = [
				'id'            => $id,
				self::PARENT_ID => (int) $row['parent_id'],
			];
			$snapshot = new Snapshot( $post, $terms[ $id ] ?? [], $children[ $id ] ?? 0, $prices[ $id ] ?? '' );
			foreach ( $fields as $key => $field ) {
				$item[ $key ] = Snapshot::answers( $field->getter() ) ? $field->read( $snapshot ) : null;
			}
			if ( null !== $images ) {
				$item[ self::THUMBNAIL_URL ] = $images[ $id ];
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * A rendered price from the lookup table's lowest and highest price: one
	 * price when they agree, a range when they differ, '' when there is none.
	 *
	 * @param mixed $min Lowest price.
	 * @param mixed $max Highest price.
	 */
	public static function render_price( mixed $min, mixed $max ): string {
		if ( ! is_numeric( $min ) ) {
			return '';
		}
		if ( ! is_numeric( $max ) || (float) $min === (float) $max ) {
			return (string) wc_price( (float) $min );
		}
		return (string) wc_format_price_range( (float) $min, (float) $max );
	}

	/**
	 * The page's terms in the visible columns' taxonomies, in one call.
	 *
	 * @param int[]                $ids    Post ids.
	 * @param array<string, Field> $fields Visible fields.
	 * @return array<int, array<string, array<int, array{0: int, 1: string}>>> Id => taxonomy => [ term id, slug ].
	 */
	private function terms( array $ids, array $fields ): array {
		$taxonomies = [];
		foreach ( $fields as $field ) {
			$storage = $field->storage();
			if ( Storage::TAXONOMY === $storage->kind() && in_array( $storage->key(), self::TAXONOMIES, true ) ) {
				$taxonomies[ $storage->key() ] = true;
			}
		}
		if ( [] === $taxonomies ) {
			return [];
		}
		$found = wp_get_object_terms(
			$ids,
			array_values( array_intersect( self::TAXONOMIES, array_keys( $taxonomies ) ) ),
			[
				'fields'                 => 'all_with_object_id',
				'update_term_meta_cache' => false,
			]
		);
		$terms = [];
		foreach ( is_array( $found ) ? $found : [] as $term ) {
			if ( is_object( $term ) ) {
				$terms[ (int) $term->object_id ][ (string) $term->taxonomy ][] = [ (int) $term->term_id, (string) $term->slug ];
			}
		}
		return $terms;
	}

	/**
	 * Variation counts for the page, in one grouped statement.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, int> Parent id => count.
	 */
	private function children( array $ids ): array {
		global $wpdb;
		$sql  = 'SELECT post_parent AS parent_id, COUNT(*) AS children FROM %i WHERE post_parent IN (' . self::placeholders( $ids ) . ') AND post_type = %s AND post_status IN (%s, %s) GROUP BY post_parent LIMIT %d';
		$args = [ $wpdb->prefix . ProductQuery::TABLES['posts'], ...$ids, 'product_variation', ...self::CHILD_STATUSES, count( $ids ) ];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Literal template with placeholders only; every value is an argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (int) $row['parent_id'] ] = (int) $row['children'];
		}
		return $counts;
	}

	/**
	 * Rendered prices for the page from one read of the lookup table.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, string> Id => rendered price.
	 */
	private function prices( array $ids ): array {
		global $wpdb;
		$sql  = 'SELECT product_id, min_price, max_price FROM %i WHERE product_id IN (' . self::placeholders( $ids ) . ') LIMIT %d';
		$args = [ $wpdb->prefix . ProductQuery::TABLES['lookup'], ...$ids, count( $ids ) ];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Literal template with placeholders only; every value is an argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$prices = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$prices[ (int) $row['product_id'] ] = self::render_price( $row['min_price'] ?? null, $row['max_price'] ?? null );
		}
		return $prices;
	}

	/**
	 * Thumbnail addresses for the page: attachment ids out of the primed meta
	 * cache, then the attachments' posts and meta primed once each; a row
	 * without a usable image gets the placeholder.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, string> Id => image address.
	 */
	private function thumbnails( array $ids ): array {
		$attachments = [];
		foreach ( $ids as $id ) {
			$attachments[ $id ] = (int) get_post_meta( $id, '_thumbnail_id', true );
		}
		$unique = array_values( array_unique( array_filter( $attachments ) ) );
		if ( [] !== $unique ) {
			_prime_post_caches( $unique, false, false );
			update_meta_cache( 'post', $unique );
		}
		$placeholder = null;
		$urls        = [];
		foreach ( $attachments as $id => $attachment ) {
			$url = $attachment > 0 ? wp_get_attachment_image_url( $attachment, self::THUMBNAIL_SIZE ) : false;
			if ( ! is_string( $url ) || '' === $url ) {
				$placeholder ??= (string) wc_placeholder_img_src( self::THUMBNAIL_SIZE );
				$url           = $placeholder;
			}
			$urls[ $id ] = $url;
		}
		return $urls;
	}

	/**
	 * One %d placeholder per id.
	 *
	 * @param int[] $ids Ids.
	 */
	private static function placeholders( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}
}
