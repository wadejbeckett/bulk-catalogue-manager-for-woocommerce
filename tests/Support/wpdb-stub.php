<?php
/**
 * A recording stand-in for WordPress's $wpdb: every statement sent through
 * query(), get_var() or get_col() is logged, SHOW TABLES LIKE answers from
 * $tables, and get_col() answers LIKE queries from $options.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support;

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! class_exists( WpdbStub::class ) ) {
	final class WpdbStub {
		public string $prefix = 'wp_';

		public string $options = 'wp_options';

		/**
		 * Every statement run, in order.
		 *
		 * @var string[]
		 */
		public array $queries = [];

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
		 * Substitutes %s as a quoted string and %d as an integer, in order.
		 */
		public function prepare( string $query, mixed ...$args ): string {
			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( array $m ) use ( &$args ): string {
					$value = array_shift( $args );
					return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;
			if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $m ) ) {
				unset( $this->tables[ $m[1] ] );
			}
			return true;
		}

		public function get_var( string $query ): ?string {
			$this->queries[] = $query;
			if ( 1 === preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
				$name = self::unlike( stripslashes( $m[1] ) );
				return isset( $this->tables[ $name ] ) ? $name : null;
			}
			return null;
		}

		/**
		 * Answers `SELECT option_name FROM wp_options WHERE option_name LIKE '<prefix>%'`.
		 *
		 * @return string[]
		 */
		public function get_col( string $query ): array {
			$this->queries[] = $query;
			if ( 1 !== preg_match( "/^SELECT option_name FROM wp_options WHERE option_name LIKE '(.*)%'$/", $query, $m ) ) {
				return [];
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
