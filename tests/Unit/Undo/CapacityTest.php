<?php
/**
 * T85: how large a change the screen can promise to undo on this host. An
 * undo plan holds every item of the batch in one web request, about 2.7 KB
 * each (measured on 20,013 items), so the size is worked out from the web
 * request's memory limit, conservatively.
 *
 * Ticket 11 item 7: that limit is the one a plain web request (the REST
 * request that plans the undo) really runs under — the value seen before
 * WordPress raises it for administrators on admin pages, never above what
 * the configured value (php.ini, the PHP-FPM pool, .user.ini) raised to
 * WP_MEMORY_LIMIT allows — and "no limit" counts as a sane ceiling.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Undo;

use BulkCatalogueManager\Undo\Capacity;
use PHPUnit\Framework\TestCase;

final class CapacityTest extends TestCase {
	/**
	 * The memory limit PHPUnit started with, put back after a test raises it.
	 *
	 * @var string
	 */
	private string $limit = '';

	/**
	 * A directory for .user.ini fixtures, removed after each test.
	 *
	 * @var string
	 */
	private string $dir = '';

	protected function setUp(): void {
		$this->limit = (string) ini_get( 'memory_limit' );
		Capacity::forget();
	}

	protected function tearDown(): void {
		ini_set( 'memory_limit', $this->limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- restores the test runner's own limit.
		Capacity::forget();
		if ( '' !== $this->dir ) {
			foreach ( (array) glob( $this->dir . '/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->dir );
		}
	}

	private function dir_with( string $name, string $contents ): string {
		$this->dir = sys_get_temp_dir() . '/bulkcat-capacity-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
		file_put_contents( $this->dir . '/' . $name, $contents );
		return $this->dir;
	}

	public function test_the_limit_follows_the_memory_limit_conservatively(): void {
		$this->assertSame( 27304, Capacity::items( '128M' ), '(128 MB − 32 MB) × 0.75 ÷ 2,765 bytes.' );
		$this->assertLessThan( 41000, Capacity::items( '128M' ), 'Below the ~40,000 where a 128 MB request ran out.' );
		$this->assertSame( 63710, Capacity::items( '256M' ) );
		$this->assertSame( Capacity::items( '128M' ), Capacity::items( '134217728' ) );
		$this->assertSame( Capacity::items( '1G' ), Capacity::items( '1024M' ) );
		$this->assertSame( 0, Capacity::items( '16M' ), 'Below the request\'s own footprint nothing is promised.' );
	}

	public function test_no_limit_counts_as_a_sane_ceiling_never_as_unlimited(): void {
		$this->assertSame( Capacity::items( Capacity::UNLIMITED ), Capacity::items( '-1' ), '-1 says nothing about the memory there is.' );
		$this->assertSame( 63710, Capacity::items( '-1' ), 'Counted as 256 MB, the most WordPress itself raises to for admin work.' );
		$this->assertSame( Capacity::items( '-1' ), Capacity::items( ' -1 ' ) );
		$this->assertSame( Capacity::items( '-1' ), Capacity::items( '' ), 'An unreadable limit is not a promise either.' );
	}

	public function test_bytes_reads_php_shorthand(): void {
		$this->assertSame( 128 * 1048576, Capacity::bytes( '128M' ) );
		$this->assertSame( 128 * 1048576, Capacity::bytes( '128m' ) );
		$this->assertSame( 2 * 1073741824, Capacity::bytes( '2g' ) );
		$this->assertSame( 2 * 1073741824, Capacity::bytes( '2G' ) );
		$this->assertSame( 512 * 1024, Capacity::bytes( '512K' ) );
		$this->assertSame( 512 * 1024, Capacity::bytes( '512k' ) );
		$this->assertSame( 134217728, Capacity::bytes( '134217728' ) );
		$this->assertSame( 96 * 1048576, Capacity::bytes( " 96M\n" ) );
		$this->assertSame( -1, Capacity::bytes( '-1' ) );
		$this->assertSame( -1, Capacity::bytes( '' ) );
	}

	public function test_a_changeable_limit_below_wp_memory_limit_is_raised_to_it_as_wordpress_does(): void {
		// wp_initial_constants(): WordPress raises a lower, changeable limit to WP_MEMORY_LIMIT for every request.
		$this->assertSame( '256M', Capacity::effective( '256M', '128M', '256M', true ) );
	}

	public function test_wp_memory_limit_never_lowers_the_limit(): void {
		// The default WP_MEMORY_LIMIT is 40M on a single site: a floor WordPress raises to, not a cap.
		$this->assertSame( '128M', Capacity::effective( '128M', '128M', '40M', true ) );
		$this->assertSame( '128M', Capacity::effective( '128M', '128M', '', true ), 'No WP_MEMORY_LIMIT at all.' );
	}

	public function test_a_locked_limit_is_not_raised_to_wp_memory_limit(): void {
		// php_admin_value in a PHP-FPM pool: ini_set() cannot raise it, whatever wp-config.php says.
		$this->assertSame( '96M', Capacity::effective( '96M', '96M', '256M', false ) );
	}

	public function test_the_value_seen_before_an_administrators_raise_is_what_counts(): void {
		// Observed before wp_raise_memory_limit( 'admin' ): lower than what the admin page runs at.
		$this->assertSame( '128M', Capacity::effective( '128M', '128M', '40M', true ) );
		// If the observation itself came after a raise, the configured value raised to WP_MEMORY_LIMIT still caps it.
		$this->assertSame( '128M', Capacity::effective( '256M', '128M', '40M', true ) );
		$this->assertSame( '160M', Capacity::effective( '512M', '128M', '160M', true ) );
	}

	public function test_a_lower_value_for_this_request_wins(): void {
		// A pool php_value or .user.ini below php.ini: the request runs at the lower value.
		$this->assertSame( '80M', Capacity::effective( '80M', '128M', '40M', true ) );
	}

	public function test_unlimited_values_count_as_the_ceiling(): void {
		$this->assertSame( Capacity::UNLIMITED, Capacity::effective( '-1', '-1', '40M', true ), 'Unlimited everywhere: the ceiling.' );
		$this->assertSame( '128M', Capacity::effective( '-1', '128M', '40M', true ), 'Unlimited seen, 128M configured.' );
		$this->assertSame( '128M', Capacity::effective( '128M', '-1', '40M', true ), 'Unlimited configured, 128M seen.' );
		$this->assertSame( Capacity::UNLIMITED, Capacity::effective( '-1', '128M', '-1', true ), 'WP_MEMORY_LIMIT -1 lifts a changeable limit.' );
		$this->assertSame( '512M', Capacity::effective( '512M', '512M', '40M', true ), 'An explicit value above the ceiling is trusted.' );
	}

	public function test_a_user_ini_memory_limit_is_read_under_php_fpm_and_cgi(): void {
		$dir = $this->dir_with( '.user.ini', "; host settings\nmemory_limit = 192M\nmax_execution_time = 60\n" );
		$this->assertSame( '192M', Capacity::user_ini( $dir, '.user.ini', 'fpm-fcgi', INI_ALL ) );
		$this->assertSame( '192M', Capacity::user_ini( $dir, '.user.ini', 'cgi-fcgi', INI_ALL ) );
	}

	public function test_a_user_ini_is_ignored_where_php_does_not_read_it(): void {
		$dir = $this->dir_with( '.user.ini', "memory_limit = 192M\n" );
		$this->assertNull( Capacity::user_ini( $dir, '.user.ini', 'apache2handler', INI_ALL ), 'mod_php reads .htaccess, not .user.ini.' );
		$this->assertNull( Capacity::user_ini( $dir, '.user.ini', 'cli', INI_ALL ) );
		$this->assertNull( Capacity::user_ini( $dir, '.user.ini', 'fpm-fcgi', INI_SYSTEM ), 'php_admin_value locks it.' );
		$this->assertNull( Capacity::user_ini( $dir, '', 'fpm-fcgi', INI_ALL ), 'user_ini.filename empty: switched off.' );
		$this->assertNull( Capacity::user_ini( $dir, 'php-custom.ini', 'fpm-fcgi', INI_ALL ), 'No such file.' );
	}

	public function test_a_user_ini_without_a_memory_limit_sets_nothing(): void {
		$dir = $this->dir_with( '.user.ini', "max_execution_time = 60\n" );
		$this->assertNull( Capacity::user_ini( $dir, '.user.ini', 'fpm-fcgi', INI_ALL ) );
	}

	public function test_a_custom_user_ini_filename_is_honoured(): void {
		$dir = $this->dir_with( 'php-user.ini', "memory_limit=64M\n" );
		$this->assertSame( '64M', Capacity::user_ini( $dir, 'php-user.ini', 'fpm-fcgi', INI_PERDIR | INI_SYSTEM ) );
	}

	public function test_web_limit_uses_the_value_observed_before_an_administrators_raise(): void {
		ini_set( 'memory_limit', '200M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the value a plain request starts with.
		Capacity::observe();
		ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- wp_raise_memory_limit( 'admin' ) on an admin page.
		$configured = (string) ini_get_all( null, true )['memory_limit']['global_value'];
		$this->assertSame( Capacity::effective( '200M', $configured, defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '', true ), Capacity::web_limit() );
		$this->assertTrue( Capacity::bytes( Capacity::web_limit() ) <= Capacity::bytes( '200M' ), 'Never the raised 1024M.' );
		$this->assertTrue( Capacity::items() <= Capacity::items( '200M' ) );
	}

	public function test_observe_keeps_the_first_value(): void {
		ini_set( 'memory_limit', '200M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the value a plain request starts with.
		Capacity::observe();
		ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- a later raise.
		Capacity::observe();
		$this->assertSame( '200M', Capacity::observed() );
	}

	public function test_without_an_observation_the_current_value_is_read_and_still_capped(): void {
		ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- already raised when first asked.
		$this->assertNull( Capacity::observed() );
		$configured = (string) ini_get_all( null, true )['memory_limit']['global_value'];
		$expected   = Capacity::effective( '1024M', $configured, defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '', true );
		$this->assertSame( $expected, Capacity::web_limit() );
	}
}
