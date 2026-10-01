<?php
/**
 * Validation (T106; docs/EXTENSIONS.md §5.2, §6, §7.1; acceptance test E4):
 * external steps are validated through Operations\Registry::validate_list()
 * like native ones — the core checks each argument against its spec, then
 * the action's capability and its provider's availability, and only then
 * asks the provider's validate(); one change holds native steps or one
 * provider's steps; an action of a provider that is not registered is 409
 * `bulkcat_provider_unavailable`; an external value sent to the
 * single-product route is refused; and a canonical intent naming external
 * steps is checked for shape only.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use BulkCatalogueManager\Requests\BatchIntent;
use BulkCatalogueManager\Rest\ProductWriteController;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Tests\Support\Extension\SampleStoreField;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class ValidationTest extends TestCase {
	private const USER = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		SampleProvider::reset();
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
	}

	/**
	 * A registry with the sample provider and, when asked, a second one.
	 *
	 * @param bool $other Whether a second provider, `other`, is registered too.
	 */
	private static function actions( bool $other = false ): Actions {
		$providers = [ new SampleProvider( new MemoryStore() ) ];
		if ( $other ) {
			$providers[] = self::other();
		}
		return new Actions( new Fields(), null, Providers::of( $providers ) );
	}

	/**
	 * A second neutral provider with one text field and one action.
	 */
	private static function other(): \BulkCatalogueManager\Extension\Provider {
		return new class() extends \BulkCatalogueManager\Extension\AbstractProvider {
			public function id(): string {
				return 'other';
			}

			public function label(): string {
				return 'Other values';
			}

			public function version(): string {
				return '2.0.0';
			}

			public function contract(): int {
				return 1;
			}

			public function availability(): bool|\WP_Error {
				return true;
			}

			public function fields(): array {
				return [ new class( new MemoryStore(), 'x_other_text' ) extends SampleStoreField {} ];
			}

			public function actions(): array {
				return [
					new class() implements ExternalAction {
						public function describe(): ActionDescriptor {
							return new ActionDescriptor( 'x_other_set', 'x_other_text', 'organisation', 'Set other text', 'Sets the other text.', 'set', [], 'edit_products' );
						}

						public function validate( array $args, Context $context ): Validation {
							return Validation::passed( $args );
						}

						public function compute( Subject $subject, array $args, Context $context ): Result {
							return Result::already( 'x_other_text', '' );
						}
					},
				];
			}

			public function state_token( Scope $scope, array $steps, Context $context ): string {
				return '';
			}
		};
	}

	/**
	 * A normalised list, or a failure naming the refusal.
	 *
	 * @param mixed $list What validate_list() answered.
	 * @return list<array<string, mixed>>
	 */
	private function valid( mixed $list ): array {
		if ( $list instanceof \WP_Error ) {
			$this->fail( $list->get_error_code() . ': ' . $list->get_error_message() );
		}
		return $list;
	}

	/**
	 * The refusal, or a failure.
	 *
	 * @param mixed  $list   What validate_list() answered.
	 * @param string $code   The code expected.
	 * @param int    $status The status expected.
	 */
	private function refused( mixed $list, string $code, int $status = 400 ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $list, 'Expected ' . $code );
		$this->assertSame( $code, $list->get_error_code(), $list->get_error_message() );
		$this->assertSame( $status, $list->get_error_data()['status'] ?? null );
		return $list;
	}

	private static function add( array $labels = [ 'red' ], array $more = [] ): array {
		return [
			'action' => 'x_sample_add',
			'args'   => [
				'field'  => 'x_sample_labels',
				'labels' => $labels,
			] + $more,
		];
	}

	public function test_a_valid_external_step_is_normalised_like_a_native_one(): void {
		$list = $this->valid( self::actions()->validate_list( [ self::add( [ 'red', 'blue', 'green' ] ) ] ) );
		$this->assertSame(
			[
				[
					'action' => 'x_sample_add',
					'args'   => [
						'field'        => 'x_sample_labels',
						'labels'       => [ 'blue', 'green', 'red' ],
						'skip_already' => true,
					],
				],
			],
			$list,
			'Labels in the order the choices are offered; skip_already on; no apply_to_variations on a parent-only field.'
		);
		$this->assertSame( [ [ 'x_sample_add', 'apply' ] ], SampleProvider::$validated, 'The provider validates with an apply context outside a preview.' );
		$note = $this->valid(
			self::actions()->validate_list(
				[
					[
						'action' => 'x_sample_set_note',
						'args'   => [
							'field' => 'x_sample_note',
							'note'  => 'Hello',
						],
					],
				]
			)
		);
		$this->assertFalse( $note[0]['args']['apply_to_variations'], 'A per-variation field starts with Also apply to variations off.' );
		$on = $this->valid(
			self::actions()->validate_list(
				[
					[
						'action' => 'x_sample_set_note',
						'args'   => [
							'field'               => 'x_sample_note',
							'note'                => '',
							'apply_to_variations' => true,
						],
					],
				]
			)
		);
		$this->assertTrue( $on[0]['args']['apply_to_variations'] );
		$preview = RunContext::within( RunContext::preview( self::USER ), fn(): mixed => self::actions()->validate_list( [ self::add() ] ) );
		$this->assertTrue( is_array( $preview ) );
		$this->assertSame( 'preview', end( SampleProvider::$validated )[1], 'A preview validates in preview mode.' );
	}

	public function test_argument_specs_are_checked_before_the_provider_sees_them(): void {
		$actions = self::actions();
		$cases   = [
			'no labels'       => [ 'x_sample_add', [ 'field' => 'x_sample_labels' ] ],
			'empty labels'    => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => [],
				],
			],
			'not a list'      => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => 'red',
				],
			],
			'unknown choice'  => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => [ 'purple' ],
				],
			],
			'repeated choice' => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => [ 'red', 'red' ],
				],
			],
			'too many'        => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => array_fill( 0, 21, 'red' ),
				],
			],
			'unknown name'    => [
				'x_sample_add',
				[
					'field'  => 'x_sample_labels',
					'labels' => [ 'red' ],
					'colour' => 'red',
				],
			],
			'no field'        => [ 'x_sample_add', [ 'labels' => [ 'red' ] ] ],
			'another field'   => [
				'x_sample_add',
				[
					'field'  => 'x_sample_note',
					'labels' => [ 'red' ],
				],
			],
			'native field'    => [
				'x_sample_add',
				[
					'field'  => 'regular_price',
					'labels' => [ 'red' ],
				],
			],
			'note too long'   => [
				'x_sample_set_note',
				[
					'field' => 'x_sample_note',
					'note'  => str_repeat( 'n', 201 ),
				],
			],
			'note not text'   => [
				'x_sample_set_note',
				[
					'field' => 'x_sample_note',
					'note'  => 5,
				],
			],
		];
		foreach ( $cases as $case => [ $action, $args ] ) {
			$refusal = $actions->validate_list(
				[
					[
						'action' => $action,
						'args'   => $args,
					],
				]
			);
			$this->assertInstanceOf( \WP_Error::class, $refusal, $case );
			$this->assertContains( $refusal->get_error_code(), [ 'bulkcat_invalid_operation', 'bulkcat_field_not_accepted' ], $case );
			$this->assertStringNotContainsString( 'bulkcat_', $refusal->get_error_message(), $case );
		}
		$this->assertSame( [], SampleProvider::$validated, 'The provider never saw a step its spec refuses.' );
		$refusal = $this->refused( $actions->validate_list( [ self::add( [], [] ) ] ), 'bulkcat_invalid_operation' );
		$this->assertSame( 'Choose at least one label from the list.', $refusal->get_error_message(), 'The spec\'s own sentence.' );
		$this->refused(
			$actions->validate_list( [ self::add( [ 'red' ], [ 'apply_to_variations' => true ] ) ] ),
			'bulkcat_invalid_operation'
		);
	}

	public function test_the_providers_own_refusal_is_a_plain_invalid_operation(): void {
		update_option( 'sample_provider_faults', [ 'refuse_validate' => true ] );
		$refusal = $this->refused( self::actions()->validate_list( [ self::add() ] ), 'bulkcat_invalid_operation' );
		$this->assertSame( 'The sample add-on refuses this change on purpose.', $refusal->get_error_message() );
		$this->assertSame( 'x_sample_refused', $refusal->get_error_data()['provider_code'] ?? null );
	}

	public function test_mixing_native_and_external_steps_or_two_providers_is_refused(): void {
		$native = [
			'action' => 'clear',
			'args'   => [ 'field' => 'sale_price' ],
		];
		$this->refused( self::actions()->validate_list( [ $native, self::add() ] ), 'bulkcat_mixed_change' );
		$this->refused( self::actions()->validate_list( [ self::add(), $native ] ), 'bulkcat_mixed_change' );
		$other = [
			'action' => 'x_other_set',
			'args'   => [ 'field' => 'x_other_text' ],
		];
		$this->valid( self::actions( true )->validate_list( [ $other ] ) );
		$refusal = $this->refused( self::actions( true )->validate_list( [ self::add(), $other ] ), 'bulkcat_mixed_change' );
		$this->assertSame( "This action can't be combined with other kinds of change. Make it a separate change.", $refusal->get_error_message() );
		$this->assertSame( [], SampleProvider::$validated, 'A mixed change is refused before any provider is asked.' );
		$two = $this->valid( self::actions()->validate_list( [ self::add( [ 'red' ] ), self::add( [ 'blue' ] ) ] ) );
		$this->assertCount( 2, $two, 'Several steps of one provider are one kind of change.' );
		$this->refused( self::actions()->validate_list( array_fill( 0, 21, self::add() ) ), 'bulkcat_too_many_operations' );
	}

	public function test_unknown_external_actions_and_missing_providers_are_refused(): void {
		$this->refused(
			self::actions()->validate_list(
				[
					[
						'action' => 'x_sample_paint',
						'args'   => [ 'field' => 'x_sample_labels' ],
					],
				]
			),
			'bulkcat_unknown_action'
		);
		$missing = $this->refused(
			self::actions()->validate_list(
				[
					[
						'action' => 'x_gone_add',
						'args'   => [ 'field' => 'x_gone_labels' ],
					],
				]
			),
			'bulkcat_provider_unavailable',
			409
		);
		$this->assertSame( 'provider_unavailable', $missing->get_error_data()['reason'] );
		$this->refused( ( new Actions( new Fields() ) )->validate_list( [ self::add() ] ), 'bulkcat_provider_unavailable', 409 );
	}

	public function test_an_unavailable_provider_or_a_missing_capability_is_refused(): void {
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$refusal = $this->refused( self::actions()->validate_list( [ self::add() ] ), 'bulkcat_provider_unavailable', 409 );
		$this->assertSame( 'Sample labels needs its settings saved before it can be used.', $refusal->get_error_message() );
		delete_option( 'sample_provider_faults' );
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [];
		$refusal = $this->refused( self::actions()->validate_list( [ self::add() ] ), 'bulkcat_forbidden', 403 );
		$this->assertSame( 'You do not have permission to use this action.', $refusal->get_error_message() );
		$this->assertSame( [], SampleProvider::$validated );
		$GLOBALS['bulkcat_test']['user_caps'][9] = [ 'edit_products' => true ];
		$this->valid( RunContext::within( RunContext::apply( 9, 0 ), static fn(): mixed => self::actions()->validate_list( [ self::add() ] ) ) );
		$this->assertSame( [ [ 'x_sample_add', 'apply' ] ], SampleProvider::$validated, 'The context\'s user is the identity: background work checks the batch\'s user.' );
	}

	public function test_the_registry_resolves_external_actions_but_lists_only_native_ones(): void {
		$actions = self::actions();
		$native  = new Actions( new Fields() );
		$this->assertSame( $native->ids(), $actions->ids() );
		$this->assertSame( $native->to_client(), $actions->to_client() );
		$this->assertNotNull( $actions->get( 'x_sample_add' ) );
		$this->assertTrue( $actions->has( 'x_sample_add' ) );
		$this->assertSame( [ 'x_sample_add', 'x_sample_remove', 'x_sample_replace', 'x_sample_clear', 'x_sample_set_note' ], $actions->external_ids() );
		$this->assertSame( 'Add labels', $actions->get( 'x_sample_add' )->describe()->label() );
		$this->assertSame( 'x_sample_labels', $actions->get( 'x_sample_add' )->describe()->group() );
		$this->assertSame( 'sample', $actions->external_provider( [ self::add() ] )?->id );
		$this->assertNull(
			$actions->external_provider(
				[
					[
						'action' => 'clear',
						'args'   => [ 'field' => 'sale_price' ],
					],
				]
			)
		);
	}

	public function test_an_external_value_sent_to_the_single_product_route_is_refused(): void {
		$GLOBALS['bulkcat_test']['caps'] = [
			'manage_woocommerce' => true,
			'edit_products'      => true,
			'edit_post'          => true,
		];
		$store                           = new \BulkCatalogueManager\Tests\Unit\Engine\InlineStore();
		$store->enable_receipts();
		$product                                  = new \BulkCatalogueManager\Tests\Unit\Engine\StoredProduct(
			'simple',
			[
				'id'            => 101,
				'regular_price' => '20',
			]
		);
		$GLOBALS['bulkcat_test']['products'][101] = $product;
		$external                                 = new MemoryStore();
		$actions                                  = new Actions( new Fields(), null, Providers::of( [ new SampleProvider( $external ) ] ) );
		$grant                                    = ( new \BulkCatalogueManager\Requests\RequestService() )->issue(
			'product.inline',
			[
				'object_id' => 101,
				'source'    => 'inline',
				'values'    => [ 'x_sample_labels' => [ 'red' ] ],
			]
		);
		$this->assertTrue( is_array( $grant ), 'Issue the signed original payload before testing its native refusal.' );
		$request = new \WP_REST_Request( 'PATCH', '/bulkcat/v1/products/101' );
		foreach ( [ 'uuid', 'request_proof', 'intent' ] as $key ) {
			$request->set_param( $key, $grant[ $key ] );
		}
		$request->set_param( 'id', 101 );
		$request->set_param( 'x_sample_labels', [ 'red' ] );
		$answer = ( new ProductWriteController( new Fields(), $actions ) )->update_item( $request );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 'bulkcat_external_not_inline', $answer->get_error_code() );
		$this->assertSame( 400, $answer->get_error_data()['status'] );
		$this->assertSame( 0, $product->saves );
		$this->assertSame( [], $external->writes );
		$this->assertSame( [], SampleProvider::$computed );
		$this->assertSame( [], SampleProvider::$flushed );
		$this->assertSame( [], $store->batches );
		$this->assertSame( [], $store->items );
		$this->assertSame( [], $store->changes );
	}

	public function test_a_canonical_intent_with_external_steps_is_checked_for_shape_only(): void {
		// E6: an accepted Apply resolves by its receipt after the provider has gone, so resolution never asks it.
		$canonical = [
			'version'    => 1,
			'source'     => 'bulk',
			'operations' => [ self::add() + [] ],
			'scope_hash' => str_repeat( 'a', 64 ),
			'ids'        => [ 101, 102 ],
		];
		$this->assertSame( $canonical, BatchIntent::normalize( 'batch.apply', $canonical, self::USER ), 'No provider is registered here, and the shape still passes.' );
		$bad                            = $canonical;
		$bad['operations'][0]['action'] = 'X_Sample_Add';
		try {
			BatchIntent::normalize( 'batch.apply', $bad, self::USER );
			$this->fail( 'A malformed action id passed.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'canonical', $e->getMessage() );
		}
	}
}
