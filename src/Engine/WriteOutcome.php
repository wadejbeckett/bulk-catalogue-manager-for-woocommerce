<?php
/**
 * What the writer did to one object (SPEC §9.3, §12.3): one Result per
 * operation, in list order — changed (and written), already, skipped or
 * failed with its plain reason — whether the object was saved, and the
 * variable parent whose price range and stock summary were, or will be,
 * resynchronised. The journal (T33) and the batch feed read it; nothing in it
 * is written back.
 *
 * results()[ $i ] answers operations[ $i ] of the list the writer was given.
 * A changed result means the value was set and saved; a change that was
 * refused while applying, or lost because the save failed, is reported as
 * failed with the reason.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Operations\Result;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable per-object write outcome.
 */
final class WriteOutcome {
	/**
	 * Build through the writer.
	 *
	 * @param int                  $id        The object id asked for.
	 * @param list<Result>         $results   One per operation, in list order.
	 * @param bool                 $saved     Whether save() was called and succeeded.
	 * @param int                  $parent_id The variable parent resynchronised after the write, or 0.
	 * @param array<string, mixed> $before    Each changed field's value before the first operation, canonical.
	 * @throws \InvalidArgumentException When the parts are inconsistent.
	 */
	public function __construct(
		private readonly int $id,
		private readonly array $results,
		private readonly bool $saved,
		private readonly int $parent_id = 0,
		private readonly array $before = []
	) {
		$changed = [] !== $this->changed();
		$problem = match ( true ) {
			! array_is_list( $results ) || [] !== array_filter( $results, static fn( mixed $r ): bool => ! $r instanceof Result ) => 'results must be a list of Result',
			$changed !== $saved => 'an object is saved exactly when a result changed',
			$parent_id > 0 && ! $saved => 'only a saved variation resynchronises its parent',
			default => '',
		};
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( 'WriteOutcome is invalid: ' . $problem . '.' ) );
		}
	}

	/**
	 * The object id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Every result, one per operation, in list order.
	 *
	 * @return list<Result>
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * Whether the object was saved.
	 */
	public function saved(): bool {
		return $this->saved;
	}

	/**
	 * The variable parent resynchronised after this variation's write, or 0.
	 */
	public function parent_id(): int {
		return $this->parent_id;
	}

	/**
	 * The results that changed the object and were saved.
	 *
	 * @return list<Result>
	 */
	public function changed(): array {
		return $this->in_state( Result::CHANGED );
	}

	/**
	 * The results that failed, with their reasons.
	 *
	 * @return list<Result>
	 */
	public function failed(): array {
		return $this->in_state( Result::FAILED );
	}

	/**
	 * Whether any operation failed on this object.
	 */
	public function has_failures(): bool {
		return [] !== $this->failed();
	}

	/**
	 * The keys of the fields a saved change touched, once each, in first-touch order.
	 *
	 * @return list<string>
	 */
	public function touched(): array {
		return array_values( array_unique( array_map( static fn( Result $r ): string => $r->field(), $this->changed() ) ) );
	}

	/**
	 * Each touched field's value before the first operation ran, canonical.
	 *
	 * @return array<string, mixed>
	 */
	public function before(): array {
		return $this->before;
	}

	/**
	 * The outcome as the feed carries it.
	 *
	 * @return array{id: int, saved: bool, parent_id: int, results: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'id'        => $this->id,
			'saved'     => $this->saved,
			'parent_id' => $this->parent_id,
			'results'   => array_map( static fn( Result $r ): array => $r->to_array(), $this->results ),
		];
	}

	/**
	 * The results in one state.
	 *
	 * @param string $state One of Result::STATES.
	 * @return list<Result>
	 */
	private function in_state( string $state ): array {
		return array_values( array_filter( $this->results, static fn( Result $r ): bool => $state === $r->state() ) );
	}
}
