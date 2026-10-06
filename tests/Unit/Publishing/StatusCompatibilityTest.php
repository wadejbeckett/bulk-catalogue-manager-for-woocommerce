<?php
/**
 * Native Status identity and the retained accepted legacy execution seam.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Publishing;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\InlineWrite;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class StatusCompatibilityTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [
			'edit_post'        => true,
			'publish_products' => true,
		];
	}

	private static function record( int $id = 501 ): string {
		return OffRecord::create( $id, 'private', '2026-09-01T08:00:00Z', 7 )->to_json();
	}

	private static function product( string $status = 'draft', string $raw = '' ): SavedProduct {
		$product = new SavedProduct(
			'simple',
			[
				'id'           => 501,
				'status'       => $status,
				'date_created' => new \DateTimeImmutable( '2026-09-01T08:00:00Z' ),
			]
		);
		if ( '' !== $raw ) {
			$product->meta[ OffRecord::META_KEY ] = $raw;
		}
		$GLOBALS['bulkcat_test']['products'][501] = $product;
		return $product;
	}

	private static function step( string $value, array $extra = [], string $action = 'set_value' ): array {
		return [
			'action' => $action,
			'args'   => $extra + [
				'field'               => 'status',
				'value'               => $value,
				'apply_to_variations' => false,
			],
		];
	}

	public function test_new_status_does_not_restore_a_legacy_private_target(): void {
		$actions = new Registry( new Fields() );
		$normal  = $actions->validate_list( [ self::step( 'publish' ) ] );
		$this->assertTrue( is_array( $normal ) );
		$this->assertSame( 'set_status', $normal[0]['action'] );
		$product = self::product( 'draft', self::record() );
		$this->assertSame( 'publish', $actions->get( 'set_status' )->compute( $product, $normal[0]['args'] )->new() );
	}

	public function test_legacy_resolver_can_compute_and_apply_while_new_discovery_excludes_it(): void {
		$actions = new Registry( new Fields() );
		$product = self::product( 'draft', self::record() );
		$args    = [
			'field'               => 'status',
			'value'               => 'publish',
			'skip_already'        => true,
			'apply_to_variations' => false,
			'keep_changed_status' => false,
		];
		$legacy  = $actions->get( 'set_value' );
		$this->assertTrue( $legacy->describe()->accepts( 'status' ) );
		$result = $legacy->compute( $product, $args );
		$this->assertSame( 'private', $result->new() );
		$legacy->apply( $result, $product );
		$this->assertSame( 'private', $product->get_status( 'edit' ) );
		$this->assertSame( [ 'set_status' ], $actions->actions_for( 'status', Field::SET ) );
		$this->assertFalse( $actions->descriptors()['set_value']->accepts( 'status' ) );
	}

	public function test_public_descriptor_is_an_immutable_projection_in_every_discovery_path(): void {
		$fields   = new Fields();
		$actions  = new Registry( $fields );
		$original = $actions->get( 'set_value' )->describe();
		$before   = $original->to_client();
		$public   = $actions->descriptors()['set_value'];
		$this->assertNotSame( $original, $public );
		$expected = array_values( array_diff( $original->fields(), [ 'status' ] ) );
		$this->assertSame( $expected, $public->fields() );
		$this->assertSame( $expected, $public->args()['properties']['field']['enum'] );
		$this->assertSame( $expected, array_column( $public->args()['properties']['field']['choices'], 'value' ) );
		$this->assertArrayNotHasKey( 'keep_changed_status', $public->args()['properties'] );
		$groups = array_values( array_intersect( Group::ALL, array_map( static fn( string $key ): string => $fields->get( $key )->group(), $expected ) ) );
		$this->assertSame( $groups, $public->groups() );
		$client = array_column( $actions->to_client(), null, 'id' );
		$this->assertSame( $public->to_client(), $client['set_value'] );
		$this->assertSame( [ 'status' ], $client['set_status']['fields'] );
		$this->assertArrayNotHasKey( 'restore_status', $client );
		$this->assertSame( $before, $original->to_client() );
		foreach ( [ 'id', 'label', 'description', 'group', 'editKinds' ] as $key ) {
			$this->assertSame( $before[ $key ], $client['set_value'][ $key ] );
		}
	}

	public function test_new_alias_and_inline_emit_native_identity_and_refuse_explicit_old_intent(): void {
		$actions = new Registry( new Fields() );
		$native  = $actions->validate_list( [ self::step( 'publish', [], 'set_status' ) ] );
		$this->assertTrue( is_array( $native ) );
		$this->assertSame( $native, $actions->validate_list( [ self::step( 'publish', [ 'keep_changed_status' => false ] ) ] ) );
		$this->assertSame( $native, ( new InlineWrite( $actions ) )->operations( [ 'status' => 'publish' ] ) );
		foreach ( [ true, 0, 1, 'false', null ] as $keep ) {
			$error = $actions->validate_list( [ self::step( 'publish', [ 'keep_changed_status' => $keep ] ) ] );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertStringContainsString( 'Reload and review', $error->get_error_message() );
		}
		$error = ( new InlineWrite( $actions ) )->operations( [ 'status' => 'publish' ], true );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertStringContainsString( 'Reload and review', $error->get_error_message() );
		foreach ( [ self::step( 'publish', [], 'restore_status' ), self::step( 'future', [], 'set_status' ), self::step( 'publish', [ 'field' => 'name' ], 'set_status' ), self::step( 'publish', [ 'keep_changed_status' => true ], 'set_status' ) ] as $step ) {
			$this->assertInstanceOf( \WP_Error::class, $actions->validate_list( [ $step ] ) );
		}
	}

	public function test_writer_clears_only_a_valid_owned_record_on_changed_native_status(): void {
		foreach ( [ '', self::record(), self::record( 502 ), '{malformed' ] as $raw ) {
			foreach ( [ 'draft', 'publish' ] as $target ) {
				bulkcat_test_reset();
				$GLOBALS['bulkcat_test']['caps'] = [
					'edit_post'        => true,
					'publish_products' => true,
				];
				$product                         = self::product( 'draft', $raw );
				$fields                          = new Fields();
				$actions                         = new Registry( $fields );
				$steps                           = $actions->validate_list( [ self::step( $target ) ] );
				$outcome                         = Writer::for_batch( $actions, new Journal( new ChangeRepository(), $fields, false ), 77 )->write( 501, $steps );
				$changed                         = 'publish' === $target;
				$cleared                         = $changed && self::record() === $raw;
				$this->assertSame( $changed, $outcome->saved() );
				$this->assertCount( $changed ? 1 : 0, $product->saves );
				$this->assertSame( $target, $product->get_status( 'edit' ) );
				$this->assertSame( $cleared ? '' : $raw, $product->get_meta( OffRecord::META_KEY, true, 'edit' ) );
				$rows = array_values( array_filter( $GLOBALS['wpdb']->writes, static fn( array $write ): bool => 'insert' === $write[0] && 'wp_bulkcat_changes' === $write[1] ) );
				$this->assertSame( $changed ? ( $cleared ? [ 'status', OffRecord::FIELD ] : [ 'status' ] ) : [], array_column( array_column( $rows, 2 ), 'prop' ) );
				if ( $cleared ) {
					$this->assertSame( $raw, ValueCodec::decode( $rows[1][2]['old_value'] ) );
					$this->assertSame( '', ValueCodec::decode( $rows[1][2]['new_value'] ) );
				}
			}
		}
	}

	public function test_actual_released_operations_still_restore_private_through_the_writer(): void {
		// Captured completed operations, executed in a unit double; Task 5 owns native queued upgrade.
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/released-status-receipts.json' ), true );
		$id      = (int) $fixture['batches'][0]['items'][0]['object_id'];
		$product = new SavedProduct(
			'simple',
			[
				'id'     => $id,
				'status' => 'private',
			]
		);
		$GLOBALS['bulkcat_test']['products'][ $id ] = $product;
		$fields                                     = new Fields();
		$actions                                    = new Registry( $fields );
		foreach ( $fixture['batches'] as $index => $case ) {
			$operations = json_decode( $case['batch']['operations'], true );
			$outcome    = Writer::for_batch( $actions, new Journal( new ChangeRepository(), $fields, false ), (int) $case['batch']['batch_id'] )->write( $id, $operations );
			$this->assertTrue( $outcome->saved() );
			$this->assertSame( 0 === $index ? 'draft' : 'private', $product->get_status( 'edit' ) );
			$this->assertSame( 0 === $index ? 'private' : null, OffRecord::for_product( $product )?->status );
			$this->assertSame( $case['intent']['operations'], $operations );
		}
		$this->assertCount( 2, $product->saves );
	}

	public function test_refused_status_never_cleans_a_record(): void {
		$actions   = new Registry( new Fields() );
		$steps     = $actions->validate_list( [ self::step( 'publish' ) ] );
		$product   = self::product( 'draft', self::record() );
		$operation = $actions->get( $steps[0]['action'] );
		foreach ( [ Result::failed( 'status', 'draft', 'Refused.' ), Result::skipped( 'status', 'draft', 'Refused.' ), Result::already( 'status', 'draft' ) ] as $result ) {
			$this->assertSame( [], $operation->follow( $product, $result, $steps[0]['args'] ) );
		}
	}

	public function test_new_cascade_keeps_selected_child_and_ignores_parent_record_but_legacy_exempts_it(): void {
		$actions = new Registry( new Fields() );
		$factory = new BatchFactory( $actions );
		$GLOBALS['wpdb']->responses['/^SELECT v.ID AS id/']          = [
			[
				'id'        => 601,
				'parent_id' => 501,
			],
			[
				'id'        => 602,
				'parent_id' => 501,
			],
			[
				'id'        => 603,
				'parent_id' => 501,
			],
		];
		$GLOBALS['wpdb']->responses['/^SELECT post_id, meta_value/'] = [
			[
				'post_id'    => 501,
				'meta_value' => self::record(),
			],
		];
		$selected = [ [ 501, 'product' ], [ 601, 'variation' ] ];
		foreach ( [ false, true ] as $cascade ) {
			$raw      = self::step( 'publish', [ 'apply_to_variations' => $cascade ] );
			$new      = $actions->validate_list( [ $raw ] );
			$expanded = $factory->expand( $selected, $new );
			$this->assertSame( $cascade ? [ ...$selected, [ 602, 'variation' ], [ 603, 'variation' ] ] : $selected, $expanded['objects'] );
			$this->assertSame( [ $cascade ? 2 : 0, 0, 0 ], [ $expanded['added'], $expanded['left_out'], $expanded['kept'] ] );
			$legacy = [
				[
					'action' => 'set_value',
					'args'   => $actions->get( 'set_value' )->validate( $raw['args'] )->args(),
				],
			];
			$old    = $factory->expand( $selected, $legacy );
			$this->assertSame( $selected, $old['objects'] );
			$this->assertSame( $cascade ? 2 : 0, $old['kept'] );
			$extra    = $actions->validate_list(
				[
					[
						'action' => 'set_value',
						'args'   => [
							'field'               => 'regular_price',
							'value'               => '12',
							'apply_to_variations' => ! $cascade,
						],
					],
				]
			);
			$mixed    = [ ...$new, ...$extra ];
			$expanded = $factory->expand( $selected, $mixed );
			$reaching = $cascade ? $new : $extra;
			$this->assertSame( [ ...$selected, [ 602, 'variation', $reaching ], [ 603, 'variation', $reaching ] ], $expanded['objects'], 'Selected child inherits every step; only added children are narrowed.' );
		}
	}
}
