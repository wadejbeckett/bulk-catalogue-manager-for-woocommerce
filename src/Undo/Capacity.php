<?php
/**
 * How large a change the screen can promise to undo on this host (T85).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * An undo plan (Planner::plan()) holds every item of the batch in the one
 * web request that previews or accepts the undo: about 2.7 KB an item,
 * measured on a 20,013-item plan (T85). Until the plan is paged, the screen
 * promises undo only for changes whose items fit, conservatively, in what a
 * web request may use here: its memory limit less its own footprint, three
 * quarters of the rest.
 *
 * The memory limit is the one a plain web request — the REST request that
 * plans the undo — really runs under (ticket 11): never the value an admin
 * page runs at after WordPress raised it for administrators
 * (wp_raise_memory_limit( 'admin' ), up to WP_MAX_MEMORY_LIMIT), and never
 * above what the configured value allows. See web_limit().
 *
 * Proved on PHP-FPM behind nginx (php.ini, pool values, .user.ini,
 * WP_MEMORY_LIMIT, WP_MAX_MEMORY_LIMIT, multisite, WordPress in its own
 * directory). Not tested: Apache mod_php `php_value` in .htaccess and
 * LiteSpeed's own settings; there the value noted as the plugin loads still
 * caps the result.
 */
final class Capacity {
	/**
	 * Memory an undo plan holds per item, in bytes.
	 */
	public const ITEM_BYTES = 2765;

	/**
	 * A web request's own footprint with a margin: WordPress, WooCommerce
	 * and the plugin loaded (about 16 MB here) and the rest of the undo
	 * request.
	 */
	public const REQUEST_BYTES = 33554432;

	/**
	 * The share of the remaining memory the plan may take.
	 */
	public const SHARE = 0.75;

	/**
	 * What a limit of -1 ("no limit") is counted as: it says nothing about
	 * the memory the host has, and no undo larger than a 128 MB request holds
	 * has been measured, so it counts as 128 MB.
	 */
	public const UNLIMITED = '128M';

	/**
	 * Most directories read for .user.ini files.
	 */
	private const MAX_DIRECTORIES = 12;

	/**
	 * PHP interfaces that read a per-directory .user.ini file.
	 */
	private const USER_INI_SAPIS = [ 'fpm-fcgi', 'cgi-fcgi' ];

	/**
	 * The memory limit noted when the plugin loaded, before any admin raise.
	 *
	 * @var string|null
	 */
	private static ?string $observed = null;

	/**
	 * Note the memory limit this request runs under now. The plugin file
	 * calls this as it loads: after WordPress applied WP_MEMORY_LIMIT
	 * (wp_initial_constants()) and before wp-admin/admin.php raises the limit
	 * for administrators, as WordPress's own Site Health does. Only the
	 * first call counts.
	 */
	public static function observe(): void {
		self::$observed ??= (string) ini_get( 'memory_limit' );
	}

	/**
	 * The value observe() noted, or null.
	 */
	public static function observed(): ?string {
		return self::$observed;
	}

	/**
	 * Forget the noted value (tests).
	 */
	public static function forget(): void {
		self::$observed = null;
	}

	/**
	 * The most items an undo can plan in one web request.
	 *
	 * @param string|null $limit A memory limit in PHP's shorthand; the web request's by default.
	 */
	public static function items( ?string $limit = null ): int {
		$bytes = self::counted( $limit ?? self::web_limit() );
		return max( 0, (int) floor( ( $bytes - self::REQUEST_BYTES ) * self::SHARE / self::ITEM_BYTES ) );
	}

	/**
	 * The memory limit a plain web request runs under here, conservatively:
	 * the lower of
	 *
	 * - the value this request had when the plugin loaded (observe()), which
	 *   includes php.ini, the PHP-FPM pool, .user.ini and WordPress's raise
	 *   to WP_MEMORY_LIMIT but not the later raise for administrators; the
	 *   value now when nothing was noted, and
	 * - the configured value — PHP's master value (php.ini and the PHP-FPM
	 *   pool), lowered, never raised, by a .user.ini where PHP reads one for
	 *   a REST request (configured(), user_ini_directories()) — raised to
	 *   WP_MEMORY_LIMIT the way WordPress raises it: only when it is higher
	 *   and the limit may be changed.
	 *
	 * get_cfg_var() is not used: it reads php.ini alone, missing the pool
	 * and .user.ini.
	 */
	public static function web_limit(): string {
		$entry  = function_exists( 'ini_get_all' ) ? ( ini_get_all( null, true )['memory_limit'] ?? null ) : null;
		$master = is_array( $entry ) ? (string) $entry['global_value'] : (string) ini_get( 'memory_limit' );
		// As wp_is_ini_value_changeable(): changeable when ini_set() may change it, or when that cannot be read.
		$access     = is_array( $entry ) ? (int) $entry['access'] : INI_ALL;
		$changeable = ! is_array( $entry ) || in_array( $access & INI_ALL, [ INI_ALL, INI_USER ], true );
		// The REST request's index.php is in the site's home directory (get_home_path(), on admin pages), below the document root.
		$home = function_exists( 'get_home_path' ) ? (string) get_home_path() : ABSPATH;
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';
		$user = self::user_ini_values( self::user_ini_directories( ABSPATH, $home, $root ), (string) ini_get( 'user_ini.filename' ), PHP_SAPI, $access );
		return self::effective(
			self::$observed ?? (string) ini_get( 'memory_limit' ),
			self::configured( $master, $user ),
			defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			$changeable
		);
	}

	/**
	 * The configured value: PHP's master value, lowered by a .user.ini value
	 * below it; a .user.ini value above it is ignored (the conservative
	 * choice, ticket 11: a raise in a directory the REST request does not
	 * read would over-promise).
	 *
	 * @param string       $master PHP's master value (php.ini and the pool).
	 * @param list<string> $user   memory_limit values found in .user.ini files.
	 * @return string The value, in PHP's shorthand.
	 */
	public static function configured( string $master, array $user ): string {
		$value = $master;
		foreach ( $user as $candidate ) {
			if ( self::bytes( $candidate ) >= 0 && self::bytes( $candidate ) < self::counted( $value ) ) {
				$value = $candidate;
			}
		}
		return $value;
	}

	/**
	 * The directories whose .user.ini a REST request may read: the site's
	 * home directory (where its index.php runs) and each directory above it
	 * up to the document root, as PHP scans them, then ABSPATH (only lowers
	 * count, so reading it too can only be more careful).
	 *
	 * @param string $abspath       ABSPATH.
	 * @param string $home          The site's home directory.
	 * @param string $document_root The document root, or '' when unknown.
	 * @return list<string> Directories without a trailing slash, each once.
	 */
	public static function user_ini_directories( string $abspath, string $home, string $document_root ): array {
		$trim = static fn( string $path ): string => '/' === $path ? $path : rtrim( str_replace( '\\', '/', $path ), '/' );
		$home = $trim( $home );
		$root = '' === trim( $document_root ) ? '' : $trim( $document_root );
		$dirs = [ $home ];
		if ( '' !== $root && str_starts_with( $home . '/', $root . '/' ) ) {
			$dir = $home;
			for ( $depth = 1; $dir !== $root && $depth < self::MAX_DIRECTORIES; $depth++ ) {
				$dir    = dirname( $dir );
				$dirs[] = $dir;
			}
		}
		$dirs[] = $trim( $abspath );
		return array_values( array_unique( array_filter( $dirs, static fn( string $dir ): bool => '' !== $dir ) ) );
	}

	/**
	 * The memory_limit values the .user.ini files in these directories set.
	 *
	 * @param list<string> $dirs     Directories.
	 * @param string       $filename `user_ini.filename`.
	 * @param string       $sapi     PHP_SAPI.
	 * @param int          $access   Who may change memory_limit.
	 * @return list<string>
	 */
	public static function user_ini_values( array $dirs, string $filename, string $sapi, int $access ): array {
		$values = [];
		foreach ( $dirs as $dir ) {
			$value = self::user_ini( $dir, $filename, $sapi, $access );
			if ( null !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * The limit a plain web request runs under, from what was observed and
	 * what is configured: the observed value, never above the configured
	 * value raised to WP_MEMORY_LIMIT as WordPress raises it
	 * (wp_initial_constants(): only a changeable, limited value lower than
	 * WP_MEMORY_LIMIT; WP_MEMORY_LIMIT never lowers a limit). An unlimited
	 * value counts as UNLIMITED.
	 *
	 * @param string $observed   The value the request had before any admin raise.
	 * @param string $configured The configured value (php.ini, pool, .user.ini).
	 * @param string $wp_limit   WP_MEMORY_LIMIT, or '' when not defined.
	 * @param bool   $changeable Whether ini_set() may change the limit.
	 * @return string The limit, in PHP's shorthand.
	 */
	public static function effective( string $observed, string $configured, string $wp_limit, bool $changeable ): string {
		$allowed = $configured;
		if ( $changeable && '' !== trim( $wp_limit ) && self::bytes( $configured ) >= 0 && ( self::bytes( $wp_limit ) < 0 || self::bytes( $wp_limit ) > self::bytes( $configured ) ) ) {
			$allowed = $wp_limit;
		}
		$lower = self::counted( $observed ) <= self::counted( $allowed ) ? $observed : $allowed;
		return self::bytes( $lower ) < 0 ? self::UNLIMITED : trim( $lower );
	}

	/**
	 * The memory_limit a .user.ini file in a directory sets for the requests
	 * PHP serves from it, or null: PHP reads one only under PHP-FPM or
	 * FastCGI, only when `user_ini.filename` names it, and not when the
	 * limit is locked (php_admin_value). The file is read line by line, not
	 * with PHP's parser, so a malformed file raises no warning; of several
	 * assignments the lowest counts (PHP keeps what it parsed before a syntax
	 * error, and only a lower value is used).
	 *
	 * @param string $dir      A directory PHP reads .user.ini files from.
	 * @param string $filename `user_ini.filename`; empty switches .user.ini off.
	 * @param string $sapi     PHP_SAPI.
	 * @param int    $access   Who may change memory_limit (ini_get_all()'s `access`).
	 */
	public static function user_ini( string $dir, string $filename, string $sapi, int $access ): ?string {
		if ( '' === $filename || ! in_array( $sapi, self::USER_INI_SAPIS, true ) || 0 === ( $access & INI_PERDIR ) ) {
			return null;
		}
		$file = rtrim( $dir, '/\\' ) . '/' . $filename;
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$lowest = null;
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( 1 !== preg_match( '/^\s*memory_limit\s*=\s*"?\s*(-?[0-9]+[kmg]?)\s*"?\s*(?:;.*)?$/i', (string) $line, $match ) ) {
				continue;
			}
			if ( null === $lowest || self::counted( $match[1] ) < self::counted( $lowest ) ) {
				$lowest = $match[1];
			}
		}
		return $lowest;
	}

	/**
	 * Bytes of a value in PHP's shorthand (`128M`, `1G`, `512K`, bytes);
	 * -1 for no limit (or nothing readable).
	 *
	 * @param string $value Shorthand.
	 */
	public static function bytes( string $value ): int {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || str_starts_with( $value, '-' ) ) {
			return -1;
		}
		$number = (int) $value;
		return match ( substr( $value, -1 ) ) {
			'g'     => $number * 1073741824,
			'm'     => $number * 1048576,
			'k'     => $number * 1024,
			default => $number,
		};
	}

	/**
	 * Bytes a limit counts as: UNLIMITED for no limit.
	 *
	 * @param string $value Shorthand.
	 */
	private static function counted( string $value ): int {
		$bytes = self::bytes( $value );
		return $bytes < 0 ? self::bytes( self::UNLIMITED ) : $bytes;
	}
}
