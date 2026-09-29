<?php
/**
 * The sample provider's `x_sample_set_note`: sets `x_sample_note` from a text
 * argument. It sits in the native Organisation group, as an external action
 * may. Test code only; never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Extension\Unknown;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;

/**
 * Set the note.
 */
final class NoteAction implements ExternalAction {
	public function describe(): ActionDescriptor {
		return new ActionDescriptor(
			'x_sample_set_note',
			NoteField::KEY,
			'organisation',
			'Set sample note',
			'Sets the sample note to the text you give.',
			'set',
			[
				'note' => [
					'type'        => 'text',
					'label'       => 'Note',
					'rule'        => 'Enter a note of up to 200 characters.',
					'required'    => true,
					'allow_empty' => true,
					'max_length'  => 200,
				],
			],
			'edit_products'
		);
	}

	/**
	 * Nothing spans arguments.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function validate( array $args, Context $context ): Validation {
		SampleProvider::$validated[] = [ 'x_sample_set_note', $context->mode() ];
		if ( SampleFaults::get( 'refuse_validate', false ) ) {
			return Validation::refused( new \WP_Error( 'x_sample_refused', 'The sample add-on refuses this change on purpose.', [ 'status' => 400 ] ) );
		}
		return Validation::passed( $args );
	}

	/**
	 * The note would become the text given.
	 *
	 * @param Subject              $subject The object.
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 * @throws \RuntimeException For the throw_compute fault.
	 */
	public function compute( Subject $subject, array $args, Context $context ): Result {
		SampleProvider::$computed[] = [ 'x_sample_set_note', $subject->id(), $context->mode() ];
		if ( SampleFaults::has( 'throw_compute', $subject->id() ) ) {
			throw new \RuntimeException( 'The sample action failed on purpose.' );
		}
		$current = $subject->value( NoteField::KEY );
		if ( $current instanceof Unknown || ! is_string( $current ) ) {
			return Result::failed( NoteField::KEY, null, 'The sample note of this product could not be read.', 'x_sample_unreadable' );
		}
		$target = (string) ( $args['note'] ?? '' );
		if ( $current === $target ) {
			return Result::already( NoteField::KEY, $current );
		}
		$result = Result::changed( NoteField::KEY, $current, $target );
		return str_ends_with( $target, '!' ) ? $result->with_warning( 'x_sample_loud', 'Some notes end with an exclamation mark.' ) : $result;
	}
}
