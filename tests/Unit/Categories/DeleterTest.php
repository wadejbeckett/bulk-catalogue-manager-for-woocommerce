<?php
/**
 * Deleting and merging categories (ticket 24): WooCommerce's native
 * consequences through wp_delete_term() (subcategories move up, the category
 * comes off its products, a product left with none gets the default), a
 * preview stating them with a token apply() needs, the default category
 * and a category the user may not delete refused, Merge into… moving the
 * products first, and one journalled `tree` batch History shows and undo
 * never offers.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\Cache;
use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\Deleter;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Tests\Unit\Engine\InlineStore;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';
require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class DeleterTest extends TestCase {
	private const USER = 12;

	private InlineStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in']                      = true;
		$GLOBALS['bulkcat_test']['user_id']                        = self::USER;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]        = [
			'delete_term' => true,
			'assign_term' => true,
		];
		$GLOBALS['bulkcat_test']['options']['default_product_cat'] = 9;
		$this->store = new InlineStore();
		CatalogueFixture::seed();
		Cache::register_hooks();
		$GLOBALS['bulkcat_test']['object_terms'] = [];
		foreach ( CatalogueFixture::PRODUCTS as $id => [ $terms ] ) {
			foreach ( $terms as $term ) {
				$GLOBALS['bulkcat_test']['object_terms'][ $id ][] = [ 'product_cat', $term, '' ];
			}
		}
	}

	/**
	 * A product's categories in the stubs, sorted.
	 *
	 * @param int $id Product.
	 * @return list<int>
	 */
	private static function held( int $id ): array {
		$ids = array_map( static fn( array $held ): int => (int) $held[1], $GLOBALS['bulkcat_test']['object_terms'][ $id ] ?? [] );
		sort( $ids );
		return $ids;
	}

	public function test_a_delete_states_its_consequences_then_has_them_natively(): void {
		$deleter = new Deleter();
		// Shirts (3) holds Formal (4); Men (2) holds Shirts. 101 is in Formal only, 102 in Shirts and Formal, 103 in Men only.
		$preview = $deleter->preview( [ 3, 2 ], 0, self::USER );
		$this->assertTrue( is_array( $preview ), $preview instanceof \WP_Error ? $preview->get_error_message() : '' );
		$this->assertSame(
			[
				[
					'id'   => 3,
					'name' => 'Shirts',
					'path' => 'Clothing › Men › Shirts',
				],
				[
					'id'   => 2,
					'name' => 'Men',
					'path' => 'Clothing › Men',
				],
			],
			$preview['categories']
		);
		$this->assertSame( 1, $preview['subcategories'], 'Formal moves up.' );
		$this->assertSame( 2, $preview['products'], '102 and 103.' );
		$this->assertSame( 1, $preview['fallback'], '103 has no other category.' );
		$this->assertSame(
			[
				'id'   => 9,
				'path' => 'Orphans',
			],
			$preview['default']
		);
		$this->assertSame( [], $GLOBALS['bulkcat_test']['deleted_terms'] ?? [], 'A preview deletes nothing.' );

		$done = $deleter->apply( [ 3, 2 ], 0, $preview['token'], self::USER );
		$this->assertTrue( is_array( $done ), $done instanceof \WP_Error ? $done->get_error_message() : '' );
		$this->assertSame( [ 'complete', [ 3, 2 ], [] ], [ $done['status'], $done['deleted'], $done['failed'] ] );
		$this->assertSame( [ [ 3, 'product_cat' ], [ 2, 'product_cat' ] ], $GLOBALS['bulkcat_test']['deleted_terms'] );
		$this->assertSame( [ [ 103, [ 9 ], 'product_cat', false ] ], array_values( array_filter( $GLOBALS['bulkcat_test']['set_object_terms'], static fn( array $call ): bool => 103 === $call[0] ) ), 'WordPress\'s own default argument put it in the default category.' );
		$this->assertSame( 1, CategoryTree::tree()->node( 4 )['parent'], 'Formal moved up to Clothing.' );
		$this->assertSame( [ 4 ], self::held( 102 ) );
		$this->assertSame( [ 9 ], self::held( 103 ), 'Left with none, it got the default category.' );
		$this->assertSame( [ 4 ], self::held( 101 ), 'Untouched.' );

		$this->assertSame(
			[
				[ 3, Deleter::DELETED_PROP, 'Clothing › Men › Shirts', '', 'applied' ],
				[ 2, Deleter::DELETED_PROP, 'Clothing › Men', '', 'applied' ],
				[ 4, 'term_parent', 3, 1, 'applied' ],
				[ 102, 'category_ids', [ 3, 4 ], [ 4 ], 'applied' ],
				[ 103, 'category_ids', [ 2 ], [ 9 ], 'applied' ],
			],
			$this->store->journal()
		);
		$batch = $this->store->batch( $done['batch_id'] );
		$this->assertSame( [ 'tree', 'complete', 2, 2 ], [ $batch['source'], $batch['status'], (int) $batch['total_items'], (int) $batch['changed_items'] ] );
		$step = json_decode( $batch['operations'], true )[0];
		$this->assertSame( Deleter::DELETE, $step['action'] );
		$this->assertSame(
			[
				'3' => 'Clothing › Men › Shirts',
				'2' => 'Clothing › Men',
			],
			$step['args']['names']
		);
	}

	public function test_the_default_category_cannot_be_deleted_or_merged(): void {
		foreach ( [ 0, 1 ] as $into ) {
			$refused = ( new Deleter() )->preview( [ 5, 9 ], $into, self::USER );
			$this->assertInstanceOf( \WP_Error::class, $refused );
			$this->assertSame( Deleter::IS_DEFAULT, $refused->get_error_code() );
			$this->assertSame( '"Orphans" is the shop\'s default category, so it cannot be deleted. Make another category the default first.', $refused->get_error_message() );
		}
		$this->assertInstanceOf( \WP_Error::class, ( new Deleter() )->apply( [ 9 ], 0, 'any', self::USER ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['deleted_terms'] ?? [] );
	}

	public function test_deleting_needs_permission_for_each_category(): void {
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['delete_term:5'] = false;
		$refused = ( new Deleter() )->preview( [ 3, 5 ], 0, self::USER );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertSame( 'You do not have permission to delete "Women". Ask an administrator for access.', $refused->get_error_message() );
		$missing = ( new Deleter() )->preview( [ 3, 999 ], 0, self::USER );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
		$this->assertSame( Deleter::NONE_CHOSEN, ( new Deleter() )->preview( [], 0, self::USER )->get_error_code() );
	}

	public function test_a_change_after_the_preview_is_refused_and_nothing_is_deleted(): void {
		$preview                                        = ( new Deleter() )->preview( [ 5 ], 0, self::USER );
		$GLOBALS['bulkcat_test']['object_terms'][110][] = [ 'product_cat', 5, '' ];
		$stale = ( new Deleter() )->apply( [ 5 ], 0, $preview['token'], self::USER );
		$this->assertInstanceOf( \WP_Error::class, $stale );
		$this->assertSame( [ 'bulkcat_preview_stale', 409 ], [ $stale->get_error_code(), $stale->get_error_data()['status'] ] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['deleted_terms'] ?? [] );
		$this->assertSame( [], $this->store->changes );
	}

	public function test_a_category_wordpress_will_not_delete_is_reported_and_the_rest_go(): void {
		$GLOBALS['bulkcat_test']['delete_term_error'][5] = false;
		$deleter = new Deleter();
		$done    = $deleter->apply( [ 3, 5 ], 0, $deleter->preview( [ 3, 5 ], 0, self::USER )['token'], self::USER );
		$this->assertSame( [ 'partial', [ 3 ] ], [ $done['status'], $done['deleted'] ] );
		$this->assertSame( 5, $done['failed'][0]['id'] );
		$this->assertContains( [ 5, Deleter::DELETED_PROP, 'Clothing › Women', 'Clothing › Women', 'failed' ], $this->store->journal() );
	}

	public function test_merge_moves_the_products_into_the_target_then_deletes(): void {
		$moved   = [];
		$mover   = static function ( int $product_id, array $remove, int $add ) use ( &$moved ): void {
			$moved[] = [ $product_id, $remove, $add ];
			$ids     = array_values( array_diff( array_map( static fn( array $held ): int => (int) $held[1], $GLOBALS['bulkcat_test']['object_terms'][ $product_id ] ), $remove ) );
			wp_set_object_terms( $product_id, array_values( array_unique( array_merge( $ids, [ $add ] ) ) ), 'product_cat' );
		};
		$deleter = new Deleter( null, null, $mover );
		// Merge Men and Women into Shoes: products 103, 104 and 106 move.
		$preview = $deleter->preview( [ 2, 5 ], 6, self::USER );
		$this->assertSame( [ 3, 0, 1 ], [ $preview['products'], $preview['fallback'], $preview['subcategories'] ] );
		$this->assertSame(
			[
				'id'   => 6,
				'path' => 'Shoes',
			],
			$preview['into']
		);
		$done = $deleter->apply( [ 2, 5 ], 6, $preview['token'], self::USER );
		$this->assertSame( 'complete', $done['status'] );
		$this->assertSame( [ [ 103, [ 2, 5 ], 6 ], [ 104, [ 2, 5 ], 6 ], [ 106, [ 2, 5 ], 6 ] ], $moved );
		$this->assertSame( [ 6 ], self::held( 103 ) );
		$this->assertSame( [ 1, 6 ], self::held( 106 ) );
		$this->assertSame( [], array_values( array_filter( range( 101, 109 ), static fn( int $id ): bool => in_array( 9, self::held( $id ), true ) && ! in_array( $id, [ 107 ], true ) ) ), 'Nothing fell back to the default.' );
		$this->assertSame( Deleter::MERGE, json_decode( $this->store->batch( $done['batch_id'] )['operations'], true )[0]['action'] );
		$this->assertSame( 'Shoes', json_decode( $this->store->batch( $done['batch_id'] )['operations'], true )[0]['args']['path'] );
	}

	public function test_merge_needs_a_target_outside_those_merged(): void {
		foreach ( [ 2, 999 ] as $into ) {
			$this->assertSame( Deleter::BAD_TARGET, ( new Deleter() )->preview( [ 2, 5 ], $into, self::USER )->get_error_code() );
		}
	}

	public function test_a_delete_or_merge_is_never_offered_for_undo(): void {
		foreach ( Deleter::PERMANENT as $action ) {
			bulkcat_test_reset();
			$store                  = new RunnerStore(
				77,
				[ [ 5, 'term' ] ],
				[
					[
						'action' => $action,
						'args'   => [ 'ids' => [ 5 ] ],
					],
				],
				self::USER
			);
			$store->batch['status'] = 'complete';
			$store->batch['source'] = 'tree';
			$store->changes[]       = [
				'batch_id'     => 77,
				'object_id'    => 5,
				'object_type'  => 'term',
				'prop'         => Deleter::DELETED_PROP,
				'old_value'    => 'Clothing › Women',
				'new_value'    => '',
				'modified_gmt' => '0000-00-00 00:00:00',
				'applied_gmt'  => '2026-09-23 08:01:00',
				'result'       => 'applied',
				'message'      => '',
			];
			$batch                  = ( new \BulkCatalogueManager\Data\BatchRepository() )->find( 77 );
			$state                  = ( new Reversibility( null, null, new \BulkCatalogueManager\Settings\Settings( [] ), static fn(): int => 1790208000 ) )->of( $batch );
			$this->assertSame( [ Reversibility::NOT_REVERSIBLE, Reversibility::PERMANENT ], [ $state['state'], $state['reason'] ], $action );
			$refused = ( new \BulkCatalogueManager\Undo\Planner( null, null, null, new \BulkCatalogueManager\Settings\Settings( [] ), static fn(): int => 1790208000 ) )->plan( 77 );
			$this->assertInstanceOf( \WP_Error::class, $refused );
			$this->assertSame( Reversibility::PERMANENT, $refused->get_error_data()['reason'] );
			$this->assertSame( 'A deleted category cannot be brought back, so this change cannot be undone.', $refused->get_error_message() );
		}
	}
}
