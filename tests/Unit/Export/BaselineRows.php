<?php
/**
 * The fixed rows the catalogue export's golden files are written from
 * (T96): one value of every awkward kind the CSV writer handles — a formula
 * start after leading spaces, a leading tab and carriage return, quotes,
 * both separators and a CRLF inside a value, a list, true and false, 0
 * against '' against null, a float, UTF-8 accents and an invalid byte — on a
 * simple product, a variable product and one of its variations, with a
 * value for every registry field.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;

final class BaselineRows {
	/**
	 * Where the golden files live.
	 */
	public const DIR = __DIR__ . '/golden';

	/**
	 * The rows, keyed by field key, as a row source gives them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function rows(): array {
		return [
			[
				'id'                 => 101,
				'parent_id'          => 0,
				'name'               => '  =SUM(A1) Mug',
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
				'visible_on_site'    => 'on',
				'featured'           => true,
				'category_ids'       => [ 12, 15 ],
				'tag_ids'            => [],
				'regular_price'      => '10.50',
				'sale_price'         => '',
				'stock_status'       => 'instock',
				'manage_stock'       => false,
				'stock_quantity'     => null,
				'sku'                => "\tTAB-1",
				'weight'             => 1.5,
				'length'             => '10',
				'width'              => '0',
				'height'             => null,
				'shipping_class_id'  => 0,
				'tax_status'         => 'taxable',
				'tax_class'          => '',
				'sold_individually'  => false,
				'reviews_allowed'    => true,
				'type'               => 'simple',
				'thumbnail'          => 5000,
				'price_html'         => '<span class="woocommerce-Price-amount amount"><bdi>R10.50</bdi></span>',
				'date_created'       => '2026-09-01 08:00:00',
				'date_modified'      => '2026-09-02 09:30:00',
				'units_sold'         => 0,
				'variation_count'    => 0,
			],
			[
				'id'                 => 102,
				'parent_id'          => 0,
				'name'               => "He said \"hi\", then; left\r\nline two",
				'status'             => 'draft',
				'catalog_visibility' => 'hidden',
				'visible_on_site'    => 'off',
				'featured'           => false,
				'category_ids'       => [ 12 ],
				'tag_ids'            => [ 30, 31 ],
				'regular_price'      => '',
				'sale_price'         => '',
				'stock_status'       => 'outofstock',
				'manage_stock'       => true,
				'stock_quantity'     => 0,
				'sku'                => "\rCR-2",
				'weight'             => '',
				'length'             => '',
				'width'              => '',
				'height'             => '',
				'shipping_class_id'  => 40,
				'tax_status'         => 'shipping',
				'tax_class'          => 'reduced-rate',
				'sold_individually'  => true,
				'reviews_allowed'    => false,
				'type'               => 'variable',
				'thumbnail'          => 0,
				'price_html'         => '',
				'date_created'       => '2026-08-15 12:00:00',
				'date_modified'      => null,
				'units_sold'         => 12,
				'variation_count'    => 2,
			],
			[
				'id'                 => 201,
				'parent_id'          => 102,
				'name'               => "Crème brûlée \u{2013} Large",
				'status'             => 'private',
				'catalog_visibility' => null,
				'visible_on_site'    => 'partly',
				'featured'           => null,
				'category_ids'       => [],
				'tag_ids'            => [],
				'regular_price'      => '12.00',
				'sale_price'         => '9.99',
				'stock_status'       => 'onbackorder',
				'manage_stock'       => true,
				'stock_quantity'     => -2,
				'sku'                => "Bad \xff byte",
				'weight'             => '0.250',
				'length'             => '-1',
				'width'              => '+5',
				'height'             => '@3',
				'shipping_class_id'  => 0,
				'tax_status'         => 'taxable',
				'tax_class'          => 'parent',
				'sold_individually'  => null,
				'reviews_allowed'    => null,
				'type'               => 'variation',
				'thumbnail'          => 0,
				'price_html'         => '',
				'date_created'       => '2026-08-15 12:05:00',
				'date_modified'      => '2026-09-03 10:00:00',
				'units_sold'         => 3,
				'variation_count'    => null,
				'parent_name'        => 'He said "hi"',
			],
		];
	}

	/**
	 * The rows as a source: every row on page 1, each row reduced to the
	 * fields read (and `id`, `parent_id`), as the products read answers.
	 *
	 * @param list<array{0: array<string, mixed>, 1: int, 2: int}>|null $calls Calls received, when given.
	 */
	public static function source( ?array &$calls = null ): CallableRowSource {
		return new CallableRowSource(
			static function ( array $params, int $page, int $per_page ) use ( &$calls ): array {
				if ( null !== $calls ) {
					$calls[] = [ $params, $page, $per_page ];
				}
				$keys  = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
				$items = [];
				foreach ( 1 === $page ? self::rows() : [] as $row ) {
					$items[] = [] === $keys ? $row : array_intersect_key( $row, array_flip( [ 'id', 'parent_id', ...$keys ] ) );
				}
				return [
					'items' => $items,
					'total' => count( self::rows() ),
				];
			}
		);
	}

	/**
	 * The two views' columns: every non-image field in registry order, and
	 * the default view's as the screen exports it (exportColumns(): the
	 * title field, then the registry's default columns in their order).
	 *
	 * @return array<string, list<string>>
	 */
	public static function views(): array {
		$registry = new Registry();
		$fields   = array_filter( $registry->all(), static fn( Field $field ): bool => Field::IMAGE !== $field->type() );
		return [
			'all'     => array_keys( $fields ),
			// The columns the default golden was recorded with (B24): fixed, so the
			// golden pins the CSV byte format, not the screen's current default columns.
			'default' => [ 'name', 'sku', 'category_ids', 'regular_price', 'stock_status', 'status' ],
		];
	}

	/**
	 * The screen's default columns today: the product cell, then every
	 * default column in its order.
	 *
	 * @return list<string>
	 */
	public static function screen_default(): array {
		$defaults = array_filter( ( new Registry() )->to_client(), static fn( array $field ): bool => true === $field['defaultColumn'] && 'image' !== $field['type'] );
		usort( $defaults, static fn( array $a, array $b ): int => (int) $a['defaultOrder'] <=> (int) $b['defaultOrder'] );
		return array_values( array_unique( [ 'name', ...array_column( $defaults, 'key' ) ] ) );
	}

	/**
	 * The golden file's path for a view and a separator.
	 *
	 * @param string $view      all or default.
	 * @param string $separator comma or semicolon.
	 */
	public static function golden( string $view, string $separator ): string {
		return self::DIR . '/catalogue-' . $view . '-' . $separator . '.csv';
	}
}
