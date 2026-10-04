<?php
/** Immutable aggregate history and domain ownership. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use PHPUnit\Framework\TestCase;
use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use BulkCatalogueManager\External\V2\HistoryCodec;
use BulkCatalogueManager\Extension\V2 as API;
require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';
final class ProvenanceTest extends TestCase {
	public static function targets(): array {
		return [
			[
				'kind'     => 'product',
				'taxonomy' => null,
				'id'       => 41,
			],
		];
	}
	public static function scenario( array $before = [] ): Scenario {
		$s = new Scenario();
		$s->product(
			41,
			$before + [
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		return $s;
	}
	public function test_cancelled_domain_is_never_owned_by_forced_undo(): void {
		$s = self::scenario();
		$c = $s->compute( self::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'a', 'remove', [ 'v1' ] ), $s->step( 'b', 'add', [ 'v2' ] ) ] );
		$this->assertCount( 3, $c['history']->contributions );
		$current = [
			'a'    => [ 'later' ],
			'b'    => [ 'v2' ],
			'c'    => [ 'unrelated' ],
			'mode' => 'plain',
		];
		foreach ( [ false, true ] as $force ) {
			$plan = $s->restore_plan( $c, $current, $force );
			$this->assertInstanceOf( API\RestoreDecision::class, $plan );
			$this->assertSame(
				[
					'a'    => [ 'later' ],
					'b'    => [],
					'c'    => [ 'unrelated' ],
					'mode' => 'plain',
				],
				$plan->target
			);
			$this->assertSame( [], $plan->conflicts );
		}
		$this->assertSame( [], $s->value( 'product::41' )['b'] );
	}
	public function test_two_effective_domains_preserve_later_third_domain_edit(): void {
		$s       = self::scenario();
		$c       = $s->compute( self::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'b', 'add', [ 'v2' ] ) ] );
		$current = [
			'a'    => [ 'v1' ],
			'b'    => [ 'v2' ],
			'c'    => [ 'later' ],
			'mode' => 'plain',
		];
		$this->assertSame(
			[
				'a'    => [],
				'b'    => [],
				'c'    => [ 'later' ],
				'mode' => 'plain',
			],
			$s->restore_plan( $c, $current )->target
		);
	}
	public function test_mode_conversion_retains_first_origin_and_second_edit(): void {
		$s                     = self::scenario(
			[
				'a' => [ 'v1' ],
				'b' => [ 'v3' ],
			]
		);
		$first                 = $s->step( 'a', 'add', [ 'v2' ] );
		$first['args']['mode'] = 'open';
		$c                     = $s->compute( self::targets(), [ $first, $s->step( 'b', 'add', [ 'v4' ] ) ] );
		$this->assertSame(
			[
				'a'    => [ 'v2' ],
				'b'    => [ 'v4' ],
				'c'    => [],
				'mode' => 'open',
			],
			$c['after']
		);
		$current = array_replace( $c['after'], [ 'c' => [ 'later' ] ] );
		$this->assertSame(
			[
				'a'    => [ 'v1' ],
				'b'    => [ 'v3' ],
				'c'    => [ 'later' ],
				'mode' => 'plain',
			],
			$s->restore_plan( $c, $current )->target
		);
	}
	public function test_all_cancelled_field_preserves_provenance_without_restoration_rights(): void {
		$s = self::scenario();
		$c = $s->compute( self::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'a', 'remove', [ 'v1' ] ) ] );
		$this->assertSame( $c['before'], $c['after'] );
		$current = [
			'a'    => [ 'later' ],
			'b'    => [],
			'c'    => [],
			'mode' => 'plain',
		];
		$this->assertSame( $current, $s->restore_plan( $c, $current, true )->target );
		$this->assertCount( 2, $c['history']->contributions );
	}
	public function test_failed_and_skipped_contributions_grant_no_domains(): void {
		$s                                  = self::scenario();
		$s->store->faults['compute_states'] = [
			0 => 'failed',
			1 => 'skipped',
		];
		$c                                  = $s->compute( self::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'a', 'add', [ 'v2' ] ), $s->step( 'b', 'add', [ 'v3' ] ) ] );
		$this->assertSame( [ 'failed', 'skipped', 'changed' ], array_map( static fn( $v ) => $v->state, $c['history']->contributions ) );
		$current = [
			'a'    => [ 'later' ],
			'b'    => [ 'v3' ],
			'c'    => [],
			'mode' => 'plain',
		];
		$this->assertSame(
			[
				'a'    => [ 'later' ],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			],
			$s->restore_plan( $c, $current, true )->target
		);
	}
	public function test_json_roundtrip_retains_order_validation_and_origin_versions(): void {
		$s       = self::scenario();
		$c       = $s->compute( self::targets(), [ $s->step( 'a', 'add', [ 'v1' ] ), $s->step( 'b', 'add', [ 'v2' ] ) ] );
		$wire    = HistoryCodec::encode( $c['history'] );
		$history = HistoryCodec::decode( json_decode( json_encode( $wire ), true ) );
		$this->assertInstanceOf( API\FieldHistory::class, $history );
		$this->assertSame( [ 0, 1 ], array_map( static fn( $v ) => $v->step->index, $history->contributions ) );
		$this->assertSame( [ 'v1' => 'Value 1' ], $history->contributions[0]->step->validation->data['labels'] );
		$this->assertSame( 1, $history->contributions[1]->origin->version );
		$this->assertSame( 'b', $history->contributions[1]->origin->data['part'] );
		$this->assertSame( $wire, HistoryCodec::encode( $history ) );
		$wire['contributions'][0]['origin']['version'] = '1';
		$this->assertInstanceOf( \WP_Error::class, HistoryCodec::decode( $wire ) );
	}
}
