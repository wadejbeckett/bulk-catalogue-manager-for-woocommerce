<?php
/**
 * Which selected or listed ids an export can write (T99, ticket 10 R12):
 * `ProductQuery::present_members()` answers `id => parent_id` in ascending
 * order for the ids that are products or variations in a status the
 * catalogue lists, leaving out a variation whose product is gone or no
 * longer listed, in statements of at most 1,000 ids. The prepared SQL runs
 * through the in-memory SqlEvaluator over the seeded catalogue.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\ProductQuery;
use PHPUnit\Framework\TestCase;

final class PresentMembersTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		SeededCatalogue::seed( self::extra() );
	}

	/**
	 * A post row in SeededCatalogue's shape.
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 * @param int    $parent Post parent.
	 * @return array<int, mixed>
	 */
	private static function post( string $type, string $status, int $parent ): array {
		return [
			[
				'post_type'         => $type,
				'post_title'        => "A $type",
				'post_status'       => $status,
				'post_parent'       => $parent,
				'menu_order'        => 0,
				'comment_status'    => 'closed',
				'post_date_gmt'     => '2026-09-01 08:00:00',
				'post_modified_gmt' => '2026-09-01 08:00:00',
			],
			null,
			[],
			[],
		];
	}

	/**
	 * Rows beyond the seed: variations whose product is binned, missing, an
	 * attachment or a page; a simple product with a post parent; a page; an
	 * auto-draft product.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private static function extra(): array {
		return [
			204 => self::post( 'product_variation', 'publish', 108 ),
			205 => self::post( 'product_variation', 'publish', 9999 ),
			206 => self::post( 'product_variation', 'publish', 5000 ),
			207 => self::post( 'product_variation', 'publish', 400 ),
			208 => self::post( 'product_variation', 'publish', 302 ),
			301 => self::post( 'product', 'publish', 101 ),
			302 => self::post( 'product', 'auto-draft', 0 ),
			400 => self::post( 'page', 'publish', 0 ),
		];
	}

	public function test_returns_listed_products_and_variations_with_their_parent(): void {
		$present = ProductQuery::present_members( [ 202, 101, 103, 201, 104, 105, 102, 301, 101 ] );
		$this->assertSame(
			[
				101 => 0,
				102 => 0,
				103 => 0,
				104 => 0,
				105 => 0,
				201 => 103,
				202 => 103,
				301 => 101,
			],
			$present,
			'Draft, pending and private products list; a private variation of a listed product lists; a simple product keeps its post parent.'
		);
		$this->assertSame( [ 101, 102, 103, 104, 105, 201, 202, 301 ], array_keys( $present ), 'Ascending ids.' );
	}

	public function test_leaves_out_missing_binned_unlisted_and_non_product_ids(): void {
		$this->assertSame( [ 101 => 0 ], ProductQuery::present_members( [ 108, 203, 5000, 400, 99999, 302, 101 ] ) );
		$this->assertSame( [], ProductQuery::present_members( [] ) );
		$this->assertSame( [], ProductQuery::present_members( [ 99999 ] ) );
	}

	public function test_leaves_out_a_variation_whose_product_is_gone_or_unlisted(): void {
		$this->assertSame(
			[ 201 => 103 ],
			ProductQuery::present_members( [ 204, 205, 206, 207, 208, 201 ] ),
			'Binned product, missing product, an attachment, a page and an auto-draft product: none is a listed product.'
		);
	}

	public function test_reads_at_most_1000_ids_per_statement(): void {
		SeededCatalogue::seed( self::extra() + SeededCatalogue::fillers( 1200 ) );
		$ids = range( 1, 2500 );
		shuffle( $ids );
		$before  = count( $GLOBALS['wpdb']->queries );
		$present = ProductQuery::present_members( $ids );
		$sent    = array_slice( $GLOBALS['wpdb']->queries, $before );
		$this->assertCount( 3, $sent, '2,500 ids: 1,000, 1,000 and 500.' );
		$in = static fn( string $sql ): int => count( explode( ',', (string) preg_replace( '/^.* AND p\.ID IN \(([^)]*)\).*$/s', '$1', $sql ) ) );
		$this->assertSame( [ 1000, 1000, 500 ], array_map( $in, $sent ) );
		foreach ( $sent as $sql ) {
			$this->assertStringContainsString( 'p.ID AS id, p.post_parent AS parent_id', $sql );
			$this->assertStringEndsWith( 'ORDER BY p.ID ASC LIMIT ' . $in( $sql ), $sql );
		}
		$this->assertCount( 1200 + 7 + 3, $present, 'Every filler, the seven seeded products, the listed variations 201 and 202 and 301.' );
		$this->assertSame( array_keys( $present ), array_values( array_unique( array_keys( $present ) ) ) );
		$sorted = array_keys( $present );
		sort( $sorted );
		$this->assertSame( $sorted, array_keys( $present ) );
	}
}
