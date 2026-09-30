<?php
/**
 * The sample provider's `x_sample_labels` (parent only): a sorted list of
 * short label values, stored as JSON under the product's `_sample_labels`.
 * Test code only; never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Extension\Unknown;

/**
 * Labels on a product.
 */
final class LabelsField implements ExternalField {
	public const KEY = 'x_sample_labels';

	public const META = '_sample_labels';

	/**
	 * Over a store.
	 *
	 * @param SampleStore $store Where the values live.
	 */
	public function __construct( private readonly SampleStore $store ) {}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'Sample labels';
	}

	public function variation_rule(): string {
		return self::PARENT_ONLY;
	}

	public function format(): int {
		return (int) SampleFaults::get( 'format', 1 );
	}

	/**
	 * The sorted labels, [] when none, or Unknown when the stored text is unreadable.
	 *
	 * @param Subject $subject The object.
	 */
	public function read( Subject $subject ): mixed {
		if ( SampleFaults::has( 'unknown', $subject->id() ) ) {
			return Unknown::because( 'The sample labels of this product could not be read.', 'x_sample_unreadable' );
		}
		$raw = $this->store->get( $subject->id(), self::META );
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		$labels = json_decode( $raw, true );
		if ( ! is_array( $labels ) || ! array_is_list( $labels ) ) {
			return Unknown::because( 'The stored sample labels are damaged.', 'x_sample_damaged' );
		}
		return self::canonical( $labels );
	}

	/**
	 * Same labels, in any order.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 */
	public function same( mixed $a, mixed $b ): bool {
		return is_array( $a ) && is_array( $b ) && self::canonical( $a ) === self::canonical( $b );
	}

	/**
	 * The labels by name, or "No labels".
	 *
	 * @param mixed   $value   Labels.
	 * @param Subject $subject The object.
	 */
	public function describe( mixed $value, Subject $subject ): string {
		unset( $subject );
		if ( ! is_array( $value ) || [] === $value ) {
			return 'No labels';
		}
		$choices = SampleProvider::choices();
		return implode( ', ', array_map( static fn( mixed $label ): string => $choices[ $label ] ?? (string) $label, self::canonical( $value ) ) );
	}

	/**
	 * "Adds Blue; removes Red", or ''.
	 *
	 * @param mixed   $old     Before.
	 * @param mixed   $new_value After.
	 * @param Subject $subject The object.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string {
		unset( $subject );
		$choices = SampleProvider::choices();
		$name    = static fn( string $label ): string => $choices[ $label ] ?? $label;
		$added   = array_diff( is_array( $new_value ) ? $new_value : [], is_array( $old ) ? $old : [] );
		$removed = array_diff( is_array( $old ) ? $old : [], is_array( $new_value ) ? $new_value : [] );
		$parts   = [];
		if ( [] !== $added ) {
			$parts[] = 'Adds ' . implode( ', ', array_map( $name, $added ) );
		}
		if ( [] !== $removed ) {
			$parts[] = 'removes ' . implode( ', ', array_map( $name, $removed ) );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Compare-and-set.
	 *
	 * @param Subject $subject  The object.
	 * @param mixed   $target   Labels to set.
	 * @param mixed   $expected Labels it should hold now.
	 * @param Context $context  The run.
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error {
		unset( $context );
		$id    = $subject->id();
		$delay = (int) SampleFaults::get( 'slow_write', 0 );
		if ( $delay > 0 ) {
			usleep( $delay );
		}
		if ( SampleFaults::has( 'write_error', $id ) ) {
			return new \WP_Error( 'x_sample_write_failed', 'The sample labels could not be saved.' );
		}
		$current = $this->read( $subject );
		if ( $current instanceof Unknown ) {
			return new \WP_Error( 'x_sample_unreadable', $current->reason() );
		}
		if ( $this->same( $current, $target ) ) {
			return true;
		}
		if ( SampleFaults::has( 'write_conflict', $id ) || ! $this->same( $current, $expected ) ) {
			return new \WP_Error( self::CONFLICT, 'The sample labels were changed by someone else during this change.' );
		}
		if ( SampleFaults::has( 'not_stored', $id ) ) {
			return true;
		}
		$labels = self::canonical( is_array( $target ) ? $target : [] );
		if ( [] === $labels ) {
			$this->store->delete( $id, self::META );
		} else {
			$this->store->set( $id, self::META, (string) wp_json_encode( $labels ) );
		}
		return true;
	}

	/**
	 * Format 1 and 2 hold the same list.
	 *
	 * @param mixed $value       Journalled value.
	 * @param int   $from_format Its format.
	 */
	public function upgrade( mixed $value, int $from_format ): mixed {
		if ( $from_format < 1 || ! is_array( $value ) ) {
			return new \WP_Error( 'x_sample_old_format', 'These sample labels were saved in a shape this version cannot read.' );
		}
		return self::canonical( $value );
	}

	/**
	 * Sorted distinct strings.
	 *
	 * @param array<mixed> $labels Labels.
	 * @return list<string>
	 */
	public static function canonical( array $labels ): array {
		$labels = array_values( array_unique( array_map( 'strval', array_filter( $labels, 'is_scalar' ) ) ) );
		sort( $labels, SORT_STRING );
		return $labels;
	}
}
