<?php
/**
 * Plugin skeleton: the plugin file loads with or without Composer, the
 * version constant matches the header, and boot() is idempotent.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {
	private const PLUGIN_FILE = 'bulk-catalogue-manager-for-woocommerce.php';

	/**
	 * What the plugin file hooked to plugins_loaded when it was first loaded.
	 * Kept because bcm_test_reset() empties the recorded hooks between tests
	 * while require_once loads the file only once.
	 *
	 * @var array<int, array{0: callable, 1: int, 2: int}>|null
	 */
	private static ?array $plugins_loaded = null;

	protected function setUp(): void {
		bcm_test_reset();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The header value WordPress would read, parsed the way get_file_data() does.
	 */
	private static function header( string $name ): string {
		$source = (string) file_get_contents( self::root() . '/' . self::PLUGIN_FILE );
		if ( 1 !== preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $source, $match ) ) {
			return '';
		}
		return trim( $match[1] );
	}

	/**
	 * Every hook the stubs recorded, as "hook|priority|callback id" => count.
	 *
	 * @return array<string, int>
	 */
	private static function registrations(): array {
		$seen = [];
		foreach ( $GLOBALS['bcm_test']['filters'] as $hook => $entries ) {
			foreach ( $entries as [ $callback, $priority ] ) {
				$id           = is_array( $callback ) ? spl_object_id( $callback[0] ) . '::' . $callback[1] : ( is_object( $callback ) ? (string) spl_object_id( $callback ) : (string) $callback );
				$key          = $hook . '|' . $priority . '|' . $id;
				$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			}
		}
		return $seen;
	}

	/**
	 * A `require` statement for a path, as PHP source.
	 */
	private static function php_require( string $path ): string {
		return "require '" . addcslashes( $path, "'\\" ) . "';\n";
	}

	private static function load_plugin_file(): void {
		if ( null === self::$plugins_loaded ) {
			require_once self::root() . '/' . self::PLUGIN_FILE;
			self::$plugins_loaded = $GLOBALS['bcm_test']['filters']['plugins_loaded'] ?? [];
		}
		$GLOBALS['bcm_test']['filters']['plugins_loaded'] = self::$plugins_loaded;
	}

	public function test_version_constant_equals_the_header_version(): void {
		$this->assertNotSame( '', self::header( 'Version' ) );
		$this->assertSame( self::header( 'Version' ), Plugin::VERSION );
	}

	public function test_header_carries_every_required_field(): void {
		$expected = [
			'Plugin Name'          => 'Bulk Catalogue Manager for WooCommerce',
			'Requires at least'    => '7.0',
			'Requires PHP'         => '8.1',
			'Requires Plugins'     => 'woocommerce',
			'WC requires at least' => '10.0',
			'Text Domain'          => 'bulk-catalogue-manager-for-woocommerce',
			'License'              => 'GPL-3.0-or-later',
			'License URI'          => 'https://www.gnu.org/licenses/gpl-3.0.html',
		];
		foreach ( $expected as $name => $value ) {
			$this->assertSame( $value, self::header( $name ), "Header \"$name\"." );
		}
		$this->assertNotSame( '', self::header( 'WC tested up to' ), 'Header "WC tested up to".' );
	}

	public function test_booting_twice_registers_each_hook_once(): void {
		$plugin = new Plugin();
		$plugin->boot();
		$once = self::registrations();
		$plugin->boot();

		$this->assertTrue( $plugin->is_booted() );
		$this->assertNotEmpty( $once );
		$this->assertCount( count( $plugin->hooks() ), $once );
		$this->assertSame( $once, self::registrations(), 'A second boot() must register nothing new.' );
		foreach ( self::registrations() as $key => $count ) {
			$this->assertSame( 1, $count, "$key is registered $count times." );
		}
	}

	public function test_plugins_loaded_firing_twice_boots_once(): void {
		self::load_plugin_file();
		$this->assertCount( 1, $GLOBALS['bcm_test']['filters']['plugins_loaded'] );

		do_action( 'plugins_loaded' );
		$after_first = self::registrations();
		do_action( 'plugins_loaded' );

		$this->assertTrue( Plugin::instance()->is_booted() );
		$this->assertSame( $after_first, self::registrations() );
		foreach ( Plugin::instance()->hooks() as [ $hook ] ) {
			$this->assertCount( 1, $GLOBALS['bcm_test']['filters'][ $hook ], "$hook must be registered once." );
		}
	}

	public function test_plugin_file_loads_without_composer_and_plugin_still_resolves(): void {
		// A copy of the shipped files with no vendor/ beside them, loaded in a
		// fresh PHP process so this suite's Composer autoloader cannot help.
		$copy = sys_get_temp_dir() . '/bcm-no-composer-' . bin2hex( random_bytes( 6 ) );
		mkdir( $copy . '/src', 0777, true );
		copy( self::root() . '/' . self::PLUGIN_FILE, $copy . '/' . self::PLUGIN_FILE );
		foreach ( (array) glob( self::root() . '/src/*', GLOB_ONLYDIR ) as $dir ) {
			mkdir( $copy . '/src/' . basename( $dir ) );
		}
		foreach ( (array) glob( self::root() . '/src/{,*/}*.php', GLOB_BRACE ) as $file ) {
			copy( $file, $copy . '/src/' . substr( $file, strlen( self::root() . '/src/' ) ) );
		}
		$script = $copy . '/probe.php';
		file_put_contents(
			$script,
			"<?php\ndefine( 'ABSPATH', '/' );\n"
			. self::php_require( self::root() . '/tests/Support/wp-stubs.php' )
			. self::php_require( $copy . '/' . self::PLUGIN_FILE )
			. "do_action( 'plugins_loaded' );\n"
			. "echo json_encode( [\n"
			. "\t'resolves'  => class_exists( BulkCatalogueManager\\Plugin::class ),\n"
			. "\t'nested'    => class_exists( BulkCatalogueManager\\Settings\\Settings::class ),\n"
			. "\t'from'      => ( new ReflectionClass( BulkCatalogueManager\\Plugin::class ) )->getFileName(),\n"
			. "\t'composer'  => class_exists( Composer\\Autoload\\ClassLoader::class, false ),\n"
			. "\t'booted'    => BulkCatalogueManager\\Plugin::instance()->is_booted(),\n"
			. "\t'bcm_file'  => BCM_FILE,\n"
			. '] );'
		);

		try {
			$output = [];
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs PHP in a clean process.
			$result = json_decode( implode( "\n", $output ), true );

			$this->assertSame( 0, $exit, implode( "\n", $output ) );
			$this->assertTrue( is_array( $result ), implode( "\n", $output ) );
			$this->assertTrue( $result['resolves'] );
			$this->assertTrue( $result['nested'], 'A class in a sub-namespace must resolve without Composer.' );
			$this->assertFalse( $result['composer'], 'Composer must not be loaded in the fallback process.' );
			$this->assertSame( realpath( $copy . '/src/Plugin.php' ), realpath( $result['from'] ) );
			$this->assertTrue( $result['booted'] );
			$this->assertSame( $copy . '/' . self::PLUGIN_FILE, $result['bcm_file'] );
		} finally {
			foreach ( (array) glob( $copy . '/{,src/,src/*/}*.php', GLOB_BRACE ) as $file ) {
				unlink( $file );
			}
			foreach ( (array) glob( $copy . '/src/*', GLOB_ONLYDIR ) as $dir ) {
				rmdir( $dir );
			}
			rmdir( $copy . '/src' );
			rmdir( $copy );
		}
	}

	public function test_every_php_file_declares_strict_types(): void {
		$files = array_merge(
			[ self::root() . '/' . self::PLUGIN_FILE ],
			(array) glob( self::root() . '/src/{,*/,*/*/}*.php', GLOB_BRACE ),
			(array) glob( self::root() . '/tests/{,*/,*/*/}*.php', GLOB_BRACE )
		);
		$this->assertGreaterThan( 5, count( $files ) );
		foreach ( $files as $file ) {
			$this->assertStringContainsString( 'declare( strict_types = 1 );', (string) file_get_contents( $file ), "$file lacks strict_types." );
		}
	}

	public function test_constants_defined_by_the_plugin_file_are_bcm_prefixed(): void {
		$source = (string) file_get_contents( self::root() . '/' . self::PLUGIN_FILE );
		preg_match_all( '/\bdefine\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches );
		$this->assertNotEmpty( $matches[1] );
		foreach ( $matches[1] as $constant ) {
			$this->assertStringStartsWith( 'BCM_', $constant );
		}
	}
}
