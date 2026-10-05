<?php
/**
 * The migrator feeds every registry table to dbDelta() and stores the schema
 * version only once the run has succeeded (SPEC §9.2, §15 Data lifecycle).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase {
	private const BODY_A = "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nPRIMARY KEY  (id)";
	private const BODY_B = "item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nstate VARCHAR(12) NOT NULL,\nPRIMARY KEY  (item_id),\nKEY state (state)";

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function fixture(): Registry {
		return new Registry(
			[
				'bulkcat_alpha' => self::BODY_A,
				'bulkcat_beta'  => self::BODY_B,
			],
			[ 'bulkcat_settings', 'bulkcat_db_version' ],
			[],
			[],
			[],
			[]
		);
	}

	private static function expected_statements(): array {
		$collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		return [
			"CREATE TABLE wp_bulkcat_alpha (\n" . self::BODY_A . "\n) $collate ENGINE=InnoDB;",
			"CREATE TABLE wp_bulkcat_beta (\n" . self::BODY_B . "\n) $collate ENGINE=InnoDB;",
		];
	}

	public function test_a_fresh_site_runs_db_delta_over_every_table_then_stores_the_version(): void {
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );

		$this->assertSame( [ self::expected_statements() ], $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
		$this->assertSame( [ 'wp_bulkcat_alpha', 'wp_bulkcat_beta' ], array_keys( $GLOBALS['wpdb']->tables ) );
	}

	public function test_the_version_is_not_stored_when_a_table_was_not_created(): void {
		$GLOBALS['bulkcat_test']['dbdelta_ok'] = false;

		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ), 'A failed run must leave the version unset.' );

		// One table created, the other not: still a failure.
		$GLOBALS['bulkcat_test']['dbdelta_ok']       = false;
		$GLOBALS['wpdb']->tables['wp_bulkcat_alpha'] = true;
		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ) );

		// A failed run is retried, and succeeds once dbDelta() works.
		$GLOBALS['bulkcat_test']['dbdelta_ok'] = true;
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$this->assertCount( 3, $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_a_failed_run_keeps_an_older_stored_version(): void {
		$GLOBALS['bulkcat_test']['options'][ Migrator::VERSION_OPTION ] = '0';
		$GLOBALS['bulkcat_test']['dbdelta_ok']                          = false;

		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertSame( '0', get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_re_running_at_the_current_version_does_nothing(): void {
		$migrator = new Migrator( self::fixture() );
		$this->assertTrue( $migrator->run() );
		$state = $GLOBALS['bulkcat_test'];
		$sql   = $GLOBALS['wpdb']->queries;

		$this->assertTrue( $migrator->is_current() );
		$this->assertTrue( $migrator->run() );
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );

		$this->assertSame( $state, $GLOBALS['bulkcat_test'], 'A current schema must not call dbDelta() or write the version again.' );
		$this->assertSame( $sql, $GLOBALS['wpdb']->queries, 'A current schema must not query the database.' );
	}

	public function test_an_older_stored_version_re_runs_the_same_statements(): void {
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$GLOBALS['bulkcat_test']['options'][ Migrator::VERSION_OPTION ] = '0';

		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$this->assertSame( [ self::expected_statements(), self::expected_statements() ], $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_a_registry_with_no_tables_stores_the_version_without_db_delta(): void {
		$registry = new Registry( [], [ 'bulkcat_settings' ], [], [], [], [] );

		$this->assertTrue( ( new Migrator( $registry ) )->run() );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_activation_migrates_the_plugin_registry(): void {
		Migrator::activate();

		$tables = Registry::plugin()->tables();
		$this->assertCount( [] === $tables ? 0 : 1, $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertCount( count( $tables ), $GLOBALS['wpdb']->tables );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_the_version_option_is_registered_for_cleanup(): void {
		$this->assertContains( Migrator::VERSION_OPTION, Registry::plugin()->options() );
	}
	private static function engine_query( string $table ): string {
		return "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'";
	}

	public function test_an_existing_plugin_table_on_another_engine_is_converted_to_innodb(): void {
		$GLOBALS['wpdb']->tables = [ 'wp_bulkcat_alpha' => true ];
		$GLOBALS['wpdb']->responses[ self::engine_query( 'wp_bulkcat_alpha' ) ] = 'MyISAM';
		$GLOBALS['wpdb']->responses[ self::engine_query( 'wp_bulkcat_beta' ) ]  = 'InnoDB';

		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );

		$altered = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'ALTER TABLE' ) ) );
		$this->assertSame( [ 'ALTER TABLE `wp_bulkcat_alpha` ENGINE=InnoDB' ], $altered, 'Only the plugin\'s own table not on InnoDB is converted.' );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_a_failed_conversion_leaves_the_version_unset_so_it_is_retried(): void {
		$GLOBALS['wpdb']->responses[ self::engine_query( 'wp_bulkcat_alpha' ) ]     = 'MyISAM';
		$GLOBALS['wpdb']->responses['ALTER TABLE `wp_bulkcat_alpha` ENGINE=InnoDB'] = false;

		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ) );
	}
	public function test_an_upper_case_prefix_on_a_lower_case_server_still_finishes(): void {
		$GLOBALS['wpdb']->prefix               = 'WP_';
		$GLOBALS['wpdb']->lower_case_names     = true;
		$GLOBALS['wpdb']->tables               = [
			'wp_bulkcat_alpha' => true,
			'wp_bulkcat_beta'  => true,
		];
		$GLOBALS['bulkcat_test']['dbdelta_ok'] = false;

		$this->assertTrue( ( new Migrator( self::fixture() ) )->run(), 'SHOW TABLES answers the lower-case name; it is the same table.' );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}
}
