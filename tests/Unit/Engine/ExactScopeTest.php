<?php
/**
 * Exact scope (T83): a typed selection keeps unavailable identities in
 * order, and the scope signature binds identities, types, per-item reach
 * and unavailable members, not only counts.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\ExactScope;
use BulkCatalogueManager\Selection\Selection;
use PHPUnit\Framework\TestCase;

final class ExactScopeTest extends TestCase {
	public function test_typed_selection_keeps_sorted_missing_members(): void {
		$selection = Selection::of_members(
			[
				'products'   => [ 9, 2 ],
				'variations' => [ 5 ],
			]
		);
		$this->assertSame( [ [ 2, 'product' ], [ 5, 'variation' ], [ 9, 'product' ] ], $selection->members );
		$this->assertSame( [ 2, 5, 9 ], $selection->ids );
		$empty = Selection::of_members(
			[
				'products'   => [],
				'variations' => [],
			]
		);
		$this->assertSame( 0, $empty->count );
	}

	public function test_scope_signature_covers_identity_type_reach_and_unavailable(): void {
		bulkcat_test_reset();
		$ops   = [
			[
				'action' => 'set_value',
				'args'   => [
					'field' => 'regular_price',
					'value' => '12',
				],
			],
		];
		$scope = [ [ 1, 'product' ], [ 2, 'variation' ] ];
		$first = ExactScope::signature( $scope, $ops, [] );
		$this->assertSame( $first, ExactScope::signature( $scope, $ops, [] ) );
		$changed = [
			'substituted identity' => [ [ 1, 'product' ], [ 3, 'variation' ] ],
			'changed type'         => [ [ 1, 'variation' ], [ 2, 'variation' ] ],
			'changed reach'        => [ [ 1, 'product', [] ], [ 2, 'variation' ] ],
		];
		foreach ( $changed as $case => $objects ) {
			$this->assertNotSame( $first, ExactScope::signature( $objects, $ops, [] ), $case );
		}
		$this->assertNotSame( $first, ExactScope::signature( $scope, $ops, [ 2 ] ), 'An unavailable member changes the scope.' );
	}

	/**
	 * T85: the scope signature of the whole catalogue is worked out without
	 * building the whole scope again in memory (a 90,000-object preview and
	 * Apply ran out of a web request's 128 MB), and it is the same hash the
	 * earlier, whole-array form gave: nothing already previewed goes stale.
	 */
	public function test_scope_signature_is_the_whole_array_hash_worked_out_as_a_stream(): void {
		bulkcat_test_reset();
		$ops       = [
			[
				'args'   => [
					'value'  => 'Café “ünï” 12',
					'field'  => 'name',
					'nested' => [
						'z' => 1,
						'a' => [ 2, 1 ],
					],
				],
				'action' => 'set_value',
			],
		];
		$reference = static function ( array $objects, array $operations, array $unavailable ): string {
			$items = array_map( static fn( array $entry ): array => [ $entry[0], $entry[1], $entry[2] ?? $operations ], $objects );
			return hash_hmac(
				'sha256',
				'bulk-catalogue-manager-for-woocommerce/scope/1/' . \BulkCatalogueManager\Requests\CaptureIntent::fingerprint(
					[
						'items'       => $items,
						'unavailable' => $unavailable,
					]
				),
				wp_salt( 'auth' )
			);
		};
		$cases     = [
			'empty'            => [ [], [] ],
			'one'              => [ [ [ 7, 'product' ] ], [] ],
			'mixed'            => [ [ [ 1, 'product' ], [ 2, 'variation' ], [ 3, 'product' ] ], [ 3 ] ],
			'own steps'        => [
				[
					[
						1,
						'product',
						[
							[
								'action' => 'clear',
								'args'   => [ 'field' => 'sku' ],
							],
						],
					],
					[ 2, 'variation' ],
				],
				[],
			],
			'empty own steps'  => [ [ [ 1, 'product', [] ], [ 2, 'variation' ] ], [ 9, 8 ] ],
			'unavailable only' => [ [], [ 4, 5 ] ],
		];
		foreach ( $cases as $case => [ $objects, $unavailable ] ) {
			$this->assertSame( $reference( $objects, $ops, $unavailable ), ExactScope::signature( $objects, $ops, $unavailable ), $case );
		}
	}

	public function test_scope_signature_of_a_large_scope_adds_almost_no_memory(): void {
		bulkcat_test_reset();
		$ops     = [
			[
				'action' => 'adjust',
				'args'   => [
					'field'               => 'regular_price',
					'direction'           => 'increase',
					'by'                  => 'percent',
					'amount'              => '10',
					'skip_already'        => true,
					'apply_to_variations' => false,
				],
			],
		];
		$objects = [];
		for ( $id = 1; $id <= 20000; $id++ ) {
			$objects[] = [ $id, 0 === $id % 4 ? 'product' : 'variation' ];
		}
		gc_collect_cycles();
		memory_reset_peak_usage();
		$start = memory_get_usage();
		ExactScope::signature( $objects, $ops, [ 3, 4 ] );
		$added = memory_get_peak_usage() - $start;
		$this->assertLessThan( 2 * 1048576, $added, 'Signing 20,000 objects added ' . round( $added / 1048576, 1 ) . ' MB.' );
	}
}
