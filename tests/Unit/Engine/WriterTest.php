<?php
/**
 * CRUD writer (T32; SPEC §5.2, §8, §12.3): every §5.2 writable field is set
 * through its typed WooCommerce setter and saved once; an unchanged object is
 * not saved; a list applies in order against the live object; terms go
 * through wp_set_object_terms(); a refusal mid-list fails that step and still
 * saves the earlier and later changes; a refused-only object is not saved; a
 * saved variation resynchronises its parent through WC_Product_Variable::sync()
 * once per object, or once per parent inside run(); the journal hook sees the
 * touched keys and the before state just before save(); and no path calls
 * update_post_meta().
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat'            => [ 11, 12 ],
			'product_tag'            => [ 21, 22 ],
			'product_shipping_class' => [ 31 ],
		];
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bcm_test']['post_meta_updates'] ?? [], 'The writer called update_post_meta().' );
	}

	private static function registry(): Registry {
		return new Registry( new Fields() );
	}

	/**
	 * A validated, normalised list, as every caller hands the writer.
	 *
	 * @param list<array{0: string, 1: array<string, mixed>}> $steps [ action, args ].
	 */
	private function steps( array $steps ): array {
		$list = self::registry()->validate_list(
			array_map(
				static fn( array $s ): array => [
					'action' => $s[0],
					'args'   => $s[1],
				],
				$steps
			)
		);
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		return $list;
	}

	/**
	 * Register a fake product under its id for wc_get_product().
	 *
	 * @param array<string, mixed> $props Props, with `id` and, for a variation, `parent_id`.
	 */
	private static function product( string $type, array $props ): SavedProduct {
		$product = new SavedProduct( $type, $props );
		$GLOBALS['bcm_test']['products'][ $props['id'] ] = $product;
		return $product;
	}

	/**
	 * Result states in list order.
	 *
	 * @return list<string>
	 */
	private static function states( WriteOutcome $outcome ): array {
		return array_map( static fn( Result $r ): string => $r->state(), $outcome->results() );
	}

	/**
	 * Every §5.2 writable field: [ action, args, object type, props, WooCommerce setter, value set ].
	 *
	 * The setter names are WooCommerce's, written out here rather than read
	 * from the registry, so a registry pointing at the wrong setter fails.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>, 4: string, 5: mixed}>
	 */
	private static function every_field(): array {
		$set = static fn( string $field, mixed $value ): array => [
			'field' => $field,
			'value' => $value,
		];
		return [
			'name'               => [ 'set_value', $set( 'name', 'Red shirt' ), 'simple', [ 'name' => 'Blue shirt' ], 'set_name', 'Red shirt' ],
			'status'             => [ 'set_value', $set( 'status', 'publish' ), 'simple', [ 'status' => 'draft' ], 'set_status', 'publish' ],
			'catalog_visibility' => [ 'set_value', $set( 'catalog_visibility', 'hidden' ), 'simple', [ 'catalog_visibility' => 'visible' ], 'set_catalog_visibility', 'hidden' ],
			'visible_on_site'    => [ 'set_value', $set( 'visible_on_site', 'on' ), 'simple', [ 'catalog_visibility' => 'catalog' ], 'set_catalog_visibility', 'visible' ],
			'featured'           => [ 'set_value', $set( 'featured', true ), 'simple', [ 'featured' => false ], 'set_featured', true ],
			'category_ids'       => [
				'replace_terms',
				[
					'field' => 'category_ids',
					'terms' => [ 12, 11 ],
				],
				'simple',
				[ 'category_ids' => [ 11 ] ],
				'set_category_ids',
				[ 12, 11 ],
			],
			'tag_ids'            => [
				'add_terms',
				[
					'field' => 'tag_ids',
					'terms' => [ 22 ],
				],
				'simple',
				[ 'tag_ids' => [ 21 ] ],
				'set_tag_ids',
				[ 21, 22 ],
			],
			'regular_price'      => [ 'set_value', $set( 'regular_price', '12.5' ), 'variation', [ 'regular_price' => '10' ], 'set_regular_price', '12.50' ],
			'sale_price'         => [ 'set_value', $set( 'sale_price', '8' ), 'simple', [ 'sale_price' => '' ], 'set_sale_price', '8.00' ],
			'stock_status'       => [ 'set_value', $set( 'stock_status', 'outofstock' ), 'simple', [ 'stock_status' => 'instock' ], 'set_stock_status', 'outofstock' ],
			'manage_stock'       => [ 'set_value', $set( 'manage_stock', true ), 'simple', [ 'manage_stock' => false ], 'set_manage_stock', true ],
			'stock_quantity'     => [
				'set_value',
				$set( 'stock_quantity', 7 ),
				'variation',
				[
					'manage_stock'   => true,
					'stock_quantity' => 5,
				],
				'set_stock_quantity',
				7,
			],
			'sku'                => [ 'set_value', $set( 'sku', 'B-2' ), 'simple', [ 'sku' => 'A-1' ], 'set_sku', 'B-2' ],
			'weight'             => [ 'set_value', $set( 'weight', '2' ), 'simple', [ 'weight' => '1.5' ], 'set_weight', '2' ],
			'length'             => [ 'set_value', $set( 'length', '10' ), 'variation', [ 'length' => '' ], 'set_length', '10' ],
			'width'              => [ 'set_value', $set( 'width', '0' ), 'simple', [ 'width' => '' ], 'set_width', '0' ],
			'height'             => [ 'set_value', $set( 'height', '4.25' ), 'simple', [ 'height' => '3' ], 'set_height', '4.25' ],
			'shipping_class_id'  => [ 'set_value', $set( 'shipping_class_id', 31 ), 'simple', [ 'shipping_class_id' => 0 ], 'set_shipping_class_id', 31 ],
			'tax_status'         => [ 'set_value', $set( 'tax_status', 'none' ), 'simple', [ 'tax_status' => 'taxable' ], 'set_tax_status', 'none' ],
			'tax_class'          => [ 'set_value', $set( 'tax_class', 'parent' ), 'variation', [ 'tax_class' => '' ], 'set_tax_class', 'parent' ],
			'sold_individually'  => [ 'set_value', $set( 'sold_individually', true ), 'simple', [ 'sold_individually' => false ], 'set_sold_individually', true ],
			'reviews_allowed'    => [ 'set_value', $set( 'reviews_allowed', false ), 'simple', [ 'reviews_allowed' => true ], 'set_reviews_allowed', false ],
		];
	}

	public function test_every_writable_field_is_set_through_its_typed_setter_and_saved_once(): void {
		$table    = self::every_field();
		$editable = array_map( static fn( Field $f ): string => $f->key(), array_values( ( new Fields() )->editable() ) );
		$this->assertSame( $editable, array_keys( $table ), 'The table covers every §5.2 writable field, in registry order.' );

		$id = 100;
		foreach ( $table as $key => [ $action, $args, $type, $props, $setter, $value ] ) {
			++$id;
			$GLOBALS['bcm_test']['set_object_terms'] = [];
			$GLOBALS['bcm_test']['variable_syncs']   = [];
			$props                                  += [ 'id' => $id ] + ( 'variation' === $type ? [ 'parent_id' => 9 ] : [] );
			$product                                 = self::product( $type, $props );

			$outcome = ( new Writer( self::registry() ) )->write( $id, $this->steps( [ [ $action, $args ] ] ) );

			$this->assertSame( [ Result::CHANGED ], self::states( $outcome ), $key );
			$this->assertSame( [ [ $setter, $value ] ], $product->sets, "$key is written through $setter only." );
			$this->assertCount( 1, $product->saves, "$key saves once." );
			$this->assertSame( $value, $product->saves[0][ substr( $setter, 4 ) ], "$key is set before save()." );
			$this->assertTrue( $outcome->saved() );
			$this->assertSame( [ $key ], $outcome->touched() );
			$terms = str_ends_with( $action, '_terms' ) ? [ [ $id, $value, 'category_ids' === $key ? 'product_cat' : 'product_tag', false ] ] : [];
			$this->assertSame( $terms, $GLOBALS['bcm_test']['set_object_terms'], "$key: terms go through wp_set_object_terms() and nothing else does." );
			$this->assertSame( 'variation' === $type ? [ 9 ] : [], $GLOBALS['bcm_test']['variable_syncs'], "$key: only a variation resynchronises its parent." );
		}
	}

	public function test_an_unchanged_object_is_not_saved_hooked_or_resynchronised(): void {
		$product = self::product(
			'variation',
			[
				'id'            => 5,
				'parent_id'     => 4,
				'regular_price' => '10',
				'sale_price'    => '',
			]
		);
		$called  = 0;
		$writer  = new Writer(
			self::registry(),
			static function () use ( &$called ): void {
				++$called;
			}
		);
		$outcome = $writer->write(
			5,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '10.00',
						],
					],
					[
						'clear',
						[ 'field' => 'sale_price' ],
					],
				]
			)
		);

		$this->assertSame( [ Result::ALREADY, Result::ALREADY ], self::states( $outcome ) );
		$this->assertFalse( $outcome->saved() );
		$this->assertSame( [], $product->sets );
		$this->assertSame( [], $product->saves, 'An unchanged object is not re-saved.' );
		$this->assertSame( 0, $called, 'The journal hook runs only for an object being saved.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['variable_syncs'] ?? [] );
		$this->assertSame( 0, $outcome->parent_id() );
		$this->assertSame( [ 5 ], $GLOBALS['bcm_test']['products_loaded'], 'The object is loaded once, with wc_get_product().' );
	}

	public function test_a_mixed_list_applies_in_order_against_the_live_object_and_saves_once(): void {
		$product = self::product(
			'simple',
			[
				'id'            => 6,
				'regular_price' => '8',
				'sale_price'    => '5',
				'sku'           => 'A-1',
				'tag_ids'       => [],
			]
		);
		$outcome = ( new Writer( self::registry() ) )->write(
			6,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '10',
						],
					],
					[
						'adjust',
						[
							'field'     => 'regular_price',
							'direction' => 'increase',
							'by'        => 'percent',
							'amount'    => '10',
						],
					],
					[
						'set_value',
						[
							'field' => 'sku',
							'value' => 'A-1',
						],
					],
					[
						'add_terms',
						[
							'field' => 'tag_ids',
							'terms' => [ 21 ],
						],
					],
					[
						'clear',
						[ 'field' => 'sale_price' ],
					],
				]
			)
		);

		$this->assertSame( [ Result::CHANGED, Result::CHANGED, Result::ALREADY, Result::CHANGED, Result::CHANGED ], self::states( $outcome ) );
		$second = $outcome->results()[1];
		$this->assertSame( '10.00', $second->old(), 'The adjustment is computed against the value step 1 set.' );
		$this->assertSame( '11.00', $second->new() );
		$this->assertSame(
			[
				[ 'set_regular_price', '10.00' ],
				[ 'set_regular_price', '11.00' ],
				[ 'set_tag_ids', [ 21 ] ],
				[ 'set_sale_price', '' ],
			],
			$product->sets
		);
		$this->assertCount( 1, $product->saves, 'Several changes save once.' );
		$this->assertSame( [ 'regular_price', 'tag_ids', 'sale_price' ], $outcome->touched() );
		$this->assertSame(
			[
				'regular_price' => '8',
				'tag_ids'       => [],
				'sale_price'    => '5',
			],
			$outcome->before()
		);
	}

	public function test_terms_are_written_with_wp_set_object_terms_before_save(): void {
		$product = self::product(
			'simple',
			[
				'id'           => 7,
				'category_ids' => [ 11 ],
			]
		);
		$writer  = new Writer(
			self::registry(),
			function ( object $subject ): void {
				$this->assertSame( [ [ 7, [ 11, 12 ], 'product_cat', false ] ], $GLOBALS['bcm_test']['set_object_terms'] ?? [], 'Terms are stored through wp_set_object_terms(), not only through the setter.' );
			}
		);
		$outcome = $writer->write(
			7,
			$this->steps(
				[
					[
						'add_terms',
						[
							'field' => 'category_ids',
							'terms' => [ 12 ],
						],
					],
				]
			)
		);
		$this->assertTrue( $outcome->saved() );
		$this->assertSame( [ [ 'set_category_ids', [ 11, 12 ] ] ], $product->sets );
		$this->assertCount( 1, $product->saves );
	}

	public function test_a_variation_write_resynchronises_its_parent_once_after_save(): void {
		$product = self::product(
			'variation',
			[
				'id'            => 21,
				'parent_id'     => 20,
				'regular_price' => '10',
				'sale_price'    => '',
			]
		);
		$outcome = ( new Writer( self::registry() ) )->write(
			21,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '12',
						],
					],
					[
						'set_value',
						[
							'field' => 'sale_price',
							'value' => '9',
						],
					],
				]
			)
		);
		$this->assertCount( 1, $product->saves );
		$this->assertSame( [ 20 ], $GLOBALS['bcm_test']['variable_syncs'], 'Two fields on one variation sync the parent once, through WooCommerce.' );
		$this->assertSame( 20, $outcome->parent_id() );
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [] );
	}

	public function test_variations_of_one_parent_sync_once_per_object_or_once_per_run(): void {
		$list  = $this->steps(
			[
				[
					'set_value',
					[
						'field' => 'regular_price',
						'value' => '15',
					],
				],
			]
		);
		$reset = static function (): void {
			$GLOBALS['bcm_test']['variable_syncs'] = [];
			foreach ( [
				31 => 30,
				32 => 30,
				41 => 40,
			] as $id => $parent ) {
				self::product(
					'variation',
					[
						'id'            => $id,
						'parent_id'     => $parent,
						'regular_price' => '10',
					]
				);
			}
		};

		$reset();
		$writer = new Writer( self::registry() );
		$writer->write( 31, $list );
		$writer->write( 32, $list );
		$this->assertSame( [ 30, 30 ], $GLOBALS['bcm_test']['variable_syncs'], 'Outside a run, each saved variation syncs its parent straight after its own save.' );

		$reset();
		$result = $writer->run(
			function ( Writer $inner ) use ( $list ): string {
				$inner->write( 31, $list );
				$inner->write( 32, $list );
				$inner->write( 41, $list );
				$inner->write( 99, $list );
				$this->assertSame( [], $GLOBALS['bcm_test']['variable_syncs'] ?? [], 'Inside a run, syncing waits for the end.' );
				return 'done';
			}
		);
		$this->assertSame( 'done', $result );
		$this->assertSame( [ 30, 40 ], $GLOBALS['bcm_test']['variable_syncs'], 'A run syncs each parent once, after its last variation.' );

		$reset();
		try {
			$writer->run(
				static function ( Writer $inner ) use ( $list ): void {
					$inner->write( 31, $list );
					throw new \RuntimeException( 'chunk killed' );
				}
			);
			$this->fail( 'The exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'chunk killed', $e->getMessage() );
		}
		$this->assertSame( [ 30 ], $GLOBALS['bcm_test']['variable_syncs'], 'A run that throws still syncs the parents it saved.' );
	}

	public function test_a_failure_mid_list_is_reported_and_the_other_changes_still_save(): void {
		$product                                       = self::product(
			'simple',
			[
				'id'            => 50,
				'regular_price' => '10',
				'category_ids'  => [ 11 ],
				'sku'           => 'A-1',
				'weight'        => '1',
			]
		);
		$product->refuse['set_sku']                    = 'Invalid or duplicated SKU.';
		$GLOBALS['bcm_test']['set_object_terms_error'] = new \WP_Error( 'db', 'Deadlock.' );
		$seen    = [];
		$writer  = new Writer(
			self::registry(),
			static function ( object $subject, array $fields, array $before ) use ( &$seen, $product ): void {
				$seen[] = [ $fields, $before, count( $product->saves ) ];
			}
		);
		$outcome = $writer->write(
			50,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '12',
						],
					],
					[
						'replace_terms',
						[
							'field' => 'category_ids',
							'terms' => [ 12 ],
						],
					],
					[
						'set_value',
						[
							'field' => 'sku',
							'value' => 'B-2',
						],
					],
					[
						'set_value',
						[
							'field' => 'weight',
							'value' => '2',
						],
					],
				]
			)
		);

		$this->assertSame( [ Result::CHANGED, Result::FAILED, Result::FAILED, Result::CHANGED ], self::states( $outcome ), 'A failure does not stop the steps after it.' );
		[ , $terms, $sku ] = $outcome->results();
		$this->assertSame( 'Categories could not be saved on this product. Try again in a moment.', $terms->reason(), 'A WriteRefused becomes a failed result with its sentence.' );
		$this->assertSame( 'bcm_invalid_value', $terms->code() );
		$this->assertSame( [ 11 ], $terms->old() );
		$this->assertSame( 'Invalid or duplicated SKU.', $sku->reason(), "WooCommerce's own setter refusal becomes a failed result." );
		$this->assertSame( Writer::INVALID, $sku->code() );
		$this->assertTrue( $outcome->has_failures() );

		$this->assertCount( 1, $product->saves, 'The earlier and later changes are saved despite the failure.' );
		$this->assertSame( '12.00', $product->saves[0]['regular_price'] );
		$this->assertSame( '2', $product->saves[0]['weight'] );
		$this->assertSame( [ 11 ], $product->saves[0]['category_ids'] );
		$this->assertSame( 'A-1', $product->saves[0]['sku'] );
		$this->assertSame(
			[
				[
					[ 'regular_price', 'weight' ],
					[
						'regular_price' => '10',
						'weight'        => '1',
					],
					0,
				],
			],
			$seen,
			'The hook sees only saved changes, with their before values, before save().'
		);
		$this->assertSame( [ 'regular_price', 'weight' ], $outcome->touched() );
	}

	public function test_an_object_whose_only_change_is_refused_is_not_saved(): void {
		$product                    = self::product(
			'variation',
			[
				'id'        => 60,
				'parent_id' => 59,
				'sku'       => 'A-1',
			]
		);
		$product->refuse['set_sku'] = 'Invalid or duplicated SKU.';
		$called                     = false;
		$writer                     = new Writer(
			self::registry(),
			static function () use ( &$called ): void {
				$called = true;
			}
		);
		$outcome                    = $writer->write(
			60,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'sku',
							'value' => 'B-2',
						],
					],
				]
			)
		);
		$this->assertSame( [ Result::FAILED ], self::states( $outcome ) );
		$this->assertFalse( $outcome->saved() );
		$this->assertSame( [], $product->saves );
		$this->assertFalse( $called );
		$this->assertSame( [], $GLOBALS['bcm_test']['variable_syncs'] ?? [] );
	}

	public function test_the_journal_hook_gets_the_state_before_the_first_step(): void {
		$product = self::product(
			'simple',
			[
				'id'                 => 70,
				'catalog_visibility' => 'catalog',
			]
		);
		$seen    = [];
		$writer  = new Writer(
			self::registry(),
			static function ( object $subject, array $fields, array $before ) use ( &$seen ): void {
				$seen = [ $subject, $fields, $before ];
			}
		);
		// Both fields share set_catalog_visibility(): step 2 reads what step 1 set.
		$outcome = $writer->write(
			70,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'catalog_visibility',
							'value' => 'search',
						],
					],
					[
						'set_value',
						[
							'field' => 'visible_on_site',
							'value' => 'on',
						],
					],
				]
			)
		);
		$this->assertSame( $product, $seen[0] );
		$this->assertSame( [ 'catalog_visibility', 'visible_on_site' ], $seen[1] );
		$this->assertSame(
			[
				'catalog_visibility' => 'catalog',
				'visible_on_site'    => 'partly',
			],
			$seen[2],
			'Before values are read through the registry before any step sets anything.'
		);
		$this->assertSame( $seen[2], $outcome->before() );
		$this->assertSame( 'visible', $product->saves[0]['catalog_visibility'] );
	}

	public function test_an_id_that_is_not_a_product_fails_every_step_and_writes_nothing(): void {
		$outcome = ( new Writer( self::registry() ) )->write(
			404,
			$this->steps(
				[
					[
						'set_value',
						[
							'field' => 'regular_price',
							'value' => '1',
						],
					],
					[
						'clear',
						[ 'field' => 'sale_price' ],
					],
				]
			)
		);
		$this->assertSame( [ Result::FAILED, Result::FAILED ], self::states( $outcome ) );
		$this->assertSame( [ 'regular_price', 'sale_price' ], array_map( static fn( Result $r ): string => $r->field(), $outcome->results() ) );
		$this->assertSame( Writer::NOT_FOUND, $outcome->results()[0]->code() );
		$this->assertFalse( $outcome->saved() );
		$this->assertSame( 404, $outcome->to_array()['id'] );
	}

	public function test_a_list_that_was_not_validated_is_refused_before_loading(): void {
		$writer = new Writer( self::registry() );
		foreach ( [
			[ [ 'action' => 'nope' ] ],
			[ [ 'action' => 'set_value' ] ],
			[ 'set_value' ],
			[
				[
					'action' => 'set_value',
					'args'   => [ 'value' => 1 ],
				],
			],
		] as $list ) {
			try {
				$writer->write( 1, $list );
				$this->fail( 'An unvalidated list was accepted.' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'validate_list', $e->getMessage() );
			}
		}
		$this->assertSame( [], $GLOBALS['bcm_test']['products_loaded'] ?? [] );
	}

	public function test_the_engine_never_writes_meta_sql_or_setters_of_its_own(): void {
		$files = glob( dirname( __DIR__, 3 ) . '/src/Engine/*.php' );
		$this->assertNotEmpty( $files );
		// SQL writes are banned; BatchFactory's two read-only selection queries are allowed until
		// they move into src/Query (T31 follow-up).
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $code );
			$this->assertSame( 0, preg_match( '/\b(update|add|delete)_(post_)?meta(data)?\s*\(|\$wpdb->(query|insert|update|replace|delete)\s*\(|->set_[a-z_]+\s*\(|->set_props\s*\(|->update_meta_data\s*\(/', $code ), basename( $file ) . ' writes product data outside the operations and CRUD save().' );
		}
	}
}
