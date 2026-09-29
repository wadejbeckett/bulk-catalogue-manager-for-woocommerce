<?php
/**
 * A write-only stream counting the bytes written, for the POST export's
 * time-limit test (ExportPostRouteTest): the `wc_set_time_limit()` stub
 * records how many bytes had been written when it was called.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

/**
 * Counts bytes into $GLOBALS['bulkcat_test']['time_limit_bytes'] and keeps them.
 */
final class CountingStream {
	/**
	 * Every byte written.
	 */
	public static string $all = '';

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
		$GLOBALS['bulkcat_test']['time_limit_bytes'] = (int) ( $GLOBALS['bulkcat_test']['time_limit_bytes'] ?? 0 ) + strlen( $data );
		self::$all                                  .= $data;
		return strlen( $data );
	}

	public function stream_flush(): bool {
		return true;
	}
}
