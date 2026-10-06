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
	public function test_pruning_includes_only_bounded_inline_refusals_not_accepted_batches(): void {
		bulkcat_test_reset();
		( new \BulkCatalogueManager\Requests\RequestRepository() )->prune( 1000 );
		$sql = end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( "(result_kind = 'inline_refusal' AND state = 'refused')", $sql );
		$this->assertStringContainsString( "(result_kind = 'batch' AND state = 'gone')", $sql );
		$this->assertStringContainsString( 'retain_until <=', $sql );
	}
}
