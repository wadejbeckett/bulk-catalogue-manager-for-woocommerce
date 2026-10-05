<?php
/**
 * Contract version 2 (docs/EXTENSIONS-v2.md): negotiation, the value types,
 * what the registrar checks for lookups, row entries and category targets,
 * and the neutral version 2 test provider's own lookup behaviour. A version
 * 1 provider registers and is described exactly as before.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Extension\AbstractProvider;
use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\CategoryProvider;
use BulkCatalogueManager\Extension\CategoryScope;
use BulkCatalogueManager\Extension\CategorySubject;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\Contract;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\LookupItem;
use BulkCatalogueManager\Extension\LookupPage;
use BulkCatalogueManager\Extension\LookupSource;
use BulkCatalogueManager\Extension\ObjectRef;
use BulkCatalogueManager\Extension\Registrar;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\External\Collector;
use BulkCatalogueManager\External\Inspector;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\PicksAction;
use BulkCatalogueManager\Tests\Support\Extension\PicksDirectory;
use BulkCatalogueManager\Tests\Support\Extension\PicksFaults;
use BulkCatalogueManager\Tests\Support\Extension\PicksField;
use BulkCatalogueManager\Tests\Support\Extension\PicksProvider;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class ContractV2Test extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		PicksProvider::reset();
	}

	/**
	 * Inspect a provider with every defined feature accepted.
	 *
	 * @param object            $provider The provider.
	 * @param list<string>|null $features Features; all defined ones when null.
	 */
	private static function inspect( object $provider, ?array $features = null ): Registered|\WP_Error {
		return Inspector::inspect( $provider, [], $features ?? Contract::DEFINED_FEATURES );
	}

	/**
	 * The version 2 test provider, in memory.
	 *
	 * @param bool $categories Whether to offer category actions.
	 */
	private static function picks( bool $categories = true ): PicksProvider {
		return new PicksProvider( new MemoryStore(), new MemoryStore(), $categories );
	}

	/**
	 * A provider offering one action over one field, with overrides.
	 *
	 * @param ActionDescriptor $descriptor The action's descriptor.
	 * @param array            $with       contract, rule, lookup (bool: the action is a LookupSource), categories (bool: implements CategoryProvider), more (extra descriptors).
	 */
	private static function one( ActionDescriptor $descriptor, array $with = [] ): AbstractProvider {
		$field   = new class( $with['rule'] ?? ExternalField::PARENT_ONLY ) extends \BulkCatalogueManager\Tests\Support\Extension\SampleStoreField {
			public function __construct( private readonly string $rule ) {
				parent::__construct( new MemoryStore(), 'x_one_value' );
			}

			public function variation_rule(): string {
				return $this->rule;
			}
		};
		$actions = array_map(
			static fn( ActionDescriptor $d ): ExternalAction => ( $with['lookup'] ?? true )
				? new class( $d ) implements ExternalAction, LookupSource {
					public function __construct( private readonly ActionDescriptor $d ) {}

					public function describe(): ActionDescriptor {
						return $this->d;
					}

					public function validate( array $args, Context $context ): Validation {
						return Validation::passed( $args );
					}

					public function compute( Subject $subject, array $args, Context $context ): Result {
						return Result::already( 'x_one_value', '' );
					}

					public function lookup( string $argument, string $query, int $page, Context $context ): LookupPage {
						return LookupPage::none();
					}

					public function resolve( string $argument, array $ids, Context $context ): array {
						return [];
					}
				}
			: new class( $d ) implements ExternalAction {
				public function __construct( private readonly ActionDescriptor $d ) {}

				public function describe(): ActionDescriptor {
					return $this->d;
				}

				public function validate( array $args, Context $context ): Validation {
					return Validation::passed( $args );
				}

				public function compute( Subject $subject, array $args, Context $context ): Result {
					return Result::already( 'x_one_value', '' );
				}
			},
			[ $descriptor, ...( $with['more'] ?? [] ) ]
		);
		$args    = [ $with['contract'] ?? 2, $field, $actions ];
		return ( $with['categories'] ?? true )
			? new class( ...$args ) extends AbstractProvider implements CategoryProvider {
				public function __construct( private readonly int $c, private readonly ExternalField $f, private readonly array $a ) {}

				public function id(): string {
					return 'one';
				}

				public function label(): string {
					return 'One';
				}

				public function version(): string {
					return '1.0.0';
				}

				public function contract(): int {
					return $this->c;
				}

				public function availability(): bool|\WP_Error {
					return true;
				}

				public function fields(): array {
					return [ $this->f ];
				}

				public function actions(): array {
					return $this->a;
				}

				public function state_token( Scope $scope, array $steps, Context $context ): string {
					return '';
				}

				public function prime_terms( string $taxonomy, array $term_ids ): void {}

				public function flush_terms( string $taxonomy, array $term_ids, Context $context ): void {}
			}
			: new class( ...$args ) extends AbstractProvider {
				public function __construct( private readonly int $c, private readonly ExternalField $f, private readonly array $a ) {}

				public function id(): string {
					return 'one';
				}

				public function label(): string {
					return 'One';
				}

				public function version(): string {
					return '1.0.0';
				}

				public function contract(): int {
					return $this->c;
				}

				public function availability(): bool|\WP_Error {
					return true;
				}

				public function fields(): array {
					return [ $this->f ];
				}

				public function actions(): array {
					return $this->a;
				}

				public function state_token( Scope $scope, array $steps, Context $context ): string {
					return '';
				}
			};
	}

	/**
	 * A descriptor on the one field.
	 *
	 * @param array<string, array<string, mixed>> $arguments Argument specs.
	 * @param string                              $id        Action id.
	 */
	private static function descriptor( array $arguments = [], string $id = 'x_one_set' ): ActionDescriptor {
		return new ActionDescriptor( $id, 'x_one_value', \BulkCatalogueManager\Operations\Group::PRICING, 'Set it', 'Sets it.', 'set', $arguments, 'edit_products' );
	}

	/**
	 * A lookup spec.
	 *
	 * @param array<string, mixed> $more Extra keys.
	 * @return array<string, array<string, mixed>>
	 */
	private static function lookup( array $more = [] ): array {
		return [
			'who' => [
				'type'  => 'lookup',
				'label' => 'Who',
				'rule'  => 'Choose someone.',
			] + $more,
		];
	}

	public function test_version_two_is_offered_beside_version_one(): void {
		$this->assertSame( 2, Contract::VERSION );
		$this->assertSame( [ 1, 2 ], Contract::SUPPORTED );
		$registrar = new Registrar( new Collector() );
		$this->assertTrue( $registrar->supports( 1 ) );
		$this->assertTrue( $registrar->supports( 2 ) );
		$this->assertFalse( $registrar->supports( 3 ) );
		$this->assertSame( [ 'lookup', 'row_entry', 'category_target' ], Contract::DEFINED_FEATURES );
		$this->assertSame( [], array_diff( Contract::FEATURES, Contract::DEFINED_FEATURES ), 'Only defined features are implemented.' );
		foreach ( Contract::DEFINED_FEATURES as $feature ) {
			$this->assertSame( in_array( $feature, Contract::FEATURES, true ), $registrar->supports_feature( $feature ) );
		}
		$this->assertFalse( $registrar->supports_feature( 'anything_else' ) );
		$this->assertSame( 'lookup', Context::LOOKUP );
	}

	public function test_a_version_one_provider_registers_and_is_described_as_before(): void {
		$registered = Inspector::inspect( new SampleProvider( new MemoryStore() ), [] );
		$this->assertInstanceOf( Registered::class, $registered );
		$this->assertSame( 1, $registered->contract );
		foreach ( $registered->all_described() as $described ) {
			$this->assertSame( 'product', $described['target'] );
			$this->assertFalse( $described['row_entry'] );
		}
		foreach ( $registered->fields_meta() as $meta ) {
			$this->assertSame( 'product', $meta['target'] );
		}
	}

	public function test_descriptor_options_are_immutable_with_version_one_defaults(): void {
		$plain = self::descriptor();
		$this->assertSame( ActionDescriptor::TARGET_PRODUCT, $plain->target() );
		$this->assertFalse( $plain->row_entry() );
		$row = $plain->with_row_entry();
		$cat = $row->for_target( ActionDescriptor::TARGET_CATEGORY );
		$this->assertFalse( $plain->row_entry(), 'The original is unchanged.' );
		$this->assertTrue( $row->row_entry() );
		$this->assertSame( ActionDescriptor::TARGET_PRODUCT, $row->target() );
		$this->assertSame( 'product_cat', $cat->target() );
		$this->assertTrue( $cat->row_entry() );
		$this->assertFalse( $cat->with_row_entry( false )->row_entry() );
	}

	public function test_object_references_never_confuse_kinds(): void {
		$product = ObjectRef::product( 41 );
		$term    = ObjectRef::term( 'product_cat', 41 );
		$this->assertSame( 'product:41', $product->key() );
		$this->assertSame( 'variation:41', ObjectRef::variation( 41 )->key() );
		$this->assertSame( 'term:product_cat:41', $term->key() );
		$this->assertFalse( $product->equals( $term ) );
		$this->assertTrue( $term->equals( ObjectRef::from_key( 'term:product_cat:41' ) ) );
		$this->assertSame( 'product_cat', $term->taxonomy() );
		$this->assertSame( '', $product->taxonomy() );
		foreach ( [ 'term:product_tag:41', 'term:product_cat:0', 'product:041', 'post:41', '41', 'term:product_cat:-1' ] as $bad ) {
			$this->assertNull( ObjectRef::from_key( $bad ), $bad );
		}
		$this->assertTrue( self::throws( static fn() => ObjectRef::term( 'product_tag', 41 ) ), 'Another taxonomy is refused.' );
	}

	public function test_an_object_reference_needs_a_positive_id(): void {
		$this->assertTrue( self::throws( static fn() => ObjectRef::product( 0 ) ) );
		$this->assertTrue( self::throws( static fn() => ObjectRef::term( 'product_cat', -3 ) ) );
	}

	/**
	 * Whether a call throws InvalidArgumentException.
	 *
	 * @param callable $call The call.
	 */
	private static function throws( callable $call ): bool {
		try {
			$call();
		} catch ( \InvalidArgumentException $e ) {
			return true;
		}
		return false;
	}

	public function test_lookup_pages_keep_only_items(): void {
		$page = new LookupPage( [ new LookupItem( 'a', 'A', 'detail' ), 'junk', new LookupItem( 'b', 'B' ) ], true );
		$this->assertCount( 2, $page->items() );
		$this->assertSame( [ 'a', 'b' ], array_map( static fn( LookupItem $i ): string => $i->id(), $page->items() ) );
		$this->assertSame( 'detail', $page->items()[0]->detail() );
		$this->assertSame( '', $page->items()[1]->detail() );
		$this->assertTrue( $page->has_more() );
		$this->assertSame( [], LookupPage::none()->items() );
		$this->assertFalse( LookupPage::none()->has_more() );
		$this->assertSame( 20, LookupPage::SIZE );
		$this->assertSame( 1, preg_match( LookupItem::ID, 'r0001' ) );
		$this->assertSame( 0, preg_match( LookupItem::ID, "bad\nid" ) );
		$this->assertSame( 0, preg_match( LookupItem::ID, str_repeat( 'x', 65 ) ) );
	}

	public function test_the_version_two_test_provider_registers_with_every_feature(): void {
		$registered = self::inspect( self::picks() );
		$this->assertInstanceOf( Registered::class, $registered, $registered instanceof \WP_Error ? $registered->get_error_message() : '' );
		$this->assertSame( 2, $registered->contract );
		$add = $registered->described( PicksAction::ADD );
		$this->assertSame( 'product', $add['target'] );
		$this->assertTrue( $add['row_entry'] );
		$this->assertSame(
			[
				'type'      => 'lookup',
				'label'     => 'Recipients',
				'rule'      => 'Choose at least one recipient from the list.',
				'required'  => true,
				'min_items' => 1,
				'max_items' => 20,
				'multiple'  => true,
			],
			$add['arguments']['recipients']
		);
		$only = $registered->described( PicksAction::ONLY );
		$this->assertFalse( $only['arguments']['recipient']['multiple'] );
		$this->assertArrayNotHasKey( 'max_items', $only['arguments']['recipient'] );
		$this->assertFalse( $only['row_entry'] );
		$this->assertSame( 'product_cat', $registered->described( PicksAction::CATEGORY_ADD )['target'] );
		$this->assertSame( 'product_cat', $registered->field_meta( PicksField::CATEGORY )['target'] );
		$this->assertSame( 'product', $registered->field_meta( PicksField::PRODUCT )['target'] );
	}

	public function test_a_feature_the_core_does_not_implement_yet_refuses_the_provider(): void {
		$refusal = self::inspect( self::picks( false ), [ 'lookup' ] );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertStringContainsString( '"row_entry", which this version of the plugin does not support yet', $refusal->get_error_message() );
		$this->assertInstanceOf( Registered::class, self::inspect( self::picks( false ), [ 'lookup', 'row_entry' ] ) );
		$refusal = self::inspect( self::picks(), [ 'lookup', 'row_entry' ] );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertStringContainsString( '"category_target", which this version of the plugin does not support yet', $refusal->get_error_message() );
		$refusal = self::inspect( self::one( self::descriptor( self::lookup() ) ), [] );
		$this->assertStringContainsString( '"lookup", which this version of the plugin does not support yet', $refusal->get_error_message() );
	}

	public function test_the_shipped_features_are_what_the_registrar_accepts_by_default(): void {
		$answer = Inspector::inspect( self::picks(), [] );
		$this->assertSame( [] === array_diff( Contract::DEFINED_FEATURES, Contract::FEATURES ), $answer instanceof Registered );
	}

	public function test_a_version_one_provider_cannot_use_version_two_options(): void {
		$cases = [
			'lookup'          => self::descriptor( self::lookup() ),
			'row_entry'       => self::descriptor()->with_row_entry(),
			'category_target' => self::descriptor()->for_target( ActionDescriptor::TARGET_CATEGORY ),
		];
		foreach ( $cases as $feature => $descriptor ) {
			$refusal = self::inspect( self::one( $descriptor, [ 'contract' => 1 ] ) );
			$this->assertInstanceOf( \WP_Error::class, $refusal, $feature );
			$this->assertStringContainsString( '"' . $feature . '", which needs version 2 of the add-on contract', $refusal->get_error_message(), $feature );
			$this->assertInstanceOf( Registered::class, self::inspect( self::one( $descriptor ) ), $feature . ' is fine in version 2.' );
		}
		$this->assertInstanceOf( Registered::class, self::inspect( self::one( self::descriptor(), [ 'contract' => 1 ] ) ), 'A plain version 1 action is fine.' );
	}

	public function test_registration_refuses_malformed_version_two_declarations(): void {
		$cases = [
			'unknown target'                 => [ self::one( self::descriptor()->for_target( 'product_tag' ) ), 'acts on a kind of object the core does not know' ],
			'lookup without a source'        => [ self::one( self::descriptor( self::lookup() ), [ 'lookup' => false ] ), 'has a lookup argument but cannot look choices up' ],
			'lookup with choices'            => [ self::one( self::descriptor( self::lookup( [ 'choices' => [ 'a' => 'A' ] ] ) ) ), 'its choices and default come from the add-on' ],
			'lookup with a default'          => [ self::one( self::descriptor( self::lookup( [ 'default' => 'a' ] ) ) ), 'its choices and default come from the add-on' ],
			'multiple that is not a switch'  => [ self::one( self::descriptor( self::lookup( [ 'multiple' => 'yes' ] ) ) ), 'not true or false' ],
			'single lookup with item counts' => [ self::one( self::descriptor( self::lookup( [ 'max_items' => 3 ] ) ) ), 'takes one item' ],
			'more than 100 items'            => [
				self::one(
					self::descriptor(
						self::lookup(
							[
								'multiple'  => true,
								'max_items' => 101,
							]
						)
					)
				),
				'more than 100 items',
			],
			'min above max'                  => [
				self::one(
					self::descriptor(
						self::lookup(
							[
								'multiple'  => true,
								'min_items' => 5,
								'max_items' => 4,
							]
						)
					)
				),
				'fewer than it requires',
			],
			'multiple on another kind'       => [
				self::one(
					self::descriptor(
						[
							'n' => [
								'type'     => 'integer',
								'label'    => 'N',
								'rule'     => 'A number.',
								'multiple' => true,
							],
						]
					)
				),
				'without being a lookup',
			],
			'category field per variation'   => [ self::one( self::descriptor()->for_target( ActionDescriptor::TARGET_CATEGORY ), [ 'rule' => ExternalField::PER_VARIATION ] ), 'cannot belong to each variation' ],
			'category without priming'       => [ self::one( self::descriptor()->for_target( ActionDescriptor::TARGET_CATEGORY ), [ 'categories' => false ] ), 'cannot prepare or refresh categories' ],
			'one field, two targets'         => [ self::one( self::descriptor(), [ 'more' => [ self::descriptor( [], 'x_one_cat' )->for_target( ActionDescriptor::TARGET_CATEGORY ) ] ] ), 'one value belongs to one kind of object' ],
		];
		foreach ( $cases as $name => [ $provider, $expected ] ) {
			$refusal = self::inspect( $provider );
			$this->assertInstanceOf( \WP_Error::class, $refusal, $name );
			$this->assertSame( 'bulkcat_provider_incompatible', $refusal->get_error_code(), $name );
			$this->assertStringContainsString( $expected, $refusal->get_error_message(), $name );
		}
	}

	public function test_lookup_spec_defaults(): void {
		$multiple = self::inspect( self::one( self::descriptor( self::lookup( [ 'multiple' => true ] ) ) ) );
		$this->assertSame( 100, $multiple->described( 'x_one_set' )['arguments']['who']['max_items'] );
		$this->assertSame( 0, $multiple->described( 'x_one_set' )['arguments']['who']['min_items'] );
		$required = self::inspect(
			self::one(
				self::descriptor(
					self::lookup(
						[
							'multiple' => true,
							'required' => true,
						]
					)
				)
			)
		);
		$this->assertSame( 1, $required->described( 'x_one_set' )['arguments']['who']['min_items'] );
		$single = self::inspect( self::one( self::descriptor( self::lookup() ) ) );
		$this->assertFalse( $single->described( 'x_one_set' )['arguments']['who']['multiple'] );
	}

	public function test_the_test_providers_lookup_pages_and_searches_five_thousand_recipients(): void {
		$action  = new PicksAction( PicksAction::ADD );
		$context = new RunContext( Context::LOOKUP, 7 );
		$first   = $action->lookup( 'recipients', '', 1, $context );
		$this->assertCount( 20, $first->items() );
		$this->assertTrue( $first->has_more() );
		$this->assertSame( 'r0001', $first->items()[0]->id() );
		$this->assertSame( 'Recipient 0001', $first->items()[0]->label() );
		$last = $action->lookup( 'recipients', '', 250, $context );
		$this->assertCount( 20, $last->items() );
		$this->assertFalse( $last->has_more() );
		$this->assertSame( 'r5000', $last->items()[19]->id() );
		$this->assertSame( [], $action->lookup( 'recipients', '', 251, $context )->items() );
		$search = $action->lookup( 'recipients', '0042', 1, $context );
		$this->assertSame( [ 'r0042' ], array_map( static fn( LookupItem $i ): string => $i->id(), $search->items() ) );
		$this->assertFalse( $search->has_more() );
		$this->assertSame( [], $action->lookup( 'recipients', 'nobody by that name', 1, $context )->items() );
		$this->assertSame( [ 'recipients', '0042', 1, 'lookup', 7 ], PicksDirectory::$lookups[3] );
	}

	public function test_the_test_providers_choices_can_be_denied_withdrawn_and_renamed(): void {
		update_option(
			PicksFaults::OPTION,
			[
				'withdrawn'  => [ 'r0002' ],
				'restricted' => [ 'r0003' ],
				'renamed'    => [ 'r0004' => 'Renamed recipient' ],
			]
		);
		$GLOBALS['bulkcat_test']['user_caps'][9] = [ 'manage_options' => true ];
		$action                                  = new PicksAction( PicksAction::ADD );
		$editor                                  = new RunContext( Context::PREVIEW, 7 );
		$admin                                   = new RunContext( Context::PREVIEW, 9 );
		$ids                                     = static fn( array $items ): array => array_map( static fn( LookupItem $i ): string => $i->id(), $items );
		$this->assertSame( [ 'r0001', 'r0004' ], $ids( $action->resolve( 'recipients', [ 'r0001', 'r0002', 'r0003', 'r0004', 'r9999' ], $editor ) ) );
		$this->assertSame( [ 'r0001', 'r0003', 'r0004' ], $ids( $action->resolve( 'recipients', [ 'r0001', 'r0002', 'r0003', 'r0004' ], $admin ) ) );
		$this->assertSame( 'Renamed recipient', $action->resolve( 'recipients', [ 'r0004' ], $editor )[0]->label() );
		$this->assertNotContains( 'r0002', $ids( $action->lookup( 'recipients', '', 1, new RunContext( Context::LOOKUP, 9 ) )->items() ) );
		$this->assertNotContains( 'r0003', $ids( $action->lookup( 'recipients', '', 1, new RunContext( Context::LOOKUP, 7 ) )->items() ) );
		update_option( PicksFaults::OPTION, [ 'lookup_error' => true ] );
		$this->assertInstanceOf( \WP_Error::class, $action->lookup( 'recipients', '', 1, $editor ) );
	}

	public function test_category_subjects_and_scopes_are_typed(): void {
		$this->assertTrue( is_subclass_of( CategorySubject::class, Subject::class ) );
		$this->assertTrue( is_subclass_of( CategoryScope::class, Scope::class ) );
		$this->assertTrue( method_exists( CategorySubject::class, 'ref' ) );
		$this->assertTrue( method_exists( CategoryProvider::class, 'prime_terms' ) );
		$this->assertTrue( method_exists( CategoryProvider::class, 'flush_terms' ) );
		$this->assertInstanceOf( CategoryProvider::class, self::picks() );
		$this->assertInstanceOf( ChooserGroup::class, self::picks()->groups()[0] );
	}
}
