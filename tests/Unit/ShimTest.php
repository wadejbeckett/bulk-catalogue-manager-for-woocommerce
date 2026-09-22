<?php
/**
 * The standalone runner's TestCase shim must offer every assertion the
 * suite uses, or `php tests/run-tests.php` fails on a host without PHPUnit
 * while the PHPUnit run stays green.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShimTest extends TestCase {
	/**
	 * Every TestCase method the suite calls as $this->..., self::... or static::...
	 *
	 * @return string[]
	 */
	private function methods_used_by_the_suite(): array {
		$used = [];
		foreach ( (array) glob( __DIR__ . '/{*,*/*}Test.php', GLOB_BRACE ) as $file ) {
			$source = (string) file_get_contents( $file );
			preg_match_all( '/(?:\$this->|self::|static::)((?:assert|expect|markTest)[A-Za-z]+|fail)\s*\(/', $source, $matches );
			foreach ( $matches[1] as $method ) {
				$used[ $method ] = true;
			}
		}
		ksort( $used );
		return array_keys( $used );
	}

	public function test_the_shim_defines_every_assertion_the_suite_uses(): void {
		$shim = (string) file_get_contents( dirname( __DIR__ ) . '/Support/testcase-shim.php' );
		preg_match_all( '/function\s+([A-Za-z]+)\s*\(/', $shim, $matches );
		$defined = $matches[1];

		$used = $this->methods_used_by_the_suite();
		$this->assertNotEmpty( $used );
		$missing = array_values( array_diff( $used, $defined ) );
		$this->assertSame( [], $missing, 'tests/Support/testcase-shim.php lacks: ' . implode( ', ', $missing ) );
	}
}
