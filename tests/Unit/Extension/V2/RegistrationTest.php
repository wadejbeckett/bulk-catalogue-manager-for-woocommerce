<?php
/**
 * Independent provider facets, stable IDs and opt-in discovery.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Extension\V2;

use BulkCatalogueManager\Extension\V2\Contract;
use BulkCatalogueManager\Extension\V2\Registrar;
use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\V2\Collector;
use BulkCatalogueManager\External\V2\Inspector;
use BulkCatalogueManager\External\V2\Registry;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\Advertiser;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Tests\Support\Extension\V2\SampleTypedProvider;
use BulkCatalogueManager\Tests\Support\Extension\V2\MemoryStore;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$class = new \ReflectionClass( Actions::class );
		$class->setStaticPropertyValue( 'instance', null );
		$class->setStaticPropertyValue( 'fired', false );
	}

	private function available(): void {
		$this->assertTrue( class_exists( Registrar::class ), 'The opt-in typed registrar is missing.' );
		$this->assertTrue( is_file( dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php' ), 'The typed fixture is missing.' );
		require_once dirname( __DIR__, 3 ) . '/Support/Extension/V2/load.php';
	}

	private static function gates(): array {
		return [
			'typed-product-subjects' => 1,
			'choice-search'          => 1,
			'dependent-arguments'    => 1,
		];
	}

	public function test_capabilities_are_closed_by_default_and_never_downgrade(): void {
		$this->available();
		$registrar = new Registrar( new Collector() );
		$this->assertSame( 2, $registrar->contract() );
		$this->assertTrue( $registrar->supports( 2 ) );
		$this->assertFalse( $registrar->supports( 1 ) );
		$this->assertFalse( $registrar->supports( 3 ) );
		foreach ( [ 'typed-product-subjects', 'aggregate-recovery', 'durable-finalisation', 'choice-search', 'dependent-arguments', 'row-actions', 'category-subjects', 'unknown' ] as $name ) {
			$this->assertFalse( $registrar->supports_capability( $name ) );
		}
		$test = new Registrar( new Collector( null, self::gates() ) );
		$this->assertTrue( $test->supports_capability( 'typed-product-subjects' ) );
		$this->assertFalse( $test->supports_capability( 'typed-product-subjects', 2 ) );
		$this->assertFalse( $test->supports_capability( 'category-subjects' ) );
	}

	public function test_one_hook_constructs_once_and_hides_legacy_without_losing_resolution(): void {
		$this->available();
		$made  = 0;
		$saved = null;
		add_action(
			Contract::HOOK,
			static function ( Registrar $registrar ) use ( &$made, &$saved ): void {
				++$made;
				$saved = $registrar;
				$registrar->register( new SampleTypedProvider( new MemoryStore() ) );
			}
		);
		$GLOBALS['bulkcat_test']['did_actions']['init'] = 1;
		$registry                                       = Actions::instance();
		$this->assertSame( $registry, Actions::instance() );
		$this->assertSame( 1, $made );
		$this->assertNotNull( $registry->get( 'x_sample_add' ) );
		$this->assertNotNull( $registry->providers()->get( 'sample' )->field( 'x_sample_labels' ) );
		$this->assertSame( [], ( new Advertiser( $registry->providers() ) )->actions( 3 ) );
		$this->assertSame( [], ( new Advertiser( $registry->providers() ) )->fields() );
		$this->assertTrue( $saved->register( new SampleTypedProvider( new MemoryStore() ) ) instanceof \WP_Error );
		$this->assertSame( ( new Actions( new Fields() ) )->to_client(), $registry->to_client() );
	}

	public function test_duplicate_identity_is_refused_across_both_hooks(): void {
		$this->available();
		$collector = new Collector( null, self::gates() );
		$provider  = new SampleTypedProvider( new MemoryStore() );
		$this->assertTrue( $collector->register( $provider ) );
		$this->assertTrue( $collector->register( $provider ) instanceof \WP_Error );
		$this->assertTrue( $collector->register_legacy( $provider->legacy() ) instanceof \WP_Error );
		$other = new Collector( null, self::gates() );
		$this->assertTrue( $other->register_legacy( $provider->legacy() ) );
		$this->assertTrue( $other->register( $provider ) instanceof \WP_Error );
		$this->assertSame( 'org.example.sample', $collector->registry()->family( 'sample' )['identity'] );
	}

	public function test_501_legacy_choices_disable_only_legacy_facet(): void {
		$this->available();
		$choices = [];
		for ( $i = 0; $i < 501; ++$i ) {
			$choices[ 'v' . $i ] = 'Value ' . $i;
		}
		update_option( \BulkCatalogueManager\Tests\Support\Extension\SampleProvider::CHOICES, $choices );
		$collector = new Collector( null, self::gates() );
		$this->assertTrue( $collector->register( new SampleTypedProvider( new MemoryStore() ) ) );
		$registry = $collector->registry();
		$this->assertNotNull( $registry->action( 'x_sample_assign' ) );
		$this->assertSame( null, $registry->family( 'sample' )['legacy'] );
		$this->assertNotEmpty( $registry->family( 'sample' )['legacy_reason'] );
		$legacy = Providers::of( [ new \BulkCatalogueManager\Tests\Support\Extension\SampleProvider() ] );
		$this->assertFalse( $legacy->any(), 'Literal v1 still refuses the entire oversize provider.' );
	}

	public function test_missing_category_capability_and_bad_dependency_do_not_poison_product(): void {
		$this->available();
		foreach ( [ [], [ 'category_cycle' => true ] ] as $config ) {
			$collector = new Collector( null, self::gates() );
			$this->assertTrue( $collector->register( new SampleTypedProvider( new MemoryStore(), $config ) ) );
			$registry = $collector->registry();
			$this->assertNotNull( $registry->action( 'x_sample_assign' ) );
			$this->assertNull( $registry->action( 'x_sample_assign_category' ) );
			$this->assertFalse( $registry->described( 'x_sample_assign_category' )['available'] );
			$this->assertNotEmpty( $registry->described( 'x_sample_assign_category' )['reason'] );
		}
		$collector = new Collector( null, self::gates() + [ 'category-subjects' => 1 ] );
		$this->assertTrue( $collector->register( new SampleTypedProvider( new MemoryStore(), [ 'category_cycle' => true ] ) ) );
		$this->assertNull( $collector->registry()->action( 'x_sample_assign_category' ) );
		$this->assertNotNull( $collector->registry()->action( 'x_sample_assign' ) );
	}
	public function test_fixture_compute_changes_only_the_requested_domain_using_subject_values(): void {
		$this->available();
		$action  = new \BulkCatalogueManager\Tests\Support\Extension\V2\AssignAction( new MemoryStore() );
		$value   = [
			'a'    => [ 'v1' ],
			'b'    => [ 'v2' ],
			'c'    => [ 'v3' ],
			'mode' => 'keep',
		];
		$subject = new class( $value ) implements \BulkCatalogueManager\Extension\V2\Subject {
			public function __construct( private array $current ) {}
			public function reference(): \BulkCatalogueManager\Extension\V2\TargetRef {
				throw new \LogicException( 'Compute must use value only.' );
			}
			public function parent(): ?\BulkCatalogueManager\Extension\V2\TargetRef {
				throw new \LogicException( 'Compute must use value only.' );
			}
			public function value( string $field ): mixed {
				return $this->current;
			}
		};
		$context = self::context( 'preview' );
		foreach ( [ [ 'add', [ 'v4' ], [ 'v1', 'v4' ] ], [ 'remove', [ 'v1' ], [] ], [ 'replace', [ 'v4' ], [ 'v4' ] ], [ 'clear', [], [] ], [ 'add', [ 'v1' ], [ 'v1' ] ] ] as [ $operation, $values, $expected ] ) {
			$step     = new \BulkCatalogueManager\Extension\V2\ValidatedStep(
				0,
				'x_sample_assign',
				'x_sample_composite',
				[
					'part'      => 'a',
					'operation' => $operation,
					'values'    => $values,
				],
				new \BulkCatalogueManager\Extension\V2\Snapshot( 1, [] )
			);
			$computed = $action->compute( $subject, $step, $context );
			$this->assertSame( $expected, $computed->result->new()['a'] );
			$this->assertSame( [ 'v2' ], $computed->result->new()['b'] );
			$this->assertSame( [ 'v3' ], $computed->result->new()['c'] );
			$this->assertSame( 'keep', $computed->result->new()['mode'] );
			$this->assertSame( 1, $computed->origin->version );
			$this->assertSame( [ 'v1' ] === $expected ? 'already' : 'changed', $computed->result->state() );
		}
	}

	private static function context( string $mode ): \BulkCatalogueManager\Extension\V2\Context {
		return new class( $mode ) implements \BulkCatalogueManager\Extension\V2\Context {
			public function __construct( private string $current ) {}
			public function mode(): string {
				return $this->current;
			}
			public function user_id(): int {
				return 3;
			}
			public function batch_id(): int {
				return 1;
			}
		};
	}

	public function test_fixture_finalise_clears_once_after_distinct_target_hygiene_and_never_fakes_ack(): void {
		$this->available();
		$store    = new MemoryStore();
		$provider = new SampleTypedProvider( $store );
		$group    = ContractTest::group( [ ContractTest::entry( 1 ), ContractTest::entry( 1, 2 ), ContractTest::entry( 2 ) ] );
		$this->assertTrue( $provider->finalise( $group, self::context( 'finalise' ) ) );
		$this->assertSame( [ 'product::1', 'product::2' ], $store->hygiene );
		$this->assertSame( 1, $store->clears );
		$store->faults['finalise_fail'] = true;
		$this->assertTrue( $provider->finalise( $group, self::context( 'finalise' ) ) instanceof \WP_Error );
		$this->assertSame( 1, $store->clears );
		$this->assertFalse( $store->calls[1]['clear_succeeded'] );
		$scenario = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$caught   = false;
		try {
			$scenario->run( 1 );
		} catch ( \LogicException $error ) {
			$caught = true;
		}
		$this->assertTrue( $caught, 'Future engine tasks cannot return canned success.' );
	}
	public function test_pure_v1_and_partial_discovery_preserve_single_request_availability(): void {
		$this->available();
		$legacy = new \BulkCatalogueManager\Tests\Support\Extension\V2\SampleLegacyProvider();
		add_action(
			'bulkcat_register_providers',
			static function ( $registrar ) use ( $legacy ): void {
				$registrar->register( $legacy );
			}
		);
		$providers  = Providers::fire();
		$advertiser = new Advertiser( $providers );
		$advertiser->actions( 3 );
		$advertiser->actions( 3 );
		$this->assertSame( 1, $legacy->availability_calls );
		$this->assertSame( $providers->get( 'sample' ), $providers->all()[0] );
		bulkcat_test_reset();
		add_action(
			Contract::HOOK,
			static function ( Registrar $registrar ): void {
				$registrar->register( new SampleTypedProvider( new MemoryStore(), [ 'legacy_discovery' => [ 'x_sample_add' ] ] ) );
			}
		);
		$providers  = Providers::fire();
		$advertiser = new Advertiser( $providers );
		$this->assertSame( [ 'x_sample_add' ], array_column( $advertiser->actions( 3 ), 'id' ) );
		$this->assertSame( [ 'x_sample_labels' ], array_column( $advertiser->fields(), 'key' ) );
		$advertiser->actions( 3 );
		$this->assertSame( 1, $providers->get( 'sample' )->provider->availability_calls );
		$this->assertNotNull( ( new Actions( new Fields(), null, $providers ) )->get( 'x_sample_remove' ) );
	}
	public function test_scenario_seeding_uses_existing_native_test_globals_and_real_service(): void {
		$this->available();
		$scenario = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$value    = [
			'a'    => [ 'v1' ],
			'b'    => [],
			'c'    => [],
			'mode' => 'keep',
		];
		$scenario->product( 41, $value );
		$scenario->term( 41, 0, $value );
		$this->assertTrue( wc_get_product( 41 ) instanceof \BulkCatalogueManager\Tests\Support\SavedProduct );
		$this->assertSame( 'product_cat', get_term( 41, 'product_cat' )->taxonomy );
		$this->assertSame( $value, $scenario->value( 'product::41' ) );
		$this->assertSame( $value, $scenario->value( 'term:product_cat:41' ) );
		$this->assertTrue( $scenario->service instanceof \BulkCatalogueManager\Requests\RequestService );
	}

	public function test_malformed_category_locations_keep_indexed_disabled_identity(): void {
		$this->assert_disabled_declaration( self::category_declaration( [ 'locations' => [ 'product.bulk' ] ] ) );
	}

	public function test_malformed_later_predicate_controller_keeps_identity_without_warning(): void {
		$arguments = self::dependent_arguments();
		unset( $arguments['operation']['choices'] );
		$this->assert_disabled_declaration( self::category_declaration( [ 'arguments' => $arguments ] ) );
	}

	public function test_malformed_descriptor_collections_do_not_emit_array_conversion_warnings(): void {
		$arguments                         = self::dependent_arguments();
		$arguments['values']['depends_on'] = [ [ 'operation' ] ];
		foreach ( [
			[ 'locations' => [ [ 'category.bulk' ] ] ],
			[ 'target_kinds' => [ [ 'term' ] ] ],
			[ 'arguments' => $arguments ],
		] as $replace ) {
			$this->assert_disabled_declaration( self::category_declaration( $replace ) );
		}
	}

	public function test_post_identity_descriptor_validation_preserves_only_safe_metadata(): void {
		foreach ( [
			[ 'field' => 'invalid' ],
			[ 'group' => 'Invalid group' ],
			[ 'description' => '<b>Private descriptor details</b>' ],
			[ 'edit_kind' => 'invalid' ],
			[ 'capability' => 'Invalid capability' ],
			[ 'confirm_reason' => '<b>Private confirmation</b>' ],
		] as $replace ) {
			$this->assert_disabled_declaration( self::category_declaration( $replace ) );
		}
	}

	public function test_valid_lookup_can_precede_its_scalar_controller(): void {
		$warnings = [];
		$registry = $this->registry_for_declarations( [ self::category_declaration( [ 'arguments' => self::dependent_arguments() ] ) ], $warnings );
		$this->assertSame( [], $warnings );
		$this->assertNotNull( $registry->action( 'x_sample_assign_category' ) );
		$this->assertTrue( $registry->described( 'x_sample_assign_category' )['available'] );
		$this->assertNotNull( $registry->action( 'x_sample_assign' ) );
		$this->assertSame( [], $registry->family( 'sample' )['typed']['diagnostics'] );
	}

	private function assert_disabled_declaration( array $declaration ): void {
		$warnings = [];
		$registry = $this->registry_for_declarations( [ $declaration ], $warnings );
		$this->assertSame( [], $warnings, 'Malformed declarations must not emit PHP warnings.' );
		$this->assertNull( $registry->action( $declaration['id'] ) );
		$this->assertSame(
			[
				'id'        => $declaration['id'],
				'label'     => $declaration['label'],
				'available' => false,
				'reason'    => Inspector::unavailable(),
			],
			$registry->described( $declaration['id'] ),
			'The indexed diagnostic must contain only validated identity and public unavailability text.'
		);
		$this->assertNotNull( $registry->action( 'x_sample_assign' ) );
		$this->assertTrue( $registry->described( 'x_sample_assign' )['available'] );
		$this->assertSame( [ Inspector::unavailable() ], $registry->family( 'sample' )['typed']['diagnostics'] );
	}

	private static function category_declaration( array $replace = [] ): array {
		return $replace + [
			'id'           => 'x_sample_assign_category',
			'field'        => 'x_sample_category_composite',
			'group'        => 'organisation',
			'label'        => 'Assign category parts',
			'description'  => 'Changes the selected part.',
			'edit_kind'    => 'set',
			'arguments'    => [],
			'capability'   => 'manage_product_terms',
			'locations'    => [ 'category.bulk' ],
			'target_kinds' => [ 'term' ],
		];
	}

	private static function dependent_arguments(): array {
		return [
			'values'    => [
				'type'        => 'lookup',
				'label'       => 'Values',
				'rule'        => 'Choose values.',
				'source'      => 'private_source',
				'multiple'    => true,
				'min_items'   => 0,
				'max_items'   => 100,
				'depends_on'  => [ 'operation' ],
				'active_when' => [
					'argument' => 'operation',
					'op'       => 'in',
					'values'   => [ 'add' ],
				],
			],
			'operation' => [
				'type'    => 'choice',
				'label'   => 'Action',
				'rule'    => 'Choose an action.',
				'choices' => [ 'add' => 'Add' ],
			],
		];
	}

	/** Exercise the real collector, inspector and registry; runtime methods must not run. */
	private function registry_for_declarations( array $declarations, array &$warnings ): Registry {
		$this->available();
		$actions = [];
		foreach ( $declarations as $declaration ) {
			$actions[] = new class( $declaration ) implements API\Action, API\ChoiceSource {
				public function __construct( private readonly array $declaration ) {}
				public function describe(): API\ActionDescriptor {
					return new API\ActionDescriptor( ...$this->declaration );
				}
				public function availability( API\Scope $scope, array $args, API\Context $context ): bool|\WP_Error {
					throw new \LogicException( 'Discovery must not execute an action.' );
				}
				public function validate( API\Scope $scope, array $args, API\Context $context ): API\Snapshot|\WP_Error {
					throw new \LogicException( 'Discovery must not execute an action.' );
				}
				public function compute( API\Subject $subject, API\ValidatedStep $step, API\Context $context ): API\Computation {
					throw new \LogicException( 'Discovery must not execute an action.' );
				}
				public function search( API\Lookup $lookup, API\Scope $scope, API\Context $context ): API\ChoicePage|\WP_Error {
					throw new \LogicException( 'Discovery must not execute a lookup.' );
				}
				public function resolve( string $argument, array $values, array $dependencies, API\Scope $scope, API\Context $context ): array|\WP_Error {
					throw new \LogicException( 'Discovery must not execute a lookup.' );
				}
			};
		}
		$provider  = new class( new SampleTypedProvider( new MemoryStore() ), $actions ) implements API\Provider {
			public function __construct( private readonly SampleTypedProvider $base, private readonly array $extra ) {}
			public function id(): string {
				return $this->base->id();
			}
			public function identity(): string {
				return $this->base->identity();
			}
			public function label(): string {
				return $this->base->label();
			}
			public function version(): string {
				return $this->base->version();
			}
			public function availability(): bool|\WP_Error {
				return true;
			}
			public function fields(): array {
				return $this->base->fields();
			}
			public function actions(): array {
				return [ $this->base->actions()[0], ...$this->extra ];
			}
			public function groups(): array {
				return [];
			}
			public function legacy(): ?\BulkCatalogueManager\Extension\Provider {
				return null;
			}
			public function legacy_discovery(): array {
				return [];
			}
			public function authorise( int $user_id, API\Subject $subject ): bool|\WP_Error {
				throw new \LogicException( 'Discovery must not execute provider runtime methods.' );
			}
			public function state_token( API\Scope $scope, array $steps, API\Context $context ): string {
				throw new \LogicException( 'Discovery must not execute provider runtime methods.' );
			}
			public function prime( array $targets, API\Context $context ): void {
				throw new \LogicException( 'Discovery must not execute provider runtime methods.' );
			}
			public function finalise( API\FinalisationGroup $group, API\Context $context ): bool|\WP_Error {
				throw new \LogicException( 'Discovery must not execute provider runtime methods.' );
			}
		};
		$collector = new Collector( null, self::gates() + [ 'category-subjects' => 1 ] );
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capture declaration warnings so this regression can assert none escaped.
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;
				return true;
			}
		);
		try {
			$this->assertTrue( $collector->register( $provider ) );
		} finally {
			restore_error_handler();
		}
		return $collector->registry();
	}
}
