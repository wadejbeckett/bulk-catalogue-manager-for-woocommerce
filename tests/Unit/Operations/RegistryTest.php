<?php
/**
 * The operation contract and action registry (T24; SPEC §4.3, §5.1, §5.2,
 * §6, §12.1): every action declares a group, a plain label and description
 * naming no storage key, and a non-empty list of accepted fields resolved
 * from the field registry by edit kind; each accepted field really allows
 * the edit; the argument schema carries `field` and `skip_already`; central
 * validation refuses an empty list, more than twenty operations, a
 * malformed step, an unknown id, an action on a field it does not accept
 * and invalid arguments, each 400 with a `bcm_` code and a §4.8 sentence,
 * and passes and normalises a valid list; compute() on a placeholder throws
 * and writes nothing; apply() writes only a change, through the field.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Operations\Adjust;
use BulkCatalogueManager\Operations\Clear;
use BulkCatalogueManager\Operations\Descriptor;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\SetValue;
use BulkCatalogueManager\Operations\Terms;
use BulkCatalogueManager\Operations\WriteRefused;
use BulkCatalogueManager\Tests\Support\FakeProduct;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase {
	/**
	 * Every version 1 action: id => [ class, primary group, ticket implementing compute() ].
	 */
	private const ACTIONS = [
		'set_value'     => [ SetValue::class, 'publishing', 'T25' ],
		'clear'         => [ Clear::class, 'pricing', 'T25' ],
		'adjust'        => [ Adjust::class, 'pricing', 'T26' ],
		'round'         => [ \BulkCatalogueManager\Operations\Round::class, 'pricing', 'T26' ],
		'copy_price'    => [ \BulkCatalogueManager\Operations\CopyPrice::class, 'pricing', 'T26' ],
		'find_replace'  => [ \BulkCatalogueManager\Operations\FindReplace::class, 'organisation', 'T25' ],
		'affix_sku'     => [ \BulkCatalogueManager\Operations\AffixSku::class, 'organisation', 'T25' ],
		'add_terms'     => [ Terms::class, 'organisation', 'T27' ],
		'remove_terms'  => [ Terms::class, 'organisation', 'T27' ],
		'replace_terms' => [ Terms::class, 'organisation', 'T27' ],
	];

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat'            => [ 11, 12, 13 ],
			'product_tag'            => [ 21, 22 ],
			'product_shipping_class' => [ 31 ],
		];
	}

	private static function registry(): Registry {
		return new Registry( new Fields() );
	}

	/**
	 * A valid argument set per action.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function valid_args(): array {
		return [
			'set_value'     => [
				'field' => 'regular_price',
				'value' => '12.5',
			],
			'clear'         => [ 'field' => 'sale_price' ],
			'adjust'        => [
				'field'     => 'regular_price',
				'direction' => 'increase',
				'by'        => 'percent',
				'amount'    => '12',
			],
			'round'         => [
				'field'  => 'regular_price',
				'to'     => 'ending',
				'ending' => '.95',
			],
			'copy_price'    => [
				'field'    => 'sale_price',
				'discount' => '20',
			],
			'find_replace'  => [
				'field' => 'name',
				'find'  => 'Blue',
			],
			'affix_sku'     => [
				'field'    => 'sku',
				'position' => 'prefix',
				'text'     => 'ACME-',
			],
			'add_terms'     => [
				'field' => 'category_ids',
				'terms' => [ 11, 12 ],
			],
			'remove_terms'  => [
				'field' => 'tag_ids',
				'terms' => [ 21 ],
			],
			'replace_terms' => [
				'field' => 'category_ids',
				'terms' => [ 13 ],
			],
		];
	}

	/**
	 * Assert a refusal: status 400, the code, a sentence with no code in it.
	 *
	 * @param mixed  $result What validate_list() returned.
	 * @param string $code   Expected code.
	 * @param string $case   Case name.
	 */
	private function refused( mixed $result, string $code, string $case ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result, "$case was accepted." );
		$this->assertSame( $code, $result->get_error_code(), $case );
		$this->assertSame( 400, $result->get_error_data()['status'], $case );
		$message = $result->get_error_message();
		$this->assertNotSame( '', trim( $message ), $case );
		$this->assertSame( 0, preg_match( '/\bbcm_|\b[a-z]+_[a-z_]+\b/', $message ), "$case names a code or key: $message" );
		$this->assertTrue( str_ends_with( $message, '.' ), "$case is a sentence: $message" );
		return $result;
	}

	public function test_every_action_is_registered_with_its_class(): void {
		$registry = self::registry();
		$this->assertSame( array_keys( self::ACTIONS ), $registry->ids() );
		foreach ( self::ACTIONS as $id => [ $class ] ) {
			$this->assertTrue( $registry->has( $id ) );
			$this->assertSame( $class, $registry->class_of( $id ) );
			$this->assertInstanceOf( Operation::class, $registry->get( $id ) );
			$this->assertSame( $id, $registry->get( $id )->describe()->id() );
		}
		$this->assertFalse( $registry->has( 'explode' ) );
		$this->assertNull( $registry->get( 'explode' ) );
		$this->assertNull( $registry->class_of( 'explode' ) );
		$this->assertSame( self::registry()->to_client(), Registry::instance()->to_client() );
	}

	public function test_every_action_declares_a_group_label_and_plain_description(): void {
		$raw = [];
		foreach ( ( new Fields() )->all() as $field ) {
			foreach ( $field->storage()->identifiers() as $identifier ) {
				$raw[] = $identifier;
			}
		}
		foreach ( self::registry()->descriptors() as $id => $descriptor ) {
			$this->assertSame( self::ACTIONS[ $id ][1], $descriptor->group(), "$id group." );
			$this->assertContains( $descriptor->group(), Group::ALL );
			foreach ( [ $descriptor->label(), $descriptor->description() ] as $text ) {
				$this->assertNotSame( '', trim( $text ), "$id needs a label and a description." );
				$this->assertSame( 0, preg_match( '/\b_[a-z]|[a-z]_[a-z]/', $text ), "$id text looks like a key: $text" );
				foreach ( $raw as $identifier ) {
					$this->assertSame( 0, preg_match( '/\b' . preg_quote( $identifier, '/' ) . '\b/', $text ), "$id text names the storage key $identifier: $text" );
				}
			}
			$this->assertTrue( str_ends_with( $descriptor->description(), '.' ), "$id description is a sentence." );
		}
	}

	public function test_every_action_accepts_registry_fields_that_allow_its_edit(): void {
		$fields = new Fields();
		foreach ( self::registry()->descriptors() as $id => $descriptor ) {
			$this->assertNotSame( [], $descriptor->fields(), "$id accepts no field." );
			$groups = [];
			foreach ( $descriptor->fields() as $key ) {
				$field = $fields->get( $key );
				$this->assertNotNull( $field, "$id names $key, which is not in the field registry." );
				$this->assertFalse( $field->read_only(), "$id accepts the column $key." );
				$this->assertNotSame( [], array_intersect( $descriptor->edit_kinds(), $field->edit_kinds() ), "$key does not allow any edit $id performs." );
				$groups[] = $field->group();
			}
			$this->assertSame( array_values( array_intersect( Group::ALL, $groups ) ), $descriptor->groups(), "$id groups are its fields' groups." );
			$this->assertContains( $descriptor->group(), $descriptor->groups(), "$id primary group holds one of its fields." );
			$this->assertSame( array_values( array_intersect( $fields->keys(), $descriptor->fields() ) ), $descriptor->fields(), "$id fields are in registry order." );
		}
	}

	public function test_accepted_fields_are_resolved_by_edit_kind_not_hard_coded(): void {
		$registry = self::registry();
		$this->assertSame( [ 'regular_price', 'sale_price', 'stock_quantity', 'weight' ], $registry->get( 'adjust' )->describe()->fields() );
		$this->assertSame( [ 'sale_price', 'weight', 'length', 'width', 'height' ], $registry->get( 'clear' )->describe()->fields() );
		$this->assertSame( [ 'name', 'sku' ], $registry->get( 'find_replace' )->describe()->fields() );
		$this->assertSame( [ 'sku' ], $registry->get( 'affix_sku' )->describe()->fields() );
		$this->assertSame( [ 'regular_price', 'sale_price' ], $registry->get( 'round' )->describe()->fields() );
		$this->assertSame( [ 'regular_price', 'sale_price' ], $registry->get( 'copy_price' )->describe()->fields() );
		foreach ( Terms::MODES as $mode ) {
			$this->assertSame( [ 'category_ids', 'tag_ids' ], $registry->get( $mode )->describe()->fields(), $mode );
		}
		$set = $registry->get( 'set_value' )->describe()->fields();
		$this->assertNotContains( 'category_ids', $set, 'A whole term list is set through replace_terms.' );
		$this->assertContains( 'status', $set );
		$this->assertCount( 20, $set );

		// A field that gains an edit kind in the field registry reaches the action untouched.
		$fields = new Fields(
			[
				...array_values( ( new Fields() )->all() ),
				new Field(
					key: 'origin_weight',
					label: 'Packed weight',
					type: Field::DECIMAL,
					storage: Storage::meta( '_origin_weight' ),
					edit_kinds: [ Field::SET, Field::ADJUST, Field::ROUND ],
					variation_rule: Field::PER_VARIATION,
					getter: 'get_origin_weight',
					setter: 'set_origin_weight',
					group: Group::SHIPPING_TAX
				),
			]
		);
		$double = new Registry( $fields );
		$this->assertContains( 'origin_weight', $double->get( 'adjust' )->describe()->fields() );
		$this->assertContains( 'origin_weight', $double->get( 'round' )->describe()->fields() );
		$this->assertContains( 'origin_weight', $double->get( 'set_value' )->describe()->fields() );
		$this->assertNotContains( 'origin_weight', $double->get( 'clear' )->describe()->fields() );
		$this->assertSame( [ 'round' ], $double->actions_for( 'origin_weight', Field::ROUND ) );
	}

	public function test_every_argument_schema_carries_field_and_skip_already(): void {
		foreach ( self::registry()->descriptors() as $id => $descriptor ) {
			$schema = $descriptor->args();
			$this->assertSame( 'object', $schema['type'], $id );
			$this->assertFalse( $schema['additionalProperties'], $id );
			$this->assertSame( 'field', array_key_first( $schema['properties'] ), $id );
			$this->assertSame( $descriptor->fields(), $schema['properties']['field']['enum'], "$id field choices are its accepted fields." );
			$this->assertTrue( $schema['properties']['field']['required'] );
			$this->assertSame( 'boolean', $schema['properties']['skip_already']['type'], $id );
			$this->assertTrue( $schema['properties']['skip_already']['default'], "$id skips rows already at the target value by default." );
			foreach ( $schema['properties'] as $name => $property ) {
				$this->assertNotSame( '', trim( $property['title'] ), "$id.$name needs a label." );
				$this->assertNotSame( '', trim( $property['description'] ), "$id.$name needs a rule." );
			}
			$client = $descriptor->to_client();
			$this->assertSame( [ 'id', 'label', 'description', 'group', 'groups', 'editKinds', 'fields', 'args' ], array_keys( $client ) );
			$this->assertTrue( rest_validate_value_from_schema( self::valid_args()[ $id ], $schema, $id ), "$id valid arguments fit its schema." );
		}
	}

	public function test_a_valid_list_passes_normalised_in_order(): void {
		$list = [];
		foreach ( self::valid_args() as $id => $args ) {
			$list[] = [
				'action' => $id,
				'args'   => $args,
			];
		}
		$valid = self::registry()->validate_list( $list );
		$this->assertTrue( is_array( $valid ), $valid instanceof \WP_Error ? $valid->get_error_message() : '' );
		$this->assertSame( array_keys( self::ACTIONS ), array_column( $valid, 'action' ) );
		foreach ( $valid as $entry ) {
			$this->assertTrue( $entry['args']['skip_already'], $entry['action'] );
		}
		$this->assertSame(
			[
				'field'              => 'regular_price',
				'value'              => '12.50',
				'clear_invalid_sale' => false,
				'skip_already'       => true,
			],
			$valid[0]['args'],
			'The value is canonical and the defaults are filled in.'
		);
		$this->assertSame(
			[
				'field'        => 'regular_price',
				'to'           => 'ending',
				'direction'    => 'nearest',
				'ending'       => '0.95',
				'skip_already' => true,
			],
			$valid[3]['args'],
			'Round keeps only the setting its way uses.'
		);
		$this->assertSame(
			[
				'field'          => 'name',
				'find'           => 'Blue',
				'replace'        => '',
				'whole_word'     => false,
				'case_sensitive' => false,
				'skip_already'   => true,
			],
			$valid[5]['args']
		);

		$off = self::registry()->validate_list(
			[
				[
					'action' => 'clear',
					'args'   => [
						'field'        => 'weight',
						'skip_already' => 'false',
					],
				],
			]
		);
		$this->assertFalse( $off[0]['args']['skip_already'] );
		$this->assertSame(
			[
				'field'        => 'status',
				'value'        => 'draft',
				'skip_already' => true,
			],
			self::registry()->validate_list(
				[
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'status',
							'value' => 'draft',
						],
					],
				]
			)[0]['args'],
			'The sale price choice is kept only for a price.'
		);
	}

	public function test_twenty_operations_pass_and_twenty_one_are_refused_before_any_step_is_read(): void {
		$step = [
			'action' => 'clear',
			'args'   => [ 'field' => 'sale_price' ],
		];
		$this->assertCount( 20, self::registry()->validate_list( array_fill( 0, 20, $step ) ) );

		$refusal = $this->refused( self::registry()->validate_list( array_fill( 0, 21, $step ) ), 'bcm_too_many_operations', '21 operations' );
		$this->assertSame( 'One change can hold at most 20 steps, and this one has 21. Remove some steps, or split them into two changes, and try again.', $refusal->get_error_message() );
		$this->assertSame( 21, $refusal->get_error_data()['count'] );
		$this->assertSame( 20, $refusal->get_error_data()['limit'] );

		$this->refused( self::registry()->validate_list( array_fill( 0, 21, 'garbage' ) ), 'bcm_too_many_operations', '21 malformed steps' );
		$this->refused( self::registry()->validate_list( [] ), 'bcm_no_operations', 'an empty list' );
	}

	public function test_an_unknown_action_is_refused(): void {
		$refusal = $this->refused(
			self::registry()->validate_list(
				[
					[
						'action' => 'clear',
						'args'   => [ 'field' => 'sale_price' ],
					],
					[
						'action' => 'regex_replace',
						'args'   => [ 'field' => 'name' ],
					],
				]
			),
			'bcm_unknown_action',
			'an unknown id'
		);
		$this->assertSame( 'Step 2 of 2: This step asks for an action that is not available. Choose an action from the list and try again.', $refusal->get_error_message() );
		$this->assertSame( 2, $refusal->get_error_data()['step'] );
		$this->assertSame( 'regex_replace', $refusal->get_error_data()['action'] );
	}

	public function test_an_action_on_a_field_it_does_not_accept_is_refused(): void {
		$cases = [
			'round on status'             => [ 'round', 'status' ],
			'adjust on a dimension'       => [ 'adjust', 'length' ],
			'clear on the name'           => [ 'clear', 'name' ],
			'set on a whole term list'    => [ 'set_value', 'category_ids' ],
			'add terms to shipping'       => [ 'add_terms', 'shipping_class_id' ],
			'set a read-only column'      => [ 'set_value', 'units_sold' ],
			'a field that does not exist' => [ 'set_value', 'meta_key' ],
		];
		foreach ( $cases as $case => [ $action, $field ] ) {
			$refusal = $this->refused(
				self::registry()->validate_list(
					[
						[
							'action' => $action,
							'args'   => [
								'field' => $field,
								'value' => '1',
							],
						],
					]
				),
				'bcm_field_not_accepted',
				$case
			);
			$this->assertSame( $field, $refusal->get_error_data()['field'], $case );
		}
		$this->assertSame(
			'Round prices cannot change Status. Choose one of the fields it offers and try again.',
			self::registry()->validate_list(
				[
					[
						'action' => 'round',
						'args'   => [ 'field' => 'status' ],
					],
				]
			)->get_error_message()
		);
		$direct = self::registry()->get( 'round' )->validate( [ 'field' => 'status' ] );
		$this->assertSame( 'bcm_field_not_accepted', $direct->error()->get_error_code(), 'The action refuses it on its own too.' );
	}

	public function test_malformed_steps_are_refused(): void {
		$cases = [
			'not a list'       => [ 'action' => 'clear' ],
			'a step as text'   => [ 'clear' ],
			'no action'        => [ [ 'args' => [ 'field' => 'sale_price' ] ] ],
			'a numeric action' => [
				[
					'action' => 5,
					'args'   => [],
				],
			],
			'args as text'     => [
				[
					'action' => 'clear',
					'args'   => 'field=sale_price',
				],
			],
			'an extra key'     => [
				[
					'action' => 'clear',
					'args'   => [ 'field' => 'sale_price' ],
					'sql'    => 'DROP',
				],
			],
		];
		foreach ( $cases as $case => $list ) {
			$this->refused( self::registry()->validate_list( $list ), 'bcm_invalid_operation', $case );
		}
		$this->refused( self::registry()->validate_list( 'clear' ), 'bcm_invalid_operation', 'a string' );
	}

	public function test_invalid_arguments_are_refused_per_action(): void {
		$bad = [
			'set_value no field'            => [ 'set_value', [ 'value' => '1' ] ],
			'set_value no value'            => [ 'set_value', [ 'field' => 'regular_price' ] ],
			'set_value negative price'      => [
				'set_value',
				[
					'field' => 'regular_price',
					'value' => '-1',
				],
			],
			'set_value thousands separator' => [
				'set_value',
				[
					'field' => 'regular_price',
					'value' => '1,234.00',
				],
			],
			'set_value fractional stock'    => [
				'set_value',
				[
					'field' => 'stock_quantity',
					'value' => '2.5',
				],
			],
			'set_value unknown status'      => [
				'set_value',
				[
					'field' => 'status',
					'value' => 'trash',
				],
			],
			'set_value missing class'       => [
				'set_value',
				[
					'field' => 'shipping_class_id',
					'value' => 99,
				],
			],
			'set_value partly visibility'   => [
				'set_value',
				[
					'field' => 'visible_on_site',
					'value' => 'partly',
				],
			],
			'an unknown argument'           => [
				'clear',
				[
					'field' => 'sale_price',
					'force' => true,
				],
			],
			'skip_already not a switch'     => [
				'clear',
				[
					'field'        => 'sale_price',
					'skip_already' => 'maybe',
				],
			],
			'adjust no amount'              => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'amount',
				],
			],
			'adjust zero'                   => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'amount',
					'amount'    => '0',
				],
			],
			'adjust negative'               => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'amount',
					'amount'    => '-5',
				],
			],
			'adjust sideways'               => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'sideways',
					'by'        => 'amount',
					'amount'    => '5',
				],
			],
			'adjust by an exponent'         => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'increase',
					'by'        => 'amount',
					'amount'    => '1e3',
				],
			],
			'adjust decrease over 100%'     => [
				'adjust',
				[
					'field'     => 'regular_price',
					'direction' => 'decrease',
					'by'        => 'percent',
					'amount'    => '101',
				],
			],
			'adjust stock by a fraction'    => [
				'adjust',
				[
					'field'     => 'stock_quantity',
					'direction' => 'increase',
					'by'        => 'amount',
					'amount'    => '1.5',
				],
			],
			'round no way'                  => [ 'round', [ 'field' => 'regular_price' ] ],
			'round ending missing'          => [
				'round',
				[
					'field' => 'regular_price',
					'to'    => 'ending',
				],
			],
			'round ending of 1'             => [
				'round',
				[
					'field'  => 'regular_price',
					'to'     => 'ending',
					'ending' => '1',
				],
			],
			'round ending too precise'      => [
				'round',
				[
					'field'  => 'regular_price',
					'to'     => 'ending',
					'ending' => '.995',
				],
			],
			'round step of zero'            => [
				'round',
				[
					'field' => 'regular_price',
					'to'    => 'step',
					'step'  => '0',
				],
			],
			'round more decimals than shop' => [
				'round',
				[
					'field'    => 'regular_price',
					'to'       => 'decimals',
					'decimals' => 3,
				],
			],
			'round sideways'                => [
				'round',
				[
					'field'     => 'regular_price',
					'to'        => 'decimals',
					'decimals'  => 0,
					'direction' => 'sideways',
				],
			],
			'copy discount of 100'          => [
				'copy_price',
				[
					'field'    => 'sale_price',
					'discount' => '100',
				],
			],
			'copy discount into regular'    => [
				'copy_price',
				[
					'field'    => 'regular_price',
					'discount' => '10',
				],
			],
			'find nothing'                  => [
				'find_replace',
				[
					'field' => 'name',
					'find'  => '',
				],
			],
			'find too long'                 => [
				'find_replace',
				[
					'field' => 'name',
					'find'  => str_repeat( 'x', 201 ),
				],
			],
			'find a list'                   => [
				'find_replace',
				[
					'field' => 'name',
					'find'  => [ 'Blue' ],
				],
			],
			'replace with the same text'    => [
				'find_replace',
				[
					'field'          => 'sku',
					'find'           => 'AB',
					'replace'        => 'AB',
					'case_sensitive' => true,
				],
			],
			'affix nowhere'                 => [
				'affix_sku',
				[
					'field'    => 'sku',
					'position' => 'middle',
					'text'     => 'X',
				],
			],
			'affix no text'                 => [
				'affix_sku',
				[
					'field'    => 'sku',
					'position' => 'suffix',
					'text'     => '',
				],
			],
			'affix padded text'             => [
				'affix_sku',
				[
					'field'    => 'sku',
					'position' => 'suffix',
					'text'     => ' X',
				],
			],
			'affix markup'                  => [
				'affix_sku',
				[
					'field'    => 'sku',
					'position' => 'suffix',
					'text'     => '<b>X</b>',
				],
			],
			'terms none'                    => [
				'add_terms',
				[
					'field' => 'category_ids',
					'terms' => [],
				],
			],
			'terms missing'                 => [ 'add_terms', [ 'field' => 'category_ids' ] ],
			'terms of another taxonomy'     => [
				'add_terms',
				[
					'field' => 'category_ids',
					'terms' => [ 21 ],
				],
			],
			'terms that do not exist'       => [
				'replace_terms',
				[
					'field' => 'tag_ids',
					'terms' => [ 999 ],
				],
			],
			'terms not ids'                 => [
				'remove_terms',
				[
					'field' => 'tag_ids',
					'terms' => [ 'shoes' ],
				],
			],
			'terms as text'                 => [
				'remove_terms',
				[
					'field' => 'tag_ids',
					'terms' => '21',
				],
			],
			'101 terms'                     => [
				'add_terms',
				[
					'field' => 'category_ids',
					'terms' => range( 1, 101 ),
				],
			],
		];
		foreach ( $bad as $case => [ $action, $args ] ) {
			$refusal = $this->refused(
				self::registry()->validate_list(
					[
						[
							'action' => $action,
							'args'   => $args,
						],
					]
				),
				'bcm_invalid_operation',
				$case
			);
			$this->assertSame( $action, $refusal->get_error_data()['action'], $case );
			$this->assertSame( 1, $refusal->get_error_data()['step'], $case );
		}

		$this->assertSame(
			'Step 2 of 2: Enter how much to change it by, as a number above 0 like 10 or 2.5.',
			self::registry()->validate_list(
				[
					[
						'action' => 'clear',
						'args'   => [ 'field' => 'sale_price' ],
					],
					[
						'action' => 'adjust',
						'args'   => [
							'field'     => 'regular_price',
							'direction' => 'increase',
							'by'        => 'amount',
							'amount'    => '0',
						],
					],
				]
			)->get_error_message()
		);
		$this->assertSame(
			'A decrease cannot be more than 100 per cent. Enter a smaller percentage and try again.',
			self::registry()->validate_list(
				[
					[
						'action' => 'adjust',
						'args'   => [
							'field'     => 'regular_price',
							'direction' => 'decrease',
							'by'        => 'percent',
							'amount'    => '101',
						],
					],
				]
			)->get_error_message()
		);
	}

	public function test_boundary_arguments_pass(): void {
		$good = [
			'adjust decrease of exactly 100%' => [
				'adjust',
				[
					'field'     => 'sale_price',
					'direction' => 'decrease',
					'by'        => 'percent',
					'amount'    => '100',
				],
			],
			'adjust stock by whole number'    => [
				'adjust',
				[
					'field'     => 'stock_quantity',
					'direction' => 'decrease',
					'by'        => 'amount',
					'amount'    => '3',
				],
			],
			'adjust stock by a percentage'    => [
				'adjust',
				[
					'field'     => 'stock_quantity',
					'direction' => 'increase',
					'by'        => 'percent',
					'amount'    => '12.5',
				],
			],
			'round to 0 decimals, up'         => [
				'round',
				[
					'field'     => 'sale_price',
					'to'        => 'decimals',
					'decimals'  => '0',
					'direction' => 'up',
				],
			],
			'round to a step'                 => [
				'round',
				[
					'field' => 'sale_price',
					'to'    => 'step',
					'step'  => '0.50',
				],
			],
			'round to .99'                    => [
				'round',
				[
					'field'  => 'sale_price',
					'to'     => 'ending',
					'ending' => '0.99',
				],
			],
			'round to .90 at two decimals'    => [
				'round',
				[
					'field'  => 'sale_price',
					'to'     => 'ending',
					'ending' => '.900',
				],
			],
			'round to .00'                    => [
				'round',
				[
					'field'  => 'sale_price',
					'to'     => 'ending',
					'ending' => '0',
				],
			],
			'copy sale back into regular'     => [ 'copy_price', [ 'field' => 'regular_price' ] ],
			'copy with a 99.99% discount'     => [
				'copy_price',
				[
					'field'    => 'sale_price',
					'discount' => '99.99',
				],
			],
			'find with a space and markup'    => [
				'find_replace',
				[
					'field'      => 'sku',
					'find'       => ' ',
					'replace'    => '-',
					'whole_word' => 'true',
				],
			],
			'find regex metacharacters'       => [
				'find_replace',
				[
					'field'   => 'name',
					'find'    => '.*(x)$',
					'replace' => 'y',
				],
			],
			'find accented text'              => [
				'find_replace',
				[
					'field' => 'name',
					'find'  => str_repeat( 'é', 200 ),
				],
			],
			'suffix'                          => [
				'affix_sku',
				[
					'field'    => 'sku',
					'position' => 'suffix',
					'text'     => '-2026',
				],
			],
			'100 terms'                       => [
				'add_terms',
				[
					'field' => 'category_ids',
					'terms' => [ 11, 12, 13 ],
				],
			],
			'clear a sale price'              => [ 'clear', [ 'field' => 'sale_price' ] ],
			'set an empty sale price'         => [
				'set_value',
				[
					'field' => 'sale_price',
					'value' => '',
				],
			],
			'set a shipping class of none'    => [
				'set_value',
				[
					'field' => 'shipping_class_id',
					'value' => 0,
				],
			],
			'set visible on'                  => [
				'set_value',
				[
					'field' => 'visible_on_site',
					'value' => 'on',
				],
			],
		];
		foreach ( $good as $case => [ $action, $args ] ) {
			$valid = self::registry()->validate_list(
				[
					[
						'action' => $action,
						'args'   => $args,
					],
				]
			);
			$this->assertTrue( is_array( $valid ), $case . ': ' . ( $valid instanceof \WP_Error ? $valid->get_error_message() : '' ) );
		}

		$GLOBALS['bcm_test']['terms']['product_cat'] = range( 1, 100 );
		$this->assertTrue(
			self::registry()->get( 'add_terms' )->validate(
				[
					'field' => 'category_ids',
					'terms' => range( 1, 100 ),
				]
			)->ok(),
			'100 terms is the limit, not over it.'
		);

		$GLOBALS['bcm_test']['decimals'] = 3;
		$three                           = new Registry( new Fields() );
		$this->assertTrue(
			$three->get( 'round' )->validate(
				[
					'field'  => 'regular_price',
					'to'     => 'ending',
					'ending' => '.995',
				]
			)->ok(),
			'A three-decimal shop takes a three-digit ending.'
		);
		$this->assertSame( 3, $three->get( 'round' )->describe()->args()['properties']['decimals']['maximum'] );
	}

	public function test_compute_on_a_placeholder_throws_and_writes_nothing(): void {
		$registry = self::registry();
		foreach ( self::ACTIONS as $id => [ , , $ticket ] ) {
			$product   = new FakeProduct(
				'simple',
				[
					'regular_price' => '10.00',
					'name'          => 'Blue shirt',
				]
			);
			$operation = $registry->get( $id );
			$args      = $operation->validate( self::valid_args()[ $id ] )->args();
			try {
				$operation->compute( $product, $args );
				$this->fail( "$id compute() ran before $ticket." );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( $ticket, $e->getMessage(), $id );
				$this->assertStringContainsString( $id, $e->getMessage() );
			}
			$this->assertSame( [], $product->sets, "$id wrote to the product." );
		}
	}

	public function test_apply_writes_only_a_change_through_the_field_and_never_saves(): void {
		$operation = self::registry()->get( 'set_value' );
		$product   = new FakeProduct( 'simple', [ 'regular_price' => '10.00' ] );

		foreach ( [ Result::already( 'regular_price', '10.00' ), Result::skipped( 'regular_price', '10.00', 'Skipped for a reason.' ), Result::failed( 'regular_price', '10.00', 'Failed for a reason.' ) ] as $result ) {
			$operation->apply( $result, $product );
		}
		$this->assertSame( [], $product->sets, 'Only a change is written.' );

		$operation->apply( Result::changed( 'regular_price', '10.00', '12.50' ), $product );
		$this->assertSame( [ [ 'set_regular_price', '12.50' ] ], $product->sets );

		try {
			$operation->apply( Result::changed( 'category_ids', [ 11 ], [ 12 ] ), $product );
			$this->fail( 'set_value applied a field it does not accept.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'category_ids', $e->getMessage() );
		}

		try {
			$operation->apply( Result::changed( 'regular_price', '10.00', '-1' ), $product );
			$this->fail( 'A value the field refuses was written.' );
		} catch ( WriteRefused $e ) {
			$this->assertSame( 'bcm_invalid_value', $e->error()->get_error_code() );
		}
		$this->assertCount( 1, $product->sets );

		$variation = new FakeProduct( 'variation', [ 'featured' => false ] );
		try {
			$operation->apply( Result::changed( 'featured', false, true ), $variation );
			$this->fail( 'A parent-only field was written to a variation.' );
		} catch ( WriteRefused $e ) {
			$this->assertSame( 'bcm_not_available', $e->error()->get_error_code() );
			$this->assertSame( 'Featured belongs to the product, not to each variation.', $e->error()->get_error_message() );
		}
		$this->assertSame( [], $variation->sets );
	}

	public function test_a_duplicate_id_and_an_inconsistent_descriptor_are_refused(): void {
		$fields = new Fields();
		try {
			new Registry( $fields, [ new Clear( $fields ), new Clear( $fields ) ] );
			$this->fail( 'A duplicate id was accepted.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'registered twice', $e->getMessage() );
		}
		try {
			new Terms( $fields, 'merge_terms' );
			$this->fail( 'An unknown term mode was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'merge_terms', $e->getMessage() );
		}

		$base = [
			'id'          => 'thing',
			'group'       => Group::PRICING,
			'label'       => 'Thing',
			'description' => 'Does a thing.',
			'edit_kinds'  => [ Field::SET ],
			'fields'      => [ 'regular_price' ],
			'groups'      => [ Group::PRICING ],
			'args'        => [ 'type' => 'object' ],
		];
		$this->assertSame( 'thing', ( new Descriptor( ...$base ) )->id() );
		$bad = [
			[ 'id' => 'Thing' ],
			[ 'group' => 'marketing' ],
			[ 'label' => ' ' ],
			[ 'description' => '' ],
			[ 'edit_kinds' => [] ],
			[ 'edit_kinds' => [ 'explode' ] ],
			[ 'groups' => [ 'marketing' ] ],
			[ 'args' => [ 'type' => 'array' ] ],
		];
		foreach ( $bad as $change ) {
			try {
				new Descriptor( ...array_merge( $base, $change ) );
				$this->fail( 'Accepted ' . wp_json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is invalid', $e->getMessage() );
			}
		}
	}
}
