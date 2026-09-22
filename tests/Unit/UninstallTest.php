<?php
/**
 * uninstall.php really runs: with "Remove all data when the plugin is
 * deleted" off (the default) it deletes nothing; with it on it removes exactly
 * what the registry lists (SPEC §11, §15 Data lifecycle). Every ticket that
 * adds to the registry extends the expected calls here in the same change.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Data\Cleanup;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {
	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Store the plugin's options and an unrelated one, as a live site would hold them.
	 */
	private static function seed( mixed $settings ): void {
		$GLOBALS['bcm_test']['options'] = [
			'bcm_settings'     => $settings,
			'bcm_db_version'   => '1',
			'unrelated_option' => 'keep',
		];
	}

	private static function run_uninstall(): void {
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'bulk-catalogue-manager-for-woocommerce/bulk-catalogue-manager-for-woocommerce.php' );
		require self::root() . '/uninstall.php';
	}

	/**
	 * Every delete, unschedule and SQL statement uninstall made.
	 *
	 * @return array{deleted: array, unscheduled: array, sql: string[]}
	 */
	private static function calls(): array {
		return [
			'deleted'     => $GLOBALS['bcm_test']['deleted'],
			'unscheduled' => $GLOBALS['bcm_test']['unscheduled'],
			'sql'         => $GLOBALS['wpdb']->queries,
		];
	}

	public function test_with_the_setting_off_by_default_nothing_is_deleted(): void {
		foreach ( [ [], null, 'corrupt', [ 'remove_data_on_delete' => false ], [ 'remove_data_on_delete' => 'yes' ] ] as $stored ) {
			bcm_test_reset();
			self::seed( $stored );
			$before = $GLOBALS['bcm_test']['options'];

			self::run_uninstall();

			$this->assertSame(
				[
					'deleted'     => [],
					'unscheduled' => [],
					'sql'         => [],
				],
				self::calls(),
				'Stored settings: ' . json_encode( $stored )
			);
			$this->assertSame( $before, $GLOBALS['bcm_test']['options'] );
		}
	}

	public function test_with_no_stored_settings_nothing_is_deleted(): void {
		self::run_uninstall();
		$this->assertSame(
			[
				'deleted'     => [],
				'unscheduled' => [],
				'sql'         => [],
			],
			self::calls()
		);
	}

	public function test_with_the_setting_on_every_registered_item_is_removed_and_nothing_else(): void {
		self::seed( [ 'remove_data_on_delete' => true ] );

		self::run_uninstall();

		// The plugin registry today: no tables, user meta, term meta or
		// transients yet (T6, T52, T64 and the count and tree caches add them here).
		$this->assertSame(
			[
				'deleted'     => [
					[ 'option', 'bcm_settings' ],
					[ 'option', 'bcm_db_version' ],
				],
				'unscheduled' => [
					[ '', [], 'bcm' ],
				],
				'sql'         => [],
			],
			self::calls()
		);
		$this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['bcm_test']['options'] );
	}

	public function test_every_kind_in_the_registry_is_removed_with_the_setting_on(): void {
		$registry                     = new Registry(
			[
				'bcm_alpha' => 'id INT',
				'bcm_beta'  => 'id INT',
			],
			[ 'bcm_settings', 'bcm_db_version' ],
			[ 'bcm_views' ],
			[ 'bcm_order' ],
			[ 'bcm_count_' ],
			[ 'bcm_prune_history' ]
		);
		$GLOBALS['wpdb']->tables      = [
			'wp_bcm_alpha' => true,
			'wp_bcm_beta'  => true,
			'wp_posts'     => true,
		];
		$GLOBALS['wpdb']->option_rows = [
			'_transient_bcm_count_abc'              => 1,
			'_transient_timeout_bcm_count_abc'      => 1,
			'_transient_bcm_countdown'              => 1,
			'_site_transient_bcm_count_net'         => 1,
			'_transient_bcmXcount_other'            => 1,
			'_transient_other_bcm_count_'           => 1,
			'_site_transient_timeout_bcm_count_net' => 1,
		];

		$this->assertTrue( ( new Cleanup( $registry ) )->uninstall( new Settings( [ 'remove_data_on_delete' => true ] ) ) );

		$this->assertSame( [ [ '', [], 'bcm' ] ], $GLOBALS['bcm_test']['unscheduled'] );
		$this->assertSame(
			[
				'DROP TABLE IF EXISTS `wp_bcm_alpha`',
				'DROP TABLE IF EXISTS `wp_bcm_beta`',
				"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bcm\\\\_count\\\\_%'",
				"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bcm\\\\_count\\\\_%'",
			],
			$GLOBALS['wpdb']->queries
		);
		$this->assertSame( [ 'wp_posts' => true ], $GLOBALS['wpdb']->tables );
		$this->assertSame(
			[
				[ 'transient', 'bcm_count_abc' ],
				[ 'site_transient', 'bcm_count_net' ],
				[ 'metadata', 'user', 0, 'bcm_views', '', true ],
				[ 'metadata', 'term', 0, 'bcm_order', '', true ],
				[ 'option', 'bcm_settings' ],
				[ 'option', 'bcm_db_version' ],
			],
			$GLOBALS['bcm_test']['deleted']
		);
	}

	public function test_every_kind_in_the_registry_is_kept_with_the_setting_off(): void {
		$registry                     = new Registry( [ 'bcm_alpha' => 'id INT' ], [ 'bcm_settings' ], [ 'bcm_views' ], [ 'bcm_order' ], [ 'bcm_count_' ], [ 'bcm_prune_history' ] );
		$GLOBALS['wpdb']->option_rows = [ '_transient_bcm_count_abc' => 1 ];

		$this->assertFalse( ( new Cleanup( $registry ) )->uninstall( new Settings( [] ) ) );
		$this->assertSame(
			[
				'deleted'     => [],
				'unscheduled' => [],
				'sql'         => [],
			],
			self::calls()
		);
	}

	public function test_deactivation_unschedules_only_the_recurring_actions(): void {
		Cleanup::deactivate();
		$this->assertSame( [ [ 'bcm_prune_history', [], 'bcm' ] ], $GLOBALS['bcm_test']['unscheduled'] );
		$this->assertSame( [], $GLOBALS['bcm_test']['deleted'], 'Deactivation deletes no data.' );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );

		bcm_test_reset();
		( new Cleanup( new Registry( [], [], [], [], [], [ 'bcm_a', 'bcm_b' ] ) ) )->unschedule_recurring();
		$this->assertSame( [ [ 'bcm_a', [], 'bcm' ], [ 'bcm_b', [], 'bcm' ] ], $GLOBALS['bcm_test']['unscheduled'] );
	}

	public function test_without_action_scheduler_nothing_is_fatal(): void {
		$output = [];
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::root() . '/tests/Support/no-scheduler-probe.php' ) . ' 2>&1', $output, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs PHP in a clean process.
		$text   = implode( "\n", $output );
		$result = json_decode( $text, true );

		$this->assertSame( 0, $exit, $text );
		$this->assertTrue( is_array( $result ), $text );
		$this->assertFalse( $result['scheduler'] );
		$this->assertSame( [], $result['unscheduled'] );
		$this->assertSame( [ [ 'option', 'bcm_settings' ], [ 'option', 'bcm_db_version' ] ], $result['deleted'] );
	}

	public function test_uninstall_guards_and_bootstraps_its_own_autoloader(): void {
		// Uninstall runs with no Composer autoloader and no plugin file loaded,
		// so the script must load src/ itself before touching any class.
		$source = (string) file_get_contents( self::root() . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $source );
		$this->assertStringContainsString( "require_once __DIR__ . '/src/Autoloader.php';", $source );
		$this->assertStringContainsString( 'Settings::load()', $source, 'Uninstall must read the settings through the typed reader.' );
		$this->assertLessThan( strpos( $source, 'Autoloader.php' ), strpos( $source, 'WP_UNINSTALL_PLUGIN' ) );
	}
}
