<?php
/**
 * Neutral product-only action observing actual availability/validation scope.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Operations\Result;

final class ParentAction implements API\Action {
	public array $available = [];
	public array $validated = [];
	public array $computed  = [];
	public function describe(): API\ActionDescriptor {
		return new API\ActionDescriptor( 'x_sample_parent', 'x_sample_composite', 'organisation', 'Observe parent', 'Preserves the parent.', 'set', [], 'edit_products', [ 'product.bulk' ], [ 'product' ] );
	}
	private function keys( API\Scope $scope ): array {
		$keys = [];
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			foreach ( $chunk as $ref ) {
				$keys[] = $ref->key(); }
		}
		return $keys;
	}
	public function availability( API\Scope $scope, array $args, API\Context $context ): bool|\WP_Error {
		$this->available[] = $this->keys( $scope );
		return true;
	}
	public function validate( API\Scope $scope, array $args, API\Context $context ): API\Snapshot|\WP_Error {
		$this->validated[] = $this->keys( $scope );
		return new API\Snapshot( 1, [ 'scope' => $scope->signature() ] );
	}
	public function compute( API\Subject $subject, API\ValidatedStep $step, API\Context $context ): API\Computation {
		$this->computed[] = $subject->reference()->key();
		return new API\Computation( Result::already( $step->field, $subject->value( $step->field ) ), new API\Snapshot( 1, [ 'parent' => true ] ) );
	}
}
