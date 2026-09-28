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
}
