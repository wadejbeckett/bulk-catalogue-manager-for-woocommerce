<?php
/**
 * A recording stand-in for WordPress's $wpdb. Every statement sent through
 * query(), get_var(), get_row(), get_results() or get_col() is logged in
 * $queries; every prepare() call is logged in $prepared with its arguments;
 * insert() and update() are logged in $writes. Reads answer from $responses,
 * a map of exact SQL (or a '/regex/' key) to the value to return, and fall
 * back to: SHOW TABLES LIKE answered from $tables, get_col() LIKE queries on
 * the options table answered from $option_rows, otherwise null or [].
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support;

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! class_exists( WpdbStub::class ) ) {
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	final class WpdbStub {
		public string $prefix = 'wp_';

		public string $options = 'wp_options';

		public int $insert_id = 0;

		public string $last_error = '';

		public int $reconnect_retries = 5;

		/** Native connection metadata is supplied only by transaction fault tests. */
		public mixed $dbh = null;

		/**
		 * Every statement run, in order.
		 *
		 * @var string[]
		 */
		public array $queries = [];

		/**
		 * Every prepare() call: [ query, args ].
		 *
		 * @var array<int, array{0: string, 1: array}>
		 */
		public array $prepared = [];

		/**
		 * Every insert() and update() call: [ method, table, data, format, where, where_format ].
		 *
		 * @var array<int, array>
		 */
		public array $writes = [];

		/**
		 * Canned results: exact SQL, or a key starting with '/' used as a regex, => return value.
		 * A Closure value is called with the SQL and its result returned.
		 *
		 * @var array<string, mixed>
		 */
		public array $responses = [];

		/**
		 * Methods (insert, update, query) that return false, as on a database error;
		 * `insert:<table>` fails only the inserts into that table.
		 *
		 * @var string[]
		 */
		public array $failing = [];

		/**
		 * Existing tables, full name => true.
		 *
		 * @var array<string, bool>
		 */
		public array $tables = [];

		/**
		 * Rows of the options table, option_name => value.
		 *
		 * @var array<string, mixed>
		 */
		public array $option_rows = [];

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		/**
		 * Substitutes %s as a quoted string, %d as an integer, %f as a float and
		 * %i as a backticked identifier, in order; %% is a literal percent. Like
		 * WordPress, a single array argument is the argument list.
		 */
		public function prepare( string $query, mixed ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$this->prepared[] = [ $query, $args ];
			return (string) preg_replace_callback(
				'/%%|%[sdfi]/',
				static function ( array $m ) use ( &$args ): string {
					if ( '%%' === $m[0] ) {
						return '%';
					}
					$value = array_shift( $args );
					return match ( $m[0] ) {
						'%d'    => (string) (int) $value,
						'%f'    => (string) (float) $value,
						'%i'    => '`' . str_replace( '`', '``', (string) $value ) . '`',
						default => "'" . addslashes( (string) $value ) . "'",
					};
				},
				$query
			);
		}

		/**
		 * The canned response for a statement, or $fallback.
		 */
		private function respond( string $query, mixed $fallback ): mixed {
			if ( array_key_exists( $query, $this->responses ) ) {
				$value = $this->responses[ $query ];
				return $value instanceof \Closure ? $value( $query ) : $value;
			}
			foreach ( $this->responses as $key => $value ) {
				if ( str_starts_with( (string) $key, '/' ) && 1 === preg_match( (string) $key, $query ) ) {
					return $value instanceof \Closure ? $value( $query ) : $value;
				}
			}
			return $fallback;
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;
			if ( in_array( 'query', $this->failing, true ) ) {
				return false;
			}
			if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $m ) ) {
				unset( $this->tables[ $m[1] ] );
			}
			return $this->respond( $query, true );
		}

		public function insert( string $table, array $data, array|string|null $format = null ): int|false {
			$this->writes[] = [ 'insert', $table, $data, $format, null, null ];
			if ( in_array( 'insert', $this->failing, true ) || in_array( 'insert:' . $table, $this->failing, true ) ) {
				return false;
			}
			++$this->insert_id;
			return 1;
		}

		public function update( string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null ): int|false {
			$this->writes[] = [ 'update', $table, $data, $format, $where, $where_format ];
			return $this->update_fails( $table, $data ) ? false : 1;
		}

		/**
		 * Whether an update() fails: every update with 'update' in $failing,
		 * or one writing a column named in 'update:<table>:<column>' (T111: a
		 * lost race to move a batch's status, while its other updates land).
		 *
		 * @param string               $table Table.
		 * @param array<string, mixed> $data  Columns written.
		 */
		public function update_fails( string $table, array $data ): bool {
			if ( in_array( 'update', $this->failing, true ) ) {
				return true;
			}
			foreach ( array_keys( $data ) as $column ) {
				if ( in_array( 'update:' . $table . ':' . $column, $this->failing, true ) ) {
					return true;
				}
			}
			return false;
		}

		public function get_var( string $query, int $x = 0, int $y = 0 ): ?string {
			$this->queries[] = $query;
			// MySQL named locks (T83's scheduling lock) are granted unless a test answers that exact statement.
			if ( 1 === preg_match( '/^SELECT (GET_LOCK|RELEASE_LOCK)\(/', $query ) && ! array_key_exists( $query, $this->responses ) ) {
				return '1';
			}
			if ( 1 === preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
				$name = self::unlike( stripslashes( $m[1] ) );
				return isset( $this->tables[ $name ] ) ? $name : null;
			}
			$value = $this->respond( $query, null );
			return null === $value ? null : (string) $value;
		}

		public function get_row( string $query, string $output = OBJECT, int $y = 0 ): array|object|null {
			$this->queries[] = $query;
			return $this->respond( $query, null );
		}

		public function get_results( string $query, string $output = OBJECT ): array {
			$this->queries[] = $query;
			return $this->respond( $query, [] );
		}

		/**
		 * Answers `SELECT option_name FROM wp_options WHERE option_name LIKE '<prefix>%'`
		 * from $option_rows, anything else from $responses.
		 *
		 * @return string[]
		 */
		public function get_col( string $query ): array {
			$this->queries[] = $query;
			if ( 1 !== preg_match( "/^SELECT option_name FROM wp_options WHERE option_name LIKE '(.*)%'$/", $query, $m ) ) {
				return $this->respond( $query, [] );
			}
			$prefix = self::unlike( stripslashes( $m[1] ) );
			return array_values( array_filter( array_keys( $this->option_rows ), static fn( string $name ): bool => str_starts_with( $name, $prefix ) ) );
		}

		/**
		 * Undo esc_like().
		 */
		private static function unlike( string $text ): string {
			return (string) preg_replace( '/\\\\([_%\\\\])/', '$1', $text );
		}
	}
}
