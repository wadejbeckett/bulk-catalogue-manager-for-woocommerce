<?php
/**
 * The identifier block's values (SPEC §10, T98): Type in WooCommerce's
 * native form, type then `downloadable` then `virtual`; Parent by
 * WooCommerce's exporter rule for any item with a post parent (amendment
 * 10), falling back to `id:<parent ID>` whenever the parent's SKU would not
 * read back unchanged (R2, amendment 1); one added read per page with
 * parents, none without; the formula guard on identifier cells.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\CallableRowSource;
use BulkCatalogueManager\Export\ColumnPlan;
use BulkCatalogueManager\Export\CsvExport;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Export\ReimportIdentifiers;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Hydrator;
use PHPUnit\Framework\TestCase;

final class ReimportIdentifiersTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Put a post and its meta in the read stubs.
	 *
	 * @param int                   $id     Post id.
	 * @param string                $type   Post type.
	 * @param int                   $parent Post parent.
	 * @param array<string, string> $meta   Meta.
	 * @param string                $title  Title.
	 */
	private static function post( int $id, string $type, int $parent = 0, array $meta = [], string $title = '' ): void {
		$GLOBALS['bulkcat_test']['posts'][ $id ] = [
			'post_type'         => $type,
			'post_title'        => '' === $title ? "Item $id" : $title,
			'post_status'       => 'publish',
			'post_parent'       => $parent,
			'menu_order'        => 0,
			'comment_status'    => 'open',
			'post_date_gmt'     => '2026-09-01 08:00:00',
			'post_modified_gmt' => '2026-09-01 08:00:00',
		];
		$GLOBALS['bulkcat_test']['meta'][ $id ]  = $meta;
	}

	/**
	 * One page through the decorator over fixed rows.
	 *
	 * @param list<array<string, mixed>> $items Rows as a row source gives them.
	 * @return list<array<string, mixed>>
	 */
	private static function decorate( array $items ): array {
		$inner = new CallableRowSource(
			static fn( array $params, int $page, int $per_page ): array => [
				'items' => $items,
				'total' => count( $items ),
			]
		);
		return ( new ReimportIdentifiers( $inner ) )->page( [], 1, 100 )['items'];
	}

	/**
	 * A row as the Hydrator gives it, with the identifier fields.
	 *
	 * @param int    $id     Id.
	 * @param string $type   Type slug.
	 * @param int    $parent Parent id.
	 * @param string $sku    Own SKU.
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $type, int $parent = 0, string $sku = '' ): array {
		return [
			'id'                => $id,
			Hydrator::PARENT_ID => $parent,
			'sku'               => $sku,
			'type'              => $type,
		];
	}

	public function test_type_is_the_native_form_for_every_type_and_flag(): void {
		$items    = [];
		$expected = [];
		$id       = 100;
		foreach ( [ 'simple', 'grouped', 'external', 'variable', 'variation' ] as $type ) {
			foreach ( [ [ 'no', 'no' ], [ 'yes', 'no' ], [ 'no', 'yes' ], [ 'yes', 'yes' ] ] as [ $downloadable, $virtual ] ) {
				++$id;
				self::post(
					$id,
					'variation' === $type ? 'product_variation' : 'product',
					0,
					[
						'_downloadable' => $downloadable,
						'_virtual'      => $virtual,
					]
				);
				$items[] = self::row( $id, $type );
				// WooCommerce's exporter: get_type(), then downloadable, then virtual, joined by ", ".
				$expected[ $id ] = implode( ', ', array_filter( [ $type, 'yes' === $downloadable ? 'downloadable' : '', 'yes' === $virtual ? 'virtual' : '' ] ) );
			}
		}
		foreach ( self::decorate( $items ) as $item ) {
			$this->assertSame( $expected[ $item['id'] ], CsvWriter::text( $item['type'] ), (string) $item['id'] );
		}
		$this->assertSame( 'simple, downloadable, virtual', $expected[104] );
		$this->assertSame( [ 'simple' ], ReimportIdentifiers::type( 999, 'simple' ), 'No meta: no flags.' );
	}

	public function test_parent_is_the_parent_sku_for_any_item_with_a_post_parent_and_empty_without_one(): void {
		self::post( 10, 'product', 0, [ '_sku' => 'VAR-10' ] );
		self::post( 11, 'product', 0, [ '_sku' => 'SIMPLE-PARENT' ] );
		self::post( 20, 'product_variation', 10 );
		self::post( 21, 'product', 11 );
		self::post( 22, 'product' );
		$items = self::decorate( [ self::row( 20, 'variation', 10 ), self::row( 21, 'simple', 11 ), self::row( 22, 'simple' ) ] );
		$this->assertSame( [ 'VAR-10', 'SIMPLE-PARENT', '' ], array_column( $items, ColumnPlan::PARENT ) );
	}

	public function test_parent_is_id_and_the_parent_id_when_the_parent_has_no_sku(): void {
		self::post( 10, 'product' );
		self::post( 12, 'product', 0, [ '_sku' => '' ] );
		self::post( 20, 'product_variation', 10 );
		self::post( 21, 'product_variation', 12 );
		$items = self::decorate( [ self::row( 20, 'variation', 10 ), self::row( 21, 'variation', 12 ) ] );
		$this->assertSame( [ 'id:10', 'id:12' ], array_column( $items, ColumnPlan::PARENT ) );
	}

	public function test_parent_is_empty_for_an_item_whose_parent_is_gone_or_not_a_product(): void {
		self::post( 50, 'page', 0, [ '_sku' => 'PAGE' ] );
		self::post( 20, 'product_variation', 99 );
		self::post( 21, 'product', 50 );
		$items = self::decorate( [ self::row( 20, 'variation', 99 ), self::row( 21, 'simple', 50 ) ] );
		$this->assertSame( [ '', '' ], array_column( $items, ColumnPlan::PARENT ), 'wc_get_product() gives nothing for either, so WooCommerce writes an empty Parent.' );
	}

	public function test_parent_is_id_and_the_parent_id_when_the_parent_sku_would_not_read_back(): void {
		$skus  = [ '-P', ' P', "P\n", 'A  B', '<b>P</b>', 'P%20', '0', 'id:12', '=V1', '@home', "\tP" ];
		$items = [];
		$id    = 10;
		foreach ( $skus as $sku ) {
			++$id;
			self::post( $id, 'product', 0, [ '_sku' => $sku ] );
			self::post( $id + 100, 'product_variation', $id );
			$items[] = self::row( $id + 100, 'variation', $id );
		}
		self::post( 60, 'product', 0, [ '_sku' => 'Tom & Jerry\'s "Classic"' ] );
		self::post( 160, 'product_variation', 60 );
		$items[] = self::row( 160, 'variation', 60 );
		$parents = array_column( self::decorate( $items ), ColumnPlan::PARENT );
		$this->assertSame( [ 'id:11', 'id:12', 'id:13', 'id:14', 'id:15', 'id:16', 'id:17', 'id:18', 'id:19', 'id:20', 'id:21', 'Tom & Jerry\'s "Classic"' ], $parents );
	}

	public function test_parent_is_id_and_the_parent_id_when_the_parent_is_in_the_bin(): void {
		// Lead review L3: WooCommerce's SKU lookup skips binned products (WC_Product_Data_Store_CPT::
		// get_product_id_by_sku(), post_status != 'trash'), so a binned parent's SKU would make the
		// importer create an "Import placeholder"; id:N is looked up by id, whatever the status.
		self::post( 10, 'product', 0, [ '_sku' => 'GROUP-10' ] );
		self::post( 11, 'product', 0, [ '_sku' => 'VAR-11' ] );
		self::post( 12, 'product', 0, [ '_sku' => 'DRAFT-12' ] );
		$GLOBALS['bulkcat_test']['posts'][10]['post_status'] = 'trash';
		$GLOBALS['bulkcat_test']['posts'][11]['post_status'] = 'trash';
		$GLOBALS['bulkcat_test']['posts'][12]['post_status'] = 'draft';
		self::post( 20, 'product', 10 );
		self::post( 21, 'product_variation', 11 );
		self::post( 22, 'product_variation', 12 );
		$items = self::decorate( [ self::row( 20, 'simple', 10 ), self::row( 21, 'variation', 11 ), self::row( 22, 'variation', 12 ) ] );
		$this->assertSame( [ 'id:10', 'id:11', 'DRAFT-12' ], array_column( $items, ColumnPlan::PARENT ), 'Only the bin: a draft parent is found by its SKU.' );
		$this->assertSame( 'id:10', ReimportIdentifiers::parent( 10 ), 'The same through the one-parent read.' );
	}

	public function test_reads_back_matches_the_importer_for_own_and_parent_skus(): void {
		foreach ( [ 'P-1', 'Tom & Jerry', 'A "B"', 'é-ü', '12' ] as $sku ) {
			$this->assertTrue( ReimportIdentifiers::reads_back( $sku ), $sku );
			$this->assertTrue( ReimportIdentifiers::reads_back( $sku, true ), $sku );
		}
		// The importer's own SKU is wc_clean()ed and kept: 0 and id:5 read back as a SKU.
		foreach ( [ '0', 'id:5', '' ] as $sku ) {
			$this->assertTrue( ReimportIdentifiers::reads_back( $sku ), wp_json_encode( $sku ) );
		}
		// As a Parent, empty() drops 0 (and ''), and id:<digits> is read as an ID.
		foreach ( [ '0', 'id:5', '' ] as $sku ) {
			$this->assertFalse( ReimportIdentifiers::reads_back( $sku, true ), wp_json_encode( $sku ) );
		}
		// The guard, or wc_clean() changing it: neither reads back, as a SKU or a Parent.
		foreach ( [ '-P', '+P', '=P', '@P', "\tP", "\rP", '  -P', ' P', "P\n", 'A  B', '<b>P</b>', 'P%20', "\xff" ] as $sku ) {
			$this->assertFalse( ReimportIdentifiers::reads_back( $sku ), wp_json_encode( mb_scrub( $sku ) ) );
			$this->assertFalse( ReimportIdentifiers::reads_back( $sku, true ), wp_json_encode( mb_scrub( $sku ) ) );
		}
	}

	public function test_the_formula_guard_applies_to_identifier_cells(): void {
		self::post( 10, 'product', 0, [ '_sku' => '=V1' ] );
		self::post( 20, 'product_variation', 10, [ '_sku' => '-V1-S' ] );
		self::post( 30, 'product', 0, [ '_sku' => '+P' ] );
		$rows   = [
			[ 'name' => 'Mug - Small' ] + self::row( 20, 'variation', 10, '-V1-S' ),
			[ 'name' => '@home' ] + self::row( 30, 'simple', 0, '+P' ),
		];
		$plan   = new ColumnPlan( new Registry(), [ 'name' ], true );
		$source = new ReimportIdentifiers(
			new CallableRowSource(
				static fn( array $params, int $page, int $per_page ): array => [
					'items' => 1 === $page ? $rows : [],
					'total' => 2,
				]
			)
		);
		$stream = fopen( 'php://memory', 'w+b' );
		( new CsvExport( $source, new Registry(), $plan->columns(), [ 'fields' => $plan->fields() ], ',', 'x.csv', $plan->labels() ) )->stream( $stream );
		rewind( $stream );
		$this->assertSame(
			CsvWriter::BOM . '"ID","SKU","Parent","Type","Name"' . "\r\n" . '"20","\'-V1-S","id:10","variation","Mug - Small"' . "\r\n" . '"30","\'+P","","simple","\'@home"' . "\r\n",
			stream_get_contents( $stream )
		);
	}

	/**
	 * A page of $size variations, each of its own product, hydrated as IdRows hydrates.
	 *
	 * @param int  $size    Rows.
	 * @param bool $parents Whether each row has a parent (a variation), or none (a product).
	 * @return array{0: int, 1: int} Queries for the page without and with the decorator.
	 */
	private function queries_for_a_page( int $size, bool $parents ): array {
		$counts = [];
		foreach ( [ false, true ] as $decorated ) {
			bulkcat_test_reset();
			$rows = [];
			for ( $index = 1; $index <= $size; ++$index ) {
				$parent = $parents ? 1000 + $index : 0;
				if ( $parents ) {
					self::post( $parent, 'product', 0, [ '_sku' => "P-$index" ] );
				}
				self::post( 2000 + $index, $parents ? 'product_variation' : 'product', $parent, [ '_sku' => "V-$index" ] );
				$rows[] = [
					'id'        => 2000 + $index,
					'parent_id' => $parent,
				];
			}
			$hydrator = new Hydrator( new Registry() );
			$source   = new CallableRowSource(
				static fn( array $params, int $page, int $per_page ): array => [
					'items' => $hydrator->hydrate( $rows, $params['fields'] ),
					'total' => count( $rows ),
				]
			);
			$source   = $decorated ? new ReimportIdentifiers( $source ) : $source;
			$before   = bulkcat_test_query_count();
			$items    = $source->page( [ 'fields' => [ 'name', 'id', 'sku', 'type' ] ], 1, $size )['items'];
			$counts[] = bulkcat_test_query_count() - $before;
			$this->assertCount( $size, $items );
			if ( $decorated && $parents ) {
				$this->assertSame( 'P-1', $items[0][ ColumnPlan::PARENT ] );
			}
		}
		return $counts;
	}

	public function test_one_added_read_per_page_for_parent_skus_at_25_50_and_100_rows(): void {
		$added = [];
		foreach ( [ 25, 50, 100 ] as $size ) {
			[ $plain, $decorated ] = $this->queries_for_a_page( $size, true );
			$added[ $size ]        = $decorated - $plain;
		}
		$this->assertSame(
			[
				25  => 1,
				50  => 1,
				100 => 1,
			],
			$added
		);
	}

	public function test_a_page_without_parents_adds_no_read(): void {
		foreach ( [ 25, 100 ] as $size ) {
			[ $plain, $decorated ] = $this->queries_for_a_page( $size, false );
			$this->assertSame( $plain, $decorated, "$size rows" );
		}
	}
}
