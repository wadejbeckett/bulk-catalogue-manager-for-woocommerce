<?php
/**
 * Actual ordered computation; no queue, recovery or restoration assertions.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\V2\{Computer, RunContext};
use BulkCatalogueManager\Tests\Support\Extension\V2\{SampleTypedProvider, MemoryStore};
use PHPUnit\Framework\TestCase;
use BulkCatalogueManager\Tests\Support\Extension\V2\ComputerProbeProvider;
require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';
require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/ComputerProbeProvider.php';
final class ComputerTest extends TestCase {
	public function test_ordered_overlay_keeps_cancelled_and_effective_origins(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'][3] = [ 'edit_products' => true ];
		$store                                   = new MemoryStore();
		$store->seed(
			'product::41',
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$provider = new SampleTypedProvider( $store );
		$subject  = new class() implements API\Subject {
			public function reference(): API\TargetRef {
				return new API\TargetRef( 'product', null, 41 );}
			public function parent(): ?API\TargetRef {
				return null;}
			public function value( string $field ): mixed {
				throw new \LogicException( 'Field must read authorized original state.' );}
		};
		$steps    = [];
		foreach ( [ [ 'a', 'add', 'v1' ], [ 'a', 'remove', 'v1' ], [ 'b', 'add', 'v2' ] ] as $i => $v ) {
			$steps[] = new API\ValidatedStep(
				$i,
				'x_sample_assign',
				'x_sample_composite',
				[
					'field'     => 'x_sample_composite',
					'part'      => $v[0],
					'operation' => $v[1],
					'values'    => [ $v[2] ],
					'mode'      => 'keep',
				],
				new API\Snapshot( 1, [] )
			);
		}
		$context = new RunContext( 'preview', 3, 0 );
		$result  = ( new Computer( $provider ) )->run( $subject, $steps, $context )['x_sample_composite'];
		$this->assertSame(
			[
				'a'    => [],
				'b'    => [ 'v2' ],
				'c'    => [],
				'mode' => 'plain',
			],
			$result['after']
		);
		$this->assertSame(
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			],
			$result['before']
		);
		$this->assertCount( 3, $result['contributions'] );
		$this->assertCount( 3, $result['results'] );
		$this->assertSame( [ 0,1,2 ], array_map( static fn( API\Contribution $c ): int=>$c->step->index, $result['contributions'] ) );
		$this->assertSame( 'remove', $result['contributions'][1]->origin->data['operation'] );
		$this->assertSame( [], $store->value( 'product::41' )['b'] );
		$cancelled = ( new Computer( $provider ) )->run( $subject, array_slice( $steps, 0, 2 ), $context )['x_sample_composite'];
		$this->assertSame( $cancelled['before'], $cancelled['after'] );
		$this->assertCount( 2, $cancelled['contributions'] );
		$this->assertSame( 3, $context->user_id() );
		$this->assertSame( 0, $context->batch_id() );
		$this->assertSame( 'preview', $context->mode() );
	}
	public function test_failed_and_skipped_origins_do_not_change_overlay_and_context_is_exact(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'][7] = [ 'edit_products' => true ];
		$store                                   = new MemoryStore();
		$store->seed(
			'product::41',
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$provider = new ComputerProbeProvider( $store );
		$steps    = [];
		foreach ( range( 0, 3 ) as $i ) {
			$steps[] = new API\ValidatedStep(
				$i,
				'x_sample_assign',
				'x_sample_composite',
				[
					'field'     => 'x_sample_composite',
					'part'      => 'a',
					'operation' => 'add',
					'values'    => [ 'v1' ],
					'mode'      => 'keep',
				],
				new API\Snapshot( 1, [] )
			);}
		$context = new RunContext( 'apply', 7, 123 );
		$result  = ( new Computer( $provider ) )->run( self::subject(), $steps, $context )['x_sample_composite'];
		$this->assertSame( [ 'changed','skipped','failed','already' ], array_map( static fn( API\Contribution $c ): string=>$c->state, $result['contributions'] ) );
		$this->assertSame( [ 'v1' ], $result['after']['a'] );
		$this->assertCount( 4, $result['contributions'] );
		foreach ( $provider->contexts as $seen ) {
			$this->assertSame( $context, $seen );
			$this->assertSame( 7, $seen->user_id() );
			$this->assertSame( 123, $seen->batch_id() );}
		foreach ( array_slice( $provider->before, 1 ) as $before ) {
			$this->assertSame( [ 'v1' ], $before['a'] );}
		$this->assertSame( [], $store->value( 'product::41' )['a'] );
	}
	public function test_value_and_aggregate_provenance_limits_are_enforced_without_writes(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'][7] = [ 'edit_products' => true ];
		$store                                   = new MemoryStore();
		$store->seed(
			'product::41',
			[
				'a'    => str_repeat( 'x', 65536 ),
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$provider = new ComputerProbeProvider( $store );
		$step     = new API\ValidatedStep(
			0,
			'x_sample_assign',
			'x_sample_composite',
			[
				'field'     => 'x_sample_composite',
				'part'      => 'a',
				'operation' => 'add',
				'values'    => [ 'v1' ],
				'mode'      => 'keep',
			],
			new API\Snapshot( 1, [] )
		);
		try {
			( new Computer( $provider ) )->run( self::subject(), [ $step ], new RunContext( 'preview', 7, 0 ) );
			$this->fail( 'Oversized field was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'byte bound', $e->getMessage() );}
		$store->seed(
			'product::41',
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$provider->large = true;
		$steps           = [];
		foreach ( range( 0, 19 ) as $i ) {
			$steps[] = new API\ValidatedStep( $i, $step->action, $step->field, $step->args, new API\Snapshot( 1, [ 'evidence' => str_repeat( 'x', 16000 ) ] ) );}
		try {
			( new Computer( $provider ) )->run( self::subject(), $steps, new RunContext( 'preview', 7, 0 ) );
			$this->fail( 'Oversized aggregate was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'byte bound', $e->getMessage() );}
		$this->assertSame( [], $store->value( 'product::41' )['a'] );
	}
	private static function subject(): API\Subject {
		return new class() implements API\Subject {
			public function reference(): API\TargetRef {
				return new API\TargetRef( 'product', null, 41 );}
			public function parent(): ?API\TargetRef {
				return null;}
			public function value( string $field ): mixed {
				throw new \LogicException( 'Overlay owns values.' );}
		};
	}
}
