<?php
/**
 * Standalone runner for hosts without PHPUnit or Composer.
 *
 *     php tests/run-tests.php
 *
 * With PHPUnit installed prefer: vendor/bin/phpunit
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

$bcm_phpunit = dirname( __DIR__ ) . '/vendor/bin/phpunit';
if ( is_file( $bcm_phpunit ) ) {
	// PHPUnit is installed: it owns the run. The shim runner exists only for hosts without it.
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $bcm_phpunit ) . ' -c ' . escapeshellarg( dirname( __DIR__ ) . '/phpunit.xml.dist' ), $bcm_exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- CLI test runner.
	exit( $bcm_exit );
}

require_once __DIR__ . '/bootstrap.php';

$top    = glob( __DIR__ . '/Unit/*Test.php' );
$nested = glob( __DIR__ . '/Unit/*/*Test.php' );
$files  = array_merge( false === $top ? [] : $top, false === $nested ? [] : $nested );
sort( $files );

$passed  = 0;
$failed  = 0;
$skipped = 0;
$errors  = [];

foreach ( $files as $file ) {
	$before = get_declared_classes();
	require_once $file;

	foreach ( array_diff( get_declared_classes(), $before ) as $class ) {
		if ( ! is_subclass_of( $class, PHPUnit\Framework\TestCase::class ) || ! str_ends_with( $class, 'Test' ) ) {
			continue;
		}
		foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$name = $method->getName();
			if ( ! str_starts_with( $name, 'test' ) ) {
				continue;
			}
			$label = "$class::$name";
			try {
				( new $class() )->runTest( $name );
				++$passed;
			} catch ( PHPUnit\Framework\SkippedTestError $e ) {
				++$skipped;
				$errors[] = "SKIPPED $label: " . $e->getMessage();
			} catch ( Throwable $e ) {
				++$failed;
				$errors[] = "FAILED $label: " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			}
		}
	}
}

foreach ( $errors as $line ) {
	fwrite( STDERR, $line . PHP_EOL );
}
printf( 'Tests: %d passed, %d failed, %d skipped.%s', $passed, $failed, $skipped, PHP_EOL );
exit( $failed > 0 ? 1 : 0 );
