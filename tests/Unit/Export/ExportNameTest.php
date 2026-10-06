<?php
/**
 * Export file names (SPEC §10, T99): `catalogue-{selected|filtered|all}`,
 * `-with-ids` with the identifier switch on, then the site's date and time;
 * plain ASCII, with no request value in it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

use BulkCatalogueManager\Export\ExportName;
use PHPUnit\Framework\TestCase;

final class ExportNameTest extends TestCase {
	public function test_the_file_name_names_the_rows_and_the_switch_in_site_time(): void {
		$site = new \DateTimeImmutable( '2026-10-05 14:32:59', new \DateTimeZone( 'Africa/Johannesburg' ) );
		$this->assertSame( 'catalogue-selected-with-ids-2026-10-05-1432.csv', ExportName::filename( ExportName::SELECTED, true, $site ) );
		$this->assertSame( 'catalogue-selected-2026-10-05-1432.csv', ExportName::filename( ExportName::SELECTED, false, $site ) );
		$this->assertSame( 'catalogue-filtered-2026-10-05-1432.csv', ExportName::filename( ExportName::FILTERED, false, $site ) );
		$this->assertSame( 'catalogue-all-with-ids-2026-10-05-1432.csv', ExportName::filename( ExportName::ALL, true, $site ) );
		// Just after midnight in the site's zone is still that day, not the day before in UTC.
		$early = new \DateTimeImmutable( '2026-10-05 00:30:00', new \DateTimeZone( '+02:00' ) );
		$this->assertSame( 'catalogue-all-2026-10-05-0030.csv', ExportName::filename( ExportName::ALL, false, $early ) );
	}

	public function test_no_request_value_enters_the_file_name(): void {
		$now = new \DateTimeImmutable( '2026-10-05 14:32:00', new \DateTimeZone( 'UTC' ) );
		foreach ( [ 'filtered"; evil', '../all', 'selected ', 'shirts', '' ] as $given ) {
			try {
				ExportName::filename( $given, false, $now );
				$this->fail( 'Accepted ' . $given );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'rows', $e->getMessage() );
			}
		}
		$this->assertSame( [ 'selected', 'filtered', 'all' ], ExportName::ROWS );
		foreach ( ExportName::ROWS as $rows ) {
			$this->assertSame( 1, preg_match( '/^catalogue-[a-z-]+-\d{4}-\d{2}-\d{2}-\d{4}\.csv$/', ExportName::filename( $rows, true, $now ) ) );
		}
	}
}
