<?php
/**
 * The three tables match SPEC §9.2 column for column and index for index
 * (plus T34's chunk_size and note on batches and claim_token, claimed_gmt
 * and message on items, T54's actions and pruned_gmt on batches, and T56's operations on items),
 * are in the form dbDelta() requires, and are in the registry.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {
	/**
	 * §9.2 transcribed: column => [ type without display width, nullable ], then indexes.
	 */
	private const SPEC = [
		'bulkcat_batches'     => [
			'columns' => [
				'batch_id'        => [ 'bigint unsigned', false ],
				'uuid'            => [ 'char(36)', false ],
				'user_id'         => [ 'bigint unsigned', false ],
				'created_gmt'     => [ 'datetime', false ],
				'finished_gmt'    => [ 'datetime', true ],
				'status'          => [ 'varchar(20)', false ],
				'source'          => [ 'varchar(20)', false ],
				'undo_of'         => [ 'bigint unsigned', true ],
				'operations'      => [ 'longtext', false ],
				'selection'       => [ 'longtext', false ],
				'total_items'     => [ 'int unsigned', false ],
				'previewed_items' => [ 'int unsigned', true ],
				'done_items'      => [ 'int unsigned', false ],
				'changed_items'   => [ 'int unsigned', false ],
				'failed_items'    => [ 'int unsigned', false ],
				'chunk_size'      => [ 'smallint unsigned', false ],
				'note'            => [ 'varchar(255)', false ],
				'actions'         => [ 'varchar(255)', false ],
				'pruned_gmt'      => [ 'datetime', true ],
				'extensions'      => [ 'longtext', true ],
			],
			'indexes' => [
				'PRIMARY'     => 'batch_id',
				'created_gmt' => 'created_gmt',
				'status'      => 'status',
				'user_id'     => 'user_id',
			],
			'auto'    => 'batch_id',
		],
		'bulkcat_batch_items' => [
			'columns' => [
				'item_id'     => [ 'bigint unsigned', false ],
				'batch_id'    => [ 'bigint unsigned', false ],
				'object_id'   => [ 'bigint unsigned', false ],
				'object_type' => [ 'varchar(16)', false ],
				'state'       => [ 'varchar(12)', false ],
				'attempts'    => [ 'tinyint unsigned', false ],
				'claim_token' => [ 'char(32)', true ],
				'claimed_gmt' => [ 'datetime', true ],
				'message'     => [ 'varchar(255)', false ],
				'operations'  => [ 'longtext', true ],
			],
			'indexes' => [
				'PRIMARY'     => 'item_id',
				'batch_state' => 'batch_id,state',
				'object'      => 'object_id',
			],
			'auto'    => 'item_id',
		],
		'bulkcat_changes'     => [
			'columns' => [
				'change_id'    => [ 'bigint unsigned', false ],
				'batch_id'     => [ 'bigint unsigned', false ],
				'object_id'    => [ 'bigint unsigned', false ],
				'object_type'  => [ 'varchar(16)', false ],
				'prop'         => [ 'varchar(64)', false ],
				'old_value'    => [ 'longtext', false ],
				'new_value'    => [ 'longtext', false ],
				'modified_gmt' => [ 'datetime', false ],
				'applied_gmt'  => [ 'datetime', false ],
				'result'       => [ 'varchar(12)', false ],
				'message'      => [ 'varchar(255)', false ],
			],
			'indexes' => [
				'PRIMARY'     => 'change_id',
				'batch_id'    => 'batch_id',
				'object_prop' => 'object_id,prop',
				'applied_gmt' => 'applied_gmt',
			],
			'auto'    => 'change_id',
		],
	];

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Parse a CREATE TABLE body into columns, indexes and the auto-increment column.
	 *
	 * @return array{columns: array<string, array{0: string, 1: bool}>, indexes: array<string, string>, auto: ?string}
	 */
	private static function parse( string $body ): array {
		$parsed = [
			'columns' => [],
			'indexes' => [],
			'auto'    => null,
		];
		foreach ( explode( "\n", $body ) as $line ) {
			if ( 1 === preg_match( '/^PRIMARY KEY  \(([a-z_,]+)\),?$/', $line, $m ) ) {
				$parsed['indexes']['PRIMARY'] = $m[1];
			} elseif ( 1 === preg_match( '/^KEY ([a-z_]+) \(([a-z_,]+)\),?$/', $line, $m ) ) {
				$parsed['indexes'][ $m[1] ] = $m[2];
			} elseif ( 1 === preg_match( '/^([a-z_]+) ([a-z]+(?:\(\d+\))?(?: unsigned)?) (NOT NULL|NULL)( DEFAULT \S+)?( AUTO_INCREMENT)?,$/', $line, $m ) ) {
				$type                       = preg_replace( '/^(tinyint|smallint|int|bigint)\(\d+\)/', '$1', $m[2] );
				$parsed['columns'][ $m[1] ] = [ $type, 'NULL' === $m[3] ];
				$parsed['auto']           ??= isset( $m[5] ) && '' !== $m[5] ? $m[1] : null;
			} else {
				self::fail( "Unparsed line: $line" );
			}
		}
		return $parsed;
	}

	public function test_the_registry_holds_exactly_the_five_tables(): void {
		$this->assertSame( [ 'bulkcat_batches', 'bulkcat_batch_items', 'bulkcat_changes', 'bulkcat_selections', 'bulkcat_requests' ], array_keys( Registry::plugin()->tables() ) );
		$this->assertSame( Schema::TABLES, Registry::plugin()->tables() );
	}

	public function test_each_table_matches_section_9_2(): void {
		foreach ( self::SPEC as $name => $spec ) {
			$this->assertSame( $spec, self::parse( Schema::TABLES[ $name ] ), "Table $name." );
		}
	}

	public function test_bodies_are_in_the_form_db_delta_requires(): void {
		foreach ( Schema::TABLES as $name => $body ) {
			$this->assertStringNotContainsString( '`', $body, "$name: no backticks." );
			$this->assertStringNotContainsString( 'INDEX', $body, "$name: KEY, not INDEX." );
			$this->assertSame( 1, preg_match_all( '/PRIMARY KEY  \(/', $body ), "$name: two spaces after PRIMARY KEY." );
			$this->assertStringEndsWith( ')', $body, "$name: no trailing comma." );
			$this->assertSame( trim( $body ), $body );
			foreach ( explode( "\n", $body ) as $line ) {
				if ( ! str_starts_with( $line, 'PRIMARY' ) && ! str_starts_with( $line, 'KEY' ) ) {
					$type = explode( ' ', $line )[1];
					$this->assertSame( strtolower( $type ), $type, "$name: field types are lower case ($line)." );
				}
			}
		}
	}

	public function test_the_migrator_creates_them_with_the_prefix_and_charset(): void {
		$GLOBALS['wpdb']->prefix = 'wp_7_';
		Migrator::activate();
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );

		$statements = $GLOBALS['bulkcat_test']['dbdelta'][0];
		$this->assertCount( 5, $statements );
		foreach ( array_keys( Schema::TABLES ) as $i => $name ) {
			$this->assertSame( "CREATE TABLE wp_7_{$name} (\n" . Schema::TABLES[ $name ] . "\n) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci ENGINE=InnoDB;", $statements[ $i ], 'Every table is InnoDB, as storage readiness requires.' );
		}
		$this->assertSame( [ 'wp_7_bulkcat_batches', 'wp_7_bulkcat_batch_items', 'wp_7_bulkcat_changes', 'wp_7_bulkcat_selections', 'wp_7_bulkcat_requests' ], array_keys( $GLOBALS['wpdb']->tables ) );
		$this->assertSame( '9', Migrator::SCHEMA_VERSION, 'T81 added snapshot and receipt storage (7); T107 adds the batch\'s provider manifest (8); 9 converts every table to InnoDB.' );
	}

	public function test_table_names_come_only_from_the_registry_with_the_site_prefix(): void {
		$registry = Registry::plugin();
		$this->assertSame( 'wp_bulkcat_changes', $registry->table( 'bulkcat_changes' ) );
		$GLOBALS['wpdb']->prefix = 'shop_3_';
		$this->assertSame( 'shop_3_bulkcat_batches', $registry->table( 'bulkcat_batches' ) );

		foreach ( [ 'bulkcat_other', 'posts', 'wp_bulkcat_changes', 'bulkcat_changes`; DROP TABLE x' ] as $name ) {
			try {
				$registry->table( $name );
				$this->fail( "\"$name\" was accepted." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'not in the registry', $e->getMessage() );
			}
		}
	}

	public function test_allowed_values_match_the_spec(): void {
		$this->assertSame( [ 'queued', 'running', 'complete', 'partial', 'failed', 'cancelled', 'undoing', 'undone' ], Schema::BATCH_STATUSES );
		$this->assertSame( [ 'bulk', 'inline', 'tree', 'undo' ], Schema::BATCH_SOURCES );
		$this->assertSame( [ 'product', 'variation', 'term' ], Schema::OBJECT_TYPES );
		$this->assertSame( [ 'pending', 'done', 'skipped', 'failed' ], Schema::ITEM_STATES );
		$this->assertSame( [ 'applied', 'skipped', 'failed' ], Schema::CHANGE_RESULTS );
	}
}
