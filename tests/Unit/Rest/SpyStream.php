<?php
/**
 * A spying output stream for the history export test (HistoryExportTest).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

/**
 * A write-only stream that records each write in the shared event log with
 * the memory in use, and keeps only a count of bytes and the first 64 KB.
 */
final class SpyStream {
	/**
	 * Events: [ 'write', bytes, memory ] for a run of writes, or [ 'read', page ].
	 *
	 * @var list<array<int, mixed>>
	 */
	public static array $log = [];

	/**
	 * The start of what was written.
	 */
	public static string $head = '';

	/**
	 * Every byte, when kept (small exports).
	 */
	public static string $all = '';

	/**
	 * Whether to keep every byte.
	 */
	public static bool $keep = false;

	/**
	 * Stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	public function stream_open( string $path, string $mode, int $options, ?string &$opened ): bool {
		unset( $path, $mode, $options, $opened );
		return true;
	}

	public function stream_write( string $data ): int {
		// Consecutive writes share one entry, so the log itself stays small: [ 'write', bytes, memory at the first ].
		$last = count( self::$log ) - 1;
		if ( $last >= 0 && 'write' === self::$log[ $last ][0] ) {
			self::$log[ $last ][1] += strlen( $data );
		} else {
			// The stubs' own call logs grow with every translated string and
			// recorded call; only tests keep them, so they are dropped before
			// measuring what the export itself holds.
			$GLOBALS['bcm_test']['gettext'] = [];
			$GLOBALS['bcm_test']['calls']   = [];
			self::$log[]                    = [ 'write', strlen( $data ), memory_get_usage() ];
		}
		if ( strlen( self::$head ) < 65536 ) {
			self::$head .= $data;
		}
		if ( self::$keep ) {
			self::$all .= $data;
		}
		return strlen( $data );
	}

	public function stream_flush(): bool {
		return true;
	}
}
