<?php
/**
 * Catalogue export file names (SPEC §10).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

defined( 'ABSPATH' ) || exit;

/**
 * `catalogue-{selected|filtered|all}[-with-ids]-{Y-m-d}-{Hi}.csv`, in the
 * site's time: `selected` for a selection or an ID list, `filtered` for a
 * filter with conditions, `all` for the whole catalogue, and `-with-ids`
 * with the identifier switch on. Only these fixed words and the time make
 * the name, so no request value can enter it. History exports keep their
 * own names (HistoryColumns::filename()).
 */
final class ExportName {
	/**
	 * A selection or an ID list.
	 */
	public const SELECTED = 'selected';

	/**
	 * A filter with conditions.
	 */
	public const FILTERED = 'filtered';

	/**
	 * The whole catalogue.
	 */
	public const ALL = 'all';

	/**
	 * Every row word.
	 */
	public const ROWS = [ self::SELECTED, self::FILTERED, self::ALL ];

	/**
	 * The download name.
	 *
	 * @param string             $rows        One of ROWS.
	 * @param bool               $identifiers Whether the identifier switch is on.
	 * @param \DateTimeInterface $now         The site's current time (current_datetime()).
	 * @throws \InvalidArgumentException When $rows is not one of ROWS.
	 */
	public static function filename( string $rows, bool $identifiers, \DateTimeInterface $now ): string {
		if ( ! in_array( $rows, self::ROWS, true ) ) {
			throw new \InvalidArgumentException( 'The rows word must be one of ROWS.' );
		}
		return 'catalogue-' . $rows . ( $identifiers ? '-with-ids' : '' ) . '-' . $now->format( 'Y-m-d-Hi' ) . '.csv';
	}
}
