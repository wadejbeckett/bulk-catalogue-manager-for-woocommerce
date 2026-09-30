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

use BulkCatalogueManager\Publishing\PublicationPassword;

final class PublicationPasswordTest extends TestCase {
	public function test_exact_internal_preimage_and_public_result_redaction(): void {
		bulkcat_test_reset();
		$secret  = "  <opaque> \\ path ' \" &  ";
		$field   = PublicationPassword::field();
		$product = new SavedProduct(
			'simple',
			[
				'id'            => 501,
				'status'        => 'draft',
				'post_password' => $secret,
			]
		);
		$this->assertSame( $secret, $field->read( $product ) );
		$this->assertSame( $secret, $field->clean( $secret ) );
		$this->assertSame( $secret, $field->write( $product, $secret ) );
		$this->assertSame( $secret, $product->get_post_password( 'edit' ) );
		$this->assertStringNotContainsString( $secret, json_encode( Result::changed( PublicationPassword::FIELD, $secret, '' )->to_array() ) );
		$actions = new Registry( new Fields() );
		$this->assertInstanceOf(
			\WP_Error::class,
			$actions->validate_list(
				[
					[
						'action' => 'set_value',
						'args'   => [
							'field' => PublicationPassword::FIELD,
							'value' => $secret,
						],
					],
				]
			)
		);
		$this->assertStringNotContainsString( PublicationPassword::FIELD, json_encode( ( new Fields() )->to_client() ) );
	}
	public function test_all_public_projections_hide_both_restore_arguments_and_conflict_values(): void {
		bulkcat_test_reset();
		$secret     = "  marker-preimage <opaque> \\ ' \"  ";
		$later      = ' other marker-password \\ ';
		$operations = [
			[
				'action' => 'restore_status',
				'args'   => [
					'field'             => 'status',
					'status'            => 'draft',
					'password'          => $secret,
					'expected_password' => $later,
				],
			],
		];
		$item       = [
			'object_id'   => 501,
			'object_type' => 'product',
			'operations'  => $operations,
			'state'       => 'conflicted',
			'code'        => 'changed_since',
			'reason'      => 'Changed',
			'conflicts'   => [
				[
					'field'    => PublicationPassword::FIELD,
					'expected' => $secret,
					'current'  => $later,
				],
			],
		];
		$plan       = new \BulkCatalogueManager\Undo\Plan( 10, [ $item ], str_repeat( 'a', 64 ) );
		$row        = [
			'change_id'    => 1,
			'batch_id'     => 10,
			'object_id'    => 501,
			'object_type'  => 'product',
			'prop'         => PublicationPassword::FIELD,
			'old_value'    => $secret,
			'new_value'    => $later,
			'result'       => 'applied',
			'message'      => '',
			'modified_gmt' => '2026-09-01 00:00:00',
			'applied_gmt'  => '2026-09-30 00:00:00',
		];
		$public     = [
			$plan->to_array(),
			( new \BulkCatalogueManager\Rest\UndoController() )->item( $item ),
			( new \BulkCatalogueManager\Rest\BatchChangesController( new \BulkCatalogueManager\Data\BatchRepository(), new \BulkCatalogueManager\Data\ChangeRepository(), new \BulkCatalogueManager\Fields\Registry() ) )->change( $row ),
			\BulkCatalogueManager\External\Disclosure::operations( $operations, 1, ( new Registry( new Fields() ) )->providers() ),
			( new \BulkCatalogueManager\Engine\WriteOutcome( 501, [ Result::changed( PublicationPassword::FIELD, $secret, $later ) ], true ) )->to_array(),
		];
		$encoded    = json_encode( $public );
		$this->assertStringNotContainsString( 'marker-', $encoded );
		$this->assertStringNotContainsString( 'expected_password', $encoded );
		$this->assertStringContainsString( 'Password protected', $encoded );
		$this->assertSame( $secret, $plan->items()[0]['operations'][0]['args']['password'], 'Only the protected server plan retains the exact preimage.' );
		$this->assertNotSame( $secret, ( new Fields() )->get( 'sku' )->clean( $secret ), 'Ordinary public text sanitation is unchanged.' );
	}
}
