<?php
/**
 * The columns of a catalogue export and their headings (SPEC §10).
 *
 * With the identifier switch off, the columns are exactly the ones sent and
 * there are no labels of its own, so CsvExport writes each column's registry
 * label and the file is byte for byte what the export wrote before the
 * switch existed.
 *
 * With the switch on, `id`, `sku`, `parent_sku` and `type` come first, then
 * the sent columns in the order sent, less any identifier already placed.
 * The four identifier headings are literal English, not gettext strings:
 * WooCommerce's product importer maps exactly these headings in every site
 * language, through the English fallback it adds to its automatic mapping
 * whenever the importing user's locale is not English
 * (`admin/importers/mappings/default.php`), so a translated heading would
 * only lose the mapping. Every other column keeps its registry label, and
 * a column outside ROUND_TRIP gets "(for reference)" after it, so the
 * importer never maps a column whose values would not read back unchanged
 * (ticket 10, R5). The list is an allow-list: a field added later is for
 * reference until the round trip proves it.
 *
 * The values of the identifier block come from ReimportIdentifiers; the
 * rows are read with fields() so each row carries what that block needs.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * An export's columns and headings.
 */
final class ColumnPlan {
	/**
	 * The column holding an item's parent: its SKU, or `id:` and its ID. It
	 * is produced by the export, not a registry field.
	 */
	public const PARENT = 'parent_sku';

	/**
	 * The identifier block, in order.
	 */
	public const IDENTIFIERS = [ 'id', 'sku', self::PARENT, 'type' ];

	/**
	 * The identifier block's headings: literal English, as the importer maps them.
	 */
	public const HEADINGS = [
		'id'         => 'ID',
		'sku'        => 'SKU',
		self::PARENT => 'Parent',
		'type'       => 'Type',
	];

	/**
	 * Registry fields the identifier block reads from each row.
	 */
	public const IDENTIFIER_FIELDS = [ 'id', 'sku', 'type' ];

	/**
	 * Fields the importer's round trip reads back unchanged (T102 proves them
	 * on both supported WooCommerce versions, with both separators).
	 */
	public const READ_BACK = [ 'id', 'sku', 'type', 'name', 'featured', 'regular_price', 'sale_price', 'tax_status', 'tax_class', 'weight', 'length', 'width', 'height' ];

	/**
	 * Fields whose headings the importer does not map by default (T102's
	 * mapping probe proves each maps to nothing).
	 */
	public const NOT_MAPPED = [ 'status', 'catalog_visibility', 'visible_on_site', 'stock_status', 'manage_stock', 'stock_quantity', 'sold_individually', 'reviews_allowed', 'thumbnail', 'date_created', 'date_modified', 'units_sold', 'variation_count' ];

	/**
	 * Fields whose headings stay as they are with the switch on; any other
	 * field is "(for reference)".
	 */
	public const ROUND_TRIP = [ ...self::READ_BACK, ...self::NOT_MAPPED ];

	/**
	 * The suffix's English format, used when a translation loses the label or the marker.
	 */
	private const FOR_REFERENCE = '%s (for reference)';

	/**
	 * Plan an export.
	 *
	 * @param Registry     $registry    Field registry, for labels.
	 * @param list<string> $sent        The columns sent, in order: registry keys, distinct (the controller checks them).
	 * @param bool         $identifiers Whether the identifier switch is on.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly array $sent,
		private readonly bool $identifiers
	) {}

	/**
	 * Whether the identifier switch is on.
	 */
	public function identifiers(): bool {
		return $this->identifiers;
	}

	/**
	 * The file's columns, in order.
	 *
	 * @return list<string>
	 */
	public function columns(): array {
		if ( ! $this->identifiers ) {
			return $this->sent;
		}
		return [ ...self::IDENTIFIERS, ...array_values( array_diff( $this->sent, self::IDENTIFIERS ) ) ];
	}

	/**
	 * The headings by column, for CsvExport's labels; null with the switch
	 * off, so CsvExport writes the registry labels as it always has.
	 *
	 * @return array<string, string>|null
	 */
	public function labels(): ?array {
		if ( ! $this->identifiers ) {
			return null;
		}
		$labels = [];
		foreach ( $this->columns() as $key ) {
			if ( isset( self::HEADINGS[ $key ] ) ) {
				$labels[ $key ] = self::HEADINGS[ $key ];
				continue;
			}
			$label          = (string) $this->registry->get( $key )?->label();
			$labels[ $key ] = in_array( $key, self::ROUND_TRIP, true ) ? $label : sprintf( self::for_reference(), $label );
		}
		return $labels;
	}

	/**
	 * The registry fields each row is read with: the sent columns, and with
	 * the switch on the identifier block's fields too, each once.
	 *
	 * @return list<string>
	 */
	public function fields(): array {
		if ( ! $this->identifiers ) {
			return $this->sent;
		}
		return [ ...$this->sent, ...array_values( array_diff( self::IDENTIFIER_FIELDS, $this->sent ) ) ];
	}

	/**
	 * The "(for reference)" format in the site language, or the English one
	 * when the translation does not keep exactly one label placeholder and
	 * some marker beside it: a heading that lost its marker could be mapped
	 * by the importer again.
	 */
	private static function for_reference(): string {
		/* translators: %s: a column's heading, such as "Categories". The words added mark a column the product importer should not map. */
		$format = __( '%s (for reference)', 'bulk-catalogue-manager-for-woocommerce' );
		if ( 1 !== substr_count( $format, '%s' ) || 1 !== substr_count( $format, '%' ) || '' === trim( str_replace( '%s', '', $format ) ) ) {
			return self::FOR_REFERENCE;
		}
		return $format;
	}
}
