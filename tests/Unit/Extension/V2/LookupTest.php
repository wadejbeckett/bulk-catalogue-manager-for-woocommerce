<?php
/** Scoped choice boundary proofs. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use PHPUnit\Framework\TestCase;
use BulkCatalogueManager\Tests\Support\Extension\V2\Scenario;
use BulkCatalogueManager\External\V2\{LookupService, Cursor, Discovery, Registry, TypedScope, Arguments};
use BulkCatalogueManager\Extension\V2\ActionDescriptor;
require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';
final class LookupTest extends TestCase {
	/** Add a legal required scalar alongside the unchanged sample action. */
	private function requiredScalarRegistry( bool $dependency = false ): Registry {
		$families      = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed()->families();
		$d             = $families['sample']['typed']['described']['x_sample_assign']['descriptor'];
		$specs         = $d->arguments;
		$specs['note'] = [
			'type'     => 'text',
			'label'    => 'Note',
			'rule'     => 'Enter a note.',
			'required' => true,
		];
		if ( $dependency ) {
			$specs['values']['depends_on'][] = 'note';
		}
		$changed = new ActionDescriptor( 'x_sample_assign_note', $d->field, $d->group, $d->label, $d->description, $d->edit_kind, $specs, $d->capability, $d->locations, $d->target_kinds );
		$families['sample']['typed']['described'][ $changed->id ] = [ 'descriptor' => $changed ];
		$families['sample']['typed']['actions'][ $changed->id ]   = $families['sample']['typed']['actions'][ $d->id ];
		return new Registry( $families );
	}

	public function test_partial_discovery_offers_required_scalar_action_and_other_actions(): void {
		$this->setupScenario();
		$registry  = $this->requiredScalarRegistry();
		$discovery = new Discovery( $registry );
		$scope     = TypedScope::from_request( self::request()['selection'], 3 );
		$result    = $discovery->for_scope( 'product.row', $scope, [], 3 );
		$this->assertIsArray( $result );
		$this->assertSame( [ 'x_sample_assign', 'x_sample_assign_note' ], array_column( $result['actions'], 'id' ) );
		$this->assertTrue( $result['actions'][1]['available'] );
		$this->assertTrue( $result['actions'][1]['arguments']['note']['required'] );
		$descriptor = $registry->family( 'sample' )['typed']['described']['x_sample_assign_note']['descriptor'];
		$args       = [
			'field'     => $descriptor->field,
			'part'      => 'a',
			'operation' => 'add',
			'values'    => [ 'v1' ],
		];
		$this->assertInstanceOf( \WP_Error::class, Arguments::normalize( $descriptor, $args ) );
		$this->assertIsArray( Arguments::normalize( $descriptor, $args + [ 'note' => 'Review' ] ) );
	}

	public function test_lookup_ignores_unrelated_required_scalar_but_rejects_extra_dependencies(): void {
		$this->setupScenario();
		$service     = new LookupService( $this->requiredScalarRegistry() );
		$r           = self::request();
		$r['action'] = 'x_sample_assign_note';
		$page        = $service->search( $r, 3 );
		$this->assertIsArray( $page );
		$this->assertCount( 50, $page['items'] );
		$resolved = $service->resolve( array_diff_key( $r, array_flip( [ 'query','cursor','limit' ] ) ) + [ 'values' => [ 'v1' ] ], 3 );
		$this->assertIsArray( $resolved );
		$this->assertSame( 'Value 1', $resolved['items'][0]['label'] );
		$r['dependencies']['note'] = 'Review';
		$this->error( $service->search( $r, 3 ), 'bulkcat_invalid_lookup', 400 );
	}

	public function test_partial_discovery_still_rejects_supplied_invalid_scalars_and_inactive_values(): void {
		$this->setupScenario();
		$discovery = new Discovery( $this->requiredScalarRegistry() );
		$scope     = TypedScope::from_request( self::request()['selection'], 3 );
		foreach ( [
			[ 'note' => 42 ],
			[ 'note' => '' ],
			[
				'operation' => 'clear',
				'values'    => [ 'v1' ],
			],
		] as $args ) {
			$this->error( $discovery->for_scope( 'product.row', $scope, [ 'x_sample_assign_note' => $args ], 3 ), 'bulkcat_invalid_lookup', 400 );
		}
	}

	public function test_lookup_requires_its_declared_required_scalar_dependency(): void {
		$this->setupScenario();
		$service     = new LookupService( $this->requiredScalarRegistry( true ) );
		$r           = self::request();
		$r['action'] = 'x_sample_assign_note';
		$this->error( $service->search( $r, 3 ), 'bulkcat_invalid_lookup', 400 );
		$r['dependencies']['note'] = 'Review';
		$page                      = $service->search( $r, 3 );
		$this->assertIsArray( $page );
		$this->assertCount( 50, $page['items'] );
		$r['dependencies']['note'] = 42;
		$this->error( $service->search( $r, 3 ), 'bulkcat_invalid_lookup', 400 );
	}
	private function setupScenario(): Scenario {
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
		$s->product(
			42,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$s->choices( 601 );
		return $s; }
	public static function request(): array {
		return [
			'provider'     => 'sample',
			'action'       => 'x_sample_assign',
			'argument'     => 'values',
			'selection'    => [
				'targets' => [
					[
						'kind'     => 'product',
						'taxonomy' => null,
						'id'       => 41,
					],
				],
			],
			'dependencies' => [
				'part'      => 'a',
				'operation' => 'add',
			],
			'query'        => '',
			'cursor'       => null,
			'limit'        => 50,
		]; }
	private function error( mixed $value, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
		$this->assertSame( $status, $value->get_error_data()['status'] ); }
	public function test_pages_are_bounded_and_selected_values_resolve_separately(): void {
		$s       = $this->setupScenario();
		$service = new LookupService();
		$r       = self::request();
		$first   = $service->search( $r, 3 );
		$this->assertTrue( is_array( $first ) );
		$this->assertCount( 50, $first['items'] );
		$this->assertNotNull( $first['next_cursor'] );
		$second = $service->search( array_replace( $r, [ 'cursor' => $first['next_cursor'] ] ), 3 );
		$this->assertCount( 50, $second['items'] );
		$this->assertNotSame( $first['items'], $second['items'] );
		$resolved = $service->resolve( array_diff_key( $r, array_flip( [ 'query','cursor','limit' ] ) ) + [ 'values' => [ 'v1' ] ], 3 );
		$this->assertSame( 'Value 1', $resolved['items'][0]['label'] );
		$this->assertCount( 3, $s->store->lookup_calls );
		$this->assertSame( [], $s->store->reads );
		$this->assertLessThan( 8192, strlen( wp_json_encode( $first ) ) );
		fwrite(
			STDOUT,
			'\n601-choice evidence: ' . wp_json_encode(
				[
					'first_bytes'      => strlen( wp_json_encode( $first ) ),
					'second_bytes'     => strlen( wp_json_encode( $second ) ),
					'provider_queries' => count( $s->store->lookup_calls ),
					'field_reads'      => count( $s->store->reads ),
				]
			) . '\n'
		);
	}
	public function test_invalid_bounds_and_shapes_are_rejected_before_queries(): void {
		$s       = $this->setupScenario();
		$service = new LookupService();
		foreach ( [
			[ 'limit' => 0 ],
			[ 'limit' => 51 ],
			[ 'limit' => '50' ],
			[ 'query' => str_repeat( 'é', 129 ) ],
			[ 'source' => 'forged' ],
			[
				'dependencies' => [
					'part'      => 'bad',
					'operation' => 'add',
				],
			],
			[ 'selection' => [ 'targets' => [] ] ],
			[ 'extra' => str_repeat( 'x', 65536 ) ],
		] as $patch ) {
			$this->error( $service->search( array_replace( self::request(), $patch ), 3 ), 'bulkcat_invalid_lookup', 400 ); }
		$r = array_diff_key( self::request(), array_flip( [ 'query','cursor','limit' ] ) );
		foreach ( [ [], array_map( fn( $i )=>'v' . $i, range( 1, 101 ) ), [ 'v1', 'v1' ], [ str_repeat( 'é', 65 ) ], [ "v\n1" ] ] as $values ) {
			$this->error( $service->resolve( $r + [ 'values' => $values ], 3 ), 'bulkcat_invalid_lookup', 400 ); }
		$this->assertSame( [], $s->store->lookup_calls );
	}
	public function test_cursor_binds_actor_scope_dependency_query_identity_and_expiry(): void {
		$this->setupScenario();
		$now         = 1000;
		$service     = new LookupService(
			null,
			new Cursor(
				'test-secret',
				static function () use ( &$now ) {
					return $now;
				}
			)
		);
		$r           = self::request();
		$first       = $service->search( $r, 3 );
		$r['cursor'] = $first['next_cursor'];
		foreach ( [
			[ 'cursor' => $r['cursor'] . 'x' ],
			[ 'query' => 'Value' ],
			[
				'dependencies' => [
					'part'      => 'b',
					'operation' => 'add',
				],
			],
			[
				'selection' => [
					'targets' => [
						[
							'kind'     => 'product',
							'taxonomy' => null,
							'id'       => 42,
						],
					],
				],
			],
		] as $patch ) {
			$this->error( $service->search( array_replace( $r, $patch ), 3 ), 'bulkcat_choice_refresh_required', 409 ); }
		$GLOBALS['bulkcat_test']['user_caps'][4] = $GLOBALS['bulkcat_test']['user_caps'][3];
		$this->error( $service->search( $r, 4 ), 'bulkcat_choice_refresh_required', 409 );
		$now = 1300;
		$this->error( $service->search( $r, 3 ), 'bulkcat_choice_refresh_required', 409 );
	}
	public function test_access_and_provider_availability_are_rechecked_before_labels(): void {
		$s                      = $this->setupScenario();
		$service                = new LookupService();
		$r                      = self::request();
		$r['cursor']            = $service->search( $r, 3 )['next_cursor'];
		$s->store->lookup_calls = [];
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post'] = false;
		$this->error( $service->search( $r, 3 ), 'bulkcat_forbidden', 403 );
		$this->assertSame( [], $s->store->lookup_calls );
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post'] = true;
		$s->store->faults['denied_targets']                   = [ 'product::41' ];
		$this->error( $service->search( $r, 3 ), 'bulkcat_forbidden', 403 );
		$this->assertSame( [], $s->store->lookup_calls );
		$s->store->faults                = [];
		$s->store->faults['unavailable'] = true;
		$this->error( $service->search( $r, 3 ), 'bulkcat_provider_unavailable', 409 );
		$this->assertSame( [], $s->store->lookup_calls );
		$s->remove_provider();
		$this->error( $service->search( $r, 3 ), 'bulkcat_provider_unavailable', 409 );
	}
	public function test_state_changes_refresh_labels_and_invalidate_preview(): void {
		$s       = $this->setupScenario();
		$service = new LookupService();
		$r       = self::request();
		$page    = $service->search( $r, 3 );
		$preview = $s->preview( $r['selection']['targets'], [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		$this->assertTrue( is_array( $preview ) );
		$s->store->choices['v1'] = 'Renamed';
		$this->error( $service->search( array_replace( $r, [ 'cursor' => $page['next_cursor'] ] ), 3 ), 'bulkcat_choice_refresh_required', 409 );
		$resolve = array_diff_key( $r, array_flip( [ 'query','cursor','limit' ] ) ) + [ 'values' => [ 'v1' ] ];
		$this->assertSame( 'Renamed', $service->resolve( $resolve, 3 )['items'][0]['label'] );
		$renewed = $s->preview( $r['selection']['targets'], [ $s->step( 'a', 'add', [ 'v1' ] ) ] );
		$this->assertNotSame( $preview['hash'], $renewed['hash'] );
		foreach ( [ 'deleted', 'revoked' ] as $mode ) {
			if ( 'deleted' === $mode ) {
					unset( $s->store->choices['v1'] );
			} else {
				$s->store->choices['v1']                = 'Secret';
					$s->store->faults['denied_choices'] = [ 'v1' ];
			} $item = $service->resolve( $resolve, 3 )['items'][0];
			$this->assertSame( 'v1', $item['value'] );
			$this->assertNull( $item['label'] );
			$this->assertFalse( $item['available'] );
			$this->assertInstanceOf( \WP_Error::class, $s->preview( $r['selection']['targets'], [ $s->step( 'a', 'add', [ 'v1' ] ) ] ) ); }
		$this->error( $s->preview( $r['selection']['targets'], [ $s->step( 'a', 'clear', [ 'v1' ] ) ] ), 'bulkcat_invalid_value', 400 );
	}
	public function test_malformed_provider_responses_never_leak_errors_or_partial_results(): void {
		$s       = $this->setupScenario();
		$service = new LookupService();
		foreach ( [ 'throw', 'oversize', 'wrong_resolve', 'extra_resolve', 'error' ] as $fault ) {
			$s->store->faults['lookup'] = $fault;
			$r                          = self::request();
			$result                     = str_contains( $fault, 'resolve' ) ? $service->resolve( array_diff_key( $r, array_flip( [ 'query','cursor','limit' ] ) ) + [ 'values' => [ 'v1' ] ], 3 ) : $service->search( $r, 3 );
			$this->error( $result, 'bulkcat_provider_response_invalid', 502 );
			$this->assertStringNotContainsString( '/private/', $result->get_error_message() ); }
	}

	public function test_cursor_origin_and_provider_response_limit_are_enforced(): void {
		$s           = $this->setupScenario();
		$service     = new LookupService();
		$r           = self::request();
		$r['cursor'] = $service->search( $r, 3 )['next_cursor'];
		$s->restore_provider( 'org.example.replacement' );
		$this->error( $service->search( $r, 3 ), 'bulkcat_choice_refresh_required', 409 );
		$s->store->faults['lookup'] = 'ignore_limit';
		$this->error( $service->search( array_replace( self::request(), [ 'limit' => 1 ] ), 3 ), 'bulkcat_provider_response_invalid', 502 );
	}

	public function test_predicate_controller_is_bound_even_without_a_redundant_depends_on_entry(): void {
		$this->setupScenario();
		$families                      = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed()->families();
		$d                             = $families['sample']['typed']['described']['x_sample_assign']['descriptor'];
		$specs                         = $d->arguments;
		$specs['values']['depends_on'] = [ 'part' ];
		$d                             = new \BulkCatalogueManager\Extension\V2\ActionDescriptor( $d->id, $d->field, $d->group, $d->label, $d->description, $d->edit_kind, $specs, $d->capability, $d->locations, $d->target_kinds );
		$families['sample']['typed']['described']['x_sample_assign']['descriptor'] = $d;
		$service = new LookupService( new \BulkCatalogueManager\External\V2\Registry( $families ) );
		$r       = self::request();
		$page    = $service->search( $r, 3 );
		$this->assertTrue( is_array( $page ) );
		$r['cursor']                    = $page['next_cursor'];
		$r['dependencies']['operation'] = 'remove';
		$this->error( $service->search( $r, 3 ), 'bulkcat_choice_refresh_required', 409 );
	}
}
