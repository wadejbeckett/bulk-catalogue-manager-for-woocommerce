<?php
/**
 * The History list names an add-on's chosen items (ticket 25;
 * docs/EXTENSIONS-v2.md §4.5): each external row of a page gains its
 * manifest's labels for the items its disclosed steps show; native rows and
 * rows already carrying them are left as they are.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Rest\BatchesController;
use PHPUnit\Framework\TestCase;

final class HistoryListChoicesTest extends TestCase {
	public function test_external_rows_gain_their_manifest_labels(): void {
		$items     = [
			[
				'batch_id'   => 9,
				'operations' => [
					[
						'action' => 'x_picks_add',
						'args'   => [
							'field'      => 'x_picks_items',
							'recipients' => [ 'r0007' ],
						],
					],
				],
			],
			[
				'batch_id'   => 8,
				'operations' => [
					[
						'action' => 'set_regular_price',
						'args'   => [],
					],
				],
			],
		];
		$manifests = [
			9 => [
				'picks' => [
					'label'    => 'Sample picks',
					'version'  => '2.0.0',
					'contract' => 2,
					'fields'   => [
						'x_picks_items' => [
							'label'  => 'Sample picks',
							'format' => 1,
						],
					],
					'actions'  => [ 'x_picks_add' => 'Add recipients' ],
					'choices'  => [ 'x_picks_add' => [ 'recipients' => [ 'r0007' => 'Recipient 0007' ] ] ],
				],
			],
		];
		$this->assertSame( [ 9 ], BatchesController::external_ids( $items ) );
		$out = BatchesController::with_manifests( $items, $manifests );
		$this->assertSame( [ 'r0007' => 'Recipient 0007' ], $out[0]['external']['choices']['x_picks_add']['recipients'] );
		$this->assertSame( [ 'x_picks_add' => 'Add recipients' ], $out[0]['external']['actions'] );
		$this->assertArrayNotHasKey( 'external', $out[1] );
		$this->assertSame( [], BatchesController::external_ids( [ $items[1] ] ), 'A page of native changes reads no manifest.' );
	}
}
