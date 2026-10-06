<?php
/**
 * The "Sample shelves" test provider's value: a sorted list of shelf codes
 * kept on a product category (`x_shelves_items`, term meta `_sample_shelves`).
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
 * The shelves field.
 */
final class ShelvesField implements ExternalField {
	/**
	 * The key.
	 */
	public const KEY = 'x_shelves_items';

	/**
	 * The storage key.
	 */
	public const META = '_sample_shelves';

	/**
	 * Every write, for assertions: [ kind, id, mode ].
	 *
	 * @var list<array{0: string, 1: int, 2: string}>
	 */
	public static array $written = [];

	/**
	 * Build it.
	 *
	 * @param SampleStore $store Where it is kept.
	 */
	public function __construct( private readonly SampleStore $store ) {}

	/**
	 * The key.
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * The label.
	 */
	public function label(): string {
		return 'Shelves';
	}

	/**
	 * Kept on the category.
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
	 * The stored list; only ever asked about a category.
	 *
	 * @param Subject $subject The object.
	 */
	public function read( Subject $subject ): mixed {
		if ( ! $subject instanceof CategorySubject ) {
			return Unknown::because( 'Shelves belong to categories.', 'x_shelves_wrong_kind' );
		}
		$raw = $this->store->get( $subject->id(), self::META );
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		$codes = json_decode( $raw, true );
		return is_array( $codes ) && array_is_list( $codes ) ? self::canonical( $codes ) : Unknown::because( 'The stored shelves are damaged.', 'x_shelves_damaged' );
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
	 * The shelves' names.
	 *
	 * @param mixed   $value   The list.
	 * @param Subject $subject The category.
	 */
	public function describe( mixed $value, Subject $subject ): string {
		unset( $subject );
		if ( ! is_array( $value ) || [] === $value ) {
			return 'No shelves';
		}
		$names = ShelvesProvider::choices();
		return implode( ', ', array_map( static fn( string $code ): string => $names[ $code ] ?? $code, self::canonical( $value ) ) );
	}

	/**
	 * What is added and removed.
	 *
	 * @param mixed   $old       Before.
	 * @param mixed   $new_value After.
	 * @param Subject $subject   The category.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string {
		$added   = array_values( array_diff( is_array( $new_value ) ? $new_value : [], is_array( $old ) ? $old : [] ) );
		$removed = array_values( array_diff( is_array( $old ) ? $old : [], is_array( $new_value ) ? $new_value : [] ) );
		$parts   = [];
		if ( [] !== $added ) {
			$parts[] = 'Puts it on ' . $this->describe( $added, $subject );
		}
		if ( [] !== $removed ) {
			$parts[] = 'takes it off ' . $this->describe( $removed, $subject );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Absolute compare-and-set write.
	 *
	 * @param Subject $subject  The category.
	 * @param mixed   $target   The list to store.
	 * @param mixed   $expected The list it must hold now.
	 * @param Context $context  The run.
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error {
		self::$written[] = [ $subject->type(), $subject->id(), $context->mode() ];
		$current         = $this->read( $subject );
		if ( $current instanceof Unknown ) {
			return new \WP_Error( 'x_shelves_unreadable', $current->reason() );
		}
		if ( $this->same( $current, $target ) ) {
			return true;
		}
		if ( ShelvesProvider::names( 'write_conflict', $subject->id() ) || ! $this->same( $current, $expected ) ) {
			return new \WP_Error( self::CONFLICT, 'The shelves were changed by someone else during this change.' );
		}
		$codes = self::canonical( is_array( $target ) ? $target : [] );
		if ( [] === $codes ) {
			$this->store->delete( $subject->id(), self::META );
		} else {
			$this->store->set( $subject->id(), self::META, (string) wp_json_encode( $codes ) );
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
		return 1 === $from_format && is_array( $value ) ? self::canonical( $value ) : new \WP_Error( 'x_shelves_old_format', 'These shelves were saved in a shape this version cannot read.' );
	}

	/**
	 * Sorted distinct strings.
	 *
	 * @param array<mixed> $codes Codes.
	 * @return list<string>
	 */
	public static function canonical( array $codes ): array {
		$codes = array_values( array_unique( array_map( 'strval', array_filter( $codes, 'is_scalar' ) ) ) );
		sort( $codes, SORT_STRING );
		return $codes;
	}
}
