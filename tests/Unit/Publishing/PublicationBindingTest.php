<?php
/**
 * Native publication lifecycle regression tests.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Publishing;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

use BulkCatalogueManager\Publishing\PublicationBinding;
use BulkCatalogueManager\Publishing\StatusTransition;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Settings\Settings;

final class PublicationBindingTest extends TestCase {
	public function test_stable_inputs_boundary_and_every_exact_member(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [
			'edit_post'        => true,
			'publish_products' => true,
		];
		$ops                             = [
			[
				'action' => 'set_status',
				'args'   => [
					'field' => 'status',
					'value' => 'publish',
				],
			],
		];
		$objects                         = [];
		for ( $id = 1;$id <= 301;++$id ) {
			$GLOBALS['bulkcat_test']['products'][ $id ] = new SavedProduct(
				'simple',
				[
					'id'           => $id,
					'status'       => 'draft',
					'date_created' => new \DateTimeImmutable( '@1060' ),
				]
			);
			$objects[]                                  = [ $id, 'product' ];
		}
		$a = PublicationBinding::of( $objects, $ops, 999 );
		$this->assertSame( $a, PublicationBinding::of( array_reverse( $objects ), $ops, 1000 ) );
		$this->assertNotSame( $a, PublicationBinding::of( $objects, $ops, 1001 ) );
		$GLOBALS['bulkcat_test']['products'][301]->props['date_created'] = new \DateTimeImmutable( '@2000' );
		$this->assertNotSame( $a, PublicationBinding::of( $objects, $ops, 1000 ) );
		$this->assertSame(
			[],
			PublicationBinding::of(
				$objects,
				[
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'status',
							'value' => 'publish',
						],
					],
				],
				1000
			)
		);
		$this->assertSame( Preview::hash( [ 1 ], [], Settings::load() ), Preview::hash( [ 1 ], [], Settings::load(), '', [], [] ) );
	}
	public function test_missing_and_zero_gmt_dates_do_not_bind_derived_now(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps']        = [ 'edit_post' => true ];
		$p                                      = new SavedProduct(
			'simple',
			[
				'id'           => 1,
				'status'       => 'draft',
				'date_created' => null,
			]
		);
		$GLOBALS['bulkcat_test']['products'][1] = $p;
		$ops                                    = [
			[
				'action' => 'set_status',
				'args'   => [
					'field' => 'status',
					'value' => 'publish',
				],
			],
		];
		foreach ( [ '', '0000-00-00 00:00:00' ] as $gmt ) {
			$GLOBALS['bulkcat_test']['post_fields'][1] = [
				'post_date_gmt' => $gmt,
				'post_date'     => '2030-01-01 00:00:00',
			];
			$this->assertSame( 'publish', StatusTransition::predict( $p, 'publish', 1000 )['effective'] );
			$this->assertSame( PublicationBinding::of( [ [ 1,'product' ] ], $ops, 1000 ), PublicationBinding::of( [ [ 1,'product' ] ], $ops, 1001 ) );
		}
	}
	public function test_reaching_steps_and_unreadable_members_are_bound_without_mutation(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps']          = [ 'edit_post' => true ];
		$p                                        = new SavedProduct(
			'simple',
			[
				'id'     => 501,
				'status' => 'draft',
			]
		);
		$GLOBALS['bulkcat_test']['products'][501] = $p;
		$publish                                  = [
			'action' => 'set_status',
			'args'   => [
				'field' => 'status',
				'value' => 'publish',
			],
		];
		$private                                  = [
			'action' => 'set_status',
			'args'   => [
				'field' => 'status',
				'value' => 'private',
			],
		];
		$a                                        = PublicationBinding::of( [ [ 501, 'product', [ $publish, $private ] ], [ 502, 'product', [] ] ], [], 1000 );
		$this->assertNotSame( $a, PublicationBinding::of( [ [ 501, 'product', [ $private, $publish ] ], [ 502, 'product', [] ] ], [], 1000 ) );
		$this->assertNotSame( $a, PublicationBinding::of( [ [ 501, 'product', [ $publish, $private ] ], [ 502, 'product', [ $publish ] ] ], [], 1000 ) );
		$GLOBALS['bulkcat_test']['caps']['edit_post'] = false;
		$unreadable                                   = PublicationBinding::of( [ [ 501, 'product', [ $publish, $private ] ], [ 502, 'product', [] ] ], [], 1000 );
		$this->assertNotSame( $a, $unreadable );
		$this->assertSame( $unreadable, PublicationBinding::of( [ [ 502, 'product', [] ], [ 501, 'product', [ $publish, $private ] ] ], [], 1001 ) );
		$this->assertSame( [], $p->sets );
		$this->assertSame( [], $p->saves );
	}
}
