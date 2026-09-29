<?php
/**
 * The explicit row sources of a catalogue export (SPEC §10): an ID list,
 * and a selection token.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Query\Hydrator;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an export's explicit rows into IdRows.
 */
final class ExportRows {
	/**
	 * The rows of an explicit ID list (at most 1,000, validated by ExportRequest).
	 *
	 * @param array<int, int> $ids      Positive ids; duplicates collapse.
	 * @param Registry        $registry Field registry, for hydration.
	 * @throws \RuntimeException On a database error.
	 */
	public static function ids( array $ids, Registry $registry ): IdRows {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return IdRows::resolve( $ids, new Hydrator( $registry ) );
	}
}
