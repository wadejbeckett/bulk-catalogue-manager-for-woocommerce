<?php
/**
 * What the native probes share (ticket 11): the guard that keeps them to a
 * disposable fixture, and a bounded drain of the plugin's cleanup.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bulkcat_probe_fixture' ) ) {
	/**
	 * Whether this is a WP-CLI process on a disposable fixture: the owned
	 * T81–T85 fixture (`http://localhost:18925`), or the throwaway site the
	 * environment names in BULKCAT_PROBE_SITEURL when that is this site's
	 * URL on a local development host (localhost, 127.0.0.1, [::1],
	 * *.localhost, *.test). Never a real shop.
	 */
	function bulkcat_probe_fixture(): bool {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return false;
		}
		$site = (string) get_option( 'siteurl' );
		if ( 'http://localhost:18925' === $site ) {
			return true;
		}
		$named = (string) getenv( 'BULKCAT_PROBE_SITEURL' );
		if ( '' === $named || $named !== $site ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
		return in_array( $host, [ 'localhost', '127.0.0.1', '[::1]' ], true ) || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
	}
}

if ( ! function_exists( 'bulkcat_probe_drain' ) ) {
	/**
	 * Run one of the plugin's bounded cleanups (RequestService::
	 * prune_selections() or prune_requests(): at most 100 rows a run,
	 * oldest first) until a run takes fewer than a full run's worth, at
	 * most $ceiling runs. A probe that checks its own row is gone drains
	 * rather than calling the cleanup once, so a backlog left by earlier
	 * runs (more than 100 older rows) cannot hide its row.
	 *
	 * @param callable $run     The cleanup; returns how many rows it removed.
	 * @param int      $ceiling Most runs before giving up.
	 * @return int Rows removed.
	 * @throws RuntimeException When the backlog outlasts the ceiling.
	 */
	function bulkcat_probe_drain( callable $run, int $ceiling = 100 ): int {
		$total = 0;
		for ( $i = 0; $i < $ceiling; ++$i ) {
			$removed = (int) $run();
			$total  += $removed;
			if ( $removed < 100 ) {
				return $total;
			}
		}
		throw new RuntimeException( sprintf( 'The cleanup still had a backlog after %d runs.', $ceiling ) );
	}
}
