<?php
/**
 * A stream write filter for the export paging tests (SQR-3): it calls back
 * with each write, so a test can change the catalogue once the first page
 * has gone out.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Export;

/**
 * A write filter that calls back on each write reaching the stream.
 */
final class AfterFirstPage extends \php_user_filter {
	/**
	 * Called on each write.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $callback = null;

	public function filter( $in, $out, &$consumed, bool $closing ): int {
		$data = '';
		while ( $bucket = stream_bucket_make_writeable( $in ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the stream bucket idiom.
			$consumed += $bucket->datalen;
			$data     .= $bucket->data;
			stream_bucket_append( $out, $bucket );
		}
		if ( null !== self::$callback ) {
			( self::$callback )( $data );
		}
		return PSFS_PASS_ON;
	}
}
