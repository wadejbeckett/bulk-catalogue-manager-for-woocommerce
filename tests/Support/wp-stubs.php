<?php
/**
 * WordPress function stubs for unit tests. Under WordPress this file defines nothing.
 * The stubs record hooks and options in $GLOBALS['bcm_test'] so tests can inspect them.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( function_exists( 'add_filter' ) ) {
	return;
}

function bcm_test_state(): array {
	return [
		'filters'     => [],
		'options'     => [],
		'did_actions' => [],
	];
}

$GLOBALS['bcm_test'] = bcm_test_state();

function bcm_test_reset(): void {
	$GLOBALS['bcm_test'] = bcm_test_state();
}

function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['bcm_test']['filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	return true;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$entries = $GLOBALS['bcm_test']['filters'][ $hook ] ?? [];
	usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $entries as [ $callback, , $accepted ] ) {
		$value = $callback( ...array_slice( [ $value, ...$args ], 0, max( 1, $accepted ) ) );
	}
	return $value;
}

function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['bcm_test']['did_actions'][ $hook ] = ( $GLOBALS['bcm_test']['did_actions'][ $hook ] ?? 0 ) + 1;
	$entries                                     = $GLOBALS['bcm_test']['filters'][ $hook ] ?? [];
	usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $entries as [ $callback, , $accepted ] ) {
		$callback( ...array_slice( $args, 0, $accepted ) );
	}
}

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['bcm_test']['did_actions'][ $hook ] ?? 0 );
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['bcm_test']['options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value ): bool {
	$GLOBALS['bcm_test']['options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['bcm_test']['options'][ $name ] );
	return true;
}
