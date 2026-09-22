<?php
/**
 * The registry lists everything the plugin stores: every `bcm_` string in
 * src/ is either a registered option, meta key, table, transient prefix or
 * recurring action, or a named non-storage identifier (SPEC §15 Data lifecycle).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase {
	/**
	 * `bcm_` strings in src/ that are not stored data, each with its reason.
	 * Add a new hook name or code here only if it is never a storage key.
	 */
	private const NOT_STORAGE = [
		'bcm_invalid_'      => 'prefix of the settings error codes passed to add_settings_error()',
		'bcm_capability'    => 'filter hook for the capability the screen and read routes need',
		'bcm_forbidden'     => 'REST refusal code for a user without that capability',
		'bcm_invalid_value' => 'refusal code for a field value that fails its validator',
		'bcm_read_only'     => 'refusal code for a write to a read-only column',
		'bcm_not_available' => 'refusal code for a field that does not apply to that object, such as a parent-only field on a variation',
		'bcm_cannot_edit'   => 'refusal code for one object the user may not edit, skipped and reported',
		'bcm_'              => 'the prefix Rest\\Refusal requires of every refusal code',
	];

	/**
	 * Every quoted string literal in the shipped PHP that starts with `bcm_`,
	 * mapped to the first file it appears in.
	 *
	 * @return array<string, string>
	 */
	private static function bcm_literals(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge(
			(array) glob( $root . '/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE ),
			[ $root . '/uninstall.php', $root . '/bulk-catalogue-manager-for-woocommerce.php' ]
		);
		$found = [];
		foreach ( $files as $file ) {
			preg_match_all( '/[\'"](bcm_[A-Za-z0-9_\-]*)/', (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $literal ) {
				$found[ $literal ] ??= substr( $file, strlen( $root ) + 1 );
			}
		}
		return $found;
	}

	public function test_every_bcm_key_in_src_is_in_the_registry(): void {
		$registry = Registry::plugin();
		$known    = array_merge(
			$registry->options(),
			$registry->user_meta(),
			$registry->term_meta(),
			array_keys( $registry->tables() ),
			$registry->recurring_actions(),
			array_keys( self::NOT_STORAGE )
		);
		$literals = self::bcm_literals();
		$this->assertContains( 'bcm_settings', array_keys( $literals ), 'The scan must see the settings option.' );
		$this->assertContains( 'bcm_db_version', array_keys( $literals ), 'The scan must see the schema version option.' );

		foreach ( $literals as $literal => $file ) {
			$covered = in_array( $literal, $known, true );
			foreach ( $registry->transient_prefixes() as $prefix ) {
				$covered = $covered || str_starts_with( $literal, $prefix );
			}
			$this->assertTrue( $covered, "\"$literal\" in $file is not in src/Data/Registry.php. Register it there (and in UninstallTest), or list it in NOT_STORAGE with its reason." );
		}
	}

	public function test_the_scan_would_catch_an_unregistered_key(): void {
		$this->assertSame( 1, preg_match_all( '/[\'"](bcm_[A-Za-z0-9_\-]*)/', "update_user_meta( \$id, 'bcm_views', \$v );", $m ) );
		$this->assertSame( [ 'bcm_views' ], $m[1] );
		$this->assertNotContains( 'bcm_views', Registry::plugin()->user_meta(), 'Adjust this canary when T52 registers bcm_views.' );
	}

	public function test_the_plugin_registry_holds_its_options_and_the_prune_action(): void {
		$registry = Registry::plugin();
		$this->assertSame( [ 'bcm_settings', 'bcm_db_version' ], $registry->options() );
		$this->assertSame( [ 'bcm_prune_history' ], $registry->recurring_actions() );
		$this->assertSame( 'bcm', Registry::ACTION_GROUP );
	}

	public function test_names_outside_bcm_are_refused(): void {
		$bad = [ 'woocommerce_x', 'bcm_', 'BCM_X', 'bcm_x; DROP TABLE wp_users', 'bcm_x`', 'wp_bcm_x', 'bcm-x' ];
		foreach ( $bad as $name ) {
			foreach ( range( 0, 5 ) as $slot ) {
				$args = [ [], [], [], [], [], [] ];
				if ( 0 === $slot ) {
					$args[0] = [ $name => 'id INT' ];
				} else {
					$args[ $slot ] = [ $name ];
				}
				try {
					new Registry( ...$args );
					$this->fail( "\"$name\" was accepted in slot $slot." );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( 'must be bcm_', $e->getMessage() );
				}
			}
		}
	}

	public function test_valid_names_are_kept_once_in_order(): void {
		$registry = new Registry( [ 'bcm_t' => 'id INT' ], [ 'bcm_a', 'bcm_b', 'bcm_a' ], [ 'bcm_u' ], [ 'bcm_m' ], [ 'bcm_count_' ], [ 'bcm_r' ] );
		$this->assertSame( [ 'bcm_t' => 'id INT' ], $registry->tables() );
		$this->assertSame( [ 'bcm_a', 'bcm_b' ], $registry->options() );
		$this->assertSame( [ 'bcm_u' ], $registry->user_meta() );
		$this->assertSame( [ 'bcm_m' ], $registry->term_meta() );
		$this->assertSame( [ 'bcm_count_' ], $registry->transient_prefixes() );
		$this->assertSame( [ 'bcm_r' ], $registry->recurring_actions() );
	}
}
