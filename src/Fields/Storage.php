<?php
/**
 * Where a field lives in WooCommerce (SPEC §5.2): a post column, a meta key,
 * a taxonomy (optionally narrowed to some of its terms) or a value WooCommerce
 * derives. Internal only: storage never reaches the REST API or the screen
 * (SPEC §12.1), and nothing writes through it directly — writes go through the
 * field's CRUD setter (SPEC §12.3). The read query builder (T18) uses it to
 * pick lookup-table columns and meta keys to prime.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable storage description.
 */
final class Storage {
	public const POST     = 'post';
	public const META     = 'meta';
	public const TAXONOMY = 'taxonomy';
	public const DERIVED  = 'derived';

	/**
	 * Every storage kind.
	 */
	public const KINDS = [ self::POST, self::META, self::TAXONOMY, self::DERIVED ];

	/**
	 * Build through the named constructors.
	 *
	 * @param string   $kind   One of KINDS.
	 * @param string   $key    Post column, meta key, taxonomy, or '' when derived.
	 * @param string[] $terms  For a taxonomy, the only terms the field uses; empty means any term.
	 * @param string[] $lookup Columns of wc_product_meta_lookup mirroring the value.
	 */
	private function __construct(
		private readonly string $kind,
		private readonly string $key,
		private readonly array $terms,
		private readonly array $lookup
	) {}

	/**
	 * A column of the posts table.
	 *
	 * @param string $column Column name.
	 */
	public static function post( string $column ): self {
		return new self( self::POST, $column, [], [] );
	}

	/**
	 * A post meta key, optionally mirrored in the lookup table.
	 *
	 * @param string   $meta_key Meta key.
	 * @param string[] $lookup   Lookup-table columns.
	 */
	public static function meta( string $meta_key, array $lookup = [] ): self {
		return new self( self::META, $meta_key, [], $lookup );
	}

	/**
	 * A taxonomy, optionally only some of its terms.
	 *
	 * @param string   $taxonomy Taxonomy name.
	 * @param string[] $terms    Term slugs; empty for any term.
	 */
	public static function taxonomy( string $taxonomy, array $terms = [] ): self {
		return new self( self::TAXONOMY, $taxonomy, $terms, [] );
	}

	/**
	 * A value WooCommerce computes rather than stores in one place.
	 *
	 * @param string[] $lookup Lookup-table columns that hold it, if any.
	 */
	public static function derived( array $lookup = [] ): self {
		return new self( self::DERIVED, '', [], $lookup );
	}

	/**
	 * One of KINDS.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * The post column, meta key or taxonomy; '' when derived.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * For a taxonomy, the term slugs the field uses; empty for any term.
	 *
	 * @return string[]
	 */
	public function terms(): array {
		return $this->terms;
	}

	/**
	 * Lookup-table columns mirroring the value.
	 *
	 * @return string[]
	 */
	public function lookup(): array {
		return $this->lookup;
	}

	/**
	 * Every raw identifier this storage names, for the test proving none
	 * reaches the client.
	 *
	 * @return string[]
	 */
	public function identifiers(): array {
		return array_values( array_filter( [ $this->key, ...$this->terms ] ) );
	}
}
