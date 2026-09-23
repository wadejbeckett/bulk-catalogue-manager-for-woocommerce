<?php
/**
 * Turns a page of identifiers from ProductQuery into rows (SPEC §12.2, §4.1,
 * §5.2) in a fixed number of queries whatever the page size, and without
 * instantiating a `WC_Product`, so `woocommerce_product_read` never fires:
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
 *
 * 1. `_prime_post_caches()` over the page's ids;
 * 2. one `update_meta_cache()` over them, only when a visible column is
 *    stored in meta (core primes every key of an object at once; priming
 *    only some keys would leave a cache that lies to other code);
 * 3. one `wp_get_object_terms()` across the visible columns' taxonomies —
 *    `product_cat`, `product_tag`, `product_visibility`,
 *    `product_shipping_class` and, for the type column, `product_type`;
 * 4. one grouped variation count on `post_parent` over the page's product
 *    rows, when that column shows (a variation has none, so a page of
 *    variations skips it);
 * 5. one read of the lookup table's minimum and maximum price, when the
 *    rendered price shows, composed with `wc_price()` and the shop's
 *    formatting — never `WC_Product::get_price_html()` (SPEC §4.1);
 * 6. for thumbnails, the `_thumbnail_id` values out of the page's meta cache,
 *    then `_prime_post_caches()` and one `update_meta_cache()` over the
 *    attachment ids, falling back to WooCommerce's placeholder image;
 * 7. when the page holds variation rows (T20): the page's meta cache (primed
 *    in step 2 whatever the columns), one `_prime_post_caches()` over their
 *    products for `parent_name`, and at most one `get_terms()` naming the
 *    attribute terms for `attribute_summary`, such as "Blue / Large", and
 *    `attributes`, the same values as `{ label, value }` pairs.
 *
 * Each value then comes through the field registry's read contract,
 * `Field::read()`, over a Snapshot answering the CRUD getters from those
 * caches, so the grid shows exactly what journal and preview read.
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
	 * Extra item property on a variation row: its product's name.
	 */
	public const PARENT_NAME = 'parent_name';

	/**
	 * Extra item property on a variation row: its attribute values, such as "Blue / Large".
	 */
	public const ATTRIBUTE_SUMMARY = 'attribute_summary';

	/**
	 * Extra item property on a variation row: its attributes as a list of
	 * `{ label, value }` pairs, in the summary's order (`value` '' for "any").
	 */
	public const ATTRIBUTES = 'attributes';

	/**
	 * Separator between attribute values in a summary.
	 */
	public const SUMMARY_SEPARATOR = ' / ';

	/**
	 * Prefix of a variation's attribute meta keys.
	 */
	private const ATTRIBUTE_META = 'attribute_';

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
		$fields  = $this->fields( $keys );
		$ids     = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) ) );
		$parents = array_values( array_unique( array_filter( array_map( static fn( array $row ): int => (int) $row['parent_id'], $rows ) ) ) );

		_prime_post_caches( $ids, false, false );

		$getters = array_map( static fn( Field $field ): string => $field->getter(), $fields );
		if ( [] !== $parents || [] !== array_filter( $fields, static fn( Field $field ): bool => Storage::META === $field->storage()->kind() ) ) {
			update_meta_cache( 'post', $ids );
		}
		$terms = $this->terms( $ids, $fields );
		// Only a product has variations: a page of variation rows skips the grouped count.
		$products = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['id'], array_filter( $rows, static fn( array $row ): bool => 0 === (int) $row['parent_id'] ) ) ) );
		$children = [] !== $products && in_array( 'get_children', $getters, true ) ? $this->children( $products ) : [];
		$prices   = in_array( 'get_price_html', $getters, true ) ? $this->prices( $ids ) : [];
		$images   = isset( $fields['thumbnail'] ) ? $this->thumbnails( $ids ) : null;
		$variants = [] === $parents ? [] : $this->variations( $rows, $parents );

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
			if ( isset( $variants[ $id ] ) ) {
				$item += $variants[ $id ];
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
	 * An attribute summary, such as "Blue / Large": each attribute's value in
	 * order, joined by SUMMARY_SEPARATOR; an attribute a variation leaves open
	 * ("any") reads "Any Size".
	 *
	 * @param array<int, array{0: string, 1: string}> $attributes [ label, value ] per attribute; value '' for any.
	 */
	public static function attribute_summary( array $attributes ): string {
		$parts = [];
		foreach ( $attributes as [ $label, $value ] ) {
			$parts[] = '' === $value
				/* translators: %s: an attribute's name, such as "Size". */
				? sprintf( __( 'Any %s', 'bulk-catalogue-manager-for-woocommerce' ), $label )
				: $value;
		}
		return implode( self::SUMMARY_SEPARATOR, $parts );
	}

	/**
	 * Attribute pairs, the summary's parts as data: `{ label, value }` per
	 * attribute in the same order, the label the attribute's name ("Size")
	 * and the value its term name or custom value; '' where the variation
	 * leaves the attribute open ("any").
	 *
	 * @param array<int, array{0: string, 1: string}> $attributes [ label, value ] per attribute; value '' for any.
	 * @return list<array{label: string, value: string}>
	 */
	public static function attribute_pairs( array $attributes ): array {
		$pairs = [];
		foreach ( $attributes as [ $label, $value ] ) {
			$pairs[] = [
				'label' => $label,
				'value' => $value,
			];
		}
		return $pairs;
	}

	/**
	 * The parent name, attribute summary and attribute pairs of each variation row, from the
	 * page's primed meta, one prime of the products' posts and at most one
	 * term read naming the attribute values stored as term slugs.
	 *
	 * @param array<int, array{id: int, parent_id: int}> $rows    The page's rows.
	 * @param int[]                                      $parents The variation rows' products.
	 * @return array<int, array<string, mixed>> Variation id => extra properties.
	 */
	private function variations( array $rows, array $parents ): array {
		_prime_post_caches( $parents, false, false );

		// Id => list of [ attribute name, stored value ], in the order the meta is stored.
		$stored = [];
		$slugs  = [];
		foreach ( $rows as $row ) {
			if ( (int) $row['parent_id'] <= 0 ) {
				continue;
			}
			$id            = (int) $row['id'];
			$stored[ $id ] = [];
			$meta          = get_post_meta( $id );
			foreach ( is_array( $meta ) ? $meta : [] as $key => $values ) {
				$key = (string) $key;
				if ( ! str_starts_with( $key, self::ATTRIBUTE_META ) || strlen( $key ) === strlen( self::ATTRIBUTE_META ) ) {
					continue;
				}
				$name            = substr( $key, strlen( self::ATTRIBUTE_META ) );
				$value           = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
				$value           = is_scalar( $value ) ? (string) $value : '';
				$stored[ $id ][] = [ $name, $value ];
				if ( '' !== $value && taxonomy_exists( $name ) ) {
					$slugs[ $name ][ $value ] = true;
				}
			}
		}

		$names = [];
		if ( [] !== $slugs ) {
			$terms = get_terms(
				[
					'taxonomy'               => array_keys( $slugs ),
					'slug'                   => array_values( array_unique( array_merge( ...array_map( 'array_keys', array_values( $slugs ) ) ) ) ),
					'hide_empty'             => false,
					'update_term_meta_cache' => false,
				]
			);
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				if ( is_object( $term ) && isset( $slugs[ (string) $term->taxonomy ][ (string) $term->slug ] ) ) {
					$names[ (string) $term->taxonomy ][ (string) $term->slug ] = (string) $term->name;
				}
			}
		}

		$labels = [];
		$extras = [];
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( ! isset( $stored[ $id ] ) ) {
				continue;
			}
			$attributes = [];
			foreach ( $stored[ $id ] as [ $name, $value ] ) {
				$labels[ $name ] ??= taxonomy_exists( $name ) ? (string) wc_attribute_label( $name ) : ucfirst( str_replace( '-', ' ', $name ) );
				// A term slug reads as its term's name; a deleted term, as its slug; a custom value, as typed.
				$attributes[] = [ $labels[ $name ], $names[ $name ][ $value ] ?? $value ];
			}
			$parent        = get_post( (int) $row['parent_id'] );
			$extras[ $id ] = [
				self::PARENT_NAME       => is_object( $parent ) ? (string) $parent->post_title : '',
				self::ATTRIBUTE_SUMMARY => self::attribute_summary( $attributes ),
				self::ATTRIBUTES        => self::attribute_pairs( $attributes ),
			];
		}
		return $extras;
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
		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args = [ $wpdb->prefix . ProductQuery::TABLES['posts'], ...$ids, 'product_variation', ...ProductQuery::CHILD_STATUSES, count( $ids ) ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders; every value is an argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_parent AS parent_id, COUNT(*) AS children FROM %i WHERE post_parent IN ({$in}) AND post_type = %s AND post_status IN (%s, %s) GROUP BY post_parent LIMIT %d", $args ), ARRAY_A );
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
		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args = [ $wpdb->prefix . ProductQuery::TABLES['lookup'], ...$ids, count( $ids ) ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders; every value is an argument.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, min_price, max_price FROM %i WHERE product_id IN ({$in}) LIMIT %d", $args ), ARRAY_A );
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
}
