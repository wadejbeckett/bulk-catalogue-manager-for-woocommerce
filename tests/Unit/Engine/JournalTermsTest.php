<?php
/**
 * Ticket 133 bug 2: a product change that both adds and removes categories
 * journals the categories the product really holds afterwards. WooCommerce
 * merges a shorter list into the one it had (WC_Data::apply_changes() uses
 * array_replace_recursive()), so after save() the object still lists a
 * removed category while WordPress stores the right ones; the journal
 * recorded the object's list, and undo then reported the product as
 * "changed since". The journal reads the stored terms instead.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Engine;

use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class JournalTermsTest extends TestCase {
	private const BATCH = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps']  = [
			'edit_post'            => true,
			'assign_product_terms' => true,
		];
		$GLOBALS['bulkcat_test']['terms'] = [ 'product_cat' => [ 76, 78 ] ];
		$GLOBALS['wpdb']->responses['/^SELECT change_id FROM `wp_bulkcat_changes` WHERE batch_id = (\d+) AND object_id = (\d+) LIMIT 1$/'] = static fn(): ?string => null;
	}

	/**
	 * The journal rows written: [ prop, old, new, result ].
	 *
	 * @return list<array{0: string, 1: mixed, 2: mixed, 3: string}>
	 */
	private static function rows(): array {
		$rows = [];
		foreach ( $GLOBALS['wpdb']->writes as [ $method, $table, $data ] ) {
			if ( 'wp_bulkcat_changes' !== $table ) {
				continue;
			}
			if ( 'insert' === $method ) {
				$rows[] = [ $data['prop'], ValueCodec::decode( $data['old_value'] ), ValueCodec::decode( $data['new_value'] ), $data['result'] ];
			} elseif ( 'update' === $method ) {
				$last             = count( $rows ) - 1;
				$rows[ $last ][2] = ValueCodec::decode( $data['new_value'] );
				$rows[ $last ][3] = $data['result'];
			}
		}
		return $rows;
	}

	public function test_adding_a_category_held_and_removing_another_journals_the_stored_list(): void {
		// Already in Pots (76) and Saucepans (78): add Pots (already there), remove Saucepans.
		$product                                      = new SavedProduct(
			'simple',
			[
				'id'           => 501,
				'category_ids' => [ 76, 78 ],
			]
		);
		$product->merge_lists                         = true;
		$GLOBALS['bulkcat_test']['products'][501]     = $product;
		$GLOBALS['bulkcat_test']['object_terms'][501] = [ [ 'product_cat', 76, 'pots' ], [ 'product_cat', 78, 'saucepans' ] ];
		$operations                                   = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'add_terms',
					'args'   => [
						'field' => 'category_ids',
						'terms' => [ 76 ],
					],
				],
				[
					'action' => 'remove_terms',
					'args'   => [
						'field' => 'category_ids',
						'terms' => [ 78 ],
					],
				],
			]
		);
		$this->assertTrue( is_array( $operations ) );
		Writer::for_batch( new Registry( new Fields() ), new Journal( new ChangeRepository(), new Fields(), false ), self::BATCH )->write( 501, $operations );

		$this->assertSame( [ 76, 78 ], $product->get_category_ids(), 'WooCommerce\'s object keeps the stale tail.' );
		$this->assertSame( [ [ 'category_ids', [ 76, 78 ], [ 76 ], 'applied' ] ], self::rows(), 'The journal holds what the product really has.' );
	}
}
