<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';

final class BindingTest extends TestCase {
	public function test_replacement_cannot_accept_an_old_preview(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$p = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$this->assertTrue( is_array( $p ) );
		$s->restore_provider( 'org.example.replacement' );
		$answer = $s->accept( $p );
		$this->assertTrue( $answer instanceof \WP_Error );
		$this->assertSame( 'bulkcat_preview_stale', $answer->get_error_code() );
	}

	public function test_changed_choice_label_invalidates_the_binding(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$p = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$this->assertTrue( is_array( $p ) );
		$s->store->choices['v1'] = 'Renamed value';
		$answer                  = $s->accept( $p );
		$this->assertTrue( $answer instanceof \WP_Error );
		$this->assertSame( 'bulkcat_preview_stale', $answer->get_error_code() );
	}
	public function test_changed_policy_and_variation_expansion_refuse_without_any_header(): void {
		$s = new Scenario();
		$s->product(
			103,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		foreach ( [ 201, 202 ] as $id ) {
			$s->store->seed(
				'variation::' . $id,
				[
					'a'    => [],
					'b'    => [],
					'c'    => [],
					'mode' => 'plain',
				]
			); }
		$step                                = $s->step( 'a', 'add', [ 'v1' ] );
		$step['args']['apply_to_variations'] = true;
		$p                                   = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 103,
				],
			],
			[ $step ]
		);
		$this->assertTrue( is_array( $p ) );
		$this->assertSame( 3, $p['totals']['matched'] );
		$s->store->faults['policy_version'] = 2;
		$answer                             = $s->accept( $p );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 'bulkcat_preview_stale', $answer->get_error_code() );
		$GLOBALS['wpdb']->responses = [
			'/^SELECT v.ID AS id, v.post_parent AS parent_id/' => [
				[
					'id'        => 201,
					'parent_id' => 103,
				],
			],
		] + $GLOBALS['wpdb']->responses;
		$answer                     = $s->accept( $p );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 'bulkcat_preview_stale', $answer->get_error_code() );
		$s->database->sync();
		$this->assertCount( 0, $s->database->batches );
		$this->assertCount( 0, $s->database->receipts );
	}

	public function test_manifest_rejects_unknown_serializer_capability_identity_and_context_without_rewriting(): void {
		$s = new Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$p        = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$accepted = $s->accept( $p );
		$batch    = ( new \BulkCatalogueManager\Data\BatchRepository() )->find_strict( $accepted['batch_id'] );
		$manifest = $batch['extensions'];
		$registry = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed();
		$this->assertTrue( \BulkCatalogueManager\External\V2\Manifest::supported( $manifest, $registry ) );
		foreach ( [ [ 'serializer' => 9 ], [ 'identity' => 'org.example.foreign' ], [ 'capabilities' => [ 'typed-product-subjects' => 2 ] ], [ 'capabilities' => [] ], [ 'steps' => [] ] ] as $patch ) {
			$this->assertInstanceOf( \WP_Error::class, \BulkCatalogueManager\External\V2\Manifest::supported( array_replace( $manifest, $patch ), $registry ) );
		}
		$unsupported                                      = $manifest;
		$unsupported['steps'][0]['validation']['version'] = 9;
		$this->assertInstanceOf( \WP_Error::class, \BulkCatalogueManager\External\V2\Manifest::supported( $unsupported, $registry ) );
		$this->assertSame( $manifest, ( new \BulkCatalogueManager\Data\BatchRepository() )->find_strict( $accepted['batch_id'] )['extensions'] );
	}

	public function test_fix1_mixed_steps_bind_only_their_assigned_expanded_targets(): void {
		require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/ParentAction.php';
		$s      = new Scenario();
		$parent = new \BulkCatalogueManager\Tests\Support\Extension\V2\ParentAction();
		$s->restore_provider( 'org.example.sample', [ $parent ] );
		$value = [
			'a'    => [],
			'b'    => [],
			'c'    => [],
			'mode' => 'plain',
		];
		$s->product( 103, $value );
		foreach ( [ 201, 202 ] as $id ) {
			$s->store->seed( 'variation::' . $id, $value ); }
		$targets = [
			[
				'kind'     => 'product',
				'taxonomy' => null,
				'id'       => 103,
			],
		];
		$steps   = [
			[
				'action' => 'x_sample_parent',
				'args'   => [ 'field' => 'x_sample_composite' ],
			],
			$s->step( 'a', 'add', [ 'v1' ] ),
		];
		$plain   = $s->preview( $targets, $steps );
		$this->assertTrue( is_array( $plain ) );
		$this->assertSame( 1, $plain['totals']['computed'] );
		$parent->available                       = [];
		$parent->validated                       = [];
		$parent->computed                        = [];
		$steps[1]['args']['apply_to_variations'] = true;
		$p                                       = $s->preview( $targets, $steps );
		$this->assertTrue( is_array( $p ) );
		$this->assertSame( 3, $p['totals']['matched'] );
		$this->assertSame( 3, $p['totals']['computed'] );
		$this->assertSame( 3, $p['totals']['would_change'] );
		$this->assertSame( [ [ 'product::103' ] ], $parent->available );
		$this->assertSame( [ [ 'product::103' ] ], $parent->validated );
		$this->assertSame( [ 'product::103' ], $parent->computed );
		$grant = $s->service->issue(
			'batch.apply',
			[
				'serializer' => 2,
				'family'     => $p['family'],
				'source'     => 'bulk',
				'entry'      => $p['entry'],
				'external'   => $p['external'],
				'selection'  => [ 'targets' => $targets ],
				'operations' => $p['operations'],
				'scope_hash' => $p['scope_hash'],
			]
		);
		$this->assertTrue( is_array( $grant ) );
		$request = array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) + [ 'preview_hash' => $p['hash'] ];
		// Drift after grant issuance must refuse before any header/receipt, even in mixed work.
		$responses                  = $GLOBALS['wpdb']->responses;
		$GLOBALS['wpdb']->responses = [
			'/^SELECT v.ID AS id, v.post_parent AS parent_id/' => [
				[
					'id'        => 201,
					'parent_id' => 103,
				],
			],
		] + $responses;
		$drift                      = $s->service->apply( $request );
		$this->assertInstanceOf( \WP_Error::class, $drift );
		$this->assertSame( 'bulkcat_preview_stale', $drift->get_error_code() );
		$s->database->sync();
		$this->assertCount( 0, $s->database->batches );
		$this->assertCount( 0, $s->database->receipts );
		$GLOBALS['wpdb']->responses = $responses;
		$accepted                   = $s->service->apply( $request );
		$this->assertTrue( is_array( $accepted ) );
		$this->assertSame( 3, $accepted['total_items'] );
		$batch = ( new \BulkCatalogueManager\Data\BatchRepository() )->find_strict( $accepted['batch_id'] );
		$this->assertSame(
			[
				'product::103'   => [ 0, 1 ],
				'variation::201' => [ 1 ],
				'variation::202' => [ 1 ],
			],
			$batch['extensions']['step_indices']
		);
		$this->assertSame( $p['scope_hash'], $batch['extensions']['scope_hash'] );
		$this->assertSame( [ 'product' ], $parent->describe()->target_kinds );
		foreach ( $s->database->items as $item ) {
			$actions = array_column( json_decode( $item['operations'], true ), 'action' );
			$this->assertSame( 'product' === $item['object_type'] ? [ 'x_sample_parent', 'x_sample_assign' ] : [ 'x_sample_assign' ], $actions );
		}
		foreach ( $parent->validated as $members ) {
			$this->assertSame( [ 'product::103' ], $members ); }

		// An explicitly selected variation is assigned to every step and must still refuse.
		$this->assertInstanceOf(
			\WP_Error::class,
			$s->preview(
				[
					[
						'kind'     => 'variation',
						'taxonomy' => null,
						'id'       => 201,
					],
				],
				$steps
			)
		);
	}
}
