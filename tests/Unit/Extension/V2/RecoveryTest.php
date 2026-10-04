<?php
/** Pure restoration refuses unsupported authority even with force. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use PHPUnit\Framework\TestCase;
use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\V2\{HistoryCodec, RecoveryState, WriteState};
require_once __DIR__ . '/ProvenanceTest.php';
final class RecoveryTest extends TestCase {
	public function test_conflicts_are_limited_to_owned_domains_and_force_merges_only_those(): void {
		$s       = ProvenanceTest::scenario();
		$c       = $s->compute( ProvenanceTest::targets(), [ $s->step( 'b', 'add', [ 'v1' ] ) ] );
		$current = [
			'a'    => [ 'untouched' ],
			'b'    => [ 'later' ],
			'c'    => [],
			'mode' => 'plain',
		];
		$normal  = $s->restore_plan( $c, $current );
		$this->assertSame( 'b', $normal->conflicts[0]['domain'] );
		$this->assertSame( $current, $normal->target );
		$forced = $s->restore_plan( $c, $current, true );
		$this->assertSame(
			[
				'a'    => [ 'untouched' ],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			],
			$forced->target
		);
	}
	public function test_force_never_bypasses_origin_context_format_target_or_permission(): void {
		$s = ProvenanceTest::scenario();
		$c = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		foreach ( [ 'identity', 'format', 'context', 'target', 'provider' ] as $bad ) {
			$wire = HistoryCodec::encode( $c['history'] );
			if ( 'identity' === $bad ) {
				$wire['identity'] = 'org.example.replaced'; }
			if ( 'format' === $bad ) {
				$wire['format'] = 99; }
			if ( 'context' === $bad ) {
				$wire['contributions'][0]['origin']['version'] = 99; }
			if ( 'target' === $bad ) {
				$wire['target']['id'] = 99; }
			if ( 'provider' === $bad ) {
				$s->remove_provider(); }
			$copy            = $c;
			$copy['history'] = HistoryCodec::decode( $wire );
			$this->assertInstanceOf( \WP_Error::class, $s->restore_plan( $copy, $c['after'], true ), $bad );
		}
		$s->restore_provider();
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] = false;
		$this->assertInstanceOf( \WP_Error::class, $s->restore_plan( $c, $c['after'], true ) );
	}
	public function test_redo_uses_actual_directional_values_and_retains_original_ceiling(): void {
		$s       = ProvenanceTest::scenario();
		$c       = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'a', 'remove', [ 'v1' ] ), $s->step( 'b', 'add', [ 'v2' ] ) ] );
		$current = [
			'a'    => [ 'later' ],
			'b'    => [ 'v2' ],
			'c'    => [ 'external' ],
			'mode' => 'plain',
		];
		$undo    = $s->restore_plan( $c, $current );
		$record  = HistoryCodec::journal( $c['history'], $current, $undo->target, 'undo', 'stored', true, true );
		$context = HistoryCodec::reversal( $record, false, 3, 71 );
		$this->assertInstanceOf( RecoveryState::class, $context );
		$this->assertSame( 'redo', $context->direction() );
		$this->assertSame( $undo->target, $context->expected() );
		$this->assertSame( $current, $context->target() );
		$this->assertSame( HistoryCodec::encode( $c['history'] ), HistoryCodec::encode( $context->original() ) );
		$redo_current = array_replace( $undo->target, [ 'a' => [ 'newer' ] ] );
		$plan         = $s->restore_plan( $c, $redo_current, false, 'redo' );
		$this->assertSame(
			[
				'a'    => [ 'newer' ],
				'b'    => [ 'v2' ],
				'c'    => [ 'external' ],
				'mode' => 'plain',
			],
			$plan->target
		);
		$write = new WriteState( str_repeat( 'a', 32 ), $c['history'], $redo_current, $plan->target, $context, 'redo', 3, 71 );
		$this->assertSame( $context, $write->recovery() );
		$this->assertSame( $c['history'], $write->history() );
	}
	public function test_unknown_or_unclosed_history_cannot_be_reversed_even_with_force(): void {
		$s = ProvenanceTest::scenario();
		$c = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		foreach ( [ [ 'stored', false, true ], [ 'partial', true, false ], [ 'unknown', true, false ], [ 'refused', true, true ] ] as [ $state, $closed, $verified ] ) {
			$record = HistoryCodec::journal( $c['history'], $c['before'], $c['after'], 'apply', $state, $closed, $verified );
			$this->assertInstanceOf( \WP_Error::class, HistoryCodec::reversal( $record, true, 3, 70 ) );
		}
		$record = HistoryCodec::journal( $c['history'], $c['before'], $c['after'], 'apply', 'partial', true, true );
		$this->assertInstanceOf( RecoveryState::class, HistoryCodec::reversal( $record, false, 3, 70 ) );
	}
	public function test_replay_keeps_recovery_direction_and_cannot_drop_original_context(): void {
		$s       = ProvenanceTest::scenario();
		$c       = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		$context = new RecoveryState( $c['history'], $c['after'], $c['before'], false, 'undo', 3, 71 );
		$write   = new WriteState( str_repeat( 'a', 32 ), $c['history'], $c['after'], $c['before'], $context, 'replay', 3, 71 );
		$this->assertSame( 'replay', $write->mode() );
		$this->assertSame( 'undo', $write->recovery()->direction() );
		try {
			new WriteState( str_repeat( 'a', 32 ), $c['history'], $c['after'], $c['before'], null, 'undo', 3, 71 );
			$this->fail( 'Undo must retain its recovery context.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'Recovery requires', $error->getMessage() );
		}
	}
	public function test_codec_refuses_overbound_envelope_and_mutated_validation_type(): void {
		$s    = ProvenanceTest::scenario();
		$c    = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		$wire = HistoryCodec::encode( $c['history'] );
		$wire['contributions'][0]['origin']['data']['too_large'] = str_repeat( 'a', 524288 );
		$this->assertInstanceOf( \WP_Error::class, HistoryCodec::decode( $wire ) );
		$family   = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed()->family( 'sample' );
		$scope    = \BulkCatalogueManager\External\V2\TypedScope::from_request( [ 'targets' => ProvenanceTest::targets() ], 3 );
		$binding  = \BulkCatalogueManager\External\V2\Binding::of( $family['provider'], $scope, [ $s->step( 'a', 'add', [ 'v1' ] ) ], new \BulkCatalogueManager\External\V2\RunContext( 'preview', 3, 0 ) );
		$manifest = \BulkCatalogueManager\External\V2\Manifest::build( $binding, 'product', 'product.bulk' );
		$wire     = HistoryCodec::encode( $c['history'] );
		$wire['contributions'][0]['step']['validation']['data']['policy'] = '1';
		$this->assertFalse( \BulkCatalogueManager\External\V2\Manifest::owns( $manifest, HistoryCodec::decode( $wire ) ) );
	}
	public function test_history_cannot_lose_semantic_direction_in_a_replay_record(): void {
		$s = ProvenanceTest::scenario();
		$c = $s->compute( ProvenanceTest::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		try {
			HistoryCodec::journal( $c['history'], $c['after'], $c['before'], 'replay', 'stored', true, true );
			$this->fail( 'A replay record must keep its semantic direction.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'Invalid directional', $error->getMessage() );
		}
	}
}
