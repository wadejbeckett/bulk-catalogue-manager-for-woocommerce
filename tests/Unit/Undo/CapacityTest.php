<?php
/**
 * T85: how large a change the screen can promise to undo on this host. An
 * undo plan holds every item of the batch in one web request, about 2.7 KB
 * each (measured on 20,013 items), so the size is worked out from the web
 * request's memory limit, conservatively.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Undo\Capacity;
use PHPUnit\Framework\TestCase;

final class CapacityTest extends TestCase {
	public function test_the_limit_follows_the_memory_limit_conservatively(): void {
		$this->assertSame( 27304, Capacity::items( '128M' ), '(128 MB − 32 MB) × 0.75 ÷ 2,765 bytes.' );
		$this->assertLessThan( 41000, Capacity::items( '128M' ), 'Below the ~40,000 where a 128 MB request ran out.' );
		$this->assertSame( 63710, Capacity::items( '256M' ) );
		$this->assertSame( Capacity::items( '128M' ), Capacity::items( '134217728' ) );
		$this->assertSame( Capacity::items( '1G' ), Capacity::items( '1024M' ) );
		$this->assertSame( 0, Capacity::items( '16M' ), 'Below the request\'s own footprint nothing is promised.' );
		$this->assertNull( Capacity::items( '-1' ), 'No limit: no size limit on undo from here.' );
	}

	public function test_bytes_reads_php_shorthand(): void {
		$this->assertSame( 128 * 1048576, Capacity::bytes( '128M' ) );
		$this->assertSame( 2 * 1073741824, Capacity::bytes( '2g' ) );
		$this->assertSame( 512 * 1024, Capacity::bytes( '512K' ) );
		$this->assertSame( -1, Capacity::bytes( '-1' ) );
	}
}
