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

/** One provider family with two independent contract facets. */
final class SampleTypedProvider implements API\Provider {
	public function __construct( public readonly MemoryStore $store, private readonly array $config = [] ) {}
	public function id(): string {
		return $this->config['id'] ?? 'sample';
	}
	public function identity(): string {
		return $this->config['identity'] ?? 'org.example.sample';
	}
	public function label(): string {
		return $this->config['label'] ?? 'Sample parts';
	}
	public function version(): string {
		return '1.0.0';
	}
	public function availability(): bool|\WP_Error {
		return empty( $this->store->faults['unavailable'] );
	}
	public function fields(): array {
		return [ new CompositeField( $this->store ), new CompositeField( $this->store, true ) ];
	}
	public function actions(): array {
		return array_merge( [ new AssignAction( $this->store, false, false, $this->config['row_actions'] ?? false ), new AssignAction( $this->store, true, $this->config['category_cycle'] ?? false ) ], $this->config['extra_actions'] ?? [] );
	}
	public function groups(): array {
		return [];
	}
	public function legacy(): ?\BulkCatalogueManager\Extension\Provider {
		return new SampleLegacyProvider();
	}
	public function legacy_discovery(): array {
		return $this->config['legacy_discovery'] ?? [];
	}
	public function authorise( int $user_id, API\Subject $subject ): bool|\WP_Error {
		return ! in_array( $subject->reference()->key(), $this->store->faults['denied_targets'] ?? [], true );
	}
	public function state_token( API\Scope $scope, array $steps, API\Context $context ): string {
		return hash( 'sha256', (string) wp_json_encode( [ $this->store->choices, $this->store->faults['policy_version'] ?? 1 ] ) );
	}
	public function prime( array $targets, API\Context $context ): void {
		/* Memory storage is already resident. */ }
	public function finalise( API\FinalisationGroup $group, API\Context $context ): bool|\WP_Error {
		if ( 'finalise' !== $context->mode() || $group->provider !== $this->id() || $group->identity !== $this->identity() ) {
			return new \WP_Error( 'x_sample_group', 'Invalid sample finalisation context.' );
		}
		$targets = [];
		$intents = [];
		$entries = [];
		foreach ( $group->entries as $entry ) {
			$targets[ $entry->target->key() ] = true;
			$intents[]                        = $entry->intent_id;
			$entries[]                        = get_object_vars( $entry );
		}
		$record = [
			'group_id'        => $group->id,
			'entries_hash'    => hash( 'sha256', (string) wp_json_encode( $entries ) ),
			'targets'         => array_keys( $targets ),
			'intent_ids'      => $intents,
			'clear_succeeded' => false,
		];
		if ( empty( $this->store->faults['finalise_fail'] ) ) {
			foreach ( array_keys( $targets ) as $target ) {
				$this->store->hygiene[] = $target;
			}
			$record['clear_succeeded'] = $this->store->clear();
		}
		$this->store->calls[] = $record;
		return $record['clear_succeeded'] ? true : new \WP_Error( 'x_sample_finalise', 'Sample native clear failed.' );
	}
}
