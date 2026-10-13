<?php
/**
 * Ticket 11 item 2: what a chunk leaves behind in a long WP-CLI queue run.
 * WordPress counts every hook name it has fired (did_action(), did_filter())
 * for the life of the process, and WooCommerce's product save clears five
 * transients named after the product (delete_transient_wc_var_prices_{id},
 * …), so a single `wp action-scheduler run` kept about 250 new names per
 * chunk of 50 products, for ever. Memory::release( $mark ) forgets the
 * per-product transient hook names first counted since the mark, and
 * nothing else: only the families measured (the lead review), never
 * another plugin's name that happens to end in a number, never a callback,
 * never the hook stack.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Engine\Memory;
use PHPUnit\Framework\TestCase;

final class MemoryTest extends TestCase {
	/**
	 * The hook counters before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	protected function setUp(): void {
		bulkcat_test_reset();
		foreach ( [ 'wp_actions', 'wp_filters', 'wp_filter', 'wp_current_filter' ] as $name ) {
			$this->saved[ $name ] = $GLOBALS[ $name ] ?? null;
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $name => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $value;
			}
		}
	}

	private static function flushes(): int {
		return count( array_filter( $GLOBALS['bulkcat_test']['calls'] ?? [], static fn( array $call ): bool => 'wp_cache_flush_runtime' === $call[0] ) );
	}

	public function test_release_forgets_the_per_object_hook_names_first_counted_since_the_mark(): void {
		$GLOBALS['wp_actions'] = [
			'init'                                   => 1,
			'delete_transient_wc_var_prices_7'       => 1,
			'woocommerce_before_product_object_save' => 3,
		];
		$GLOBALS['wp_filters'] = [
			'the_content'        => 2,
			'pre_transient_wc_7' => 1,
		];
		$mark                  = Memory::mark();

		// A chunk's writes.
		$GLOBALS['wp_actions']['woocommerce_before_product_object_save'] = 53;
		$GLOBALS['wp_actions']['delete_transient_wc_var_prices_7']       = 2;
		foreach ( [ 101, 102, 103 ] as $id ) {
			foreach ( [ 'wc_product_children_', 'wc_var_prices_', 'wc_related_' ] as $transient ) {
				$GLOBALS['wp_actions'][ 'delete_transient_' . $transient . $id ] = 1;
			}
			$GLOBALS['wp_actions'][ 'delete_option__transient_wc_var_prices_' . $id ] = 1;
			$GLOBALS['wp_filters'][ 'pre_transient_wc_related_' . $id ]               = 1;
		}
		$GLOBALS['wp_actions']['woocommerce_update_product']    = 50;
		$GLOBALS['wp_filters']['woocommerce_product_get_price'] = 50;

		Memory::release( $mark );

		$this->assertSame(
			[
				'init'                                   => 1,
				'delete_transient_wc_var_prices_7'       => 2,
				'woocommerce_before_product_object_save' => 53,
				'woocommerce_update_product'             => 50,
			],
			$GLOBALS['wp_actions'],
			'Names counted before the mark keep their counts; a new name that is not per object stays.'
		);
		$this->assertSame(
			[
				'the_content'                   => 2,
				'pre_transient_wc_7'            => 1,
				'woocommerce_product_get_price' => 50,
			],
			$GLOBALS['wp_filters']
		);
		$this->assertSame( 1, self::flushes(), 'The runtime cache is released too.' );
	}

	public function test_another_plugins_names_ending_in_a_number_keep_their_counts(): void {
		$GLOBALS['wp_actions'] = [ 'init' => 1 ];
		$GLOBALS['wp_filters'] = [];
		$mark                  = Memory::mark();
		$kept                  = [
			'my_sync_77'                               => 1, // Another plugin's own did_action( 'my_sync_77' ).
			'delete_transient_my_cache_77'             => 2, // Another plugin's transient.
			'delete_transient_wc_admin_report_12'      => 1, // A WooCommerce transient that is not per product.
			'delete_transient_wc_var_prices_12x'       => 1, // Not a product id.
			'delete_transient_wc_var_prices_'          => 1, // No id at all.
			'woocommerce_order_status_changed_5'       => 1,
			'xdelete_transient_wc_var_prices_12'       => 1, // Not the family's own prefix.
			'update_option__transient_wc_var_prices_9' => 1, // A family that was not measured.
		];
		foreach ( $kept as $name => $count ) {
			$GLOBALS['wp_actions'][ $name ] = $count;
		}
		$GLOBALS['wp_filters']['my_filter_2024']                      = 3;
		$GLOBALS['wp_actions']['delete_transient_wc_var_prices_12']   = 1;
		$GLOBALS['wp_filters']['pre_option__transient_wc_related_12'] = 1;

		Memory::release( $mark );

		$this->assertSame( [ 'init' => 1 ] + $kept, $GLOBALS['wp_actions'], 'Only the per-product transient name goes.' );
		$this->assertSame( [ 'my_filter_2024' => 3 ], $GLOBALS['wp_filters'] );
	}

	public function test_callbacks_and_the_hook_stack_are_untouched(): void {
		$GLOBALS['wp_actions']        = [];
		$GLOBALS['wp_filters']        = [];
		$GLOBALS['wp_filter']         = [
			'delete_transient_wc_var_prices_101' => 'callbacks for the per-product hook',
			'my_sync_77'                         => 'callbacks for another plugin',
		];
		$GLOBALS['wp_current_filter'] = [ 'action_scheduler_run_queue', 'bulkcat_run_chunk' ];
		$callbacks                    = $GLOBALS['wp_filter'];
		$stack                        = $GLOBALS['wp_current_filter'];
		add_action( 'delete_transient_wc_var_prices_101', 'strlen' );
		$registered = $GLOBALS['bulkcat_test']['filters'];
		$mark       = Memory::mark();
		$GLOBALS['wp_actions']['delete_transient_wc_var_prices_101'] = 1;
		$GLOBALS['wp_actions']['my_sync_77']                         = 1;

		Memory::release( $mark );

		$this->assertSame( [ 'my_sync_77' => 1 ], $GLOBALS['wp_actions'] );
		$this->assertSame( $callbacks, $GLOBALS['wp_filter'], 'Callbacks stay registered, the per-product hook\'s too.' );
		$this->assertSame( $registered, $GLOBALS['bulkcat_test']['filters'] );
		$this->assertSame( $stack, $GLOBALS['wp_current_filter'], 'The hook stack is untouched.' );
	}

	public function test_the_measured_families_are_listed(): void {
		// Measured on the scale fixture (ticket 11): Hide, Show and a price change with variations.
		foreach ( [ 'wc_product_children_', 'wc_var_prices_', 'wc_related_', 'wc_child_has_weight_', 'wc_child_has_dimensions_' ] as $transient ) {
			foreach ( [ 'delete_transient_', 'pre_transient_', 'transient_', 'pre_set_transient_', 'expiration_of_transient_', 'set_transient_' ] as $family ) {
				$this->assertTrue( Memory::per_product( $family . $transient . '4711' ), $family . $transient );
			}
			foreach ( [ 'pre_option_', 'default_option_', 'sanitize_option_', 'add_option_', 'delete_option_' ] as $family ) {
				$this->assertTrue( Memory::per_product( $family . '_transient_' . $transient . '4711' ), $family . '_transient_' . $transient );
				$this->assertTrue( Memory::per_product( $family . '_transient_timeout_' . $transient . '4711' ), $family . '_transient_timeout_' . $transient );
			}
		}
		$this->assertFalse( Memory::per_product( 'my_sync_77' ) );
		$this->assertFalse( Memory::per_product( 'delete_transient_wc_var_prices_' ) );
	}

	public function test_a_chunk_after_chunk_keeps_the_counters_flat(): void {
		$GLOBALS['wp_actions'] = [ 'init' => 1 ];
		$GLOBALS['wp_filters'] = [];
		$sizes                 = [];
		for ( $chunk = 0; $chunk < 5; $chunk++ ) {
			$mark = Memory::mark();
			for ( $n = 0; $n < 50; $n++ ) {
				$GLOBALS['wp_actions'][ 'delete_transient_wc_related_' . ( $chunk * 50 + $n ) ] = 1;
			}
			Memory::release( $mark );
			$sizes[] = count( $GLOBALS['wp_actions'] );
		}
		$this->assertSame( [ 1, 1, 1, 1, 1 ], $sizes );
	}

	public function test_release_without_a_mark_only_releases_the_cache(): void {
		$GLOBALS['wp_actions'] = [ 'delete_transient_wc_related_9' => 1 ];
		Memory::release();
		$this->assertSame( [ 'delete_transient_wc_related_9' => 1 ], $GLOBALS['wp_actions'] );
		$this->assertSame( 1, self::flushes() );
	}

	public function test_the_counters_are_forgotten_even_when_the_cache_cannot_flush(): void {
		$GLOBALS['bulkcat_test']['no_flush_runtime'] = true;
		$GLOBALS['wp_actions']                       = [];
		$mark                                        = Memory::mark();
		$GLOBALS['wp_actions']['delete_transient_wc_related_9'] = 1;
		Memory::release( $mark );
		$this->assertSame( [], $GLOBALS['wp_actions'], 'The counters are forgotten whatever the cache can do.' );
		$this->assertSame( 0, self::flushes() );
	}

	public function test_missing_counters_are_harmless(): void {
		unset( $GLOBALS['wp_actions'], $GLOBALS['wp_filters'] );
		$mark = Memory::mark();
		Memory::release( $mark );
		$this->assertArrayNotHasKey( 'wp_actions', $GLOBALS );
		$this->assertSame( [ 0, 0 ], $mark );
	}
}
