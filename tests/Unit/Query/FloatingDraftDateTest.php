<?php
/**
 * SQR-5: a draft saved in the classic editor keeps a zero `post_date_gmt`
 * (its date floats until it is published), so its date created falls back
 * to `post_date`, in the site's time, everywhere: the cell shows it, the
 * default newest-first sort places it by it, and the date filters test it.
 * Before, such a draft sorted after every dated product, showed no date,
 * matched "before 2026" and missed "in the past 7 days".
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Query\Snapshot;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SeededCatalogue.php';

final class FloatingDraftDateTest extends TestCase {
	/**
	 * The clock: 1 October 2026, 12:00 UTC.
	 */
	private const NOW = '2026-10-01 12:00:00';

	/**
	 * The draft's post fields.
	 *
	 * @var array<string, mixed>
	 */
	private const DRAFT = [
		'post_type'         => 'product',
		'post_title'        => 'Fresh Draft',
		'post_status'       => 'draft',
		'post_parent'       => 0,
		'menu_order'        => 0,
		'comment_status'    => 'open',
		'post_date'         => '2026-09-30 10:00:00',
		'post_date_gmt'     => '0000-00-00 00:00:00',
		'post_modified'     => '2026-09-30 10:00:00',
		'post_modified_gmt' => '2026-09-30 08:00:00',
	];

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['timezone'] = 'Africa/Johannesburg';
		SeededCatalogue::seed( [ 402 => [ self::DRAFT, [ 'DRAFT', 5.0, 5.0, 'instock', null, '' ], [ 60 ], [] ] ] );
	}

	/**
	 * The ids a read lists, in order.
	 *
	 * @param array<string, mixed> $args Read arguments.
	 * @return list<int>
	 */
	private static function ids( array $args ): array {
		$query = new ProductQuery( Criteria::from_array( $args + [ 'per_page' => 100 ] ), new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) );
		$ids   = array_column( $query->rows(), 'id' );
		self::assertSame( count( $ids ), $query->total() );
		return $ids;
	}

	/**
	 * A date created filter.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value    Value.
	 * @return list<int>
	 */
	private static function created( string $operator, mixed $value ): array {
		return self::ids(
			[
				'filters' => [
					[
						'field'    => 'date_created',
						'operator' => $operator,
						'value'    => $value,
					],
				],
			]
		);
	}

	public function test_the_cell_shows_the_draft_s_own_date_in_site_time(): void {
		$date = ( new Snapshot( (object) self::DRAFT, [], 0, '' ) )->get_date_created();
		$this->assertNotNull( $date );
		$this->assertSame( '2026-09-30 10:00', $date->date( 'Y-m-d H:i' ) );
	}

	public function test_newest_first_lists_the_draft_first(): void {
		$this->assertSame( 402, self::ids( [] )[0] );
		$asc = self::ids(
			[
				'orderby' => 'date_created',
				'order'   => 'asc',
			]
		);
		$this->assertSame( 402, end( $asc ), 'Oldest first, it comes last.' );
	}

	public function test_date_filters_test_the_draft_s_date(): void {
		$this->assertNotContains( 402, self::created( 'before', '2026-01-01' ) );
		$this->assertContains( 402, self::created( 'before', '2026-10-01' ) );
		$this->assertSame( [ 402 ], self::created( 'after', '2026-09-29' ) );
		$this->assertSame( [], self::created( 'after', '2026-09-30' ) );
		$this->assertSame(
			[ 402 ],
			self::created(
				'inThePast',
				[
					'value' => 7,
					'unit'  => 'days',
				]
			)
		);
	}
}
