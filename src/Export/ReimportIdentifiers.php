<?php
/**
 * The identifier block's values for re-import (SPEC §10).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Query\Hydrator;

defined( 'ABSPATH' ) || exit;

/**
 * A RowSource decorator giving each row the Type and Parent cells
 * WooCommerce's own product exporter writes, so an unedited file can go back
 * through WooCommerce's importer with "Update existing products" and change
 * nothing.
 *
 * Type replaces the row's bare type with the list `type`, `downloadable`,
 * `virtual` (each flag only where it is on), which CsvWriter joins as
 * WooCommerce does: "simple, downloadable, virtual"
 * (`WC_Product_CSV_Exporter::get_column_value_type()`). The importer resets
 * both flags from this column, so a bare type would switch them off. The
 * flags are the row's `_downloadable` and `_virtual` meta, read as
 * `wc_string_to_bool()` reads them, from the page's meta cache.
 *
 * Parent follows `WC_Product_CSV_Exporter::get_column_value_parent_id()`
 * for any row with a post parent, whatever its type: the parent's SKU, or
 * `id:` and the parent's ID when it has none; empty when there is no post
 * parent, or the parent post is gone or is not a product or variation
 * (where `wc_get_product()` gives nothing). An empty Parent would make the
 * importer set the post parent to 0, which is why the rule is copied
 * exactly. One deviation (ticket 10, R2): the parent's SKU is replaced by
 * `id:` and its ID whenever it would not read back unchanged (reads_back()),
 * or the parent is in the bin, which WooCommerce's SKU lookup skips
 * (`get_product_id_by_sku()`, Main Build L3), because the importer's
 * `parse_relative_field()` creates an "Import placeholder" product for any
 * SKU it cannot find, even when updating.
 *
 * Cost: at most one added read per page, `update_meta_cache()` over the
 * page's parents (the rows' own meta, and the parents' posts, are already
 * primed by the Hydrator, so priming them again reads nothing).
 */
final class ReimportIdentifiers implements RowSource {
	/**
	 * The meta key WooCommerce stores the downloadable flag in.
	 */
	public const DOWNLOADABLE = '_downloadable';

	/**
	 * The meta key WooCommerce stores the virtual flag in.
	 */
	public const VIRTUAL = '_virtual';

	/**
	 * The meta key WooCommerce stores a SKU in.
	 */
	public const SKU = '_sku';

	/**
	 * Post types `wc_get_product()` builds a product from.
	 */
	private const PRODUCT_POST_TYPES = [ 'product', 'product_variation' ];

	/**
	 * Decorate a row source whose rows carry `id`, `type` and `parent_id`.
	 *
	 * @param RowSource $rows The rows, read with ColumnPlan::fields().
	 */
	public function __construct( private readonly RowSource $rows ) {}

	/**
	 * One page of rows, each with its native Type and its Parent.
	 *
	 * @param array<string, mixed> $params   The read's parameters, without paging.
	 * @param int                  $page     Page, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function page( array $params, int $page, int $per_page ): array {
		$result  = $this->rows->page( $params, $page, $per_page );
		$ids     = [];
		$parents = [];
		foreach ( $result['items'] as $item ) {
			$ids[]  = (int) ( $item['id'] ?? 0 );
			$parent = (int) ( $item[ Hydrator::PARENT_ID ] ?? 0 );
			if ( $parent > 0 ) {
				$parents[ $parent ] = $parent;
			}
		}
		if ( [] !== $parents ) {
			// Already primed by the Hydrator for these rows; reads only what a cached page lacks.
			_prime_post_caches( array_values( $parents ), false, false );
		}
		$primed = array_values( array_unique( array_filter( [ ...$ids, ...array_values( $parents ) ] ) ) );
		if ( [] !== $primed ) {
			// The rows' own meta is primed already, so this reads the parents' meta only: one statement.
			update_meta_cache( 'post', $primed );
		}

		$items = [];
		foreach ( $result['items'] as $item ) {
			$item['type']               = self::type( (int) ( $item['id'] ?? 0 ), (string) ( $item['type'] ?? '' ) );
			$item[ ColumnPlan::PARENT ] = self::parent( (int) ( $item[ Hydrator::PARENT_ID ] ?? 0 ) );
			$items[]                    = $item;
		}
		return [
			'items' => $items,
			'total' => $result['total'],
		] + array_intersect_key( $result, [ 'more' => true ] );
	}

	/**
	 * The Type cell: the type, then `downloadable` and `virtual` where they
	 * are on, as WooCommerce's exporter writes them.
	 *
	 * @param int    $id   The row's id.
	 * @param string $type Its product type, such as "simple" or "variation".
	 * @return list<string>
	 */
	public static function type( int $id, string $type ): array {
		$cell = [ $type ];
		if ( self::on( get_post_meta( $id, self::DOWNLOADABLE, true ) ) ) {
			$cell[] = 'downloadable';
		}
		if ( self::on( get_post_meta( $id, self::VIRTUAL, true ) ) ) {
			$cell[] = 'virtual';
		}
		return $cell;
	}

	/**
	 * The Parent cell for a row whose post parent is $parent_id.
	 *
	 * @param int $parent_id The row's post parent; 0 for none.
	 */
	public static function parent( int $parent_id ): string {
		if ( $parent_id < 1 ) {
			return '';
		}
		$post = get_post( $parent_id );
		if ( ! is_object( $post ) || ! in_array( $post->post_type ?? '', self::PRODUCT_POST_TYPES, true ) ) {
			return '';
		}
		if ( 'trash' === ( $post->post_status ?? '' ) ) {
			// WooCommerce's SKU lookup skips binned products, so the importer would not find this
			// parent by its SKU and would create a placeholder; `id:` finds it whatever its status.
			return 'id:' . $parent_id;
		}
		$sku = get_post_meta( $parent_id, self::SKU, true );
		$sku = is_scalar( $sku ) ? (string) $sku : '';
		return self::reads_back( $sku, true ) ? $sku : 'id:' . $parent_id;
	}

	/**
	 * Whether a SKU in this file comes back unchanged through WooCommerce's
	 * importer: neither the formula guard (CsvWriter::guard(), which the
	 * importer does not remove from a SKU or a Parent) nor `wc_clean()`
	 * (which the importer applies to a SKU) would change it. As a Parent, it
	 * must also survive `parse_relative_field()`: an `empty()` value (`''`
	 * or `0`) is dropped, and `id:` and digits is read as an ID.
	 *
	 * @param string $sku       The SKU.
	 * @param bool   $as_parent Whether it is written as another row's Parent.
	 */
	public static function reads_back( string $sku, bool $as_parent = false ): bool {
		$text = CsvWriter::text( $sku );
		if ( $text !== $sku || CsvWriter::guard( $text ) !== $text || wc_clean( $text ) !== $text ) {
			return false;
		}
		return ! $as_parent || ( '' !== $sku && '0' !== $sku && 1 !== preg_match( '/^id:\d+$/', $sku ) );
	}

	/**
	 * A stored flag read as WooCommerce's `wc_string_to_bool()` reads it.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function on( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return 1 === $value || in_array( strtolower( (string) $value ), [ 'yes', 'true', '1' ], true );
	}
}
