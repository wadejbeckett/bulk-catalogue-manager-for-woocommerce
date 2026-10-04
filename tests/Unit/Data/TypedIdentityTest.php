<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class TypedIdentityTest extends TestCase {
	public function test_typed_storage_has_additive_taxonomy_and_full_tuple_indexes(): void {
		foreach ( [ Schema::ITEMS, Schema::CHANGES ] as $table ) {
			$ddl = Schema::TABLES[ $table ];
			$this->assertStringContainsString( "target_taxonomy varchar(32) NOT NULL DEFAULT ''", $ddl );
			$this->assertStringContainsString( '(batch_id,object_type,target_taxonomy,object_id)', $ddl );
		}
		$this->assertTrue( (int) Migrator::SCHEMA_VERSION > 8 );
	}
	public function test_full_tuple_items_and_change_queries_do_not_alias_numeric_ids(): void {
		$s       = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$b       = new \BulkCatalogueManager\Data\BatchRepository();
		$targets = [
			[
				'kind'     => 'product',
				'taxonomy' => null,
				'id'       => 41,
			],
			[
				'kind'     => 'variation',
				'taxonomy' => null,
				'id'       => 41,
			],
			[
				'kind'     => 'term',
				'taxonomy' => 'product_cat',
				'id'       => 41,
			],
		];
		$this->assertSame( 3, $b->materialise_typed( 1, $targets ) );
		$this->assertSame( \BulkCatalogueManager\Selection\TypedMembers::normalize( $targets ), $b->typed_targets( 1 ) );
		$this->assertCount( 3, $s->database->items );
		$read                       = [];
		$GLOBALS['wpdb']->responses = [
			'/^SELECT \* FROM `wp_bulkcat_changes` WHERE batch_id = /' => function () use ( &$read ): array {
						$read[] = end( $GLOBALS['wpdb']->prepared )[1];
						return [];
			},
		] + $GLOBALS['wpdb']->responses;
		foreach ( $targets as $wire ) {
			( new \BulkCatalogueManager\Data\ChangeRepository() )->for_target( 1, \BulkCatalogueManager\Extension\V2\TargetRef::from_array( $wire ) ); }
		$this->assertSame( [ 'wp_bulkcat_changes', 1, 'product', '', 41 ], $read[0] );
		$this->assertSame( [ 'wp_bulkcat_changes', 1, 'term', 'product_cat', 41 ], $read[2] );
	}

	public function test_schema_eight_upgrade_is_additive_and_repeat_is_noop(): void {
		$s                     = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$s->database->items[1] = [
			'item_id'     => 1,
			'batch_id'    => 9,
			'object_type' => 'product',
			'object_id'   => 41,
			'operations'  => null,
		];
		$before                = $s->database->items;
		update_option( Migrator::VERSION_OPTION, '8' );
		$m = new Migrator( \BulkCatalogueManager\Data\Registry::plugin() );
		$this->assertTrue( $m->run() );
		$this->assertSame( '9', get_option( Migrator::VERSION_OPTION ) );
		$first = $GLOBALS['bulkcat_test']['dbdelta'];
		$this->assertTrue( $m->run() );
		$this->assertSame( $first, $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertSame( $before, $s->database->items );
		$this->assertStringNotContainsString( 'DROP ', implode( '\n', $first[0] ) );
		$this->assertStringContainsString( "target_taxonomy varchar(32) NOT NULL DEFAULT ''", implode( '\n', $first[0] ) );
	}

	public function test_partial_typed_identity_migration_refuses_before_provider_or_receipt(): void {
		$s = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$this->assertTrue( \BulkCatalogueManager\Data\FoundationStorage::typed_ready() );
		$GLOBALS['wpdb']->responses = [
			'/^SHOW COLUMNS FROM `wp_bulkcat_changes` LIKE/' => [
				'Type'    => 'varchar(32)',
				'Null'    => 'NO',
				'Default' => 'wrong',
			],
		] + $GLOBALS['wpdb']->responses;
		$this->assertFalse( \BulkCatalogueManager\Data\FoundationStorage::typed_ready() );
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$error = $s->preview(
			[
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			[ $s->step( 'a', 'add', [ 'v1' ] ) ]
		);
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 503, $error->get_error_data()['status'] );
		$this->assertCount( 0, $s->database->receipts );
	}
}
