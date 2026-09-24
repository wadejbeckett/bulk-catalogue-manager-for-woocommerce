<?php
/**
 * Translation readiness (SPEC §15, T74), beside the WordPress.WP.I18n sniff:
 * every gettext call in the plugin's PHP names its strings and the text
 * domain as single literals, every string with a placeholder carries a
 * `translators:` comment where make-pot will find it, and the committed .pot
 * matches what `composer make-pot` generates from the sources now.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase {
	private const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';

	private const POT = 'languages/bulk-catalogue-manager-for-woocommerce.pot';

	/**
	 * Each gettext function and the role of each argument: `text` (a literal
	 * that may hold placeholders), `context` (a literal), `number` (anything)
	 * and `domain` (the plugin's literal text domain).
	 */
	private const FUNCTIONS = [
		'__'         => [ 'text', 'domain' ],
		'_e'         => [ 'text', 'domain' ],
		'esc_html__' => [ 'text', 'domain' ],
		'esc_html_e' => [ 'text', 'domain' ],
		'esc_attr__' => [ 'text', 'domain' ],
		'esc_attr_e' => [ 'text', 'domain' ],
		'_x'         => [ 'text', 'context', 'domain' ],
		'_ex'        => [ 'text', 'context', 'domain' ],
		'esc_html_x' => [ 'text', 'context', 'domain' ],
		'esc_attr_x' => [ 'text', 'context', 'domain' ],
		'_n'         => [ 'text', 'text', 'number', 'domain' ],
		'_nx'        => [ 'text', 'text', 'number', 'context', 'domain' ],
		'_n_noop'    => [ 'text', 'text', 'domain' ],
		'_nx_noop'   => [ 'text', 'text', 'context', 'domain' ],
	];

	/** A printf placeholder, as make-pot flags php-format: `%s`, `%1$d`, `%.2f`; not `%%`. */
	private const PLACEHOLDER = '/(?<!%)%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The plugin file, uninstall.php and every PHP file under src/.
	 *
	 * @return list<string> Paths relative to the plugin root.
	 */
	private static function sources(): array {
		$root  = self::root();
		$files = [ 'bulk-catalogue-manager-for-woocommerce.php', 'uninstall.php' ];
		$tree  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $tree as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Every problem with the gettext calls in one piece of PHP, as "line: what".
	 *
	 * @param string $code PHP source.
	 * @return list<string>
	 */
	private static function offences( string $code ): array {
		$tokens   = token_get_all( $code );
		$count    = count( $tokens );
		$comments = [];
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) && false !== stripos( $token[1], 'translators:' ) ) {
				$comments[] = $token[2] + substr_count( $token[1], "\n" );
			}
		}

		$found = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) {
				continue;
			}
			$name = ltrim( $token[1], '\\' );
			if ( ! isset( self::FUNCTIONS[ $name ] ) ) {
				continue;
			}
			$before = self::skip( $tokens, $i - 1, -1 );
			if ( null !== $before && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ], true ) ) {
				continue;
			}
			$open = self::skip( $tokens, $i + 1, 1 );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$line  = $token[2];
			$args  = self::arguments( $tokens, $open );
			$roles = self::FUNCTIONS[ $name ];
			if ( count( $args ) !== count( $roles ) ) {
				$found[] = sprintf( '%d: %s() takes %d arguments here, the last the literal text domain', $line, $name, count( $roles ) );
				continue;
			}
			$placeholders = false;
			foreach ( $roles as $n => $role ) {
				if ( 'number' === $role ) {
					continue;
				}
				$arg = $args[ $n ];
				if ( 1 !== count( $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
					$found[] = sprintf( '%d: %s() argument %d (%s) is not a single string literal', $line, $name, $n + 1, $role );
					continue;
				}
				$value = self::literal( $arg[0][1] );
				if ( 'domain' === $role && self::DOMAIN !== $value ) {
					$found[] = sprintf( '%d: %s() uses the text domain "%s"', $line, $name, $value );
				}
				if ( 'text' === $role && 1 === preg_match( self::PLACEHOLDER, $value ) ) {
					$placeholders = true;
				}
			}
			// make-pot takes a comment ending on the call's line or the line before it.
			if ( $placeholders && ! in_array( $line, $comments, true ) && ! in_array( $line - 1, $comments, true ) ) {
				$found[] = sprintf( '%d: %s() has a placeholder but no translators comment on the line before it', $line, $name );
			}
		}
		return $found;
	}

	/**
	 * The next index from $i in $step direction that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Start.
	 * @param int               $step   1 or -1.
	 */
	private static function skip( array $tokens, int $i, int $step ): ?int {
		$count = count( $tokens );
		for ( ; $i >= 0 && $i < $count; $i += $step ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * The call's arguments from its opening parenthesis, each the list of its
	 * significant tokens.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $open   Index of "(".
	 * @return list<list<mixed>>
	 */
	private static function arguments( array $tokens, int $open ): array {
		$args    = [];
		$current = [];
		$depth   = 0;
		$count   = count( $tokens );
		for ( $i = $open + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$text = is_array( $token ) ? $token[1] : $token;
			if ( 0 === $depth && ( ')' === $text || ',' === $text ) ) {
				if ( [] !== $current ) {
					$args[] = $current;
				}
				if ( ')' === $text ) {
					return $args;
				}
				$current = [];
				continue;
			}
			if ( in_array( $text, [ '(', '[', '{' ], true ) || ( is_array( $token ) && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
				++$depth;
			} elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
				--$depth;
			}
			$current[] = $token;
		}
		return $args;
	}

	/**
	 * A quoted PHP string literal's value.
	 *
	 * @param string $quoted The literal as written.
	 */
	private static function literal( string $quoted ): string {
		$inner = substr( $quoted, 1, -1 );
		if ( '"' === $quoted[0] ) {
			return stripcslashes( $inner );
		}
		$escapes = [
			"\\'"  => "'",
			'\\\\' => '\\',
		];
		return strtr( $inner, $escapes );
	}

	public function test_the_scanner_catches_each_kind_of_offence(): void {
		$code = <<<'PHP'
<?php
__( $text, 'bulk-catalogue-manager-for-woocommerce' );
__( 'A ' . 'B', 'bulk-catalogue-manager-for-woocommerce' );
__( 'Hello', 'woocommerce' );
__( 'Hello', $domain );
__( 'Hello' );
sprintf( __( 'Hello %s', 'bulk-catalogue-manager-for-woocommerce' ), $name );
_n( 'One %d', 'Many %d', $n, 'bulk-catalogue-manager-for-woocommerce' );
_x( 'Hello', $context, 'bulk-catalogue-manager-for-woocommerce' );
/* translators: %s: a name. */
sprintf( __( 'Hi %s', 'bulk-catalogue-manager-for-woocommerce' ), $name );
__( '100%% sure', 'bulk-catalogue-manager-for-woocommerce' );
$object->__( 'Not gettext', 'other' );
_n( 'One', 'Many', count( $list ), 'bulk-catalogue-manager-for-woocommerce' );
PHP;
		$this->assertSame(
			[
				'2: __() argument 1 (text) is not a single string literal',
				'3: __() argument 1 (text) is not a single string literal',
				'4: __() uses the text domain "woocommerce"',
				'5: __() argument 2 (domain) is not a single string literal',
				'6: __() takes 2 arguments here, the last the literal text domain',
				'7: __() has a placeholder but no translators comment on the line before it',
				'8: _n() has a placeholder but no translators comment on the line before it',
				'9: _x() argument 2 (context) is not a single string literal',
			],
			self::offences( $code )
		);
	}

	public function test_every_gettext_call_is_literal_in_the_plugins_domain_with_translator_comments(): void {
		$sources = self::sources();
		$this->assertContains( 'src/Plugin.php', $sources );
		$found = [];
		foreach ( $sources as $file ) {
			foreach ( self::offences( (string) file_get_contents( self::root() . '/' . $file ) ) as $offence ) {
				$found[] = $file . ':' . $offence;
			}
		}
		$this->assertSame( [], $found, "Fix these translation calls:\n" . implode( "\n", $found ) );
	}

	public function test_the_committed_pot_is_fresh(): void {
		$root = self::root();
		$wp   = $root . '/vendor/bin/wp';
		if ( ! is_file( $wp ) || ! is_file( $root . '/vendor/wp-cli/i18n-command/i18n-command.php' ) ) {
			$this->markTestSkipped( 'wp i18n make-pot is not installed (composer install brings wp-cli/i18n-command), so the .pot freshness check did not run.' );
		}
		$this->assertFileExists( $root . '/' . self::POT, 'Run composer make-pot and commit ' . self::POT . '.' );

		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$script   = (string) ( $composer['scripts']['make-pot'] ?? '' );
		$this->assertStringStartsWith( 'wp i18n make-pot . ' . self::POT . ' ', $script, 'The make-pot script writes the committed .pot.' );

		$fresh   = sys_get_temp_dir() . '/bulkcat-pot-' . bin2hex( random_bytes( 6 ) ) . '.pot';
		$command = sprintf(
			'cd %s && %s i18n make-pot . %s %s 2>&1',
			escapeshellarg( $root ),
			escapeshellarg( $wp ),
			escapeshellarg( $fresh ),
			substr( $script, strlen( 'wp i18n make-pot . ' . self::POT . ' ' ) )
		);
		exec( $command, $output, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- CLI test running the project's own dev tool.
		$generated = is_file( $fresh ) ? (string) file_get_contents( $fresh ) : '';
		if ( is_file( $fresh ) ) {
			unlink( $fresh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI test cleanup.
		}
		$this->assertSame( 0, $exit, "make-pot failed:\n" . implode( "\n", $output ) );

		$strip = static fn( string $pot ): string => (string) preg_replace( '/^"POT-Creation-Date: [^"]*"\n/m', '', $pot );
		$this->assertSame( $strip( (string) file_get_contents( $root . '/' . self::POT ) ), $strip( $generated ), 'The .pot is stale: run composer make-pot and commit ' . self::POT . '.' );
	}

	public function test_script_translations_are_set_for_every_registered_script(): void {
		$handles = [];
		$calls   = '';
		foreach ( self::sources() as $file ) {
			$code = (string) file_get_contents( self::root() . '/' . $file );
			if ( preg_match_all( '/wp_(?:register|enqueue)_script\(\s*\'(bulkcat-[a-z0-9-]+)\'/', $code, $matches ) ) {
				$handles = array_merge( $handles, $matches[1] );
			}
			$calls .= $code;
		}
		$this->assertContains( 'bulkcat-app', $handles, 'The screen script (T8) is registered with a literal handle.' );
		foreach ( array_unique( $handles ) as $handle ) {
			$this->assertSame(
				1,
				preg_match( '/wp_set_script_translations\(\s*\'' . preg_quote( $handle, '/' ) . '\',\s*\'' . self::DOMAIN . '\',\s*[^)]*languages/', $calls ),
				"Script $handle needs wp_set_script_translations( '$handle', '" . self::DOMAIN . "', <plugin dir>/languages )."
			);
		}
		$this->assertSame(
			1,
			preg_match( '/^ \* Text Domain:\s+' . self::DOMAIN . '$/m', (string) file_get_contents( self::root() . '/bulk-catalogue-manager-for-woocommerce.php' ) ),
			'The plugin header names the text domain.'
		);
	}
}
