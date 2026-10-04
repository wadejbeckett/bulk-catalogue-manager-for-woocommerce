<?php
/**
 * Neutral typed contract fixture; test-only, excluded from every package.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Operations\Result;

/** Canonical assignment uses only the immutable Subject value during compute. */
final class AssignAction implements API\Action, API\ChoiceSource {
	use ChoiceSource;

	public function __construct( private readonly MemoryStore $store, private readonly bool $category = false, private readonly bool $cycle = false, private readonly bool $row = false ) {}
	public function describe(): API\ActionDescriptor {
		$arguments = [
			'part'      => [
				'type'    => 'choice',
				'label'   => 'Part',
				'rule'    => 'Choose a part.',
				'choices' => [
					'a' => 'Part A',
					'b' => 'Part B',
				],
				'default' => 'a',
			],
			'operation' => [
				'type'    => 'choice',
				'label'   => 'Action',
				'rule'    => 'Choose an action.',
				'choices' => [
					'add'     => 'Add',
					'remove'  => 'Remove',
					'replace' => 'Replace',
					'clear'   => 'Clear',
				],
				'default' => 'add',
			],
			'values'    => [
				'type'        => 'lookup',
				'label'       => 'Values',
				'rule'        => 'Choose available values.',
				'source'      => 'values',
				'multiple'    => true,
				'min_items'   => 1,
				'max_items'   => 100,
				'depends_on'  => $this->cycle ? [ 'values' ] : [ 'part', 'operation' ],
				'active_when' => [
					'argument' => 'operation',
					'op'       => 'in',
					'values'   => [ 'add', 'remove', 'replace' ],
				],
			],
			'mode'      => [
				'type'    => 'choice',
				'label'   => 'Mode',
				'rule'    => 'Choose a mode.',
				'choices' => [
					'keep'   => 'Keep',
					'open'   => 'Open',
					'closed' => 'Closed',
				],
				'default' => 'keep',
			],
		];
		return new API\ActionDescriptor( $this->category ? 'x_sample_assign_category' : 'x_sample_assign', $this->category ? 'x_sample_category_composite' : 'x_sample_composite', 'organisation', 'Assign sample parts', 'Changes the selected part.', 'set', $arguments, $this->category ? 'manage_product_terms' : 'edit_products', $this->category ? [ 'category.bulk' ] : ( $this->row ? [ 'product.bulk', 'product.row' ] : [ 'product.bulk' ] ), $this->category ? [ 'term' ] : [ 'product', 'variation' ] );
	}
	public function availability( API\Scope $scope, array $args, API\Context $context ): bool|\WP_Error {
		return true;
	}
	public function validate( API\Scope $scope, array $args, API\Context $context ): API\Snapshot|\WP_Error {
		if ( ! in_array( $args['part'] ?? '', [ 'a', 'b' ], true ) || ! in_array( $args['operation'] ?? '', [ 'add', 'remove', 'replace', 'clear' ], true ) || ! is_array( $args['values'] ?? null ) || ! array_is_list( $args['values'] ) || count( $args['values'] ) > 100 || count( array_unique( $args['values'] ) ) !== count( $args['values'] ) || ! in_array( $args['mode'] ?? 'keep', [ 'keep', 'open', 'closed' ], true ) ) {
			return new \WP_Error( 'x_sample_args', 'Invalid sample assignment.' );
		}
		if ( 'clear' === $args['operation'] ? [] !== $args['values'] : [] === $args['values'] ) {
			return new \WP_Error( 'x_sample_values', 'Invalid active values.' );
		}
		foreach ( $args['values'] as $value ) {
			if ( ! is_string( $value ) || ! isset( $this->store->choices[ $value ] ) || in_array( $value, $this->store->faults['denied_choices'] ?? [], true ) ) {
					return new \WP_Error( 'x_sample_choice', 'A sample value is unavailable.' );
			}
		}
		return new API\Snapshot(
			1,
			[
				'policy' => $this->store->faults['policy_version'] ?? 1,
				'labels' => array_intersect_key( $this->store->choices, array_flip( $args['values'] ) ),
			]
		);
	}
	public function compute( API\Subject $subject, API\ValidatedStep $step, API\Context $context ): API\Computation {
		$before = $subject->value( $step->field );
		$after  = $before;
		$part   = $step->args['part'];
		$values = $step->args['values'];
		if ( 'keep' !== ( $step->args['mode'] ?? 'keep' ) ) {
			$after['a']    = [];
			$after['b']    = [];
			$after['mode'] = $step->args['mode'];
		}
		$after[ $part ] = match ( $step->args['operation'] ) {
			'add' => array_values( array_unique( [ ...$after[ $part ], ...$values ] ) ),
			'remove' => array_values( array_diff( $after[ $part ], $values ) ),
			'replace' => array_values( array_unique( $values ) ),
			'clear' => [],
		};
		sort( $after[ $part ], SORT_STRING );
		if ( 'keep' !== ( $step->args['mode'] ?? 'keep' ) ) {
			$after['mode'] = $step->args['mode'];
		}
		$origin = new API\Snapshot(
			1,
			[
				'part'      => $part,
				'operation' => $step->args['operation'],
				'values'    => $values,
				'mode'      => $step->args['mode'] ?? 'keep',
			]
		);
		$state  = $this->store->faults['compute_states'][ $step->index ] ?? null;
		if ( in_array( $state, [ 'failed', 'skipped' ], true ) ) {
			return new API\Computation( Result::$state( $step->field, $before, 'Fixture outcome.' ), $origin );
		}
		return new API\Computation( $before === $after ? Result::already( $step->field, $before ) : Result::changed( $step->field, $before, $after ), $origin );
	}
}
