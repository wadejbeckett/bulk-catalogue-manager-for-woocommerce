<?php
/**
 * Deactivation and reactivation (STANDALONE-WPORG-6): deactivation removes
 * the plugin's recurring clean-ups and any pending chunk of a batch, on every
 * site of a network when deactivated network-wide, so Action Scheduler never
 * runs (and fails) an action with no callback. A batch interrupted that way
 * keeps its status and items, and is queued again on reactivation.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Cleanup;
use BulkCatalogueManager\Engine\ChunkRunner;
use PHPUnit\Framework\TestCase;

final class DeactivationTest extends TestCase {
	private const GROUP = 'bulk-catalogue-manager-for-woocommerce';

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['scheduled']   = [];
		$GLOBALS['bulkcat_test']['unscheduled'] = [];
	}

	public function test_deactivation_also_removes_pending_chunks_so_none_fails_while_inactive(): void {
		Cleanup::deactivate();
		$this->assertSame(
			[
				[ 'bulkcat_prune_history', [], self::GROUP ],
				[ 'bulkcat_prune_selections', [], self::GROUP ],
				[ 'bulkcat_prune_requests', [], self::GROUP ],
				[ ChunkRunner::HOOK, [], self::GROUP ],
			],
			$GLOBALS['bulkcat_test']['unscheduled']
		);
	}

	public function test_network_deactivation_unschedules_on_every_site(): void {
		$GLOBALS['bulkcat_test']['multisite'] = true;
		$GLOBALS['bulkcat_test']['sites']     = [ 1, 3 ];
		Cleanup::deactivate( true );
		$this->assertSame( [ [ 'switch', 1 ], [ 'restore' ], [ 'switch', 3 ], [ 'restore' ] ], $GLOBALS['bulkcat_test']['switched'] );
		$this->assertCount( 8, $GLOBALS['bulkcat_test']['unscheduled'], 'Each site\'s own actions are removed.' );
	}

	public function test_deactivating_one_site_of_a_network_touches_only_that_site(): void {
		$GLOBALS['bulkcat_test']['multisite'] = true;
		$GLOBALS['bulkcat_test']['sites']     = [ 1, 3 ];
		Cleanup::deactivate( false );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['switched'] ?? [] );
		$this->assertCount( 4, $GLOBALS['bulkcat_test']['unscheduled'] );
	}

	public function test_an_interrupted_batch_is_queued_again_on_reactivation(): void {
		$GLOBALS['wpdb']->responses["SELECT batch_id FROM `wp_bulkcat_batches` WHERE status IN ('queued', 'running') ORDER BY batch_id ASC LIMIT 100"] = [ '9' ];
		$GLOBALS['wpdb']->responses['SELECT * FROM `wp_bulkcat_batches` WHERE batch_id = 9'] = [
			'batch_id'      => '9',
			'uuid'          => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'user_id'       => '1',
			'created_gmt'   => '2026-09-28 10:00:00',
			'finished_gmt'  => null,
			'status'        => 'running',
			'source'        => 'bulk',
			'undo_of'       => null,
			'operations'    => '[]',
			'selection'     => '{}',
			'total_items'   => '3',
			'done_items'    => '1',
			'changed_items' => '1',
			'failed_items'  => '0',
		];
		ChunkRunner::resume_interrupted();
		ChunkRunner::resume_interrupted();
		$this->assertSame( [ [ 'enqueue', ChunkRunner::HOOK, [ 9 ], self::GROUP, null ] ], $GLOBALS['bulkcat_test']['scheduled'], 'One chunk is queued, however often reactivation runs.' );
	}

	public function test_activation_resumes_interrupted_batches(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/bulk-catalogue-manager-for-woocommerce.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertStringContainsString( "register_activation_hook( __FILE__, [ \\BulkCatalogueManager\\Engine\\ChunkRunner::class, 'resume_interrupted' ] );", $source );
	}
}
