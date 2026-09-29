<?php
/**
 * The Off record (SPEC §5.5): the one product meta value that switching a
 * product Off through Status writes, holding the status it had, so that
 * switching it back On restores exactly that status.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable, product-bound record of the status a product had before Off.
 *
 * The stored value is canonical JSON, keys always in this order and slashes
 * unescaped: `{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":418}`.
 * `id` binds it to its product, so a copy on another product (WooCommerce's
 * Duplicate, which is told to leave it out, an import or a meta copy) is no
 * record for that product; `status` is the status before Off, one of
 * STATUSES (never draft); `at` is the UTC time Off wrote it; `batch` the
 * batch that wrote it (0 only in a preview, which never stores it). The value
 * holds only integers and fixed strings, so the unslashing WordPress applies
 * to stored meta cannot change it. Any other value — malformed JSON, another
 * key or order, another status, another id — is unreadable and reads as no
 * record, and a later Off overwrites it.
 *
 * This class is the only code naming the meta key. It reads and writes the
 * key through the product object's meta API and never saves: the caller
 * saves once, with the status. It stores no variation statuses, because Off
 * never changes a variation (SPEC §8).
 */
final class OffRecord {
	/**
	 * The product meta key. The leading underscore keeps it out of the
	 * editor's Custom Fields box, WooCommerce's CSV export and REST.
	 */
	public const META_KEY = '_bulkcat_availability_off';

	/**
	 * The internal field the record is journalled as ("Status before Off").
	 */
	public const FIELD = 'availability_off';

	/**
	 * The statuses a record may save: the listable ones other than draft.
	 */
	public const STATUSES = [ 'publish', 'future', 'pending', 'private' ];

	/**
	 * The shape of `at`: UTC, to the second.
	 */
	private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

	/**
	 * Build through create() or parse().
	 *
	 * @param int    $id     The product the record belongs to.
	 * @param string $status The status before Off.
	 * @param string $at     When Off wrote it, UTC.
	 * @param int    $batch  The batch that wrote it.
	 */
	private function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly string $at,
		public readonly int $batch
	) {}

	/**
	 * A new record.
	 *
	 * @param int    $id     The product's id, 1 or more.
	 * @param string $status The status before Off, one of STATUSES.
	 * @param string $at     UTC time as `Y-m-d\TH:i:s\Z`.
	 * @param int    $batch  The batch writing it, 0 or more.
	 * @throws \InvalidArgumentException When a part is outside the record's shape.
	 */
	public static function create( int $id, string $status, string $at, int $batch ): self {
		if ( $id < 1 || ! in_array( $status, self::STATUSES, true ) || 1 !== preg_match( self::TIME, $at ) || $batch < 0 ) {
			throw new \InvalidArgumentException( 'An Off record needs a product id, a status other than draft, a UTC time and a batch.' );
		}
		return new self( $id, $status, $at, $batch );
	}

	/**
	 * The record a stored value holds for a product, or null when it holds
	 * none: empty, unreadable, not in canonical form, or naming another product.
	 *
	 * @param mixed $raw The stored value.
	 * @param int   $id  The product it sits on.
	 */
	public static function parse( mixed $raw, int $id ): ?self {
		$record = self::read( $raw );
		return null !== $record && $record->id === $id ? $record : null;
	}

	/**
	 * The record a value holds, whichever product it names, or null when it
	 * is not a record in canonical form.
	 *
	 * @param mixed $raw A stored or journalled value.
	 */
	public static function read( mixed $raw ): ?self {
		if ( ! is_string( $raw ) || '' === $raw || ! str_starts_with( $raw, '{' ) ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || [ 'id', 'status', 'at', 'batch' ] !== array_keys( $data ) ) {
			return null;
		}
		if ( ! is_int( $data['id'] ) || ! is_string( $data['status'] ) || ! is_string( $data['at'] ) || ! is_int( $data['batch'] ) ) {
			return null;
		}
		try {
			$record = self::create( $data['id'], $data['status'], $data['at'], $data['batch'] );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		// Only the canonical encoding is a record, so the grid's SQL prefix match and PHP agree.
		return $record->to_json() === $raw ? $record : null;
	}

	/**
	 * The product's own record, or null.
	 *
	 * @param object $product A WooCommerce product (or a variation, which never has one).
	 */
	public static function for_product( object $product ): ?self {
		return self::parse( self::stored( $product ), self::id_of( $product ) );
	}

	/**
	 * The canonical JSON of the product's own record, or '' when it has none.
	 * This is the value the journal records and undo compares.
	 *
	 * @param object $product A WooCommerce product.
	 */
	public static function raw( object $product ): string {
		$record = self::for_product( $product );
		return null === $record ? '' : $record->to_json();
	}

	/**
	 * Whatever the product's meta holds under the key, readable or not.
	 *
	 * @param object $product A WooCommerce product.
	 */
	public static function stored( object $product ): string {
		if ( ! method_exists( $product, 'get_meta' ) ) {
			return '';
		}
		$value = $product->get_meta( self::META_KEY, true, 'edit' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Set the record on the product ('' removes it), through the product's
	 * own meta API. Never saves: the caller saves once, with the status.
	 *
	 * @param object $product A WooCommerce product.
	 * @param string $raw     The canonical JSON to store, or '' to remove the record.
	 */
	public static function write( object $product, string $raw ): void {
		if ( '' === $raw ) {
			$product->delete_meta_data( self::META_KEY );
			return;
		}
		$product->update_meta_data( self::META_KEY, $raw );
	}

	/**
	 * WooCommerce's Duplicate leaves the record out of the copy
	 * (`woocommerce_duplicate_product_exclude_meta`). The id binding stays
	 * the guard for copies this filter does not see.
	 *
	 * @param mixed $keys The meta keys Duplicate leaves out.
	 * @return list<string>
	 */
	public static function exclude_from_duplicate( mixed $keys ): array {
		$keys = is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : [];
		if ( ! in_array( self::META_KEY, $keys, true ) ) {
			$keys[] = self::META_KEY;
		}
		return $keys;
	}

	/**
	 * A stored or journalled record as people read it (SPEC §9.4): the saved
	 * status and when it was saved, such as "Published, saved 28 Sep 2026
	 * 14:03" in the site's time zone, or "—" for none. Never the JSON.
	 *
	 * @param mixed $raw A record's canonical JSON, or ''.
	 */
	public static function describe( mixed $raw ): string {
		$record = self::read( $raw );
		if ( null === $record ) {
			return '—';
		}
		return sprintf(
			/* translators: 1: a status, such as "Published", 2: when it was saved, such as "28 Sep 2026 14:03". */
			__( '%1$s, saved %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			Outcome::status_label( $record->status ),
			(string) wp_date( 'j M Y H:i', $record->timestamp() )
		);
	}

	/**
	 * The canonical JSON.
	 */
	public function to_json(): string {
		return (string) wp_json_encode(
			[
				'id'     => $this->id,
				'status' => $this->status,
				'at'     => $this->at,
				'batch'  => $this->batch,
			],
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * The time Off wrote the record as a Unix timestamp.
	 */
	public function timestamp(): int {
		$time = strtotime( $this->at );
		return false === $time ? 0 : $time;
	}

	/**
	 * A product's id.
	 *
	 * @param object $product A WooCommerce product.
	 */
	private static function id_of( object $product ): int {
		return method_exists( $product, 'get_id' ) || method_exists( $product, '__call' ) ? (int) $product->get_id() : 0;
	}
}
