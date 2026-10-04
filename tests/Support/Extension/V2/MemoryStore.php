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

/** Controlled separate-key storage, never a real site or a future engine stand-in. */
final class MemoryStore {
	public array $keys         = [];
	public array $reads        = [];
	public array $lookup_calls = [];
	public array $hygiene      = [];
	public array $faults       = [];
	public array $calls        = [];
	public int $clears         = 0;
	public array $choices      = [
		'v1' => 'Value 1',
		'v2' => 'Value 2',
		'v3' => 'Value 3',
		'v4' => 'Value 4',
	];
	public function seed( string $target, array $value, string $field = 'x_sample_composite' ): void {
		foreach ( [ 'a', 'b', 'c', 'mode' ] as $part ) {
			$this->keys[ $target ][ '_sample_' . $field . '_' . $part ] = $value[ $part ];
		}
	}
	public function value( string $target, string $field = 'x_sample_composite' ): array {
		$value = [];
		foreach ( [ 'a', 'b', 'c', 'mode' ] as $part ) {
			$value[ $part ] = $this->keys[ $target ][ '_sample_' . $field . '_' . $part ] ?? ( 'mode' === $part ? 'keep' : [] );
		}
		return $value;
	}
	public function clear(): bool {
		if ( ! empty( $this->faults['finalise_fail'] ) ) {
			return false;
		}
		++$this->clears;
		return true;
	}
}
