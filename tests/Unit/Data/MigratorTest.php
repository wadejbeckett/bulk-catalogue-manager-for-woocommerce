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
		bcm_test_reset();
	}

	private static function fixture(): Registry {
		return new Registry(
			[
				'bcm_alpha' => self::BODY_A,
				'bcm_beta'  => self::BODY_B,
			],
			[ 'bcm_settings', 'bcm_db_version' ],
			[],
			[],
			[],
			[]
		);
	}

	private static function expected_statements(): array {
		$collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		return [
			"CREATE TABLE wp_bcm_alpha (\n" . self::BODY_A . "\n) $collate;",
			"CREATE TABLE wp_bcm_beta (\n" . self::BODY_B . "\n) $collate;",
		];
	}

	public function test_a_fresh_site_runs_db_delta_over_every_table_then_stores_the_version(): void {
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );

		$this->assertSame( [ self::expected_statements() ], $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
		$this->assertSame( [ 'wp_bcm_alpha', 'wp_bcm_beta' ], array_keys( $GLOBALS['wpdb']->tables ) );
	}

	public function test_the_version_is_not_stored_when_a_table_was_not_created(): void {
		$GLOBALS['bcm_test']['dbdelta_ok'] = false;

		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertCount( 1, $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ), 'A failed run must leave the version unset.' );

		// One table created, the other not: still a failure.
		$GLOBALS['bcm_test']['dbdelta_ok']       = false;
		$GLOBALS['wpdb']->tables['wp_bcm_alpha'] = true;
		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ) );

		// A failed run is retried, and succeeds once dbDelta() works.
		$GLOBALS['bcm_test']['dbdelta_ok'] = true;
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$this->assertCount( 3, $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_a_failed_run_keeps_an_older_stored_version(): void {
		$GLOBALS['bcm_test']['options'][ Migrator::VERSION_OPTION ] = '0';
		$GLOBALS['bcm_test']['dbdelta_ok']                          = false;

		$this->assertFalse( ( new Migrator( self::fixture() ) )->run() );
		$this->assertSame( '0', get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_re_running_at_the_current_version_does_nothing(): void {
		$migrator = new Migrator( self::fixture() );
		$this->assertTrue( $migrator->run() );
		$state = $GLOBALS['bcm_test'];
		$sql   = $GLOBALS['wpdb']->queries;

		$this->assertTrue( $migrator->is_current() );
		$this->assertTrue( $migrator->run() );
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );

		$this->assertSame( $state, $GLOBALS['bcm_test'], 'A current schema must not call dbDelta() or write the version again.' );
		$this->assertSame( $sql, $GLOBALS['wpdb']->queries, 'A current schema must not query the database.' );
	}

	public function test_an_older_stored_version_re_runs_the_same_statements(): void {
		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$GLOBALS['bcm_test']['options'][ Migrator::VERSION_OPTION ] = '0';

		$this->assertTrue( ( new Migrator( self::fixture() ) )->run() );
		$this->assertSame( [ self::expected_statements(), self::expected_statements() ], $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_a_registry_with_no_tables_stores_the_version_without_db_delta(): void {
		$registry = new Registry( [], [ 'bcm_settings' ], [], [], [], [] );

		$this->assertTrue( ( new Migrator( $registry ) )->run() );
		$this->assertSame( [], $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_activation_migrates_the_plugin_registry(): void {
		Migrator::activate();

		$tables = Registry::plugin()->tables();
		$this->assertCount( [] === $tables ? 0 : 1, $GLOBALS['bcm_test']['dbdelta'] );
		$this->assertCount( count( $tables ), $GLOBALS['wpdb']->tables );
		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( Migrator::VERSION_OPTION ) );
	}

	public function test_the_version_option_is_registered_for_cleanup(): void {
		$this->assertContains( Migrator::VERSION_OPTION, Registry::plugin()->options() );
	}
}
