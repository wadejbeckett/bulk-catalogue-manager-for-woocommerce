<?php
/**
 * Registration (T104; docs/EXTENSIONS.md §3, §4, §12; acceptance tests E1
 * and E2): the one hook fired once per request after `init`, the contract
 * version, what the core checks when a provider registers, the limits, late
 * registration, isolation of a provider that throws, and that a refused
 * provider leaves every native action and the `/fields` payload as they were.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Admin\Screen;
use BulkCatalogueManager\Extension\AbstractProvider;
use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\Contract;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\Registrar;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use BulkCatalogueManager\Rest\FieldsController;
use BulkCatalogueManager\Tests\Support\Extension\LabelsAction;
use BulkCatalogueManager\Tests\Support\Extension\LabelsField;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\NoteField;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class RegistrationTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		self::forget_shared_registry();
	}

	protected function tearDown(): void {
		self::forget_shared_registry();
	}

	/**
	 * Drop the shared action registry, so the next instance() builds afresh.
	 */
	private static function forget_shared_registry(): void {
		$class = new \ReflectionClass( Actions::class );
		$class->setStaticPropertyValue( 'instance', null );
		$class->setStaticPropertyValue( 'fired', false );
	}

	/**
	 * The one refusal a list of providers produced, or a failure.
	 *
	 * @param list<object> $providers Providers.
	 */
	private function refusal_of( array $providers ): string {
		$registered = Providers::of( $providers );
		$this->assertCount( 1, $registered->refusals(), 'Exactly one provider is refused.' );
		return $registered->refusals()[0]['reason'];
	}

	/**
	 * A provider like the sample one, with some answers replaced.
	 *
	 * @param array<string, mixed> $with id, label, contract, fields, actions or groups.
	 */
	private static function provider( array $with ): AbstractProvider {
		return new class( $with ) extends AbstractProvider {
			private SampleProvider $sample;

			public function __construct( private array $with ) {
				$this->sample = new SampleProvider( new MemoryStore() );
			}

			public function id(): string {
				return $this->with['id'] ?? 'sample';
			}

			public function label(): string {
				if ( isset( $this->with['label_throws'] ) ) {
					throw new \RuntimeException( 'No label.' );
				}
				return $this->with['label'] ?? 'Sample labels';
			}

			public function version(): string {
				return '1.0.0';
			}

			public function contract(): int {
				return $this->with['contract'] ?? 1;
			}

			public function availability(): bool|\WP_Error {
				return true;
			}

			public function fields(): array {
				return $this->with['fields'] ?? $this->sample->fields();
			}

			public function actions(): array {
				return $this->with['actions'] ?? $this->sample->actions();
			}

			public function groups(): array {
				return $this->with['groups'] ?? $this->sample->groups();
			}

			public function state_token( Scope $scope, array $steps, Context $context ): string {
				return '';
			}
		};
	}

	/**
	 * An action with one descriptor.
	 *
	 * @param ActionDescriptor $descriptor The descriptor.
	 */
	private static function action( ActionDescriptor $descriptor ): ExternalAction {
		return new class( $descriptor ) implements ExternalAction {
			public function __construct( private ActionDescriptor $descriptor ) {}

			public function describe(): ActionDescriptor {
				return $this->descriptor;
			}

			public function validate( array $args, Context $context ): Validation {
				return Validation::passed( $args );
			}

			public function compute( Subject $subject, array $args, Context $context ): Result {
				return Result::already( $this->descriptor->field(), [] );
			}
		};
	}

	/**
	 * The sample add action with some descriptor parts replaced.
	 *
	 * @param array<string, mixed> $with Constructor arguments by name.
	 */
	private static function add_action_with( array $with ): ExternalAction {
		$parts = $with + [
			'id'          => 'x_sample_add',
			'field'       => LabelsField::KEY,
			'group'       => 'x_sample_labels',
			'label'       => 'Add labels',
			'description' => 'Adds the chosen labels.',
			'edit_kind'   => 'add',
			'arguments'   => [],
			'capability'  => 'edit_products',
		];
		return self::action( new ActionDescriptor( $parts['id'], $parts['field'], $parts['group'], $parts['label'], $parts['description'], $parts['edit_kind'], $parts['arguments'], $parts['capability'] ) );
	}

	public function test_the_contract_is_version_1_and_the_registrar_says_so(): void {
		$this->assertSame( 1, Contract::VERSION );
		$this->assertSame( [ 1 ], Contract::SUPPORTED );
		$this->assertSame( 'bulkcat_register_providers', Contract::HOOK );
		$registrar = new Registrar( new \BulkCatalogueManager\External\Collector() );
		$this->assertSame( 1, $registrar->contract() );
		$this->assertTrue( $registrar->supports( 1 ) );
		$this->assertFalse( $registrar->supports( 2 ) );
		$this->assertFalse( $registrar->supports( 0 ) );
		$main = (string) file_get_contents( dirname( __DIR__, 3 ) . '/bulk-catalogue-manager-for-woocommerce.php' );
		$this->assertSame( 1, preg_match( "/define\\( 'BULKCAT_EXTENSION_CONTRACT', (\\d+) \\);/", $main, $match ), 'The plugin file defines BULKCAT_EXTENSION_CONTRACT.' );
		$this->assertSame( Contract::VERSION, (int) $match[1], 'BULKCAT_EXTENSION_CONTRACT equals Contract::VERSION.' );
	}

	public function test_a_valid_provider_registers_with_its_fields_actions_and_groups_checked(): void {
		$providers = Providers::of( [ new SampleProvider( new MemoryStore() ) ] );
		$this->assertTrue( $providers->any() );
		$this->assertSame( [], $providers->refusals() );
		$sample = $providers->get( 'sample' );
		$this->assertNotNull( $sample );
		$this->assertSame( 'Sample labels', $sample->label );
		$this->assertSame( '1.0.0', $sample->version );
		$this->assertSame( 1, $sample->contract );
		$this->assertSame( [ LabelsField::KEY, NoteField::KEY ], array_keys( $sample->fields() ) );
		$this->assertSame(
			[
				'key'    => LabelsField::KEY,
				'label'  => 'Sample labels',
				'rule'   => 'parent_only',
				'format' => 1,
			],
			$sample->field_meta( LabelsField::KEY )
		);
		$this->assertSame( 'per_variation', $sample->field_meta( NoteField::KEY )['rule'] );
		$this->assertSame( [ 'x_sample_add', 'x_sample_remove', 'x_sample_replace', 'x_sample_clear', 'x_sample_set_note' ], array_keys( $sample->all_described() ) );
		$add = $sample->described( 'x_sample_add' );
		$this->assertSame( 'x_sample_labels', $add['group'] );
		$this->assertSame( 'add', $add['edit_kind'] );
		$this->assertSame( 'edit_products', $add['capability'] );
		$this->assertSame( 'multichoice', $add['arguments']['labels']['type'] );
		$this->assertSame( 20, $add['arguments']['labels']['max_items'] );
		$this->assertSame( 1, $add['arguments']['labels']['min_items'] );
		$this->assertTrue( $sample->described( 'x_sample_replace' )['always_confirm'] );
		$this->assertSame( 'organisation', $sample->described( 'x_sample_set_note' )['group'], 'An external action may sit in a native group.' );
		$this->assertSame(
			[
				[
					'id'    => 'x_sample_labels',
					'label' => 'Sample labels',
				],
			],
			$sample->groups()
		);
		$this->assertTrue( $sample->available() );
		$this->assertSame( 'sample', $providers->owning( 'x_sample_add' )?->id );
		$this->assertNull( $providers->owning( 'x_other_add' ) );
		$this->assertNull( $providers->owning( 'set_value' ) );
	}

	public function test_the_hook_fires_once_per_request_after_init_and_never_before(): void {
		$calls = 0;
		add_action(
			Contract::HOOK,
			static function ( Registrar $registrar ) use ( &$calls ): void {
				++$calls;
				if ( $registrar->supports( 1 ) ) {
					$registrar->register( new SampleProvider( new MemoryStore() ) );
				}
			}
		);
		$before = Actions::instance();
		$this->assertSame( 0, $calls, 'Before init nothing is fired.' );
		$this->assertFalse( $before->providers()->any() );

		$GLOBALS['bulkcat_test']['did_actions']['init'] = 1;
		$after = Actions::instance();
		$this->assertSame( 1, $calls, 'The first instance after init fires the hook.' );
		$this->assertNotSame( $before, $after, 'An instance built before init is rebuilt once init has run.' );
		$this->assertSame( 'sample', $after->providers()->get( 'sample' )?->id );
		$this->assertSame( $after, Actions::instance() );
		Actions::instance();
		$this->assertSame( 1, $calls, 'Once per request.' );
		$this->assertSame( 1, did_action( Contract::HOOK ) );
	}

	public function test_with_no_provider_the_hook_fires_without_callbacks_and_nothing_changes(): void {
		$GLOBALS['bulkcat_test']['did_actions']['init'] = 1;
		$shared = Actions::instance();
		$this->assertSame( 1, did_action( Contract::HOOK ), 'One do_action() without callbacks.' );
		$this->assertFalse( $shared->providers()->any() );
		$native = new Actions( new Fields() );
		$this->assertSame( $native->ids(), $shared->ids() );
		$this->assertSame( $native->to_client(), $shared->to_client() );
		$json = (string) wp_json_encode( ( new FieldsController( new Fields(), $shared ) )->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$this->assertSame( (string) file_get_contents( dirname( __DIR__ ) . '/Fields/fields.snapshot.json' ), $json, 'E1: with no provider the /fields payload is byte-identical to the snapshot.' );
	}

	public function test_a_duplicate_provider_id_is_refused_and_the_first_kept(): void {
		$providers = Providers::of( [ new SampleProvider( new MemoryStore() ), new SampleProvider( new MemoryStore() ) ] );
		$this->assertSame( 'sample', $providers->get( 'sample' )?->id );
		$this->assertCount( 1, $providers->all() );
		$this->assertCount( 1, $providers->refusals() );
		$this->assertStringContainsString( 'already registered with the id "sample"', $providers->refusals()[0]['reason'] );
		$this->assertSame( 'Sample labels', $providers->refusals()[0]['label'] );
	}

	public function test_register_answers_true_or_the_incompatible_refusal(): void {
		$collector = new \BulkCatalogueManager\External\Collector();
		$registrar = new Registrar( $collector );
		$this->assertTrue( $registrar->register( new SampleProvider( new MemoryStore() ) ) );
		$refused = $registrar->register( new SampleProvider( new MemoryStore() ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_provider_incompatible', $refused->get_error_code() );
		$this->assertStringContainsString( 'Sample labels could not be added to Bulk Catalogue Manager', $refused->get_error_message() );
		$logged = $GLOBALS['bulkcat_test']['logged'] ?? [];
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', end( $logged )[2]['source'] ?? null, 'The reason goes to the WooCommerce log under the plugin.' );
	}

	public function test_malformed_ids_and_prefixes_are_refused(): void {
		foreach ( [ 'Bad', 'ab', 'with_underscore', '1abc', str_repeat( 'a', 21 ) ] as $id ) {
			$this->assertStringContainsString( 'its id must be three to twenty', $this->refusal_of( [ self::provider( [ 'id' => $id ] ) ] ), "Provider id $id." );
		}
		$this->assertStringContainsString( 'its value "sample_labels" must be named "x_sample_"', $this->refusal_of( [ self::provider( [ 'fields' => [ new class( new MemoryStore(), 'sample_labels' ) extends \BulkCatalogueManager\Tests\Support\Extension\SampleStoreField {} ] ] ) ] ) );
		$this->assertStringContainsString( 'its action "sample_add" must be named "x_sample_"', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'id' => 'sample_add' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'its action "x_other_add" must be named "x_sample_"', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'id' => 'x_other_add' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'its action "x_sample_Add" must be named', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'id' => 'x_sample_Add' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'must be named', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'id' => 'x_sample_' . str_repeat( 'a', 60 ) ] ) ] ] ) ] ), 'At most 64 characters.' );
		$this->assertStringContainsString( 'its chooser group "labels" must be named "x_sample_"', $this->refusal_of( [ self::provider( [ 'groups' => [ new ChooserGroup( 'labels', 'Labels' ) ] ] ) ] ) );
		$this->assertStringContainsString( 'is declared twice', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [] ), self::add_action_with( [] ) ] ] ) ] ) );
	}

	public function test_an_action_on_another_providers_field_or_an_unknown_group_is_refused(): void {
		update_option( 'sample_provider_faults', [ 'malformed' => true ] );
		$this->assertStringContainsString( 'changes "x_other_labels", which is not one of its own values', $this->refusal_of( [ new SampleProvider( new MemoryStore() ) ] ) );
		delete_option( 'sample_provider_faults' );
		$this->assertStringContainsString( 'sits in the chooser group "x_sample_other", which does not exist', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'group' => 'x_sample_other' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'names the edit kind "merge"', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'edit_kind' => 'merge' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'names no capability', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'capability' => ' ' ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'has no name or no description', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'label' => '<b></b>' ] ) ] ] ) ] ) );
	}

	public function test_reserved_names_and_unsupported_argument_kinds_are_refused(): void {
		$spec = [
			'type'  => 'boolean',
			'label' => 'Flag',
			'rule'  => 'On or off.',
		];
		foreach ( [ 'field', 'apply_to_variations', 'skip_already' ] as $reserved ) {
			$this->assertStringContainsString( 'uses a name the core adds itself', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ $reserved => $spec ] ] ) ] ] ) ] ), $reserved );
		}
		foreach ( [ 'ids', 'value', 'date', '' ] as $kind ) {
			$this->assertStringContainsString( 'is of a kind the core does not support', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'what' => [ 'type' => $kind ] + $spec ] ] ) ] ] ) ] ), "Kind $kind." );
		}
		$this->assertStringContainsString( 'has a spec the core cannot read', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'what' => $spec + [ 'pattern' => '.*' ] ] ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'has no label or no rule sentence', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'what' => [ 'rule' => '' ] + $spec ] ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'offers no choices', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'what' => [ 'type' => 'choice' ] + $spec ] ] ) ] ] ) ] ) );
		$this->assertStringContainsString( 'has a default its own rule refuses', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'what' => [ 'default' => 'yes please' ] + $spec ] ] ) ] ] ) ] ) );
	}

	public function test_an_unsupported_contract_is_refused(): void {
		update_option( 'sample_provider_faults', [ 'contract' => 2 ] );
		$this->assertStringContainsString( 'a version of the add-on contract this plugin does not support', $this->refusal_of( [ new SampleProvider( new MemoryStore() ) ] ) );
		$this->assertStringContainsString( 'does not support', $this->refusal_of( [ self::provider( [ 'contract' => 0 ] ) ] ) );
	}

	public function test_an_exception_while_describing_refuses_the_provider_and_is_logged(): void {
		update_option( 'sample_provider_faults', [ 'throw_describe' => true ] );
		$reason = $this->refusal_of( [ new SampleProvider( new MemoryStore() ) ] );
		$this->assertStringContainsString( 'Sample labels could not be added', $reason );
		$this->assertStringContainsString( 'one of its methods failed while it was being read', $reason );
		$this->assertStringNotContainsString( 'on purpose', $reason, 'The exception itself never reaches the notice.' );
		$messages = implode( "\n", array_column( $GLOBALS['bulkcat_test']['logged'] ?? [], 1 ) );
		$this->assertStringContainsString( 'The sample action could not describe itself.', $messages, 'The details go to the WooCommerce log.' );
		$this->assertStringContainsString( 'An add-on could not be added', $this->refusal_of( [ self::provider( [ 'label_throws' => true ] ) ] ), 'An unreadable label reads "An add-on".' );
	}

	public function test_late_registration_is_refused(): void {
		$kept = null;
		add_action(
			Contract::HOOK,
			static function ( Registrar $registrar ) use ( &$kept ): void {
				$kept = $registrar;
			}
		);
		$providers = Providers::fire();
		$this->assertFalse( $providers->any() );
		$this->assertInstanceOf( Registrar::class, $kept );
		$late = $kept->register( new SampleProvider( new MemoryStore() ) );
		$this->assertInstanceOf( \WP_Error::class, $late );
		$this->assertSame( 'bulkcat_provider_late', $late->get_error_code() );
		$this->assertFalse( $providers->any(), 'A late provider never appears half-way through a request.' );
	}

	public function test_each_limit_refuses_and_long_text_is_trimmed(): void {
		$sample = new SampleProvider( new MemoryStore() );
		$fields = [];
		foreach ( range( 1, 6 ) as $n ) {
			$fields[] = new class( new MemoryStore(), "x_sample_f$n" ) extends \BulkCatalogueManager\Tests\Support\Extension\SampleStoreField {};
		}
		$this->assertStringContainsString( 'more values than the 5 allowed', $this->refusal_of( [ self::provider( [ 'fields' => $fields ] ) ] ) );
		$actions = array_map( static fn( int $n ): ExternalAction => self::add_action_with( [ 'id' => "x_sample_a$n" ] ), range( 1, 21 ) );
		$this->assertStringContainsString( 'more actions than the 20 allowed', $this->refusal_of( [ self::provider( [ 'actions' => $actions ] ) ] ) );
		$groups = array_map( static fn( int $n ): ChooserGroup => new ChooserGroup( "x_sample_g$n", "Group $n" ), range( 1, 3 ) );
		$this->assertStringContainsString( 'more chooser groups than the 2 allowed', $this->refusal_of( [ self::provider( [ 'groups' => $groups ] ) ] ) );
		$many = [];
		foreach ( range( 1, 501 ) as $n ) {
			$many[ "c$n" ] = "Choice $n";
		}
		$multi = [
			'type'    => 'multichoice',
			'label'   => 'Labels',
			'rule'    => 'Choose labels.',
			'choices' => $many,
		];
		$this->assertStringContainsString( 'offers more than 500 choices', $this->refusal_of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'labels' => $multi ] ] ) ] ] ) ] ) );
		$this->assertStringContainsString(
			'choose more than 100 items',
			$this->refusal_of(
				[
					self::provider(
						[
							'actions' => [
								self::add_action_with(
									[
										'arguments' => [
											'labels' => [
												'choices' => array_slice( $many, 0, 200 ),
												'max_items' => 101,
											] + $multi,
										],
									]
								),
							],
						]
					),
				]
			)
		);
		$this->assertTrue( Providers::of( [ self::provider( [ 'actions' => [ self::add_action_with( [ 'arguments' => [ 'labels' => [ 'choices' => array_slice( $many, 0, 500 ) ] + $multi ] ] ) ] ] ) ] )->any(), 'Exactly 500 choices pass.' );

		$long = Providers::of(
			[
				self::provider(
					[
						'label'   => str_repeat( 'L', 100 ),
						'actions' => [
							self::add_action_with(
								[
									'label'       => '<script>alert(1)</script>' . str_repeat( 'a', 100 ),
									'description' => str_repeat( 'd', 400 ),
								]
							),
						],
					]
				),
			]
		)->get( 'sample' );
		$this->assertNotNull( $long );
		$this->assertSame( 80, mb_strlen( $long->label ) );
		$this->assertStringEndsWith( '…', $long->label );
		$described = $long->described( 'x_sample_add' );
		$this->assertStringNotContainsString( '<script>', $described['label'], 'Tags are stripped.' );
		$this->assertSame( 80, mb_strlen( $described['label'] ) );
		$this->assertSame( 300, mb_strlen( $described['description'] ) );
		unset( $sample );
	}

	public function test_a_refused_provider_does_not_affect_native_actions_or_other_providers(): void {
		update_option( 'sample_provider_faults', [ 'malformed' => true ] );
		$providers = Providers::of(
			[
				new SampleProvider( new MemoryStore() ),
				self::provider(
					[
						'id'      => 'other',
						'actions' => [ self::action( new ActionDescriptor( 'x_other_add', 'x_other_labels', 'organisation', 'Add', 'Adds.', 'add', [], 'edit_products' ) ) ],
						'fields'  => [ new class( new MemoryStore(), 'x_other_labels' ) extends \BulkCatalogueManager\Tests\Support\Extension\SampleStoreField {} ],
						'groups'  => [],
					]
				),
			]
		);
		$this->assertNull( $providers->get( 'sample' ) );
		$this->assertNotNull( $providers->get( 'other' ), 'One failing provider never disables another.' );
		$with   = new Actions( new Fields(), null, $providers );
		$native = new Actions( new Fields() );
		$this->assertSame( $native->ids(), $with->ids() );
		$this->assertSame( $native->to_client(), $with->to_client() );
		$this->assertTrue(
			is_array(
				$with->validate_list(
					[
						[
							'action' => 'clear',
							'args'   => [ 'field' => 'sale_price' ],
						],
					]
				)
			)
		);
	}

	public function test_the_screen_shows_one_plain_notice_per_refused_provider(): void {
		update_option( 'sample_provider_faults', [ 'malformed' => true ] );
		add_action(
			Contract::HOOK,
			static function ( Registrar $registrar ): void {
				$registrar->register( new SampleProvider( new MemoryStore() ) );
			}
		);
		$GLOBALS['bulkcat_test']['did_actions']['init']        = 1;
		$GLOBALS['bulkcat_test']['caps']['manage_woocommerce'] = true;
		ob_start();
		( new Screen() )->render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'notice-warning' ) );
		$this->assertStringContainsString( 'Sample labels could not be added to Bulk Catalogue Manager', $html );
		$this->assertStringContainsString( 'Everything else works as usual.', $html );
	}
}
