<?php
/**
 * The "Sample shelves" test provider's actions: put chosen categories on
 * shelves, or take them off, with a note on the products the change
 * touches (the core never computes product impact itself).
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
 * A shelves action.
 */
final class ShelvesAction implements ExternalAction {
	public const ADD    = 'x_shelves_add';
	public const REMOVE = 'x_shelves_remove';

	/**
	 * Every compute, for assertions: [ action, type, id, mode ].
	 *
	 * @var list<array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static array $computed = [];

	/**
	 * Build one.
	 *
	 * @param string $id ADD or REMOVE.
	 */
	public function __construct( private readonly string $id ) {}

	/**
	 * The descriptor.
	 */
	public function describe(): ActionDescriptor {
		$shelves    = [
			'shelves' => [
				'type'      => 'multichoice',
				'label'     => 'Shelves',
				'rule'      => 'Choose at least one shelf from the list.',
				'required'  => true,
				'choices'   => ShelvesProvider::choices(),
				'min_items' => 1,
				'max_items' => 4,
			],
		];
		$descriptor = self::ADD === $this->id
			? new ActionDescriptor( self::ADD, ShelvesField::KEY, 'x_shelves_items', 'Put on shelves', 'Puts each chosen category on the chosen shelves. Other shelves are kept.', 'add', $shelves, 'manage_product_terms' )
			: new ActionDescriptor( self::REMOVE, ShelvesField::KEY, 'x_shelves_items', 'Take off shelves', 'Takes each chosen category off the chosen shelves. Other shelves are kept.', 'remove', $shelves, 'manage_product_terms' );
		return $descriptor->for_target( ActionDescriptor::TARGET_CATEGORY );
	}

	/**
	 * No rules beyond the core's.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function validate( array $args, Context $context ): Validation {
		unset( $context );
		return Validation::passed( $args );
	}

	/**
	 * The new list, with a note on the products it touches.
	 *
	 * @param Subject              $subject The category.
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function compute( Subject $subject, array $args, Context $context ): Result {
		self::$computed[] = [ $this->id, $subject->type(), $subject->id(), $context->mode() ];
		$current          = $subject->value( ShelvesField::KEY );
		if ( $current instanceof Unknown || ! is_array( $current ) ) {
			return Result::failed( ShelvesField::KEY, null, 'The shelves of this category could not be read.', 'x_shelves_unreadable' );
		}
		$chosen = ShelvesField::canonical( (array) ( $args['shelves'] ?? [] ) );
		$target = self::ADD === $this->id
			? ShelvesField::canonical( [ ...$current, ...$chosen ] )
			: ShelvesField::canonical( array_diff( $current, $chosen ) );
		if ( ShelvesField::canonical( $current ) === $target ) {
			return Result::already( ShelvesField::KEY, $current );
		}
		return Result::changed( ShelvesField::KEY, $current, $target )->with_notes( [ 'Products in this category show the new shelves at their next page load.' ] );
	}
}
