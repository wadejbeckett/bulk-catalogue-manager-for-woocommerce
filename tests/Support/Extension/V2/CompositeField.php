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

/** One composite field, with no production metadata mapping. */
final class CompositeField implements API\Field {
	public function __construct( private readonly MemoryStore $store, private readonly bool $category = false ) {}
	public function key(): string {
		return $this->category ? 'x_sample_category_composite' : 'x_sample_composite';
	}
	public function label(): string {
		return 'Sample parts';
	}
	public function target_kinds(): array {
		return $this->category ? [ 'term' ] : [ 'product', 'variation' ];
	}
	public function variation_rule(): string {
		return 'per_variation';
	}
	public function format(): int {
		return 1;
	}
	public function read( API\Subject $subject ): mixed {
		$this->store->reads[] = $subject->reference()->key();
		return $this->store->value( $subject->reference()->key(), $this->key() );
	}
	public function same( mixed $a, mixed $b ): bool {
		return $a === $b;
	}
	public function describe( mixed $value, API\Subject $subject ): string {
		return (string) wp_json_encode( $value );
	}
	public function describe_change( mixed $old, mixed $new_value, API\Subject $subject ): string {
		return $this->describe( $new_value, $subject );
	}
	public function upgrade( mixed $value, int $from_format ): mixed {
		return 1 === $from_format ? $value : new \WP_Error( 'x_sample_format', 'Unsupported sample format.' );
	}
	public function upgrade_context( API\Snapshot $snapshot ): API\Snapshot|\WP_Error {
		return 1 === $snapshot->version ? $snapshot : new \WP_Error( 'x_sample_format', 'Unsupported sample context.' );
	}
	public function write( API\Subject $subject, mixed $target, mixed $expected, API\WriteContext $context ): bool|\WP_Error {
		throw new \LogicException( 'S5 writer is not implemented.' );
	}
	public function reconcile( API\Subject $subject, mixed $current, API\WriteContext $context ): API\ReconcileDecision|\WP_Error {
		throw new \LogicException( 'S5 reconciliation is not implemented.' );
	}
	public function restore( API\Subject $subject, mixed $current, API\RecoveryContext $context ): API\RestoreDecision|\WP_Error {
		$history = $context->original();
		$domains = [];
		foreach ( $history->contributions as $contribution ) {
			$origin = $contribution->origin;
			if ( 1 !== $origin->version || ! in_array( $origin->data['part'] ?? null, [ 'a', 'b' ], true ) || ! in_array( $origin->data['mode'] ?? null, [ 'keep', 'open', 'closed' ], true ) ) {
				return new \WP_Error( 'x_sample_origin', 'Unsupported sample origin.' );
			}
			if ( ! in_array( $contribution->state, [ 'changed', 'already' ], true ) ) {
				continue; }
			$domains[] = $origin->data['part'];
			if ( 'keep' !== $origin->data['mode'] ) {
				$domains = [ ...$domains, 'a', 'b', 'mode' ]; }
		}
		$target    = $current;
		$conflicts = [];
		foreach ( array_unique( $domains ) as $domain ) {
			foreach ( [ $history->before, $history->after, $current, $context->expected(), $context->target() ] as $value ) {
				if ( ! is_array( $value ) || ! array_key_exists( $domain, $value ) ) {
					return new \WP_Error( 'x_sample_value', 'Unsupported sample value.' ); }
			}
			if ( $history->before[ $domain ] === $history->after[ $domain ] ) {
				continue; }
			if ( $current[ $domain ] !== $context->expected()[ $domain ] && ! $context->force() ) {
				$conflicts[] = [
					'domain' => $domain,
					'reason' => 'This part changed after the batch.',
				];
				continue;
			}
			$target[ $domain ] = $context->target()[ $domain ];
		}
		return new API\RestoreDecision( $target, $conflicts );
	}
}
