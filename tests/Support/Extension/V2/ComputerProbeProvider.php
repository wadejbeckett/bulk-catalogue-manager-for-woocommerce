<?php
/**
 * Observation provider for pure Computer outcome/context boundary tests.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;

/** Test provider delegates declarations/domain computation; observes outcome and context boundaries. */
final class ComputerProbeProvider implements API\Provider {
	public array $contexts = [];
	public array $before   = [];
	public bool $large     = false;
	public function __construct( private readonly MemoryStore $store ) {}
	public function id(): string {
		return 'sample';}
	public function identity(): string {
		return 'org.example.sample';}
	public function label(): string {
		return 'Sample';}
	public function version(): string {
		return '1.0.0';}
	public function availability(): bool|\WP_Error {
		return true;}
	public function fields(): array {
		return [ new \BulkCatalogueManager\Tests\Support\Extension\V2\CompositeField( $this->store ) ];}
	public function groups(): array {
		return [];}
	public function legacy(): ?\BulkCatalogueManager\Extension\Provider {
		return null;}
	public function legacy_discovery(): array {
		return [];}
	public function authorise( int $user_id, API\Subject $subject ): bool|\WP_Error {
		return 7 === $user_id;}
	public function state_token( API\Scope $scope, array $steps, API\Context $context ): string {
		return '';}
	public function prime( array $targets, API\Context $context ): void {}
	public function finalise( API\FinalisationGroup $group, API\Context $context ): bool|\WP_Error {
		throw new \LogicException( 'Pure computation never finalises.' );}
	public function actions(): array {
		$real = new \BulkCatalogueManager\Tests\Support\Extension\V2\AssignAction( $this->store );
		return [
			new class($this,$real) implements API\Action {
				public function __construct( private readonly ComputerProbeProvider $provider, private readonly API\Action $real ) {}
				public function describe(): API\ActionDescriptor {
					return $this->real->describe();}
				public function availability( API\Scope $scope, array $args, API\Context $context ): bool|\WP_Error {
					return true;}
				public function validate( API\Scope $scope, array $args, API\Context $context ): API\Snapshot|\WP_Error {
					return $this->real->validate( $scope, $args, $context );}
				public function compute( API\Subject $subject, API\ValidatedStep $step, API\Context $context ): API\Computation {
					$this->provider->contexts[] = $context;
					$this->provider->before[]   = $subject->value( $step->field );
					$real                       = $this->real->compute( $subject, $step, $context );
					$result                     = match ( $step->index ) {
						1=>\BulkCatalogueManager\Operations\Result::skipped( $step->field, $subject->value( $step->field ), 'Skipped fixture step.', 'x_sample_skip' ), 2=>\BulkCatalogueManager\Operations\Result::failed( $step->field, $subject->value( $step->field ), 'Failed fixture step.', 'x_sample_failure' ), default=>$real->result};
					return new API\Computation( $result, $this->provider->large ? new API\Snapshot( 1, [ 'origin' => str_repeat( 'y', 16000 ) ] ) : $real->origin );
				}
			},
		];
	}
}
