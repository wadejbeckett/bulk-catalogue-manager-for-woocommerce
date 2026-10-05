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
		bulkcat_test_reset();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Store the plugin's options and an unrelated one, as a live site would hold them.
	 */
	private static function seed( mixed $settings ): void {
		$GLOBALS['bulkcat_test']['options'] = [
			'bulkcat_settings'      => $settings,
			'bulkcat_db_version'    => '1',
			'bulkcat_request_epoch' => str_repeat( 'a', 64 ),
			'unrelated_option'      => 'keep',
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
			'deleted'     => $GLOBALS['bulkcat_test']['deleted'],
			'unscheduled' => $GLOBALS['bulkcat_test']['unscheduled'],
			'sql'         => $GLOBALS['wpdb']->queries,
		];
	}

	public function test_with_the_setting_off_by_default_nothing_is_deleted(): void {
		foreach ( [ [], null, 'corrupt', [ 'remove_data_on_delete' => false ], [ 'remove_data_on_delete' => 'yes' ] ] as $stored ) {
			bulkcat_test_reset();
			self::seed( $stored );
			$before = $GLOBALS['bulkcat_test']['options'];

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
			$this->assertSame( $before, $GLOBALS['bulkcat_test']['options'] );
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
		$GLOBALS['wpdb']->tables = [
			'wp_posts'               => true,
			'wp_bulkcat_batches'     => true,
			'wp_bulkcat_batch_items' => true,
			'wp_bulkcat_changes'     => true,
			'wp_bulkcat_selections'  => true,
			'wp_bulkcat_requests'    => true,
		];

		self::run_uninstall();

		// The plugin registry today: the three §9.2 tables (T6), the category
		// tree cache's transients (T21), the filtered count cache's transients
		// (T19), the batch request replay transients (T31), the stored
		// selections behind selection tokens (security review p2), the category tree
		// order term meta (T64) and the saved views user meta (T52).
		$this->assertSame(
			[
				'deleted'     => [
					[ 'metadata', 'user', 0, 'wp_bulkcat_views', '', true ],
					[ 'metadata', 'user', 0, 'bulkcat_views', '', true ],
					[ 'metadata', 'term', 0, 'bulkcat_order', '', true ],
					[ 'metadata', 'post', 0, '_bulkcat_availability_off', '', true ],
					[ 'option', 'bulkcat_settings' ],
					[ 'option', 'bulkcat_db_version' ],
					[ 'option', 'bulkcat_request_epoch' ],
				],
				'unscheduled' => [
					[ '', [], 'bulk-catalogue-manager-for-woocommerce' ],
				],
				'sql'         => [
					'DROP TABLE IF EXISTS `wp_bulkcat_batches`',
					'DROP TABLE IF EXISTS `wp_bulkcat_batch_items`',
					'DROP TABLE IF EXISTS `wp_bulkcat_changes`',
					'DROP TABLE IF EXISTS `wp_bulkcat_selections`',
					'DROP TABLE IF EXISTS `wp_bulkcat_requests`',
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bulkcat\\\\_category\\\\_tree\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bulkcat\\\\_category\\\\_tree\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bulkcat\\\\_count\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bulkcat\\\\_count\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bulkcat\\\\_batch\\\\_request\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bulkcat\\\\_batch\\\\_request\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bulkcat\\\\_selection\\\\_%'",
					"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bulkcat\\\\_selection\\\\_%'",
				],
			],
			self::calls()
		);
		$this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['bulkcat_test']['options'] );
		$this->assertSame( [ 'wp_posts' => true ], $GLOBALS['wpdb']->tables );
	}

	public function test_every_kind_in_the_registry_is_removed_with_the_setting_on(): void {
		$registry                     = new Registry(
			[
				'bulkcat_alpha' => 'id INT',
				'bulkcat_beta'  => 'id INT',
			],
			[ 'bulkcat_settings', 'bulkcat_db_version' ],
			[ 'bulkcat_views' ],
			[ 'bulkcat_order' ],
			[ 'bulkcat_count_' ],
			[ 'bulkcat_prune_history' ],
			[ '_bulkcat_record' ]
		);
		$GLOBALS['wpdb']->tables      = [
			'wp_bulkcat_alpha' => true,
			'wp_bulkcat_beta'  => true,
			'wp_posts'         => true,
		];
		$GLOBALS['wpdb']->option_rows = [
			'_transient_bulkcat_count_abc'              => 1,
			'_transient_timeout_bulkcat_count_abc'      => 1,
			'_transient_bulkcat_countdown'              => 1,
			'_site_transient_bulkcat_count_net'         => 1,
			'_transient_bulkcatXcount_other'            => 1,
			'_transient_other_bulkcat_count_'           => 1,
			'_site_transient_timeout_bulkcat_count_net' => 1,
		];

		$this->assertTrue( ( new Cleanup( $registry ) )->uninstall( new Settings( [ 'remove_data_on_delete' => true ] ) ) );

		$this->assertSame( [ [ '', [], 'bulk-catalogue-manager-for-woocommerce' ] ], $GLOBALS['bulkcat_test']['unscheduled'] );
		$this->assertSame(
			[
				'DROP TABLE IF EXISTS `wp_bulkcat_alpha`',
				'DROP TABLE IF EXISTS `wp_bulkcat_beta`',
				"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_transient\\\\_bulkcat\\\\_count\\\\_%'",
				"SELECT option_name FROM wp_options WHERE option_name LIKE '\\\\_site\\\\_transient\\\\_bulkcat\\\\_count\\\\_%'",
			],
			$GLOBALS['wpdb']->queries
		);
		$this->assertSame( [ 'wp_posts' => true ], $GLOBALS['wpdb']->tables );
		$this->assertSame(
			[
				[ 'transient', 'bulkcat_count_abc' ],
				[ 'site_transient', 'bulkcat_count_net' ],
				[ 'metadata', 'user', 0, 'wp_bulkcat_views', '', true ],
				[ 'metadata', 'user', 0, 'bulkcat_views', '', true ],
				[ 'metadata', 'term', 0, 'bulkcat_order', '', true ],
				[ 'metadata', 'post', 0, '_bulkcat_record', '', true ],
				[ 'option', 'bulkcat_settings' ],
				[ 'option', 'bulkcat_db_version' ],
			],
			$GLOBALS['bulkcat_test']['deleted']
		);
		// Off products stay drafts (SPEC §5.5): no status, post or product write, only the key goes.
		$this->assertSame( [], array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => str_contains( $sql, 'post_status' ) || str_contains( $sql, 'wp_posts' ) ) );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['updated_posts'] ?? [] );
	}

	public function test_every_kind_in_the_registry_is_kept_with_the_setting_off(): void {
		$registry                     = new Registry( [ 'bulkcat_alpha' => 'id INT' ], [ 'bulkcat_settings' ], [ 'bulkcat_views' ], [ 'bulkcat_order' ], [ 'bulkcat_count_' ], [ 'bulkcat_prune_history' ], [ '_bulkcat_record' ] );
		$GLOBALS['wpdb']->option_rows = [ '_transient_bulkcat_count_abc' => 1 ];

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
		$this->assertSame( [ [ 'bulkcat_prune_history', [], 'bulk-catalogue-manager-for-woocommerce' ], [ 'bulkcat_prune_selections', [], 'bulk-catalogue-manager-for-woocommerce' ], [ 'bulkcat_prune_requests', [], 'bulk-catalogue-manager-for-woocommerce' ] ], $GLOBALS['bulkcat_test']['unscheduled'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['deleted'], 'Deactivation deletes no data.' );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );

		bulkcat_test_reset();
		( new Cleanup( new Registry( [], [], [], [], [], [ 'bulkcat_a', 'bulkcat_b' ] ) ) )->unschedule_recurring();
		$this->assertSame( [ [ 'bulkcat_a', [], 'bulk-catalogue-manager-for-woocommerce' ], [ 'bulkcat_b', [], 'bulk-catalogue-manager-for-woocommerce' ] ], $GLOBALS['bulkcat_test']['unscheduled'] );
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
		$this->assertSame( [ [ 'metadata', 'user', 0, 'wp_bulkcat_views', '', true ], [ 'metadata', 'user', 0, 'bulkcat_views', '', true ], [ 'metadata', 'term', 0, 'bulkcat_order', '', true ], [ 'metadata', 'post', 0, '_bulkcat_availability_off', '', true ], [ 'option', 'bulkcat_settings' ], [ 'option', 'bulkcat_db_version' ], [ 'option', 'bulkcat_request_epoch' ] ], $result['deleted'] );
	}

	public function test_tables_are_dropped_through_prepare_with_an_identifier_placeholder(): void {
		$registry                = new Registry( [ 'bulkcat_alpha' => 'id INT' ], [], [], [], [], [] );
		$GLOBALS['wpdb']->tables = [ 'wp_bulkcat_alpha' => true ];

		( new Cleanup( $registry ) )->uninstall( new Settings( [ 'remove_data_on_delete' => true ] ) );

		$this->assertSame( [ 'DROP TABLE IF EXISTS %i', [ 'wp_bulkcat_alpha' ] ], $GLOBALS['wpdb']->prepared[0], 'The table name reaches SQL as a %i identifier, never interpolated.' );
		$this->assertStringNotContainsString( '{$table}', (string) file_get_contents( self::root() . '/src/Data/Cleanup.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
	}

	public function test_on_multisite_every_site_is_cleaned_under_its_own_prefix(): void {
		self::seed( [ 'remove_data_on_delete' => true ] );
		$GLOBALS['bulkcat_test']['multisite'] = true;
		$GLOBALS['bulkcat_test']['sites']     = [ 1, 2, 5 ];
		// Site 2 keeps its data; site 5 asks for it to go, as site 1 does.
		$GLOBALS['bulkcat_test']['site_options'] = [
			2 => [ 'bulkcat_settings' => [ 'remove_data_on_delete' => false ] ],
			5 => [ 'bulkcat_settings' => [ 'remove_data_on_delete' => true ] ],
		];
		$GLOBALS['wpdb']->tables                 = [
			'wp_bulkcat_batches'   => true,
			'wp_2_bulkcat_batches' => true,
			'wp_5_bulkcat_batches' => true,
			'wp_5_bulkcat_changes' => true,
			'wp_5_other_table'     => true,
		];

		self::run_uninstall();

		$this->assertSame( [ [ 'switch', 1 ], [ 'restore' ], [ 'switch', 2 ], [ 'restore' ], [ 'switch', 5 ], [ 'restore' ] ], $GLOBALS['bulkcat_test']['switched'] );
		$this->assertSame(
			[
				'wp_2_bulkcat_batches' => true,
				'wp_5_other_table'     => true,
			],
			$GLOBALS['wpdb']->tables,
			'Each site\'s own tables go under its own setting.'
		);
		$this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['bulkcat_test']['options'] );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['site_options'][5] );
		$this->assertSame( 'wp_', $GLOBALS['wpdb']->prefix, 'The current site is restored.' );
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
	public function test_on_a_network_one_sites_uninstall_removes_only_that_sites_saved_views(): void {
		self::seed( [ 'remove_data_on_delete' => false ] );
		$GLOBALS['bulkcat_test']['multisite']    = true;
		$GLOBALS['bulkcat_test']['sites']        = [ 1, 5 ];
		$GLOBALS['bulkcat_test']['site_options'] = [
			5 => [ 'bulkcat_settings' => [ 'remove_data_on_delete' => true ] ],
		];

		self::run_uninstall();

		$users = array_values( array_filter( $GLOBALS['bulkcat_test']['deleted'], static fn( array $call ): bool => 'metadata' === $call[0] && 'user' === $call[1] ) );
		$this->assertSame( [ [ 'metadata', 'user', 0, 'wp_5_bulkcat_views', '', true ] ], $users, 'Only site 5\'s views go; the main site keeps its own and the old network-wide list.' );
	}
}
