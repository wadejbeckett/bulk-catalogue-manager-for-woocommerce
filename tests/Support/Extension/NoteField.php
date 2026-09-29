<?php
/**
 * The sample provider's `x_sample_note` (per variation): one line of text,
 * stored under `_sample_note`. Test code only; never shipped.
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
 * A note on a product or variation.
 */
final class NoteField implements ExternalField {
	public const KEY = 'x_sample_note';

	public const META = '_sample_note';

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
		return 'Sample note';
	}

	public function variation_rule(): string {
		return self::PER_VARIATION;
	}

	public function format(): int {
		return 1;
	}

	/**
	 * The note, '' when none.
	 *
	 * @param Subject $subject The object.
	 */
	public function read( Subject $subject ): mixed {
		if ( SampleFaults::has( 'unknown', $subject->id() ) ) {
			return Unknown::because( 'The sample note of this product could not be read.', 'x_sample_unreadable' );
		}
		return $this->store->get( $subject->id(), self::META ) ?? '';
	}

	/**
	 * Same text.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 */
	public function same( mixed $a, mixed $b ): bool {
		return is_string( $a ) && is_string( $b ) && $a === $b;
	}

	/**
	 * The note, or "No note".
	 *
	 * @param mixed   $value   Note.
	 * @param Subject $subject The object.
	 */
	public function describe( mixed $value, Subject $subject ): string {
		unset( $subject );
		return is_string( $value ) && '' !== $value ? $value : 'No note';
	}

	/**
	 * Nothing beyond the old and new values.
	 *
	 * @param mixed   $old     Before.
	 * @param mixed   $new_value After.
	 * @param Subject $subject The object.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string {
		unset( $old, $new_value, $subject );
		return '';
	}

	/**
	 * Compare-and-set.
	 *
	 * @param Subject $subject  The object.
	 * @param mixed   $target   Note to set.
	 * @param mixed   $expected Note it should hold now.
	 * @param Context $context  The run.
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error {
		unset( $context );
		$current = $this->read( $subject );
		if ( $current instanceof Unknown ) {
			return new \WP_Error( 'x_sample_unreadable', $current->reason() );
		}
		if ( $this->same( $current, $target ) ) {
			return true;
		}
		if ( ! $this->same( $current, $expected ) ) {
			return new \WP_Error( self::CONFLICT, 'The sample note was changed by someone else during this change.' );
		}
		if ( '' === $target ) {
			$this->store->delete( $subject->id(), self::META );
		} else {
			$this->store->set( $subject->id(), self::META, (string) $target );
		}
		return true;
	}

	/**
	 * Only format 1 exists.
	 *
	 * @param mixed $value       Journalled value.
	 * @param int   $from_format Its format.
	 */
	public function upgrade( mixed $value, int $from_format ): mixed {
		return 1 === $from_format && is_string( $value ) ? $value : new \WP_Error( 'x_sample_old_format', 'This sample note was saved in a shape this version cannot read.' );
	}
}
