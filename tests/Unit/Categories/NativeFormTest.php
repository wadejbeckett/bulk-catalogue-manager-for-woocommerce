<?php
/**
 * Saves made through WordPress's own category form (term.php, opened normally
 * or as All fields) are recorded in History (1.3.4): the category's name,
 * slug, description, parent, display type and image as journal rows that undo
 * can put back, and fields other plugins keep in term meta as "Other fields",
 * never by their keys. Only that form's own POST is recorded; this plugin's
 * own writes, quick edit and background work are not recorded twice.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Cache;
use BulkCatalogueManager\Categories\HistoryText;
use BulkCatalogueManager\Categories\NativeForm;
use BulkCatalogueManager\Categories\Writer;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';
require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class NativeFormTest extends TestCase {
	private const USER = 12;

	private InlineStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
		$GLOBALS['bulkcat_test']['is_admin']  = true;
		$this->store                          = new InlineStore();
		CatalogueFixture::seed();
		Cache::register_hooks();
		HistoryText::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [
			'action'   => 'editedtag',
			'taxonomy' => 'product_cat',
			'tag_ID'   => '3',
		];
	}

	protected function tearDown(): void {
		$_POST = [];
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * What WordPress, WooCommerce and another plugin do to Shirts (3) when its form is saved:
	 * the description changes, WooCommerce stores its display type and image fields, and
	 * the other plugin writes three keys of its own.
	 */
	private function save_through_the_form(): void {
		NativeForm::before( 3, 'product_cat' );
		get_term( 3, 'product_cat' )->description                    = 'Shirts for every day';
		$GLOBALS['bulkcat_test']['term_meta'][3]['display_type']     = '';
		$GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id']     = '0';
		$GLOBALS['bulkcat_test']['term_meta'][3]['vendor_seen_by_a'] = '0';
		$GLOBALS['bulkcat_test']['term_meta'][3]['vendor_seen_by_b'] = '0';
		$GLOBALS['bulkcat_test']['term_meta'][3]['vendor_users']     = '';
		NativeForm::after( 3 );
	}

	public function test_a_native_form_save_is_a_category_change_by_the_current_user(): void {
		$this->save_through_the_form();
		$rows = $this->store->journal();
		$this->assertSame( [ 3, 'term_description', '', 'Shirts for every day', 'applied' ], $rows[0] );
		$this->assertSame( [ 3, NativeForm::OTHER_PROP, 'applied' ], [ $rows[1][0], $rows[1][1], $rows[1][4] ] );
		$this->assertCount( 2, $rows, 'An image of 0 and Default display, stored for the first time, are no change.' );
		$batch = $this->store->batch( array_key_last( $this->store->batches ) );
		$this->assertSame( [ 'tree', self::USER, 'complete' ], [ $batch['source'], (int) $batch['user_id'], $batch['status'] ] );

		// History names the other plugins' fields only generically, never by key.
		$this->assertSame( 'Other fields', Writer::label( NativeForm::OTHER_PROP ) );
		$words = HistoryText::values(
			[
				'prop'      => NativeForm::OTHER_PROP,
				'old_value' => $rows[1][2],
				'new_value' => $rows[1][3],
			]
		);
		$this->assertSame( [ '', '3 fields changed' ], $words );
		$this->assertSame(
			'Saved category Shirts with WordPress’s category form',
			HistoryText::headline(
				[
					'source'     => 'tree',
					'operations' => json_decode( $batch['operations'], true ),
					'selection'  => [ 'ids' => [ 3 ] ],
				]
			)
		);
		$this->assertStringNotContainsString( 'vendor_', implode( ' ', $words ) );
	}

	public function test_only_the_form_itself_is_recorded(): void {
		$cases = [
			'quick edit'          => static function (): void {
				$GLOBALS['bulkcat_test']['doing_ajax'] = true;
			},
			'a REST write'        => static function (): void {
				$GLOBALS['bulkcat_test']['is_admin'] = false;
			},
			'another action'      => static function (): void {
				$_POST['action'] = 'add-tag';
			},
			'another category id' => static function (): void {
				$_POST['tag_ID'] = '4';
			},
			'another taxonomy'    => static function (): void {
				$_POST['taxonomy'] = 'product_tag';
			},
		];
		foreach ( $cases as $case => $change ) {
			$this->setUp();
			$change();
			$this->save_through_the_form();
			$this->assertSame( [], $this->store->journal(), "Not recorded: $case." );
		}
	}

	public function test_a_save_that_changes_nothing_records_nothing(): void {
		$GLOBALS['bulkcat_test']['term_meta'][3]['display_type'] = '';
		NativeForm::before( 3, 'product_cat' );
		NativeForm::after( 3 );
		$this->assertSame( [], $this->store->journal() );
	}
}
