<?php
/**
 * Native publication lifecycle regression tests.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Publishing;

use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class StatusPermissionTest extends TestCase {
	public function test_recorded_actor_cannot_borrow_admin_for_a_coupled_change(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps']          = [
			'edit_post'        => true,
			'publish_products' => true,
		];
		$GLOBALS['bulkcat_test']['user_caps'][23] = [
			'edit_post'        => true,
			'publish_products' => false,
		];
		$fields                                   = new Fields();
		$actions                                  = new Registry( $fields );
		$product                                  = new SavedProduct(
			'simple',
			[
				'id'            => 501,
				'status'        => 'draft',
				'post_password' => ' secret ',
			]
		);
		$args                                     = [
			'field' => 'status',
			'value' => 'private',
		];
		$validator                                = Validator::for_user( $fields, 23 );
		$checked                                  = $validator->check( $product, $actions->get( 'set_status' )->compute( $product, $args ), $args );
		$this->assertSame( Result::SKIPPED, $checked[0]->state() );
		$this->assertSame( [], $validator->follow( $actions->get( 'set_status' ), $product, $checked[0], $args ) );
		$this->assertSame( [], $product->sets );
		$this->assertSame( [], $product->saves );
	}
}
