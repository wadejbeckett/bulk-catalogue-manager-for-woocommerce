<?php
/**
 * The sample provider's label actions: `x_sample_add`, `x_sample_remove`,
 * `x_sample_replace` and `x_sample_clear`, in its own chooser group. The
 * first three take a multichoice `labels` argument whose choices come from
 * the option `sample_provider_choices`; replace and clear always confirm.
 * Test code only; never shipped.
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
 * Add, remove, replace or clear labels.
 */
final class LabelsAction implements ExternalAction {
	public const ADD     = 'add';
	public const REMOVE  = 'remove';
	public const REPLACE = 'replace';
	public const CLEAR   = 'clear';

	/**
	 * One mode.
	 *
	 * @param string $mode ADD, REMOVE, REPLACE or CLEAR.
	 */
	public function __construct( private readonly string $mode ) {}

	public function describe(): ActionDescriptor {
		if ( SampleFaults::get( 'throw_describe', false ) ) {
			throw new \RuntimeException( 'The sample action could not describe itself.' );
		}
		$labels = [
			'labels' => [
				'type'      => 'multichoice',
				'label'     => 'Labels',
				'rule'      => 'Choose at least one label from the list.',
				'required'  => true,
				'choices'   => SampleProvider::choices(),
				'min_items' => 1,
				'max_items' => 20,
			],
		];
		$field  = SampleFaults::get( 'malformed', false ) ? 'x_other_labels' : LabelsField::KEY;
		return match ( $this->mode ) {
			self::ADD     => new ActionDescriptor( 'x_sample_add', $field, 'x_sample_labels', 'Add labels', 'Adds the chosen labels. Other labels are kept.', 'add', $labels, 'edit_products' ),
			self::REMOVE  => new ActionDescriptor( 'x_sample_remove', $field, 'x_sample_labels', 'Remove labels', 'Removes the chosen labels. Other labels are kept.', 'remove', $labels, 'edit_products' ),
			self::REPLACE => new ActionDescriptor( 'x_sample_replace', $field, 'x_sample_labels', 'Replace labels', 'Replaces every label with the chosen ones.', 'set', $labels, 'edit_products', true, 'This replaces every label on the chosen products.' ),
			default       => new ActionDescriptor( 'x_sample_clear', $field, 'x_sample_labels', 'Clear labels', 'Removes every label.', 'clear', [], 'edit_products', true, 'This removes every label from the chosen products.' ),
		};
	}

	/**
	 * The chosen labels must still be offered.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function validate( array $args, Context $context ): Validation {
		SampleProvider::$validated[] = [ $this->describe()->id(), $context->mode() ];
		if ( SampleFaults::get( 'refuse_validate', false ) ) {
			return Validation::refused( new \WP_Error( 'x_sample_refused', 'The sample add-on refuses this change on purpose.', [ 'status' => 400 ] ) );
		}
		$choices = SampleProvider::choices();
		foreach ( (array) ( $args['labels'] ?? [] ) as $label ) {
			if ( ! isset( $choices[ $label ] ) ) {
				return Validation::refused( new \WP_Error( 'x_sample_no_label', 'One of the chosen labels no longer exists. Choose the labels again.', [ 'status' => 400 ] ) );
			}
		}
		return Validation::passed( $args );
	}

	/**
	 * What the labels would become.
	 *
	 * @param Subject              $subject The object.
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 * @throws \RuntimeException For the throw_compute fault.
	 */
	public function compute( Subject $subject, array $args, Context $context ): Result {
		$id                         = $subject->id();
		SampleProvider::$computed[] = [ $this->describe()->id(), $id, $context->mode() ];
		if ( SampleFaults::has( 'throw_compute', $id ) ) {
			throw new \RuntimeException( 'The sample action failed on purpose.' );
		}
		$current = $subject->value( LabelsField::KEY );
		if ( $current instanceof Unknown || ! is_array( $current ) ) {
			return Result::failed( LabelsField::KEY, null, 'The sample labels of this product could not be read.', 'x_sample_unreadable' );
		}
		$chosen = array_map( 'strval', (array) ( $args['labels'] ?? [] ) );
		$target = match ( $this->mode ) {
			self::ADD     => LabelsField::canonical( [ ...$current, ...$chosen ] ),
			self::REMOVE  => LabelsField::canonical( array_diff( $current, $chosen ) ),
			self::REPLACE => LabelsField::canonical( $chosen ),
			default       => [],
		};
		if ( SampleFaults::has( 'huge', $id ) ) {
			$target = array_map( static fn( int $n ): string => str_repeat( 'x', 1000 ) . $n, range( 1, 70 ) );
		}
		if ( SampleFaults::has( 'wrong_field', $id ) ) {
			return Result::changed( NoteField::KEY, '', 'wrong' );
		}
		if ( LabelsField::canonical( $current ) === $target ) {
			return Result::already( LabelsField::KEY, $current );
		}
		$result = Result::changed( LabelsField::KEY, $current, $target, self::REPLACE === $this->mode );
		if ( self::ADD === $this->mode && [] !== $current ) {
			$result = $result->with_notes( [ sprintf( 'Keeps %d existing label%s.', count( $current ), 1 === count( $current ) ? '' : 's' ) ] );
		}
		if ( in_array( 'sale', $target, true ) && ! in_array( 'sale', $current, true ) ) {
			$result = $result->with_warning( 'x_sample_sale', 'Adding the Sale label asks for a second click before it is applied.', true );
		} elseif ( count( $target ) > 3 ) {
			$result = $result->with_warning( 'x_sample_many', 'Some products will carry more than three labels.' );
		}
		return $result;
	}
}
