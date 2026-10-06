<?php
/**
 * The contract version 2 test provider's actions: add, remove, keep only
 * one, and clear recipients, on products (with a row entry) and add and
 * remove on categories (with a row entry). Recipients are chosen through a
 * `lookup` argument answered by PicksDirectory.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\LookupPage;
use BulkCatalogueManager\Extension\LookupSource;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Extension\Unknown;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;

/**
 * A picks action.
 */
final class PicksAction implements ExternalAction, LookupSource {
	public const ADD          = 'x_picks_add';
	public const REMOVE       = 'x_picks_remove';
	public const ONLY         = 'x_picks_only';
	public const CLEAR        = 'x_picks_clear';
	public const CATEGORY_ADD = 'x_picks_category_add';
	public const CATEGORY_REM = 'x_picks_category_remove';

	/**
	 * Every compute, for assertions: [ action, kind, id, mode ].
	 *
	 * @var list<array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static array $computed = [];

	/**
	 * Build one.
	 *
	 * @param string $id   One of the constants.
	 * @param bool   $rows Whether to offer row entries (when the core supports them).
	 */
	public function __construct( private readonly string $id, private readonly bool $rows = true ) {}

	/**
	 * The descriptor.
	 */
	public function describe(): ActionDescriptor {
		$many = [
			'recipients' => [
				'type'      => 'lookup',
				'label'     => 'Recipients',
				'rule'      => 'Choose at least one recipient from the list.',
				'required'  => true,
				'multiple'  => true,
				'min_items' => 1,
				'max_items' => 20,
			],
		];
		$one  = [
			'recipient' => [
				'type'     => 'lookup',
				'label'    => 'Recipient',
				'rule'     => 'Choose one recipient from the list.',
				'required' => true,
			],
		];
		return match ( $this->id ) {
			self::ADD          => ( new ActionDescriptor( self::ADD, PicksField::PRODUCT, 'x_picks_items', 'Add recipients', 'Adds the chosen recipients. Others are kept.', 'add', $many, 'edit_products' ) )->with_row_entry( $this->rows ),
			self::REMOVE       => ( new ActionDescriptor( self::REMOVE, PicksField::PRODUCT, 'x_picks_items', 'Remove recipients', 'Removes the chosen recipients. Others are kept.', 'remove', $many, 'edit_products' ) )->with_row_entry( $this->rows ),
			self::ONLY         => new ActionDescriptor( self::ONLY, PicksField::PRODUCT, 'x_picks_items', 'Keep one recipient', 'Replaces every recipient with the chosen one.', 'set', $one, 'edit_products', true, 'This replaces every recipient on the chosen products.' ),
			self::CLEAR        => new ActionDescriptor( self::CLEAR, PicksField::PRODUCT, 'x_picks_items', 'Clear recipients', 'Removes every recipient.', 'clear', [], 'edit_products', true, 'This removes every recipient from the chosen products.' ),
			self::CATEGORY_ADD => ( new ActionDescriptor( self::CATEGORY_ADD, PicksField::CATEGORY, 'x_picks_items', 'Add recipients to categories', 'Adds the chosen recipients to each chosen category. Others are kept.', 'add', $many, 'manage_product_terms' ) )->for_target( ActionDescriptor::TARGET_CATEGORY )->with_row_entry( $this->rows ),
			default            => ( new ActionDescriptor( self::CATEGORY_REM, PicksField::CATEGORY, 'x_picks_items', 'Remove recipients from categories', 'Removes the chosen recipients from each chosen category. Others are kept.', 'remove', $many, 'manage_product_terms' ) )->for_target( ActionDescriptor::TARGET_CATEGORY )->with_row_entry( $this->rows ),
		};
	}

	/**
	 * Cross-argument rules: none beyond the core's resolve check.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function validate( array $args, Context $context ): Validation {
		unset( $context );
		return Validation::passed( $args );
	}

	/**
	 * The new list.
	 *
	 * @param Subject              $subject The object.
	 * @param array<string, mixed> $args    Arguments.
	 * @param Context              $context The run.
	 */
	public function compute( Subject $subject, array $args, Context $context ): Result {
		$field            = in_array( $this->id, [ self::CATEGORY_ADD, self::CATEGORY_REM ], true ) ? PicksField::CATEGORY : PicksField::PRODUCT;
		self::$computed[] = [ $this->id, $subject->type(), $subject->id(), $context->mode() ];
		$current          = $subject->value( $field );
		if ( $current instanceof Unknown || ! is_array( $current ) ) {
			return Result::failed( $field, null, 'The picks of this object could not be read.', 'x_picks_unreadable' );
		}
		$chosen = PicksField::canonical( self::ONLY === $this->id ? [ (string) ( $args['recipient'] ?? '' ) ] : (array) ( $args['recipients'] ?? [] ) );
		foreach ( $chosen as $id ) {
			if ( in_array( $this->id, [ self::ADD, self::ONLY, self::CATEGORY_ADD ], true ) && ! PicksDirectory::allowed( $id, $context->user_id() ) ) {
				return Result::failed( $field, $current, 'A chosen recipient can no longer be chosen.', 'x_picks_gone' );
			}
		}
		$target = match ( $this->id ) {
			self::ADD, self::CATEGORY_ADD    => PicksField::canonical( [ ...$current, ...$chosen ] ),
			self::REMOVE, self::CATEGORY_REM => PicksField::canonical( array_diff( $current, $chosen ) ),
			self::ONLY                       => $chosen,
			default                          => [],
		};
		return PicksField::canonical( $current ) === $target ? Result::already( $field, $current ) : Result::changed( $field, $current, $target, self::ONLY === $this->id );
	}

	/**
	 * One page of recipients.
	 *
	 * @param string  $argument The argument.
	 * @param string  $query    The search.
	 * @param int     $page     The page.
	 * @param Context $context  The run.
	 */
	public function lookup( string $argument, string $query, int $page, Context $context ): LookupPage|\WP_Error {
		return PicksDirectory::lookup( $argument, $query, $page, $context );
	}

	/**
	 * The recipients that may be chosen now.
	 *
	 * @param string       $argument The argument.
	 * @param list<string> $ids      Ids.
	 * @param Context      $context  The run.
	 */
	public function resolve( string $argument, array $ids, Context $context ): array {
		return PicksDirectory::resolve( $argument, $ids, $context );
	}
}
