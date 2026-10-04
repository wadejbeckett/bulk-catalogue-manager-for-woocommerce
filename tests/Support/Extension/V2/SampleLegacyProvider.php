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

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
/** Wrapper preserving old keys, formats, methods and behavior. */
final class SampleLegacyProvider implements \BulkCatalogueManager\Extension\Provider {
	private readonly SampleProvider $sample;
	public int $availability_calls = 0;
	public function __construct() {
		$this->sample = new SampleProvider( new \BulkCatalogueManager\Tests\Support\Extension\MemoryStore() );
	}
	public function id(): string {
		return $this->sample->id();
	}
	public function label(): string {
		return $this->sample->label();
	}
	public function version(): string {
		return $this->sample->version();
	}
	public function contract(): int {
		return $this->sample->contract();
	}
	public function availability(): bool|\WP_Error {
		++$this->availability_calls;
		return $this->sample->availability();
	}
	public function fields(): array {
		return $this->sample->fields();
	}
	public function actions(): array {
		return $this->sample->actions();
	}
	public function groups(): array {
		return $this->sample->groups();
	}
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error {
		return $this->sample->authorise( $user_id, $subject );
	}
	public function state_token( Scope $scope, array $steps, Context $context ): string {
		return $this->sample->state_token( $scope, $steps, $context );
	}
	public function prime( array $object_ids ): void {
		$this->sample->prime( $object_ids );
	}
	public function flush( array $object_ids, Context $context ): void {
		$this->sample->flush( $object_ids, $context );
	}
}
