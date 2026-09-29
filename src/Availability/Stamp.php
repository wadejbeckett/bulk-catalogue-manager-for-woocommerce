<?php
/**
 * What a new Off record is stamped with (SPEC §5.5): the batch writing it
 * and the time now.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * The batch and clock a record written now carries.
 *
 * The writer runs each object inside within( its batch ), so a record an Off
 * writes names the batch that wrote it; the preview stamps batch 0, and its
 * records are never stored. Outside any within() the batch is 0 and the clock
 * is time(). A test passes its own clock; a nested within() given no clock
 * keeps the one around it.
 */
final class Stamp {
	/**
	 * The batch writing now.
	 *
	 * @var int
	 */
	private static int $batch = 0;

	/**
	 * The clock now, or null for time().
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $clock = null;

	/**
	 * Run work stamped with a batch (and a clock).
	 *
	 * @param int           $batch_id The batch writing, 0 for none.
	 * @param \Closure|null $clock    fn(): int Unix time, or null to keep the current clock.
	 * @param callable      $work     fn(): mixed.
	 * @return mixed What the work returned.
	 */
	public static function within( int $batch_id, ?\Closure $clock, callable $work ): mixed {
		$batch = self::$batch;
		$time  = self::$clock;

		self::$batch = max( 0, $batch_id );
		if ( null !== $clock ) {
			self::$clock = $clock;
		}
		try {
			return $work();
		} finally {
			self::$batch = $batch;
			self::$clock = $time;
		}
	}

	/**
	 * The batch writing now.
	 */
	public static function batch(): int {
		return self::$batch;
	}

	/**
	 * The time now, as a Unix time.
	 */
	public static function now(): int {
		return null === self::$clock ? time() : (int) ( self::$clock )();
	}

	/**
	 * The time now as a record stores it: UTC, to the second.
	 */
	public static function at(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', self::now() );
	}
}
