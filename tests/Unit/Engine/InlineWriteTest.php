<?php
/**
 * A single-object write as a batch of one (T37; SPEC §12.1, §9.3, §8, §13):
 * a price edit is written, journalled as a one-item batch with source
 * inline, finalised complete in the same call and never enqueued, with the
 * same journal rows a bulk batch writes for the same change; the submitted
 * fields become one step each in registry order (clear for an empty value
 * on a clearable field, both prices deciding the pair); a value the object
 * cannot take is refused 400 naming the field with nothing written and no
 * batch; a parent-only field on a variation is refused with its reason while
 * a variation's own price is written; an object the user may not edit is
 * refused 403 and a missing or trashed id 404.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\InlineWrite;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Settings\Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InlineStore.php';
require_once __DIR__ . '/RunnerStore.php';

final class InlineWriteTest extends TestCase {
	private const USER = 12;

	private InlineStore $store;

	private Registry $actions;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']         = true;
		$GLOBALS['bulkcat_test']['user_id']           = self::USER;
		$GLOBALS['bulkcat_test']['caps']['edit_post'] = true;
		$GLOBALS['bulkcat_test']['scheduled']         = [];
		// The chunk runner listens on bulkcat_batch_created, as it does on a live site.
		( new Plugin() )->boot();
		$this->store   = new InlineStore();
		$this->actions = new Registry( new Fields() );
		self::product( 101, 'simple', [ 'regular_price' => '20' ] );
	}

	protected function tearDown(): void {
		$this->assertSame( [], $GLOBALS['bulkcat_test']['post_meta_updates'] ?? [], 'The write wrote meta of its own.' );
	}

	/**
	 * Register a product for wc_get_product().
	 *
	 * @param array<string, mixed> $props Props besides the id.
	 */
	private static function product( int $id, string $type, array $props ): StoredProduct {
		$GLOBALS['bulkcat_test']['products'][ $id ] = new StoredProduct( $type, [ 'id' => $id ] + $props );
		return $GLOBALS['bulkcat_test']['products'][ $id ];
	}

	private function writes(): InlineWrite {
		return new InlineWrite( $this->actions, null, null, new Settings( [] ) );
	}

	/**
	 * Assert a refusal, and that nothing was written and no batch recorded.
	 */
	private function assert_refused( mixed $result, string $code, int $status ): \WP_Error {
		global $wpdb;
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'bulkcat_', $result->get_error_message() );
		$this->assertSame( [], $wpdb->writes, 'No batch, item or journal row was written.' );
		$this->assertSame( [], $this->store->items );
		foreach ( $GLOBALS['bulkcat_test']['products'] as $product ) {
			$this->assertSame( 0, $product->saves, 'Nothing was saved.' );
			$this->assertSame( [], $product->sets, 'Nothing was set on the loaded product.' );
		}
		return $result;
	}

	public function test_a_price_edit_is_written_and_journalled_as_a_finished_batch_of_one(): void {
		$result = $this->writes()->write( 101, [ 'regular_price' => '25' ], 'inline', self::USER );

		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertInstanceOf( WriteOutcome::class, $result['outcome'] );
		$this->assertTrue( $result['outcome']->saved() );
		$this->assertSame( '25.00', $GLOBALS['bulkcat_test']['products'][101]->props['regular_price'] );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves );

		$batch = $this->store->batch( $result['batch_id'] );
		$this->assertSame( 'inline', $batch['source'] );
		$this->assertSame( 'complete', $batch['status'] );
		$this->assertSame( self::USER, $batch['user_id'] );
		$this->assertSame( [ 1, 1, 1, 0 ], [ $batch['total_items'], $batch['done_items'], $batch['changed_items'], $batch['failed_items'] ] );
		$this->assertNotNull( $batch['finished_gmt'] );
		$this->assertSame(
			[
				[
					'kind'         => 'ids',
					'ids'          => [ 101 ],
					'count'        => 1,
					'materialised' => 1,
				],
			],
			[ json_decode( $batch['selection'], true ) ]
		);
		$this->assertSame( [ 'set_value' ], array_column( json_decode( $batch['operations'], true ), 'action' ) );

		$this->assertCount( 1, $this->store->items );
		$item = array_values( $this->store->items )[0];
		$this->assertSame( [ 101, 'product', 'done', '', 1 ], [ $item['object_id'], $item['object_type'], $item['state'], $item['message'], $item['attempts'] ] );
		$this->assertSame( [ [ 101, 'regular_price', '20', '25.00', 'applied' ] ], $this->store->journal() );

		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'], 'Nothing was queued: no chunk, no retry.' );
		$this->assertSame( 0, did_action( 'bulkcat_batch_created' ), 'The runner was never told about the batch.' );
	}

	public function test_it_journals_exactly_what_a_bulk_batch_journals_for_the_same_change(): void {
		self::product(
			101,
			'simple',
			[
				'regular_price' => '20',
				'sale_price'    => '18',
				'sku'           => 'OLD-1',
			]
		);
		$values = [
			'sku'           => 'NEW-1',
			'regular_price' => '15',
			'sale_price'    => '12',
		];
		$result = $this->writes()->write( 101, $values, 'bulk', self::USER );
		$this->assertTrue( is_array( $result ) );
		$inline = $this->store->journal();
		$this->assertSame( 'bulk', $this->store->batch( $result['batch_id'] )['source'] );
		$this->assertSame( [ 'regular_price', 'sale_price', 'sku' ], array_column( $inline, 1 ), 'One row per field, in registry order.' );
		$this->assertSame( [ 'applied', 'applied', 'applied' ], array_column( $inline, 4 ) );

		// The same operations as a queued bulk batch, worked by the chunk runner.
		$operations = json_decode( (string) wp_json_encode( $this->writes()->operations( $values ) ), true );
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		self::product(
			101,
			'simple',
			[
				'regular_price' => '20',
				'sale_price'    => '18',
				'sku'           => 'OLD-1',
			]
		);
		$runner = new RunnerStore( 7, [ 101 ], $operations, self::USER );
		$status = ( new ChunkRunner( null, $this->actions, null, new Settings( [] ), static fn(): float => 1790000000.0, 30 ) )->run( 7 );

		$this->assertSame( 'complete', $status );
		$this->assertSame( $runner->journal(), $inline, 'Identical journal rows.' );
	}

	/**
	 * A product carrying its own Off record (T89).
	 *
	 * @param int    $id     Id.
	 * @param string $status Its status now.
	 * @param string $saved  The status its record saved.
	 */
	private static function off_product( int $id, string $status, string $saved ): StoredProduct {
		$product                                    = self::product( $id, 'simple', [ 'status' => $status ] );
		$product->meta['_bulkcat_availability_off'] = \BulkCatalogueManager\Availability\OffRecord::create( $id, $saved, '2026-09-01T08:00:00Z', 7 )->to_json();
		return $product;
	}

	public function test_an_inline_write_never_reaches_the_variations_so_its_steps_say_so(): void {
		// T94: the write takes the one object (BatchFactory::open()), so its steps, and the dry run
		// that checks them, carry Also apply to variations off, whatever the settings default: an
		// inline Off of a variable product is not refused for its variations, which it never touches.
		$settings = new Settings(
			[
				\BulkCatalogueManager\Settings\Schema::VARIATIONS_STATUS => true,
				\BulkCatalogueManager\Settings\Schema::VARIATIONS_PRICE  => true,
				\BulkCatalogueManager\Settings\Schema::VARIATIONS_STOCK  => true,
			]
		);
		$writes   = new InlineWrite( $this->actions, null, null, $settings );
		foreach ( [ [ 'status' => 'draft' ], [ 'status' => 'publish' ], [ 'regular_price' => '12' ], [ 'stock_status' => 'outofstock' ] ] as $values ) {
			$steps = $writes->operations( $values );
			$this->assertTrue( is_array( $steps ), wp_json_encode( $values ) );
			$this->assertFalse( $steps[0]['args']['apply_to_variations'] ?? false, wp_json_encode( $values ) );
		}
		$this->assertArrayNotHasKey( 'apply_to_variations', $writes->operations( [ 'catalog_visibility' => 'hidden' ] )[0]['args'], 'A product-only field has no such argument.' );
	}

	public function test_new_publish_ignores_a_legacy_conflict_and_journals_owned_record_removal(): void {
		$product = self::off_product( 102, 'private', 'publish' );
		$result  = $this->writes()->write( 102, [ 'status' => 'publish' ], 'inline', self::USER );
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( 'publish', $product->props['status'] );
		$this->assertSame( 1, $product->saves );
		$this->assertArrayNotHasKey( '_bulkcat_availability_off', $product->meta );
		$this->assertSame(
			[
				[ 102, 'status', 'private', 'publish', 'applied' ],
				[ 102, 'availability_off', '{"id":102,"status":"publish","at":"2026-09-01T08:00:00Z","batch":7}', '', 'applied' ],
			],
			$this->store->journal()
		);
	}

	public function test_explicit_keep_current_status_requires_reload_without_writes(): void {
		$product = self::off_product( 104, 'private', 'publish' );
		$refused = $this->assert_refused( $this->writes()->write( 104, [ 'status' => 'publish' ], 'inline', self::USER, true ), InlineWrite::INVALID, 400 );
		$this->assertStringContainsString( 'Reload and review', $refused->get_error_message() );
		$this->assertSame( 'private', $product->props['status'] );
		$this->assertArrayHasKey( '_bulkcat_availability_off', $product->meta );
		$refused = $this->assert_refused( $this->writes()->write( 101, [ 'regular_price' => '21' ], 'inline', self::USER, true ), InlineWrite::INVALID, 400 );
		$this->assertSame( 'keep_changed_status', $refused->get_error_data()['field'] );
	}

	public function test_already_draft_preserves_legacy_record_without_save_or_journal(): void {
		$product = self::off_product( 105, 'draft', 'publish' );
		$before  = $product->meta;
		$off     = $this->writes()->write( 105, [ 'status' => 'draft' ], 'inline', self::USER );
		$this->assertTrue( is_array( $off ) );
		$this->assertFalse( $off['outcome']->saved() );
		$this->assertSame( Result::ALREADY, $off['outcome']->results()[0]->state() );
		$this->assertSame( '', $off['outcome']->results()[0]->note() );
		$this->assertSame( 0, $product->saves );
		$this->assertSame( $before, $product->meta );
		$plain = self::product( 102, 'simple', [ 'status' => 'draft' ] );
		$draft = $this->writes()->write( 102, [ 'status' => 'draft' ], 'inline', self::USER );
		$this->assertSame( Result::ALREADY, $draft['outcome']->results()[0]->state() );
		$this->assertSame( '', $draft['outcome']->results()[0]->note() );
		$this->assertSame( 0, $plain->saves );
		$this->assertSame( [], $this->store->journal() );
	}

	public function test_inline_native_status_journals_the_same_rows_as_a_bulk_batch(): void {
		$product = self::product( 106, 'simple', [ 'status' => 'publish' ] );
		$off     = $this->writes()->write( 106, [ 'status' => 'draft' ], 'inline', self::USER );
		$this->assertSame( 'complete', $off['status'] );
		$inline = $this->store->journal();
		$this->assertSame( [ [ 106, 'status', 'publish', 'draft', 'applied' ] ], $inline );
		$this->assertSame( [], $product->meta );
		$operations = json_decode( (string) wp_json_encode( $this->writes()->operations( [ 'status' => 'draft' ] ) ), true );
		$this->assertSame( 'set_status', $operations[0]['action'] );
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_caps'] = [ self::USER => [ 'edit_post' => true ] ];
		$product                              = self::product( 106, 'simple', [ 'status' => 'publish' ] );
		$runner                               = new RunnerStore( 7, [ 106 ], $operations, self::USER );
		$this->assertSame( 'complete', ( new ChunkRunner( null, $this->actions, null, new Settings( [] ), static fn(): float => 1790000000.0, 30 ) )->run( 7 ) );
		$this->assertSame( $runner->journal(), $inline );
		$this->assertSame( [], $product->meta );
	}

	public function test_fields_become_one_step_each_in_registry_order(): void {
		$operations = $this->writes()->operations(
			[
				'sku'            => '',
				'sale_price'     => null,
				'stock_quantity' => '',
				'regular_price'  => '',
				'name'           => 'Blue Shirt',
				'id'             => 5,
			]
		);
		$this->assertTrue( is_array( $operations ) );
		$this->assertSame(
			[
				[ 'set_value', 'name' ],
				[ 'set_value', 'regular_price' ],
				[ 'clear', 'sale_price' ],
				[ 'set_value', 'stock_quantity' ],
				[ 'set_value', 'sku' ],
			],
			array_map( static fn( array $step ): array => [ $step['action'], $step['args']['field'] ], $operations ),
			'A read-only column is never a step; an empty clearable field is cleared.'
		);
		$this->assertSame( [ '', null, '' ], [ $operations[1]['args']['value'], $operations[3]['args']['value'], $operations[4]['args']['value'] ] );
		$this->assertFalse( $this->writes()->operations( [ 'regular_price' => '15' ] )[0]['args']['clear_invalid_sale'], 'One price alone does not touch the other.' );

		$pair = $this->writes()->operations(
			[
				'regular_price' => '15',
				'sale_price'    => '12',
			]
		);
		$this->assertTrue( $pair[0]['args']['clear_invalid_sale'], 'With both prices, the sale price step decides the pair.' );

		$refused = $this->writes()->operations( [ 'status' => '' ] );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'status', $refused->get_error_data()['field'] );
		$this->assertSame( 400, $refused->get_error_data()['status'] );
		$this->assertSame( 'bulkcat_no_operations', $this->writes()->operations( [ 'id' => 5 ] )->get_error_code() );
	}

	public function test_a_value_the_object_cannot_take_is_refused_naming_the_field_with_nothing_written(): void {
		$error = $this->assert_refused(
			$this->writes()->write(
				101,
				[
					'sku'        => 'FINE-1',
					'sale_price' => '30',
				],
				'inline',
				self::USER
			),
			'bulkcat_invalid_value',
			400
		);
		$this->assertSame( 'sale_price', $error->get_error_data()['field'] );
		$this->assertSame( [ 'sale_price' ], array_keys( $error->get_error_data()['fields'] ) );
		$this->assertStringContainsString( 'sale price', strtolower( $error->get_error_message() ) );

		$error = $this->assert_refused( $this->writes()->write( 101, [ 'stock_quantity' => '1.5' ], 'inline', self::USER ), 'bulkcat_invalid_operation', 400 );
		$this->assertSame( 'stock_quantity', $error->get_error_data()['field'] );
		$this->assertSame( ( new Fields() )->get( 'stock_quantity' )->rule_text(), $error->get_error_message() );
	}

	public function test_a_variation_takes_its_own_fields_and_refuses_parent_only_ones_with_their_reason(): void {
		self::product(
			201,
			'variation',
			[
				'regular_price' => '60',
				'parent_id'     => 103,
			]
		);
		$reason = ( new Fields() )->get( 'featured' )->unavailable_reason( $GLOBALS['bulkcat_test']['products'][201] );
		$this->assertNotNull( $reason );

		$error = $this->assert_refused(
			$this->writes()->write(
				201,
				[
					'regular_price' => '65',
					'featured'      => true,
				],
				'inline',
				self::USER
			),
			'bulkcat_not_available',
			400
		);
		$this->assertSame( 'featured', $error->get_error_data()['field'] );
		$this->assertSame( $reason, $error->get_error_message() );

		$result = $this->writes()->write( 201, [ 'regular_price' => '65' ], 'inline', self::USER );
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( [ [ 201, 'regular_price', '60', '65.00', 'applied' ] ], $this->store->journal() );
		$this->assertSame( 'variation', array_values( $this->store->items )[0]['object_type'] );
		$this->assertSame( [ 103 ], $GLOBALS['bulkcat_test']['variable_syncs'], 'The parent was resynchronised once.' );
	}

	public function test_an_object_the_user_may_not_edit_is_refused_403(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		$error = $this->assert_refused( $this->writes()->write( 101, [ 'regular_price' => '25' ], 'inline', self::USER ), 'bulkcat_cannot_edit', 403 );
		$this->assertStringContainsString( 'permission', $error->get_error_message() );
	}

	public function test_a_missing_or_trashed_id_is_refused_404(): void {
		$this->assert_refused( $this->writes()->write( 999, [ 'regular_price' => '25' ], 'inline', self::USER ), 'bulkcat_not_found', 404 );
		self::product( 108, 'simple', [ 'regular_price' => '5' ] );
		$this->assert_refused( $this->writes()->write( 108, [ 'regular_price' => '25' ], 'inline', self::USER ), 'bulkcat_not_found', 404 );
	}

	public function test_an_unexpected_error_is_logged_and_its_text_never_sent(): void {
		$secret                                   = 'SQLSTATE[42S02]: Table wp_secret missing in /var/www/html/wp-content/plugins/other/save-hook.php';
		$stored                                   = self::product( 101, 'simple', [ 'regular_price' => '20' ] );
		$GLOBALS['bulkcat_test']['products'][101] = new class( $stored, $secret ) {
			public function __construct( private StoredProduct $stored, private string $secret ) {}

			public function __clone() {
				$this->stored = clone $this->stored;
			}

			public function save(): int {
				throw new \Error( $this->secret ); // A third-party save hook failing deep inside WooCommerce.
			}

			public function __call( string $method, array $args ): mixed {
				return $this->stored->$method( ...$args );
			}
		};

		$result = $this->writes()->write( 101, [ 'regular_price' => '25' ], 'inline', self::USER );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( Writer::SAVE_FAILED, $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'details', $result->get_error_data() );
		$this->assertStringNotContainsString( 'SQLSTATE', (string) wp_json_encode( [ $result->get_error_message(), $result->get_error_data() ] ) );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['logged'] ?? [] );
		[ $level, $message, $context ] = $GLOBALS['bulkcat_test']['logged'][0];
		$this->assertSame( 'error', $level );
		$this->assertStringContainsString( $secret, $message, 'The site owner finds the real cause in the WooCommerce log.' );
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', $context['source'] );
	}

	public function test_a_value_already_in_place_finishes_complete_with_nothing_saved(): void {
		$result = $this->writes()->write( 101, [ 'regular_price' => '20.00' ], 'inline', self::USER );
		$this->assertTrue( is_array( $result ) );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( [ Result::ALREADY ], array_map( static fn( Result $r ): string => $r->state(), $result['outcome']->results() ) );
		$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
		$this->assertSame( [], $this->store->journal(), 'An unchanged value is not journalled.' );
		$this->assertSame( [ 1, 0, 0 ], [ $this->store->batch( $result['batch_id'] )['done_items'], $this->store->batch( $result['batch_id'] )['changed_items'], $this->store->batch( $result['batch_id'] )['failed_items'] ] );
	}
}
