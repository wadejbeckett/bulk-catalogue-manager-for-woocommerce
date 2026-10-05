<?php
/**
 * One catalogue row read from WordPress's primed caches, answering the
 * WooCommerce CRUD getters the field registry names (SPEC §5.1, §12.2), so
 * the read path goes through `Field::read()` — the same contract journal,
 * preview and writer use — without ever building a `WC_Product`.
 *
 * Each getter answers in edit context the way WooCommerce's product data
 * store reads that prop: meta as stored with WooCommerce's defaults, switches
 * from "yes", term ids from the object's terms, the catalogue visibility from
 * the two exclude terms, the type from the `product_type` term (or
 * "variation"), and dates as objects with a `date()` method. Every value
 * comes from caches the Hydrator primed for the whole page, so no getter
 * issues a query. The rendered price and the variation count are handed in
 * by the Hydrator, which computes them once per page.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Query;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only product row.
 */
final class Snapshot {
	/**
	 * Getter => the meta key WooCommerce reads it from. SnapshotTest holds
	 * this equal to the field registry's meta storage.
	 */
	public const META = [
		'get_regular_price'     => '_regular_price',
		'get_sale_price'        => '_sale_price',
		'get_stock_status'      => '_stock_status',
		'get_manage_stock'      => '_manage_stock',
		'get_stock_quantity'    => '_stock',
		'get_sku'               => '_sku',
		'get_weight'            => '_weight',
		'get_length'            => '_length',
		'get_width'             => '_width',
		'get_height'            => '_height',
		'get_tax_status'        => '_tax_status',
		'get_tax_class'         => '_tax_class',
		'get_sold_individually' => '_sold_individually',
		'get_image_id'          => '_thumbnail_id',
		'get_total_sales'       => 'total_sales',
	];

	/**
	 * A date column WordPress leaves unset.
	 */
	private const NO_DATE = '0000-00-00 00:00:00';

	/**
	 * Build a row.
	 *
	 * @param object                                              $post       The row's post, from the primed post cache.
	 * @param array<string, array<int, array{0: int, 1: string}>> $terms      Taxonomy => the row's terms as [ term id, slug ].
	 * @param int                                                 $children   Variation count from the page's grouped count.
	 * @param string                                              $price_html Rendered price from the page's lookup rows.
	 */
	public function __construct(
		private readonly object $post,
		private readonly array $terms,
		private readonly int $children,
		private readonly string $price_html
	) {}

	/**
	 * Whether this row answers a getter.
	 *
	 * @param string $getter Getter name.
	 */
	public static function answers( string $getter ): bool {
		return method_exists( self::class, $getter );
	}

	/**
	 * Post id.
	 */
	public function get_id(): int {
		return (int) $this->post->ID;
	}

	/**
	 * Name.
	 */
	public function get_name(): string {
		return (string) $this->post->post_title;
	}

	/**
	 * Post status.
	 */
	public function get_status(): string {
		return (string) $this->post->post_status;
	}

	/**
	 * Whether reviews are open.
	 */
	public function get_reviews_allowed(): bool {
		return 'open' === $this->post->comment_status;
	}

	/**
	 * Date created, or null when unset; for a draft whose date floats (a zero
	 * `post_date_gmt`), its `post_date` in site time (SQR-5).
	 *
	 * @return object|null An object with date( $format ), as WC_DateTime has.
	 */
	public function get_date_created(): ?object {
		$gmt = (string) $this->post->post_date_gmt;
		if ( self::NO_DATE === $gmt ) {
			// A draft whose date floats (SQR-5): its post_date, already in site time.
			return self::local_date( (string) ( $this->post->post_date ?? '' ) );
		}
		return self::date( $gmt );
	}

	/**
	 * Date last modified.
	 *
	 * @return object|null An object with date( $format ), as WC_DateTime has.
	 */
	public function get_date_modified(): ?object {
		return self::date( (string) $this->post->post_modified_gmt );
	}

	/**
	 * Product type: "variation" for a variation, else the `product_type` term, "simple" by default.
	 */
	public function get_type(): string {
		if ( 'product_variation' === $this->post->post_type ) {
			return 'variation';
		}
		return $this->terms['product_type'][0][1] ?? 'simple';
	}

	/**
	 * Category ids.
	 *
	 * @return int[]
	 */
	public function get_category_ids(): array {
		return $this->term_ids( 'product_cat' );
	}

	/**
	 * Tag ids.
	 *
	 * @return int[]
	 */
	public function get_tag_ids(): array {
		return $this->term_ids( 'product_tag' );
	}

	/**
	 * Shipping class id, 0 for none.
	 */
	public function get_shipping_class_id(): int {
		return $this->term_ids( 'product_shipping_class' )[0] ?? 0;
	}

	/**
	 * Whether the product is featured.
	 */
	public function get_featured(): bool {
		return $this->has_visibility( 'featured' );
	}

	/**
	 * Catalogue visibility from the two exclude terms, as WooCommerce reads it.
	 */
	public function get_catalog_visibility(): string {
		$catalog = $this->has_visibility( 'exclude-from-catalog' );
		$search  = $this->has_visibility( 'exclude-from-search' );
		return match ( true ) {
			$catalog && $search => 'hidden',
			$catalog            => 'search',
			$search             => 'catalog',
			default             => 'visible',
		};
	}

	/**
	 * Regular price as stored.
	 */
	public function get_regular_price(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Sale price as stored.
	 */
	public function get_sale_price(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Stock status, "instock" when unset.
	 */
	public function get_stock_status(): string {
		$value = $this->meta( __FUNCTION__ );
		return '' === $value ? 'instock' : $value;
	}

	/**
	 * Whether stock is managed.
	 */
	public function get_manage_stock(): bool {
		return 'yes' === $this->meta( __FUNCTION__ );
	}

	/**
	 * Stock quantity as a whole number, null when unset.
	 */
	public function get_stock_quantity(): ?int {
		$value = $this->meta( __FUNCTION__ );
		return is_numeric( $value ) ? (int) (float) $value : null;
	}

	/**
	 * SKU.
	 */
	public function get_sku(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Weight.
	 */
	public function get_weight(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Length.
	 */
	public function get_length(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Width.
	 */
	public function get_width(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Height.
	 */
	public function get_height(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Tax status, "taxable" when unset.
	 */
	public function get_tax_status(): string {
		$value = $this->meta( __FUNCTION__ );
		return '' === $value ? 'taxable' : $value;
	}

	/**
	 * Tax class; "" is the standard rate.
	 */
	public function get_tax_class(): string {
		return $this->meta( __FUNCTION__ );
	}

	/**
	 * Whether the product is sold individually.
	 */
	public function get_sold_individually(): bool {
		return 'yes' === $this->meta( __FUNCTION__ );
	}

	/**
	 * Featured image attachment id, 0 for none.
	 */
	public function get_image_id(): int {
		return (int) $this->meta( __FUNCTION__ );
	}

	/**
	 * Units sold.
	 */
	public function get_total_sales(): int {
		return (int) $this->meta( __FUNCTION__ );
	}

	/**
	 * The rendered price the Hydrator composed from the lookup table.
	 */
	public function get_price_html(): string {
		return $this->price_html;
	}

	/**
	 * A list as long as the variation count. The registry's count adapter
	 * reads only its length, so the page never loads the variations.
	 *
	 * @return int[]
	 */
	public function get_children(): array {
		return array_fill( 0, $this->children, 0 );
	}

	/**
	 * One meta value, as a string, from the primed meta cache.
	 *
	 * @param string $getter A key of META.
	 */
	private function meta( string $getter ): string {
		$value = get_post_meta( (int) $this->post->ID, self::META[ $getter ], true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Term ids in one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function term_ids( string $taxonomy ): array {
		return array_map( static fn( array $term ): int => $term[0], $this->terms[ $taxonomy ] ?? [] );
	}

	/**
	 * Whether the row has a `product_visibility` term.
	 *
	 * @param string $slug Term slug.
	 */
	private function has_visibility( string $slug ): bool {
		foreach ( $this->terms['product_visibility'] ?? [] as $term ) {
			if ( $slug === $term[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A site-time column as a date object in the site's time zone, or null when unset.
	 *
	 * @param string $local Column value.
	 * @return object|null
	 */
	private static function local_date( string $local ): ?object {
		if ( '' === $local || self::NO_DATE === $local ) {
			return null;
		}
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return self::date( ( new \DateTimeImmutable( $local, $zone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A GMT column as a date object in the site's time zone, or null when unset.
	 *
	 * @param string $gmt Column value.
	 * @return object|null
	 */
	private static function date( string $gmt ): ?object {
		if ( '' === $gmt || self::NO_DATE === $gmt ) {
			return null;
		}
		$time = new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) );
		if ( function_exists( 'wp_timezone' ) ) {
			$time = $time->setTimezone( wp_timezone() );
		}
		return new class( $time ) {
			/**
			 * Wrap a time.
			 *
			 * @param \DateTimeImmutable $time Time.
			 */
			public function __construct( private readonly \DateTimeImmutable $time ) {}

			/**
			 * Format, as WC_DateTime::date() does.
			 *
			 * @param string $format Date format.
			 */
			public function date( string $format ): string {
				return $this->time->format( $format );
			}
		};
	}
}
