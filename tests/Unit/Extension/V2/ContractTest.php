<?php
/**
 * Frozen v1 API and bounded opt-in typed values.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\Extension\V2\TargetRef;
use BulkCatalogueManager\Extension\V2\Snapshot;
use BulkCatalogueManager\Extension\V2\Finalisation;
use BulkCatalogueManager\Extension\V2\FinalisationGroup;
use BulkCatalogueManager\Extension\V2\Computation;
use BulkCatalogueManager\Operations\Result;
use PHPUnit\Framework\TestCase;

final class ContractTest extends TestCase {
	/** Capture only the literal top-level v1 surface, including inherited public methods. */
	public static function snapshot(): array {
		$api = [];
		foreach ( glob( dirname( __DIR__, 4 ) . '/src/Extension/*.php' ) as $file ) {
			$name    = 'BulkCatalogueManager\\Extension\\' . basename( $file, '.php' );
			$class   = new \ReflectionClass( $name );
			$methods = [];
			foreach ( $class->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				$params = [];
				foreach ( $method->getParameters() as $param ) {
					$params[] = [
						'name'        => $param->getName(),
						'type'        => (string) $param->getType(),
						'optional'    => $param->isOptional(),
						'variadic'    => $param->isVariadic(),
						'reference'   => $param->isPassedByReference(),
						'has_default' => $param->isDefaultValueAvailable(),
						'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
					];
				}
				$methods[ $method->getName() ] = [
					'parameters' => $params,
					'return'     => (string) $method->getReturnType(),
					'static'     => $method->isStatic(),
					'final'      => $method->isFinal(),
					'abstract'   => $method->isAbstract(),
				];
			}
			ksort( $methods );
			$api[ $name ] = [
				'final'     => $class->isFinal(),
				'interface' => $class->isInterface(),
				'constants' => $class->getConstants(),
				'methods'   => $methods,
				'sha256'    => hash_file( 'sha256', $file ),
			];
		}
		return $api;
	}

	public function test_literal_v1_surface_and_negotiation_remain_unchanged(): void {
		$expected = json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/extension-v1-api.json' ), true );
		$this->assertSame( $expected, self::snapshot() );
		$old = new \BulkCatalogueManager\Extension\Registrar( new \BulkCatalogueManager\External\Collector() );
		$this->assertTrue( $old->supports( 1 ) );
		$this->assertFalse( $old->supports( 2 ) );
		$this->assertSame( 1, \BulkCatalogueManager\Extension\Contract::VERSION );
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/bulk-catalogue-manager-for-woocommerce.php' );
		$this->assertStringContainsString( "define( 'BULKCAT_EXTENSION_CONTRACT', 1 );", $source );
	}

	public function test_equal_numbers_are_not_equal_targets(): void {
		$this->assertTrue( class_exists( TargetRef::class ), 'The opt-in typed target is missing.' );
		$product = new TargetRef( 'product', null, 41 );
		$term    = new TargetRef( 'term', 'product_cat', 41 );
		$this->assertSame( 'product::41', $product->key() );
		$this->assertSame( 'term:product_cat:41', $term->key() );
		$this->assertFalse( $product->key() === $term->key() );
		$this->assertSame(
			[
				'kind'     => 'term',
				'taxonomy' => 'product_cat',
				'id'       => 41,
			],
			$term->to_array()
		);
		$this->assertSame(
			'variation::41',
			TargetRef::from_array(
				[
					'kind'     => 'variation',
					'taxonomy' => null,
					'id'       => 41,
				]
			)->key()
		);
	}

	/** Assert constructor rejection without PHPUnit-only expectation APIs. */
	private function rejects( callable $call ): void {
		$caught = false;
		try {
			$call();
		} catch ( \InvalidArgumentException $error ) {
			$caught = true;
		}
		$this->assertTrue( $caught, 'Invalid input must raise InvalidArgumentException.' );
	}

	public function test_targets_reject_malformed_wire_values_without_coercion(): void {
		$this->assertTrue( class_exists( TargetRef::class ), 'The opt-in typed target is missing.' );
		$valid = [
			'kind'     => 'term',
			'taxonomy' => 'product_cat',
			'id'       => 41,
		];
		foreach ( [ [ 'kind' => 'other' ], [ 'id' => '41' ], [ 'id' => 0 ], [ 'id' => -1 ], [ 'taxonomy' => 'category' ], [ 'term_taxonomy_id' => 41 ], [ 'kind' => 'product' ] ] as $patch ) {
			$this->rejects( static fn() => TargetRef::from_array( array_replace( $valid, $patch ) ) );
		}
		$this->rejects(
			static fn() => TargetRef::from_array(
				[
					'kind' => 'product',
					'id'   => 41,
				]
			)
		);
		$this->rejects( static fn() => new TargetRef( 'term', null, 41 ) );
	}

	public function test_snapshots_are_plain_immutable_and_bounded(): void {
		$this->assertTrue( class_exists( Snapshot::class ), 'The opt-in snapshot is missing.' );
		$this->assertSame( 1, ( new Snapshot( 1, str_repeat( 'a', 16382 ) ) )->version );
		foreach ( [ new \stdClass(), INF, str_repeat( 'a', 16383 ), "\xff" ] as $value ) {
			$this->rejects( static fn() => new Snapshot( 1, $value ) );
		}
		$this->rejects( static fn() => new Snapshot( 0, [] ) );
		$data        = [ 'a' => [] ];
		$snapshot    = new Snapshot( 1, $data );
		$data['a'][] = 'changed';
		$this->assertSame( [ 'a' => [] ], $snapshot->data );
	}

	/** Valid complete group; five entries can represent just one target. */
	public static function group( array $entries, array $replace = [] ): FinalisationGroup {
		return new FinalisationGroup(
			...( $replace + [
				'id'               => str_repeat( 'a', 32 ),
				'chunk_id'         => str_repeat( 'b', 32 ),
				'provider'         => 'sample',
				'identity'         => 'org.example.sample',
				'contract'         => 2,
				'provider_version' => '1.0.0',
				'manifest_hash'    => str_repeat( 'c', 64 ),
				'family'           => 'product',
				'origin_mode'      => 'apply',
				'entries'          => $entries,
			] )
		);
	}

	public static function entry( int $target, int $field = 1, string $kind = 'product' ): Finalisation {
		$intent = md5( "$kind:$target:$field" );
		return new Finalisation( hash( 'sha256', $intent . ':1' ), $intent, 1, new TargetRef( $kind, 'term' === $kind ? 'product_cat' : null, $target ), 'x_sample_composite_' . $field, 1, 'stored' );
	}

	public function test_finalisation_enforces_complete_group_bounds_and_membership(): void {
		$this->assertTrue( class_exists( FinalisationGroup::class ), 'The complete finalisation group is missing.' );
		$entries = [];
		for ( $id = 1; $id <= 100; ++$id ) {
			for ( $field = 1; $field <= 5; ++$field ) {
				$entries[] = self::entry( $id, $field );
			}
		}
		$this->assertSame( 100, self::group( $entries )->target_count() );
		$this->assertSame( 1, self::group( array_slice( $entries, 0, 5 ) )->target_count() );
		$this->rejects( static fn() => self::group( [] ) );
		$this->rejects( static fn() => self::group( [ ...$entries, self::entry( 101 ) ] ) );
		$this->rejects( static fn() => self::group( array_map( static fn( $id ) => self::entry( $id ), range( 1, 101 ) ) ) );
		$this->rejects( static fn() => self::group( [ $entries[0], $entries[0] ] ) );
		$this->rejects( static fn() => self::group( [ self::entry( 1, 1, 'term' ) ] ) );
		$this->rejects( static fn() => new Finalisation( str_repeat( 'd', 64 ), str_repeat( 'e', 32 ), 1, new TargetRef( 'product', null, 1 ), 'x_sample_composite', 1, 'stored' ) );
	}
	public function test_readonly_dtos_reject_mutable_references_and_bound_lookup_results(): void {
		$choice = new \BulkCatalogueManager\Extension\V2\Choice( 'v1', 'One' );
		$items  = [ &$choice ];
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\ChoicePage( $items, null ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\ChoicePage( array_fill( 0, 51, $choice ), null ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\ChoicePage( [ $choice, $choice ], null ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\Choice( str_repeat( 'a', 129 ), 'One' ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\ResolvedChoice( 'v1', 'Private label', false, 'Unavailable.' ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\Lookup( 'values', 'values', [], '', null, 51 ) );
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\Lookup( 'values', 'values', [], str_repeat( 'é', 129 ), null, 50 ) );
		$this->assertSame( 50, ( new \BulkCatalogueManager\Extension\V2\Lookup( 'values', 'values', [], str_repeat( 'é', 128 ), null, 50 ) )->limit );
		$this->assertSame( str_repeat( 'é', 64 ), ( new \BulkCatalogueManager\Extension\V2\Choice( str_repeat( 'é', 64 ), 'One' ) )->value );
		$entry   = self::entry( 1 );
		$entries = [ &$entry ];
		$this->rejects( static fn() => self::group( $entries ) );
	}

	public function test_field_provenance_bounds_the_whole_history_not_only_contributions(): void {
		$step         = new \BulkCatalogueManager\Extension\V2\ValidatedStep( 0, 'x_sample_assign', 'x_sample_composite', [], new Snapshot( 1, [] ) );
		$contribution = new \BulkCatalogueManager\Extension\V2\Contribution( $step, 'already', new Snapshot( 1, [] ) );
		$history      = new \BulkCatalogueManager\Extension\V2\FieldHistory( 'sample', 'org.example.sample', 2, 'x_sample_composite', 1, new TargetRef( 'product', null, 1 ), [], [], [ $contribution ] );
		$this->assertSame( 'already', $history->contributions[0]->state );
		$references = [ &$contribution ];
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\FieldHistory( 'sample', 'org.example.sample', 2, 'x_sample_composite', 1, new TargetRef( 'product', null, 1 ), [], [], $references ) );
		$large = [];
		for ( $i = 0; $i < 20; ++$i ) {
			$step    = new \BulkCatalogueManager\Extension\V2\ValidatedStep( $i, 'x_sample_assign', 'x_sample_composite', [], new Snapshot( 1, str_repeat( 'v', 11000 ) ) );
			$large[] = new \BulkCatalogueManager\Extension\V2\Contribution( $step, 'changed', new Snapshot( 1, str_repeat( 'o', 11000 ) ) );
		}
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\FieldHistory( 'sample', 'org.example.sample', 2, 'x_sample_composite', 1, new TargetRef( 'product', null, 1 ), str_repeat( 'a', 65534 ), str_repeat( 'b', 65534 ), $large ) );
	}
	public function test_descriptor_locations_cannot_change_through_a_caller_reference(): void {
		$location = 'product.bulk';
		$this->rejects( static fn() => new \BulkCatalogueManager\Extension\V2\ActionDescriptor( 'x_sample_assign', 'x_sample_composite', 'organisation', 'Assign', 'Assigns values.', 'set', [], 'edit_products', [ &$location ], [ 'product' ] ) );
	}

	public function test_computation_rejects_caller_owned_result_note_references(): void {
		$note   = 'Accepted note';
		$result = Result::already( 'x_sample_composite', [] )->with_notes( [ &$note ] );
		$this->rejects( static fn() => new Computation( $result, new Snapshot( 1, [] ) ) );
		$note = 'Changed after validation';
		$this->assertSame( [ 'Changed after validation' ], $result->notes(), 'The old Result API remains unchanged.' );
	}

	public function test_computation_checks_every_retained_result_collection(): void {
		$base = Result::already( 'x_sample_composite', [] );
		foreach ( [
			$base->with_notes( [ "\xff" ] ),
			$base->with_note( 'sample_note', [ 'current' => INF ] ),
			$base->with_warning( 'x_sample_warning', "\xff" ),
		] as $result ) {
			$this->rejects( static fn() => new Computation( $result, new Snapshot( 1, [] ) ) );
		}
		$value = 'Original';
		foreach ( [
			Result::changed( 'x_sample_composite', [ 'nested' => [ &$value ] ], [] ),
			Result::changed( 'x_sample_composite', [], [ 'nested' => [ &$value ] ] ),
		] as $result ) {
			$this->rejects( static fn() => new Computation( $result, new Snapshot( 1, [] ) ) );
		}
	}

	public function test_computation_preserves_detached_result_metadata(): void {
		$current             = 'Original';
		$sentence            = 'Review this change.';
		$result              = Result::already( 'x_sample_composite', [] )
			->with_note( 'sample_note', [ 'current' => &$current ] )
			->with_notes( [ 'Accepted note' ] )
			->with_warning( 'x_sample_warning', $sentence, true );
		$computed            = new Computation( $result, new Snapshot( 1, [] ) );
		$current             = 'Changed';
		$sentence            = 'Changed';
		$notes               = $computed->result->notes();
		$warning             = $computed->result->warning();
		$context             = $computed->result->context();
		$notes[0]            = 'Changed';
		$warning['sentence'] = 'Changed';
		$context['current']  = 'Changed';
		$this->assertSame( $result, $computed->result );
		$this->assertSame( [ 'current' => 'Original' ], $computed->result->context() );
		$this->assertSame( [ 'Accepted note' ], $computed->result->notes() );
		$this->assertSame(
			[
				'code'     => 'x_sample_warning',
				'sentence' => 'Review this change.',
				'confirm'  => true,
			],
			$computed->result->warning()
		);
	}
}
