<?php
/**
 * Preview computation (T30; SPEC §4.4, §9.1, §12.1) over the seeded
 * catalogue, with a WatchedProduct loaded for each object: totals and the
 * sample for set, adjust, copy and terms actions; a later step computing
 * from an earlier step's value on the detached copy; each warning kind;
 * the sample sizes; extrapolation past the cap; the hash changing with the
 * selection, the operations or a bound setting and stable otherwise;
 * nothing saved, no term written, no SQL write; unreadable objects listed
 * and left out; per-object permission; and the filter's current count.
 *
 * The integration test (preview, then apply the same batch and compare every
 * value; no woocommerce_update_product and no post_modified_gmt change) waits
 * on wp-env and the chunk runner.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\WatchedProduct;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class PreviewTest extends TestCase {
	private const VARIABLE_PRICE = 'A variable product takes its price from its variations. Change the variations instead.';

	private const SALE_NOT_BELOW = 'The sale price has to be below the regular price, so this product was skipped. Lower the sale price, or choose to clear it where it is not below the regular price.';

	/**
	 * The loaded objects, by id.
	 *
	 * @var array<int, WatchedProduct>
	 */
	private array $products = [];

	/**
	 * Each object's props as seeded, to show nothing changed them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seeded = [];

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['caps']  = [ 'edit_post' => true ];
		$GLOBALS['bcm_test']['terms'] = [
			'product_cat' => [ 10, 11, 12, 20 ],
			'product_tag' => [ 30, 31 ],
		];
		SeededCatalogue::seed();
		$simple = static fn( string $name, string $regular, string $sale, string $sku, ?int $stock, array $cats ): array => [
			'name'           => $name,
			'status'         => 'publish',
			'featured'       => false,
			'regular_price'  => $regular,
			'sale_price'     => $sale,
			'sku'            => $sku,
			'manage_stock'   => null !== $stock,
			'stock_quantity' => $stock,
			'category_ids'   => $cats,
			'tag_ids'        => [],
		];
		$this->load( 101, 'simple', $simple( 'Blue Shirt', '20', '', 'SHIRT-BLUE', 5, [ 11 ] ) );
		$this->load( 102, 'simple', $simple( 'Formal Shirt', '45', '40', 'SHIRT-FORMAL', 0, [ 12 ] ) );
		$this->load( 103, 'variable', $simple( 'Rain Jacket', '', '', 'JACKET', null, [ 20 ] ) );
		$this->load( 104, 'simple', $simple( 'Canvas Tote', '10', '', 'TOTE-1', -2, [] ) );
		$this->load( 105, 'grouped', $simple( 'Gift Box', '', '', '', null, [ 10 ] ) );
		$this->load( 106, 'simple', $simple( 'Striped Tee', '15', '12', 'TEE-STRIPE', 12, [ 11, 20 ] ) );
		$this->load( 107, 'variable', $simple( 'Plain Hoodie', '', '', 'HOODIE', null, [ 11 ] ) );
		$this->load( 201, 'variation', [ 'parent_id' => 103 ] + $simple( 'Rain Jacket - Blue, Large', '60', '', 'JACKET-BL-L', 3, [] ) );
		$this->load( 202, 'variation', [ 'parent_id' => 103 ] + $simple( 'Rain Jacket - Dark Green', '80', '', 'JACKET-DG', null, [] ) );
		$GLOBALS['bcm_test']['watched'] = [];
	}

	protected function tearDown(): void {
		global $wpdb;
		$this->assertSame( [], array_diff( array_column( $GLOBALS['bcm_test']['watched'] ?? [], 0 ), [ 'clone', 'set' ] ), 'Preview did more than copy objects and set values on the copies.' );
		$this->assertNotContains( 'save', array_column( $GLOBALS['bcm_test']['watched'] ?? [], 0 ), 'Preview saved an object.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['set_object_terms'] ?? [], 'Preview wrote terms.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['post_meta_updates'] ?? [], 'Preview wrote meta.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['variable_syncs'] ?? [], 'Preview resynchronised a parent.' );
		$this->assertSame( [], $GLOBALS['bcm_test']['transients'] ?? [], 'Preview stored something.' );
		$this->assertSame( [], $wpdb->writes, 'Preview wrote to the database.' );
		foreach ( $wpdb->queries as $sql ) {
			$this->assertSame( 0, preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ), $sql );
		}
		foreach ( $this->products as $id => $product ) {
			$this->assertSame( $this->seeded[ $id ], $product->props, "Object $id was changed." );
		}
	}

	/**
	 * Register an object for wc_get_product().
	 *
	 * @param array<string, mixed> $props Props.
	 */
	private function load( int $id, string $type, array $props ): WatchedProduct {
		$props                                  = [ 'id' => $id ] + $props;
		$product                                = new WatchedProduct( $type, $props );
		$this->products[ $id ]                  = $product;
		$this->seeded[ $id ]                    = $props;
		$GLOBALS['bcm_test']['products'][ $id ] = $product;
		if ( '' !== ( $props['sku'] ?? '' ) ) {
			$GLOBALS['bcm_test']['skus'][ $props['sku'] ] = $id;
		}
		return $product;
	}

	private static function preview( int $cap = Preview::CAP ): Preview {
		$actions = new Actions( new Fields() );
		return new Preview( $actions, new BatchFactory( $actions ), $cap );
	}

	/**
	 * Steps as [ action, args ] pairs.
	 *
	 * @param list<array{0: string, 1: array<string, mixed>}> $steps Steps.
	 * @return list<array{action: string, args: array<string, mixed>}>
	 */
	private static function ops( array $steps ): array {
		return array_map(
			static fn( array $s ): array => [
				'action' => $s[0],
				'args'   => $s[1],
			],
			$steps
		);
	}

	/**
	 * One step setting a field.
	 *
	 * @return list<array{action: string, args: array<string, mixed>}>
	 */
	private static function set( string $field, mixed $value ): array {
		return self::ops(
			[
				[
					'set_value',
					[
						'field' => $field,
						'value' => $value,
					],
				],
			]
		);
	}

	/**
	 * Run a preview and assert it answered.
	 *
	 * @param array<mixed> $operations Operations.
	 * @return array<string, mixed>
	 */
	private function answer( Selection $selection, array $operations, int $sample = 1000, ?Preview $preview = null, string $token = '' ): array {
		$result = ( $preview ?? self::preview() )->run( $selection, $operations, $sample, $token, new Settings( [] ) );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * Sample rows as [ object id, field, current, new, state ].
	 *
	 * @param array<string, mixed> $result A preview.
	 * @return list<array{0: int, 1: string, 2: mixed, 3: mixed, 4: string}>
	 */
	private static function rows( array $result ): array {
		return array_map( static fn( array $r ): array => [ $r['object_id'], $r['field'], $r['current'], $r['new'], $r['state'] ], $result['sample'] );
	}

	public function test_set_value_totals_and_sample(): void {
		$result = $this->answer( Selection::of_ids( [ 101, 102, 103, 104, 201 ] ), self::set( 'regular_price', '25' ) );

		$this->assertSame(
			[
				'matched'      => 5,
				'computed'     => 5,
				'estimated'    => false,
				'would_change' => 3,
				'already'      => 0,
				'would_fail'   => [
					self::SALE_NOT_BELOW => 1,
					self::VARIABLE_PRICE => 1,
				],
				'rows'         => [
					'changed' => 3,
					'already' => 0,
					'skipped' => 1,
					'failed'  => 1,
				],
			],
			$result['totals']
		);
		$this->assertSame(
			[
				[ 101, 'regular_price', '20', '25.00', 'changed' ],
				[ 102, 'regular_price', '45', '45', 'skipped' ],
				[ 103, 'regular_price', '', '', 'failed' ],
				[ 104, 'regular_price', '10', '25.00', 'changed' ],
				[ 201, 'regular_price', '60', '25.00', 'changed' ],
			],
			self::rows( $result )
		);
		$first = $result['sample'][0];
		$this->assertSame(
			[
				'object_id'   => 101,
				'object_type' => 'product',
				'name'        => 'Blue Shirt',
				'field'       => 'regular_price',
				'current'     => '20',
				'new'         => '25.00',
				'state'       => 'changed',
				'reason'      => '',
				'code'        => '',
				'replaces'    => false,
			],
			$first
		);
		$this->assertSame( 'variation', $result['sample'][4]['object_type'] );
		$this->assertSame( self::VARIABLE_PRICE, $result['sample'][2]['reason'] );
		$this->assertSame( 1, $result['warnings']['sale_above_regular'], '102: 25 is not above its sale price of 40.' );
		$this->assertSame( [], $result['unreadable'] );
		$this->assertSame(
			[
				'kind'  => 'ids',
				'count' => 5,
			],
			$result['selection']
		);
	}

	public function test_adjust_over_a_filter_with_the_current_count(): void {
		$selection = Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ), 3 );
		$result    = $this->answer(
			$selection,
			self::ops(
				[
					[
						'adjust',
						[
							'field'     => 'regular_price',
							'direction' => 'increase',
							'by'        => 'percent',
							'amount'    => '10',
						],
					],
				]
			)
		);

		$this->assertSame(
			[
				[ 101, 'regular_price', '20', '22.00', 'changed' ],
				[ 102, 'regular_price', '45', '49.50', 'changed' ],
				[ 103, 'regular_price', '', '', 'skipped' ],
				[ 104, 'regular_price', '10', '11.00', 'changed' ],
				[ 105, 'regular_price', '', '', 'skipped' ],
				[ 106, 'regular_price', '15', '16.50', 'changed' ],
				[ 107, 'regular_price', '', '', 'skipped' ],
			],
			self::rows( $result )
		);
		$this->assertSame( 7, $result['totals']['matched'] );
		$this->assertSame( 4, $result['totals']['would_change'] );
		$this->assertSame( 3, array_sum( $result['totals']['would_fail'] ) );
		$this->assertSame(
			[
				'kind'          => 'filter',
				'count'         => 3,
				'current_count' => 7,
			],
			$result['selection'],
			'A filter selection answers the count when chosen and the count now.'
		);
	}

	public function test_copy_price_and_terms(): void {
		$copy = $this->answer(
			Selection::of_ids( [ 101, 106 ] ),
			self::ops(
				[
					[
						'copy_price',
						[
							'field'    => 'sale_price',
							'discount' => '50',
						],
					],
				]
			)
		);
		$this->assertSame(
			[
				[ 101, 'sale_price', '', '10.00', 'changed' ],
				[ 106, 'sale_price', '12', '7.50', 'changed' ],
			],
			self::rows( $copy )
		);
		$this->assertSame( 2, $copy['totals']['would_change'] );

		$terms = $this->answer(
			Selection::of_ids( [ 101, 106, 201 ] ),
			self::ops(
				[
					[
						'add_terms',
						[
							'field' => 'category_ids',
							'terms' => [ 20 ],
						],
					],
				]
			)
		);
		$this->assertSame(
			[
				[ 101, 'category_ids', [ 11 ], [ 11, 20 ], 'changed' ],
				[ 106, 'category_ids', [ 11, 20 ], [ 11, 20 ], 'already' ],
				[ 201, 'category_ids', [], [], 'failed' ],
			],
			self::rows( $terms )
		);
		$this->assertSame( 1, $terms['totals']['would_change'] );
		$this->assertSame( 1, $terms['totals']['already'] );
		$this->assertSame( [ 'Categories belong to the product, not to each variation.' ], array_keys( $terms['totals']['would_fail'] ) );

		$replace = $this->answer(
			Selection::of_ids( [ 101 ] ),
			self::ops(
				[
					[
						'replace_terms',
						[
							'field' => 'category_ids',
							'terms' => [ 12 ],
						],
					],
				]
			)
		);
		$this->assertTrue( $replace['sample'][0]['replaces'], 'A replace is flagged for the bold note.' );
	}

	public function test_a_later_step_computes_from_an_earlier_one_on_the_copy(): void {
		$result = $this->answer(
			Selection::of_ids( [ 101 ] ),
			self::ops(
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
						'add_terms',
						[
							'field' => 'tag_ids',
							'terms' => [ 30 ],
						],
					],
					[
						'add_terms',
						[
							'field' => 'tag_ids',
							'terms' => [ 30 ],
						],
					],
				]
			)
		);
		$this->assertSame(
			[
				[ 101, 'regular_price', '20', '10.00', 'changed' ],
				[ 101, 'regular_price', '10.00', '11.00', 'changed' ],
				[ 101, 'tag_ids', [], [ 30 ], 'changed' ],
				[ 101, 'tag_ids', [ 30 ], [ 30 ], 'already' ],
			],
			self::rows( $result )
		);
		$this->assertSame( 1, $result['totals']['would_change'] );
		$this->assertSame(
			[
				'changed' => 3,
				'already' => 1,
				'skipped' => 0,
				'failed'  => 0,
			],
			$result['totals']['rows']
		);
		$this->assertContains( [ 'clone', 101, null ], $GLOBALS['bcm_test']['watched'], 'The steps ran on a copy.' );
	}

	public function test_each_warning_kind(): void {
		$none = array_fill_keys( Preview::WARNINGS, 0 );
		$this->assertSame( [ 'sale_above_regular', 'price_not_positive', 'stock_negative', 'sku_duplicate' ], Preview::WARNINGS );

		$sale = $this->answer( Selection::of_ids( [ 102, 106 ] ), self::set( 'regular_price', '30' ) );
		$this->assertSame( array_merge( $none, [ 'sale_above_regular' => 1 ] ), $sale['warnings'], '102 has a sale price of 40.' );
		$sale = $this->answer( Selection::of_ids( [ 101, 106 ] ), self::set( 'sale_price', '18' ) );
		$this->assertSame( array_merge( $none, [ 'sale_above_regular' => 1 ] ), $sale['warnings'], '106 has a regular price of 15.' );

		$zero = $this->answer( Selection::of_ids( [ 101, 104 ] ), self::set( 'regular_price', '0' ) );
		$this->assertSame( array_merge( $none, [ 'price_not_positive' => 2 ] ), $zero['warnings'] );
		$this->assertSame( 2, $zero['totals']['would_change'], 'A price of zero is allowed, and warned about.' );

		$stock = $this->answer( Selection::of_ids( [ 101, 106 ] ), self::set( 'stock_quantity', -3 ) );
		$this->assertSame( array_merge( $none, [ 'stock_negative' => 2 ] ), $stock['warnings'] );

		$sku = $this->answer( Selection::of_ids( [ 101 ] ), self::set( 'sku', 'TEE-STRIPE' ) );
		$this->assertSame( array_merge( $none, [ 'sku_duplicate' => 1 ] ), $sku['warnings'], 'Another object holds the SKU.' );
		$this->assertSame( 'failed', $sku['sample'][0]['state'] );
		$sku = $this->answer( Selection::of_ids( [ 101, 104 ] ), self::set( 'sku', 'NEW-1' ) );
		$this->assertSame( array_merge( $none, [ 'sku_duplicate' => 1 ] ), $sku['warnings'], 'Two objects in one change would share it.' );
		$this->assertSame( [ 'changed', 'failed' ], array_column( $sku['sample'], 'state' ) );

		$quiet = $this->answer( Selection::of_ids( [ 101, 106 ] ), self::set( 'regular_price', '99' ) );
		$this->assertSame( $none, $quiet['warnings'] );
	}

	public function test_sample_sizes(): void {
		$extra = SeededCatalogue::fillers( 600 );
		SeededCatalogue::seed( $extra );
		foreach ( array_keys( $extra ) as $id ) {
			$this->load(
				$id,
				'simple',
				[
					'name'     => 'Filler',
					'featured' => false,
					'status'   => 'publish',
				]
			);
		}
		$selection = Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS ] ), 607 );
		$one       = self::set( 'featured', true );
		$two       = [ ...$one, ...self::set( 'status', 'draft' ) ];

		foreach ( [ 25, 100 ] as $size ) {
			$this->assertCount( $size, $this->answer( $selection, $one, $size )['sample'] );
		}
		$all = $this->answer( $selection, $one, Preview::SAMPLE_ALL );
		$this->assertCount( 607, $all['sample'], 'Every row when there are fewer than 1,000.' );
		$this->assertSame( 607, $all['totals']['would_change'] );
		$this->assertCount( 1000, $this->answer( $selection, $two, Preview::SAMPLE_ALL )['sample'], 'At most 1,000 rows.' );
		$this->assertCount( 1000, $this->answer( $selection, $two, 5000 )['sample'], 'Never more than 1,000.' );
		$this->assertSame( 1214, $this->answer( $selection, $two, 25 )['totals']['rows']['changed'], 'Totals cover every row whatever the sample.' );
	}

	public function test_totals_past_the_cap_are_extrapolated_and_flagged(): void {
		$selection = Selection::of_filter( Criteria::from_array( [ 'mode' => Criteria::MODE_PRODUCTS_AND_VARIATIONS ] ), 9 );
		$result    = $this->answer( $selection, self::set( 'featured', true ), 1000, self::preview( 4 ) );

		$this->assertSame( 9, $result['totals']['matched'] );
		$this->assertSame( 4, $result['totals']['computed'] );
		$this->assertTrue( $result['totals']['estimated'] );
		$this->assertSame( 9, $result['totals']['would_change'], '4 of 4 computed, scaled to 9.' );
		$this->assertCount( 4, $result['sample'], 'The sample comes from the objects computed.' );
		$this->assertSame( [ 101, 102, 103, 104 ], array_column( $result['sample'], 'object_id' ) );

		$exact = $this->answer( $selection, self::set( 'featured', true ), 1000, self::preview( 9 ) );
		$this->assertFalse( $exact['totals']['estimated'] );
		$this->assertSame( 7, $exact['totals']['would_change'], 'Featured belongs to the product, so the two variations fail.' );
		$this->assertSame( 10000, Preview::CAP );
	}

	public function test_the_hash_binds_selection_operations_and_settings(): void {
		$ops      = self::set( 'regular_price', '25' );
		$list     = ( new Actions( new Fields() ) )->validate_list( $ops );
		$settings = new Settings( [] );
		$hash     = $this->answer( Selection::of_ids( [ 101, 104 ] ), $ops )['hash'];

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $hash ) );
		$this->assertSame( $hash, $this->answer( Selection::of_ids( [ 101, 104 ] ), $ops )['hash'], 'Stable for the same request.' );
		$this->assertSame( $hash, Preview::hash( [ 101, 104 ], $list, $settings ), 'The static hash is the one the preview answers.' );
		$this->assertSame( $hash, $this->answer( Selection::of_ids( [ 101, 104 ] ), $ops, 25 )['hash'], 'The sample size is not bound.' );
		$reordered = self::ops(
			[
				[
					'set_value',
					[
						'value' => '25',
						'field' => 'regular_price',
					],
				],
			]
		);
		$this->assertSame( $hash, $this->answer( Selection::of_ids( [ 101, 104 ] ), $reordered )['hash'], 'Argument order does not matter.' );
		$this->assertSame( $hash, Preview::hash( [ 101, 104 ], $list, new Settings( [ 'rows_per_page' => 100 ] ) ), 'A setting that changes nothing done is not bound.' );

		$different = [
			'ids'               => Preview::hash( [ 104, 101 ], $list, $settings ),
			'fewer ids'         => $this->answer( Selection::of_ids( [ 101 ] ), $ops )['hash'],
			'token'             => $this->answer( Selection::of_ids( [ 101, 104 ] ), $ops, 25, null, 'a-token' )['hash'],
			'another token'     => Preview::hash( 'b-token', $list, $settings ),
			'value'             => $this->answer( Selection::of_ids( [ 101, 104 ] ), self::set( 'regular_price', '26' ) )['hash'],
			'field'             => $this->answer( Selection::of_ids( [ 101, 104 ] ), self::set( 'sale_price', '25' ) )['hash'],
			'another step'      => $this->answer( Selection::of_ids( [ 101, 104 ] ), [ ...$ops, ...self::set( 'featured', true ) ] )['hash'],
			'confirm threshold' => Preview::hash( [ 101, 104 ], $list, new Settings( [ 'confirm_threshold' => 50 ] ) ),
			'administrators'    => Preview::hash( [ 101, 104 ], $list, new Settings( [ 'administrators_only' => true ] ) ),
			'variation default' => Preview::hash( [ 101, 104 ], $list, new Settings( [ 'variations_price' => true ] ) ),
			'record unchanged'  => Preview::hash( [ 101, 104 ], $list, new Settings( [ 'record_unchanged' => true ] ) ),
		];
		foreach ( $different as $what => $other ) {
			$this->assertNotSame( $hash, $other, "A changed $what changes the hash." );
		}
		$this->assertCount( count( $different ), array_unique( $different ) );

		$GLOBALS['bcm_test']['salt'] = 'rotated';
		$this->assertNotSame( $hash, Preview::hash( [ 101, 104 ], $list, $settings ), 'The hash is keyed on the site salt.' );
	}

	public function test_objects_that_cannot_be_read_are_listed_and_left_out(): void {
		unset( $GLOBALS['bcm_test']['products'][106] );
		$result = $this->answer( Selection::of_ids( [ 101, 999, 106, 104 ] ), self::set( 'regular_price', '25' ) );

		$this->assertSame( [ 999, 106 ], $result['unreadable'], '999 is not a product; 106 could not be loaded.' );
		$this->assertSame( [ 101, 104 ], array_column( $result['sample'], 'object_id' ) );
		$this->assertSame( 3, $result['totals']['matched'] );
		$this->assertSame( 2, $result['totals']['computed'] );
		$this->assertSame( 2, $result['totals']['would_change'] );
	}

	public function test_an_object_the_user_may_not_edit_is_skipped_with_the_reason(): void {
		$GLOBALS['bcm_test']['caps']['edit_post:104'] = false;
		$result                                       = $this->answer( Selection::of_ids( [ 101, 104 ] ), self::set( 'regular_price', '25' ) );
		$this->assertSame( [ 'changed', 'skipped' ], array_column( $result['sample'], 'state' ) );
		$this->assertSame( 'bcm_cannot_edit', $result['sample'][1]['code'] );
		$this->assertSame( 1, $result['totals']['would_change'] );
	}

	public function test_refusals_come_before_anything_is_read(): void {
		$preview = self::preview();
		foreach ( [
			'bcm_no_operations'       => [],
			'bcm_too_many_operations' => array_fill( 0, 21, self::set( 'featured', true )[0] ),
			'bcm_unknown_action'      => self::ops( [ [ 'nope', [ 'field' => 'name' ] ] ] ),
			'bcm_field_not_accepted'  => self::set( 'total_sales', 3 ),
		] as $code => $operations ) {
			$GLOBALS['bcm_test']['products_loaded'] = [];
			$refused                                = $preview->run( Selection::of_ids( [ 101 ] ), $operations );
			$this->assertInstanceOf( \WP_Error::class, $refused, $code );
			$this->assertSame( $code, $refused->get_error_code() );
			$this->assertSame( 400, $refused->get_error_data()['status'] );
			$this->assertSame( [], $GLOBALS['bcm_test']['products_loaded'], "$code: nothing was loaded." );
		}

		$ids     = range( 1, Selection::MAX_OBJECTS + 1 );
		$refused = $preview->run( Selection::of_ids( $ids ), self::set( 'featured', true ) );
		$this->assertSame( 'bcm_selection_too_large', $refused->get_error_code() );
	}

	public function test_a_value_woocommerce_refuses_on_the_copy_fails_that_row_as_in_the_writer(): void {
		$this->products[101]->refuse['set_sku'] = 'Invalid or duplicated SKU.';
		$result                                 = $this->answer( Selection::of_ids( [ 101 ] ), self::set( 'sku', 'NEW-9' ) );
		$this->assertSame( [ 'failed' ], array_column( $result['sample'], 'state' ) );
		$this->assertSame( 'Invalid or duplicated SKU.', $result['sample'][0]['reason'] );
		$this->assertSame( 'bcm_invalid_value', $result['sample'][0]['code'] );
		$this->assertSame( [ 'Invalid or duplicated SKU.' => 1 ], $result['totals']['would_fail'] );
	}

	public function test_nothing_to_do(): void {
		$result = $this->answer( Selection::of_ids( [ 101, 106 ] ), self::set( 'featured', false ) );
		$this->assertSame( 0, $result['totals']['would_change'] );
		$this->assertSame( 2, $result['totals']['already'] );
		$this->assertSame( [], $result['totals']['would_fail'] );
	}
}
