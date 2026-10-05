<?php
/**
 * The contract version 2 test provider's value: a sorted list of recipient
 * ids, on a product (`x_picks_items`, post meta `_sample_picks`) or on a
 * product category (`x_picks_category_items`, term meta `_sample_picks`).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\CategorySubject;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Extension\Unknown;

/**
 * A picks field.
 */
final class PicksField implements ExternalField {
	/**
	 * The product field.
	 */
	public const PRODUCT = 'x_picks_items';

	/**
	 * The category field.
	 */
	public const CATEGORY = 'x_picks_category_items';

	/**
	 * The storage key, post meta or term meta.
	 */
	public const META = '_sample_picks';

	/**
	 * Every write, for assertions: [ key, object kind, id, mode ].
	 *
	 * @var list<array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static array $written = [];

	/**
	 * Build one.
	 *
	 * @param string      $key   PRODUCT or CATEGORY.
	 * @param SampleStore $store Where it is kept.
	 */
	public function __construct( private readonly string $key, private readonly SampleStore $store ) {}

	/**
	 * The key.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * The label.
	 */
	public function label(): string {
		return self::CATEGORY === $this->key ? 'Category picks' : 'Sample picks';
	}

	/**
	 * Kept on the product (or the category).
	 */
	public function variation_rule(): string {
		return self::PARENT_ONLY;
	}

	/**
	 * Format 1.
	 */
	public function format(): int {
		return 1;
	}

	/**
	 * The stored list.
	 *
	 * @param Subject $subject The object.
	 */
	public function read( Subject $subject ): mixed {
		if ( ( self::CATEGORY === $this->key ) !== ( $subject instanceof CategorySubject ) ) {
			return Unknown::because( 'These picks belong to another kind of object.', 'x_picks_wrong_kind' );
		}
		$raw = $this->store->get( $subject->id(), self::META );
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		$ids = json_decode( $raw, true );
		return is_array( $ids ) && array_is_list( $ids ) ? self::canonical( $ids ) : Unknown::because( 'The stored picks are damaged.', 'x_picks_damaged' );
	}

	/**
	 * Same list.
	 *
	 * @param mixed $a One.
	 * @param mixed $b Other.
	 */
	public function same( mixed $a, mixed $b ): bool {
		return is_array( $a ) && is_array( $b ) && self::canonical( $a ) === self::canonical( $b );
	}

	/**
	 * Labels of the recipients.
	 *
	 * @param mixed   $value   The list.
	 * @param Subject $subject The object.
	 */
	public function describe( mixed $value, Subject $subject ): string {
		unset( $subject );
		if ( ! is_array( $value ) || [] === $value ) {
			return 'No recipients';
		}
		return implode( ', ', array_map( PicksDirectory::label( ... ), self::canonical( $value ) ) );
	}

	/**
	 * What is added and removed.
	 *
	 * @param mixed   $old       Before.
	 * @param mixed   $new_value After.
	 * @param Subject $subject   The object.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string {
		unset( $subject );
		$added   = array_diff( is_array( $new_value ) ? $new_value : [], is_array( $old ) ? $old : [] );
		$removed = array_diff( is_array( $old ) ? $old : [], is_array( $new_value ) ? $new_value : [] );
		$parts   = [];
		if ( [] !== $added ) {
			$parts[] = 'Adds ' . implode( ', ', array_map( PicksDirectory::label( ... ), $added ) );
		}
		if ( [] !== $removed ) {
			$parts[] = 'removes ' . implode( ', ', array_map( PicksDirectory::label( ... ), $removed ) );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Absolute compare-and-set write.
	 *
	 * @param Subject $subject  The object.
	 * @param mixed   $target   The list to store.
	 * @param mixed   $expected The list it must hold now.
	 * @param Context $context  The run.
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error {
		$id              = $subject->id();
		self::$written[] = [ $this->key, $subject->type(), $id, $context->mode() ];
		$current         = $this->read( $subject );
		if ( $current instanceof Unknown ) {
			return new \WP_Error( 'x_picks_unreadable', $current->reason() );
		}
		if ( $this->same( $current, $target ) ) {
			return true;
		}
		if ( PicksFaults::has( 'write_conflict', $id ) || ! $this->same( $current, $expected ) ) {
			return new \WP_Error( self::CONFLICT, 'The picks were changed by someone else during this change.' );
		}
		$ids = self::canonical( is_array( $target ) ? $target : [] );
		if ( [] === $ids ) {
			$this->store->delete( $id, self::META );
		} else {
			$this->store->set( $id, self::META, (string) wp_json_encode( $ids ) );
		}
		return true;
	}

	/**
	 * Format 1 only.
	 *
	 * @param mixed $value       Old value.
	 * @param int   $from_format Its format.
	 */
	public function upgrade( mixed $value, int $from_format ): mixed {
		return 1 === $from_format && is_array( $value ) ? self::canonical( $value ) : new \WP_Error( 'x_picks_old_format', 'These picks were saved in a shape this version cannot read.' );
	}

	/**
	 * Sorted distinct strings.
	 *
	 * @param array<mixed> $ids Ids.
	 * @return list<string>
	 */
	public static function canonical( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'strval', array_filter( $ids, 'is_scalar' ) ) ) );
		sort( $ids, SORT_STRING );
		return $ids;
	}
}
