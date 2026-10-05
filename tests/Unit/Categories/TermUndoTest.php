<?php
/**
 * Undoing category writes (ticket 133 bug 1; SPEC §7, §9.4): a rename, a
 * move, a reorder, a description, a display type, an image, the default
 * category and a created category are planned as revertible and put back
 * exactly, with the usual changed-since protection (left alone unless
 * Revert anyway), and the undo journals what it put back so it can itself
 * be undone.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Categories;

use BulkCatalogueManager\Categories\CategoryTree;
use BulkCatalogueManager\Categories\TermUndo;
use BulkCatalogueManager\Categories\Writer;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CatalogueFixture.php';
require_once dirname( __DIR__ ) . '/Engine/RunnerStore.php';

final class TermUndoTest extends TestCase {
	private const BATCH = 40;

	private const UNDO = 41;

	private const USER = 3;

	private const NOW = 1790208000;

	private RunnerStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_id']   = self::USER;
		$GLOBALS['bulkcat_test']['user_caps'] = [
			self::USER => [
				'manage_product_terms' => true,
				'edit_term'            => true,
			],
		];
		CatalogueFixture::seed();
		$this->store                        = new RunnerStore(
			self::BATCH,
			[ [ 3, 'term' ] ],
			[
				[
					'action' => Writer::EDIT,
					'args'   => [],
				],
			],
			self::USER
		);
		$this->store->batch['status']       = 'complete';
		$this->store->batch['source']       = 'tree';
		$this->store->batch['done_items']   = 1;
		$this->store->batch['finished_gmt'] = '2026-09-23 08:05:00';
	}

	/**
	 * A journal row of the batch undone.
	 */
	private function change( int $id, string $prop, mixed $old, mixed $new ): void {
		$this->store->changes[] = [
			'batch_id'     => self::BATCH,
			'object_id'    => $id,
			'object_type'  => 'term',
			'prop'         => $prop,
			'old_value'    => $old,
			'new_value'    => $new,
			'modified_gmt' => '0000-00-00 00:00:00',
			'applied_gmt'  => '2026-09-23 08:01:00',
			'result'       => 'applied',
			'message'      => '',
		];
	}

	private function planner(): Planner {
		return new Planner( null, null, new Registry( new Fields() ), new Settings( [] ), static fn(): int => self::NOW );
	}

	/**
	 * The plan's one item for a term.
	 *
	 * @return array<string, mixed>
	 */
	private function item( int $id ): array {
		$plan = $this->planner()->plan( self::BATCH );
		$this->assertInstanceOf( Plan::class, $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		foreach ( $plan->items() as $item ) {
			if ( $item['object_id'] === $id ) {
				return $item;
			}
		}
		$this->fail( "No item for $id." );
	}

	/**
	 * Write an item as the runner does, the way it stands now unless forced.
	 *
	 * @return array{0: string, 1: string, 2: bool}
	 */
	private function write( int $id, bool $force = false ): array {
		$item = $this->item( $id );
		$now  = $force ? null : $this->planner()->items( self::BATCH, [ $id ] )[ $id ];
		return TermUndo::write( new ChangeRepository(), self::UNDO, $id, $item['operations'], $now );
	}

	/**
	 * The undo batch's journal rows: [ object, prop, old, new, result ].
	 *
	 * @return list<array{0: int, 1: string, 2: mixed, 3: mixed, 4: string}>
	 */
	private function undo_rows(): array {
		$this->store->sync();
		$rows = [];
		foreach ( $this->store->changes as $row ) {
			if ( self::UNDO === (int) $row['batch_id'] ) {
				$rows[] = [ (int) $row['object_id'], $row['prop'], $row['old_value'], $row['new_value'], $row['result'] ];
			}
		}
		return $rows;
	}

	public function test_a_rename_and_its_details_are_put_back_exactly(): void {
		// The batch renamed Shirts (3) to Tops, gave it a description, a display type and an image.
		$term              = get_term( 3, 'product_cat' );
		$term->name        = 'Tops';
		$term->slug        = 'tops';
		$term->description = 'All tops';
		$GLOBALS['bulkcat_test']['term_meta'][3]['display_type'] = 'both';
		$GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id'] = 504;
		$this->change( 3, 'term_name', 'Shirts', 'Tops' );
		$this->change( 3, 'term_slug', 'shirts', 'tops' );
		$this->change( 3, 'term_description', '', 'All tops' );
		$this->change( 3, 'term_display_type', '', 'both' );
		$this->change( 3, 'term_thumbnail_id', 0, 504 );

		$batch = ( new \BulkCatalogueManager\Data\BatchRepository() )->find( self::BATCH );
		$this->assertSame( Reversibility::REVERSIBLE, ( new Reversibility( null, null, new Settings( [] ), static fn(): int => self::NOW ) )->of( $batch )['state'], 'Undo is offered, and now it works.' );

		$item = $this->item( 3 );
		$this->assertSame( [ Plan::REVERTIBLE, 'term' ], [ $item['state'], $item['object_type'] ] );
		$this->assertSame( [ TermUndo::ACTION ], array_values( array_unique( array_column( $item['operations'], 'action' ) ) ) );

		$this->assertSame( [ 'done', '', true ], $this->write( 3 ) );
		$term = get_term( 3, 'product_cat' );
		$this->assertSame( [ 'Shirts', 'shirts', '', 2 ], [ $term->name, $term->slug, $term->description, $term->parent ] );
		$this->assertSame( [ '', 0 ], [ $GLOBALS['bulkcat_test']['term_meta'][3]['display_type'], $GLOBALS['bulkcat_test']['term_meta'][3]['thumbnail_id'] ] );
		$this->assertSame(
			[
				[ 3, 'term_name', 'Tops', 'Shirts', 'applied' ],
				[ 3, 'term_slug', 'tops', 'shirts', 'applied' ],
				[ 3, 'term_description', 'All tops', '', 'applied' ],
				[ 3, 'term_display_type', 'both', '', 'applied' ],
				[ 3, 'term_thumbnail_id', 504, 0, 'applied' ],
			],
			$this->undo_rows(),
			'Journalled so the undo can itself be undone.'
		);
	}

	public function test_a_move_and_a_reorder_are_put_back_with_woocommerce_order(): void {
		// The batch moved Shirts (3) from Men (2) to Women (5), order 3 -> 7.
		get_term( 3, 'product_cat' )->parent              = 5;
		$GLOBALS['bulkcat_test']['term_meta'][3]['order'] = '7';
		$this->change( 3, 'term_parent', 2, 5 );
		$this->change( 3, Writer::ORDER_PROP, 3, 7 );
		$this->assertSame( [ 'done', '', true ], $this->write( 3 ) );
		$this->assertSame( 2, CategoryTree::tree()->node( 3 )['parent'] );
		$this->assertSame( 3, (int) $GLOBALS['bulkcat_test']['term_meta'][3]['order'] );

		// An order that did not exist before is removed again.
		$this->store->changes = [];
		$this->change( 5, Writer::ORDER_PROP, null, 0 );
		$GLOBALS['bulkcat_test']['term_meta'][5]['order'] = 0;
		$this->assertSame( 'done', $this->write( 5 )[0] );
		$this->assertArrayNotHasKey( 'order', $GLOBALS['bulkcat_test']['term_meta'][5] ?? [] );
	}

	public function test_a_category_changed_since_is_left_alone_unless_revert_anyway(): void {
		get_term( 3, 'product_cat' )->name = 'Blouses';
		$this->change( 3, 'term_name', 'Shirts', 'Tops' );
		$item = $this->item( 3 );
		$this->assertSame( [ Plan::CONFLICTED, Planner::CHANGED_SINCE ], [ $item['state'], $item['code'] ] );
		$this->assertSame(
			[
				[
					'field'    => 'term_name',
					'expected' => 'Tops',
					'current'  => 'Blouses',
				],
			],
			$item['conflicts']
		);
		$this->assertSame( 'skipped', $this->write( 3 )[0] );
		$this->assertSame( 'Blouses', get_term( 3, 'product_cat' )->name );
		$this->assertSame( [], $this->undo_rows() );

		$this->assertSame( [ 'done', '', true ], $this->write( 3, true ), 'Revert anyway.' );
		$this->assertSame( 'Shirts', get_term( 3, 'product_cat' )->name );
	}

	public function test_the_default_category_is_put_back(): void {
		$GLOBALS['bulkcat_test']['options']['default_product_cat'] = 5;
		$this->change( 5, Writer::DEFAULT_PROP, 9, 5 );
		$this->assertSame( Plan::REVERTIBLE, $this->item( 5 )['state'] );
		$this->assertSame( 'done', $this->write( 5 )[0] );
		$this->assertSame( 9, $GLOBALS['bulkcat_test']['options']['default_product_cat'] );
	}

	public function test_a_created_category_is_removed_while_it_is_empty(): void {
		$this->change( 9, 'term_name', null, 'Orphans' );
		$this->change( 9, 'term_slug', null, 'orphans' );
		$this->change( 9, 'term_parent', null, 99 );
		$GLOBALS['bulkcat_test']['object_terms'] = [ 107 => [ [ 'product_cat', 9, '' ] ] ];
		$item                                    = $this->item( 9 );
		$this->assertSame( [ Plan::SKIPPED, Planner::CANNOT_RESTORE ], [ $item['state'], $item['code'] ], 'It has a product now.' );

		$GLOBALS['bulkcat_test']['object_terms'] = [];
		$this->assertSame( Plan::REVERTIBLE, $this->item( 9 )['state'] );
		$this->assertSame( [ 'done', '', true ], $this->write( 9 ) );
		$this->assertNull( CategoryTree::tree()->node( 9 ) );
		$this->assertSame( [ [ 9, 'term_deleted', 'Orphans', '', 'applied' ] ], $this->undo_rows() );
	}

	public function test_only_the_retired_tree_order_cannot_be_put_back(): void {
		$this->change( 3, Writer::LEGACY_ORDER_PROP, null, 0 );
		$item = $this->item( 3 );
		$this->assertSame( [ Plan::SKIPPED, Planner::CANNOT_RESTORE ], [ $item['state'], $item['code'] ] );
	}
}
