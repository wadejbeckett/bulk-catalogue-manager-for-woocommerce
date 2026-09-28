<?php
/**
 * Both independent cleanup schedules must be installed once and survive reactivation.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\Cleanup;
use PHPUnit\Framework\TestCase;
final class CleanupTest extends TestCase {
	public function test_scheduling_is_idempotent_and_registry_owned(): void {
		bulkcat_test_reset();
		$this->assertTrue( class_exists( Cleanup::class ) );
		Cleanup::schedule();
		$once = $GLOBALS['bulkcat_test']['scheduled'];
		Cleanup::schedule();
		$this->assertSame( $once, $GLOBALS['bulkcat_test']['scheduled'] );
		$this->assertCount( 2, $once );
	}
}
