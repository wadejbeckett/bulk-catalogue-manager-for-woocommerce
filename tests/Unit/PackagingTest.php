<?php
/**
 * The release archive (T78; SPEC §15): what ships, what it references, what
 * it must never contain, and the version it claims.
 *
 * The archive's file list is the repository's tracked and untracked,
 * not-ignored files less everything `.gitattributes` marks export-ignore,
 * which is what `git archive` in bin/build-zip.sh exports; the script checks
 * the same list against bin/archive-manifest.txt after rebuilding the bundle.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Plugin;
use PHPUnit\Framework\TestCase;

final class PackagingTest extends TestCase {
	private const SLUG = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * Where SQL reaches $wpdb from something Plugin Check cannot trace to a
	 * literal, per file: a prepare() template held in a variable, a query
	 * run from a statement a method returned, or a `{$where}` joined from
	 * conditions. Every one is a read bound through $wpdb->prepare(), and
	 * each file is listed with its reason in bin/plugin-check-allowed.tsv and
	 * docs/RELEASE.md "Known notices".
	 */
	private const UNTRACEABLE_READS = [
		'src/Categories/Counts.php'     => 2,
		'src/Data/BatchRepository.php'  => 2,
		'src/Data/ChangeRepository.php' => 4,
		'src/Engine/BatchFactory.php'   => 1,
		'src/Export/GuardedValues.php'  => 1,
		'src/Query/ProductQuery.php'    => 7,
	];

	/**
	 * Hosts a URL in shipped code may name. None is fetched: these are XML
	 * namespaces, licence and documentation links, and error-message links
	 * inside bundled libraries. A new host fails the test so someone looks.
	 */
	private const URL_HOSTS = [
		'base-ui.com',
		'github.com',
		'json-schema.org',
		'react.dev',
		'reactjs.org',
		'www.gnu.org',
		'www.w3.org',
	];

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Run bounded Git directly, spooling all three streams to avoid pipe deadlock.
	 *
	 * @param list<string> $args Git arguments.
	 * @param string $input Complete standard input, never truncated.
	 */
	private static function git( array $args, string $input = '' ): ?string {
		if ( ! file_exists( self::root() . '/.git' ) ) {
			return null;
		}
		$limit = 16 * 1024 * 1024;
		if ( strlen( $input ) > $limit ) {
			throw new \RuntimeException( 'Archive Git input exceeds the 16 MiB test bound.' );
		}
		$streams = [];
		$process = null;
		try {
			for ( $i = 0; $i < 3; ++$i ) {
				$streams[ $i ] = tmpfile();
				if ( ! is_resource( $streams[ $i ] ) ) {
					throw new \RuntimeException( 'Cannot allocate archive Git stream.' );
				}
			}
			if ( strlen( $input ) !== fwrite( $streams[0], $input ) || ! fflush( $streams[0] ) || ! rewind( $streams[0] ) ) {
				throw new \RuntimeException( 'Cannot prepare complete archive Git input.' );
			}
			$process = proc_open( [ 'git', '-C', self::root(), ...$args ], $streams, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Direct owned Git child; file-backed streams avoid bidirectional pipe deadlock.
			if ( ! is_resource( $process ) ) {
				throw new \RuntimeException( 'Cannot start archive Git child.' );
			}
			$deadline = microtime( true ) + 30;
			do {
				$status = proc_get_status( $process );
				if ( false === $status ) {
					throw new \RuntimeException( 'Cannot inspect archive Git child.' );
				}
				foreach ( [ 1, 2 ] as $index ) {
					$stat = fstat( $streams[ $index ] );
					if ( false === $stat || $stat['size'] > $limit ) {
						throw new \RuntimeException( 'Archive Git output exceeds the 16 MiB test bound.' );
					}
				}
				if ( ! $status['running'] ) {
					break;
				}
				if ( microtime( true ) >= $deadline ) {
					throw new \RuntimeException( 'Archive Git child exceeded 30 seconds.' );
				}
				usleep( 10000 );
			} while ( true );
			$closed  = proc_close( $process );
			$process = null;
			$exit    = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
			if ( ! rewind( $streams[1] ) || ! rewind( $streams[2] ) ) {
				throw new \RuntimeException( 'Cannot rewind archive Git output.' );
			}
			$output = stream_get_contents( $streams[1] );
			$error  = stream_get_contents( $streams[2] );
			if ( false === $output || false === $error || 0 !== $exit || '' !== $error ) {
				throw new \RuntimeException( 'Archive Git exit ' . $exit . ': ' . $error );
			}
			return $output;
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process, 9 ); // Only this directly spawned child, including timeout/error paths.
				proc_close( $process );
			}
			foreach ( $streams as $stream ) {
				if ( is_resource( $stream ) ) {
					fclose( $stream );
				}
			}
		}
	}

	/**
	 * The paths the archive would carry, relative to its <slug>/ directory.
	 *
	 * @return list<string>
	 */
	private function archive_files(): array {
		$listed = self::git( [ 'ls-files', '-z', '--cached', '--others', '--exclude-standard' ] );
		if ( null === $listed ) {
			$this->markTestSkipped( 'Not a git checkout (or no git): the archive list comes from git archive.' );
		}
		$files = array_values( array_filter( explode( "\0", $listed ), static fn( string $file ): bool => '' !== $file && is_file( self::root() . '/' . $file ) ) );
		$attrs = (string) self::git( [ 'check-attr', '-z', '--stdin', 'export-ignore' ], implode( "\0", $files ) . "\0" );
		$parts = explode( "\0", $attrs );
		$count = count( $parts );
		$this->assertSame( 3 * count( $files ) + 1, $count, 'Every complete path has an attribute result.' );
		$ship = [];
		for ( $i = 0; $i + 2 < $count; $i += 3 ) {
			$this->assertSame( $files[ intdiv( $i, 3 ) ], $parts[ $i ] );
			$this->assertSame( 'export-ignore', $parts[ $i + 1 ] );
			if ( 'set' !== $parts[ $i + 2 ] ) {
				$ship[] = $parts[ $i ];
			}
		}
		sort( $ship );
		return array_values( array_unique( $ship ) );
	}

	/**
	 * The manifest: allowed patterns and required files.
	 *
	 * @return array{allow: list<string>, required: list<string>}
	 */
	private static function manifest(): array {
		$allow    = [];
		$required = [];
		foreach ( file( self::root() . '/bin/archive-manifest.txt', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( '' === trim( $line ) || str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( str_starts_with( $line, '!' ) ) {
				$required[] = substr( $line, 1 );
			} else {
				$allow[] = '~' . $line . '~';
			}
		}
		return [
			'allow'    => $allow,
			'required' => $required,
		];
	}

	/**
	 * The code files in the archive: PHP, JavaScript and styles.
	 *
	 * @return list<string>
	 */
	private function code_files(): array {
		return array_values( array_filter( $this->archive_files(), static fn( string $file ): bool => 1 === preg_match( '/\.(php|js|css|scss)$/', $file ) ) );
	}

	public function test_every_archived_file_is_in_the_manifest_and_every_required_file_ships(): void {
		$files    = $this->archive_files();
		$manifest = self::manifest();
		$this->assertNotEmpty( $manifest['allow'] );

		$stray = array_values(
			array_filter(
				$files,
				static function ( string $file ) use ( $manifest ): bool {
					foreach ( $manifest['allow'] as $pattern ) {
						if ( 1 === preg_match( $pattern, $file ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		$this->assertSame( [], $stray, 'Mark these export-ignore in .gitattributes (and .distignore), or allow them in bin/archive-manifest.txt.' );
		$this->assertSame( [], array_values( array_diff( $manifest['required'], $files ) ), 'Required by bin/archive-manifest.txt but not in the archive.' );

		foreach ( [ 'tests/', 'docs/', 'node_modules/', 'vendor/', '.github/', 'bin/', 'dist/', 'scratch/' ] as $dir ) {
			$this->assertSame( [], array_values( array_filter( $files, static fn( string $file ): bool => str_starts_with( $file, $dir ) ) ), $dir . ' must not ship.' );
		}
		foreach ( [ 'assets/src/index.js', 'webpack.config.js', 'package.json', 'package-lock.json', 'assets/build/index.js' ] as $file ) {
			$this->assertContains( $file, $files, 'The readable source and its build configuration ship beside the bundle (SPEC §15).' );
		}
	}

	public function test_the_external_action_test_provider_never_ships(): void {
		// E1 (docs/EXTENSIONS.md §15.1): the neutral test provider lives in the test tree only.
		$files = $this->archive_files();
		foreach ( [ 'tests/Support/Extension/SampleProvider.php', 'tests/Support/Extension/load.php', 'tests/fixtures/mu-plugins/bulkcat-sample-provider.php' ] as $fixture ) {
			$this->assertFileExists( self::root() . '/' . $fixture );
			$this->assertNotContains( $fixture, $files, $fixture . ' must not ship.' );
		}
		foreach ( $this->code_files() as $file ) {
			$code = (string) file_get_contents( self::root() . '/' . $file );
			$this->assertStringNotContainsString( 'Tests\\Support\\Extension', $code, $file . ' refers to the test provider.' );
			$this->assertStringNotContainsString( 'x_sample_', $code, $file . ' names a test provider id.' );
		}
	}

	public function test_distignore_and_gitattributes_leave_out_the_same_paths(): void {
		$attributes = [];
		foreach ( file( self::root() . '/.gitattributes', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( 1 === preg_match( '/^(\S+)\s+export-ignore\s*$/', $line, $match ) ) {
				$attributes[] = str_ends_with( $match[1], '/**' ) ? substr( $match[1], 0, -2 ) : $match[1];
			}
		}
		$distignore = [];
		foreach ( file( self::root() . '/.distignore', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( '' !== trim( $line ) && ! str_starts_with( $line, '#' ) ) {
				$distignore[] = trim( $line );
			}
		}
		$this->assertNotEmpty( $attributes );
		$this->assertSame( [], array_values( array_diff( $attributes, $distignore ) ), 'In .gitattributes export-ignore but not in .distignore.' );

		// .distignore may add only paths git never tracks (build output, caches, local overrides).
		$gitignore = (string) file_get_contents( self::root() . '/.gitignore' );
		foreach ( array_diff( $distignore, $attributes ) as $extra ) {
			$this->assertStringContainsString( $extra, $gitignore, $extra . ' is only in .distignore, so it must be gitignored.' );
		}
		foreach ( [ 'assets/src', 'webpack.config.js', 'package.json', 'package-lock.json', 'languages', 'assets/build' ] as $ship ) {
			$this->assertNotContains( $ship, $distignore );
			$this->assertNotContains( $ship . '/', $distignore );
		}
	}

	public function test_every_build_file_the_screen_loads_exists_and_ships(): void {
		$screen = (string) file_get_contents( self::root() . '/src/Admin/Screen.php' );
		$this->assertGreaterThan( 0, preg_match_all( "~'(assets/build/[^']+)'~", $screen, $matches ) );
		$referenced = array_unique( $matches[1] );
		$this->assertContains( 'assets/build/index.js', $referenced );
		$this->assertContains( 'assets/build/index.asset.php', $referenced );
		$this->assertContains( 'assets/build/style-index.css', $referenced );
		if ( str_contains( $screen, "wp_style_add_data( 'bulkcat-app', 'rtl', 'replace' )" ) ) {
			$referenced[] = 'assets/build/style-index-rtl.css';
		}
		$files = $this->archive_files();
		foreach ( $referenced as $file ) {
			$this->assertFileExists( self::root() . '/' . $file, 'Run npm run build.' );
			$this->assertContains( $file, $files, $file . ' is loaded by the screen, so it must ship.' );
		}
	}

	public function test_script_translations_load_from_the_shipped_languages_directory(): void {
		$screen = (string) file_get_contents( self::root() . '/src/Admin/Screen.php' );
		$this->assertStringContainsString( "wp_register_script( 'bulkcat-app', plugins_url( 'assets/build/index.js', \$this->plugin_file )", $screen );
		$this->assertStringContainsString( "wp_set_script_translations( 'bulkcat-app', 'bulk-catalogue-manager-for-woocommerce', \$dir . 'languages' )", $screen );

		// WordPress looks for <domain>-<locale>-<md5 of the script's path in the plugin>.json there.
		$hash = md5( 'assets/build/index.js' );
		foreach ( (array) glob( self::root() . '/languages/*.json' ) as $json ) {
			$this->assertSame( 1, preg_match( '/^' . self::SLUG . '-[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?-' . $hash . '\.json$/', basename( $json ) ), basename( $json ) . ' is for a script the plugin does not load.' );
		}

		$composer = json_decode( (string) file_get_contents( self::root() . '/composer.json' ), true );
		$this->assertSame( [ '@make-pot', '@make-json' ], $composer['scripts']['i18n'] );
		$this->assertStringStartsWith( 'wp i18n make-json languages', (string) $composer['scripts']['make-json'] );
	}

	public function test_no_shipped_code_calls_out_or_loads_anything_remote(): void {
		$network = [
			'/(?<![\w-])fetch\s*\(/'    => 'fetch()',
			'/\bXMLHttpRequest\b/'      => 'XMLHttpRequest',
			'/\bsendBeacon\b/'          => 'sendBeacon',
			'/\bnew\s+WebSocket\b/'     => 'WebSocket',
			'/\bnew\s+EventSource\b/'   => 'EventSource',
			'/\bimportScripts\s*\(/'    => 'importScripts()',
			'/\bwp_(safe_)?remote_(get|post|head|request)\s*\(/' => 'wp_remote_*()',
			'/\bdownload_url\s*\(/'     => 'download_url()',
			'/\bcurl_(init|exec)\s*\(/' => 'curl',
			'/\bfsockopen\s*\(/'        => 'fsockopen()',
			'/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/' => 'remote file read',
			'/\bwp_(register|enqueue)_(script|style)\s*\([^;]*[\'"]https?:/' => 'remote enqueue',
		];
		$found   = [];
		$hosts   = [];
		$files   = $this->code_files();
		$this->assertContains( 'assets/build/index.js', $files );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( self::root() . '/' . $file );
			foreach ( $network as $pattern => $what ) {
				if ( 1 === preg_match( $pattern, $code ) ) {
					$found[] = $file . ': ' . $what;
				}
			}
			if ( 1 === preg_match( '/\.s?css$/', $file ) && 1 === preg_match( '/(\burl\(|@import)\s*[\'"]?(https?:)?\/\//i', $code ) ) {
				$found[] = $file . ': remote url() or @import';
			}
			if ( preg_match_all( '~https?://([a-z0-9.-]+)~i', $code, $matches ) ) {
				foreach ( $matches[1] as $host ) {
					if ( ! in_array( strtolower( $host ), self::URL_HOSTS, true ) && ! str_ends_with( strtolower( $host ), 'wordpress.org' ) ) {
						$hosts[] = $file . ': ' . $host;
					}
				}
			}
		}
		$this->assertSame( [], $found, 'No external calls (SPEC §15).' );
		$this->assertSame( [], array_values( array_unique( $hosts ) ), 'A URL to a new host: check nothing loads from it, then add it to URL_HOSTS.' );
	}

	public function test_no_shipped_code_evaluates_strings_or_dumps_debug_output(): void {
		$found = [];
		foreach ( $this->code_files() as $file ) {
			$code = (string) file_get_contents( self::root() . '/' . $file );
			if ( str_ends_with( $file, '.php' ) ) {
				foreach ( [ '/\beval\s*\(/', '/\bcreate_function\s*\(/', '/\bvar_dump\s*\(/', '/\bvar_export\s*\([^;]*\)\s*;/', '/\bprint_r\s*\([^,;]*\)\s*;/' ] as $pattern ) {
					if ( 1 === preg_match( $pattern, $code ) ) {
						$found[] = $file . ' ' . $pattern;
					}
				}
			} elseif ( str_ends_with( $file, '.js' ) ) {
				foreach ( [ '/(?<![.\w])eval\s*\(/', '/\bnew\s+Function\s*\(/' ] as $pattern ) {
					if ( 1 === preg_match( $pattern, $code ) ) {
						$found[] = $file . ' ' . $pattern;
					}
				}
			}
		}
		$this->assertSame( [], $found, 'No eval and no debug output (SPEC §15).' );
	}

	public function test_every_shipped_php_file_refuses_direct_access_near_its_top(): void {
		$late = [];
		foreach ( $this->archive_files() as $file ) {
			if ( ! str_ends_with( $file, '.php' ) ) {
				continue;
			}
			$lines = file( self::root() . '/' . $file, FILE_IGNORE_NEW_LINES );
			if ( 'uninstall.php' === $file ) {
				$this->assertContains( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $lines );
				continue;
			}
			if ( 'assets/build/index.asset.php' === $file ) {
				// Generated by wp-scripts: it only returns an array.
				$this->assertStringStartsWith( '<?php return array(', (string) file_get_contents( self::root() . '/' . $file ) );
				continue;
			}
			// Plugin Check reads only the first 50 lines for the guard; keep a margin.
			$guard = array_search( "defined( 'ABSPATH' ) || exit;", $lines, true );
			if ( false === $guard || $guard >= 40 ) {
				$late[] = $file . ( false === $guard ? ' (no guard)' : ' (line ' . ( $guard + 1 ) . ')' );
			}
		}
		$this->assertSame( [], $late, 'Put defined( \'ABSPATH\' ) || exit; within the first 40 lines: move the long description into the class docblock.' );
	}

	private static function untraceable_database_calls( string $code ): int {
		return preg_match_all( '/\$wpdb->(?:get_results|get_var|get_col|get_row|query)\(\s*\$this->/', $code )
			+ preg_match_all( '/\$wpdb->prepare\(\s*\$/', $code )
			+ preg_match_all( '/\$wpdb->prepare\(\s*"[^"]*\{\$where\}/', $code );
	}

	public function test_database_scan_distinguishes_native_prepare_from_sql_prepare(): void {
		$this->assertSame( 0, self::untraceable_database_calls( '$inline->prepare( $input );' ) );
		$this->assertSame( 1, self::untraceable_database_calls( '$wpdb->prepare( $sql, ...$args );' ) );
		$this->assertSame( 1, self::untraceable_database_calls( '$wpdb->get_results( $this->query() );' ) );
		$this->assertSame( 1, self::untraceable_database_calls( '$wpdb->prepare( "SELECT id FROM t WHERE {$where}", $id );' ) );
		$this->assertSame( 0, self::untraceable_database_calls( '$wpdb->prepare( "SELECT id FROM t WHERE id = %d", $id );' ) );
	}

	public function test_database_calls_plugin_check_cannot_trace_are_only_the_listed_reads(): void {
		$found = [];
		foreach ( (array) glob( self::root() . '/src/{*,*/*,*/*/*}.php', GLOB_BRACE ) as $path ) {
			$file = substr( $path, strlen( self::root() ) + 1 );
			$code = (string) file_get_contents( $path );
			$hits = self::untraceable_database_calls( $code );
			if ( $hits > 0 ) {
				$found[ $file ] = $hits;
			}
		}
		ksort( $found );
		$this->assertSame( self::UNTRACEABLE_READS, $found, 'Give prepare() a literal template (placeholder lists as {$in} from implode( array_fill() )), or, for a read only, list it in UNTRACEABLE_READS, bin/plugin-check-allowed.tsv and docs/RELEASE.md.' );

		$allowed = (string) file_get_contents( self::root() . '/bin/plugin-check-allowed.tsv' );
		foreach ( array_keys( self::UNTRACEABLE_READS ) as $file ) {
			$this->assertStringContainsString( $file . "\tPluginCheck.Security.DirectDB.UnescapedDBParameter\t", $allowed );
		}
	}

	public function test_the_version_agrees_across_header_readme_changelog_and_constant(): void {
		$main = (string) file_get_contents( self::root() . '/' . self::SLUG . '.php' );
		$this->assertSame( 1, preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $main, $header ) );
		$this->assertSame( Plugin::VERSION, $header[1], 'The plugin header Version.' );

		$readme = (string) file_get_contents( self::root() . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable ) );
		$this->assertSame( Plugin::VERSION, $stable[1], 'The readme Stable tag.' );

		// The newest readme changelog entry, when it names a version, is this one.
		if ( preg_match( '/^== Changelog ==\s*\n+= ([^=]+?) =/mi', $readme, $entry ) && 1 === preg_match( '/^\d+\.\d+/', $entry[1] ) ) {
			$this->assertSame( Plugin::VERSION, trim( $entry[1] ), 'The newest readme.txt changelog entry.' );
		}

		// CHANGELOG.md: an [Unreleased] entry may lead; the newest released entry is never ahead of VERSION.
		$changelog = (string) file_get_contents( self::root() . '/CHANGELOG.md' );
		preg_match_all( '/^## \[([^\]]+)\]/m', $changelog, $headings );
		$released = array_values( array_filter( $headings[1], static fn( string $name ): bool => 1 === preg_match( '/^\d+\.\d+\.\d+/', $name ) ) );
		if ( [] !== $released ) {
			if ( 'unreleased' === strtolower( (string) ( $headings[1][0] ?? '' ) ) ) {
				$this->assertTrue( version_compare( $released[0], Plugin::VERSION, '<=' ), 'CHANGELOG.md names a release after ' . Plugin::VERSION . '.' );
			} else {
				$this->assertSame( Plugin::VERSION, $released[0], 'The newest CHANGELOG.md entry.' );
			}
		}

		$composer = json_decode( (string) file_get_contents( self::root() . '/composer.json' ), true );
		if ( isset( $composer['version'] ) ) {
			$this->assertSame( Plugin::VERSION, $composer['version'], 'composer.json version.' );
		}
	}

	public function test_readme_ci_and_wp_env_agree_with_the_woocommerce_minimum(): void {
		// Ticket 04 (WooCommerce 11.1): every place that names the minimum moves together.
		$readme = (string) file_get_contents( self::root() . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^WC requires at least:\s*(\S+)/mi', $readme, $header ) );
		$this->assertSame( Plugin::MIN_WC_VERSION, $header[1], 'readme.txt WC requires at least.' );
		$this->assertStringContainsString( 'WooCommerce ' . Plugin::MIN_WC_VERSION . ' or later', $readme, 'readme.txt install step and FAQ.' );

		$env = json_decode( (string) file_get_contents( self::root() . '/.wp-env.json' ), true );
		$zip = null;
		foreach ( (array) ( $env['plugins'] ?? [] ) as $plugin ) {
			if ( is_string( $plugin ) && 1 === preg_match( '/woocommerce\.(\d+\.\d+(?:\.\d+)?)\.zip$/', $plugin, $match ) ) {
				$zip = $match[1];
			}
		}
		$this->assertNotNull( $zip, '.wp-env.json pins a WooCommerce zip.' );
		$this->assertTrue( version_compare( (string) $zip, Plugin::MIN_WC_VERSION, '>=' ), ".wp-env.json's WooCommerce $zip is below the minimum." );

		$ci = (string) file_get_contents( self::root() . '/.github/workflows/ci.yml' );
		$this->assertSame( 1, preg_match( '/^\s*wc:\s*\[([^\]]*)\]/m', $ci, $legs ), 'CI has a WooCommerce matrix.' );
		$versions = array_map( static fn( string $leg ): string => trim( $leg, " '\"" ), explode( ',', $legs[1] ) );
		usort( $versions, 'version_compare' );
		$this->assertTrue( version_compare( $versions[0], Plugin::MIN_WC_VERSION, '>=' ), "CI's lowest WooCommerce leg {$versions[0]} is below the minimum." );
	}
}
