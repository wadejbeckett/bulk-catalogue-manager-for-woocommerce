<?php
/**
 * PSR-4 autoloader for the BulkCatalogueManager namespace, used when
 * Composer's autoloader is absent (the distributed plugin ZIP and hosts
 * without Composer).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a PSR-4 loader for one base directory.
 */
final class Autoloader {
	/**
	 * Register the loader.
	 *
	 * @param string $base_dir Directory holding the namespace root.
	 */
	public static function register( string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/' ) . '/';
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$file = $base_dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}
