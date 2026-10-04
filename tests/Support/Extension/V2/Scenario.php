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

/** Test vocabulary grows only when the corresponding real core service exists. */
final class Scenario {
	public readonly MemoryStore $store;
	public readonly \BulkCatalogueManager\Tests\Unit\Engine\InlineStore $database;
	private array $grants   = [];
	public array $snapshots = [];
	private int $seen       = 0;
	public readonly \BulkCatalogueManager\Requests\RequestService $service;
	public function __construct() {
		bulkcat_test_reset();
		require_once dirname( __DIR__, 3 ) . '/Unit/Engine/InlineStore.php';
		$this->database = new \BulkCatalogueManager\Tests\Unit\Engine\InlineStore();
		$this->database->enable_receipts();
		$this->store                             = new MemoryStore();
		$this->service                           = new \BulkCatalogueManager\Requests\RequestService();
		$GLOBALS['bulkcat_test']['user_id']      = 3;
		$GLOBALS['bulkcat_test']['user_caps'][3] = [
			'manage_woocommerce'   => true,
			'edit_products'        => true,
			'manage_product_terms' => true,
			'edit_product_terms'   => true,
			'edit_post'            => true,
			'edit_term'            => true,
		];
		$registry                                = new \ReflectionClass( \BulkCatalogueManager\Operations\Registry::class );
		$registry->setStaticPropertyValue( 'instance', null );
		$registry->setStaticPropertyValue( 'fired', false );
		$this->restore_provider();
		$this->storage_responses();
	}
	public function product( int $id, array $value ): void {
		$this->store->seed( 'product::' . $id, $value );
		$GLOBALS['bulkcat_test']['posts'][ $id ]    = [
			'ID'          => $id,
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_parent' => 0,
		];
		$GLOBALS['bulkcat_test']['products'][ $id ] = new \BulkCatalogueManager\Tests\Support\SavedProduct( 'simple', [ 'id' => $id ] );
	}
	public function term( int $id, int $parent, array $value ): void {
		$this->store->seed( 'term:product_cat:' . $id, $value, 'x_sample_category_composite' );
		$GLOBALS['bulkcat_test']['term_list'][] = (object) [
			'term_id'  => $id,
			'taxonomy' => 'product_cat',
			'parent'   => $parent,
		];
	}
	public function step( string $part, string $operation, array $values ): array {
		return [
			'action' => 'x_sample_assign',
			'args'   => [
				'field'     => 'x_sample_composite',
				'part'      => $part,
				'operation' => $operation,
				'values'    => $values,
			],
		];
	}
	public function preview( array $targets, array $steps ): array|\WP_Error {
				return $this->service->preview(
					[
						'serializer' => 2,
						'external'   => [
							'contract' => 2,
							'provider' => 'sample',
							'identity' => 'org.example.sample',
						],
						'entry'      => 'product.bulk',
						'targets'    => $targets,
						'operations' => $steps,
					]
				);
	}
	public function compute( array $targets, array $steps ): array {
		if ( 1 !== count( $targets ) ) {
			throw new \LogicException( 'Pure fixture requires one target.' ); }
		$family   = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed()->family( 'sample' );
		$provider = $family['provider'];
		$scope    = \BulkCatalogueManager\External\V2\TypedScope::from_request( [ 'targets' => $targets ], 3 );
		$context  = new \BulkCatalogueManager\External\V2\RunContext( 'preview', 3, 0 );
		$binding  = \BulkCatalogueManager\External\V2\Binding::of( $provider, $scope, $steps, $context );
		if ( is_wp_error( $binding ) ) {
			throw new \LogicException( $binding->get_error_message() ); }
		$ref              = API\TargetRef::from_array( $targets[0] );
		$subject          = \BulkCatalogueManager\External\V2\SubjectLoader::load( $ref, 3 );
		$rows             = ( new \BulkCatalogueManager\External\V2\Computer( $provider ) )->run( $subject, \BulkCatalogueManager\External\V2\Binding::validated( $binding ), $context );
		$key              = array_key_first( $rows );
		$row              = $rows[ $key ];
		$row['history']   = new API\FieldHistory( $provider->id(), $provider->identity(), 2, $key, $family['typed']['fields'][ $key ]->format(), $ref, $row['before'], $row['after'], $row['contributions'] );
		$row['reference'] = $ref;
		return $row;
	}
	public function restore_plan( array $computed, array $current, bool $force = false, string $direction = 'undo' ): API\RestoreDecision|\WP_Error {
		$history = $computed['history'];
		$family  = \BulkCatalogueManager\Operations\Registry::instance()->providers()->typed()->family( $history->provider );
		$field   = $family['typed']['fields'][ $history->field ] ?? null;
		if ( null === $field ) {
			return \BulkCatalogueManager\External\Refusals::missing(); }
		$subject = \BulkCatalogueManager\External\V2\SubjectLoader::load( $computed['reference'], 3 );
		if ( is_wp_error( $subject ) ) {
			return $subject; }
		$redo    = 'redo' === $direction;
		$context = new \BulkCatalogueManager\External\V2\RecoveryState( $history, $redo ? $history->before : $history->after, $redo ? $history->after : $history->before, $force, $direction, 3, 0 );
		return \BulkCatalogueManager\External\V2\Recovery::plan( $field, $subject, $current, $context );
	}
	public function accept( array $preview ): array|\WP_Error {
				$key = $preview['hash'];
		if ( ! isset( $this->grants[ $key ] ) ) {
			$intent = [
				'serializer' => 2,
				'family'     => $preview['family'],
				'source'     => 'bulk',
				'entry'      => $preview['entry'],
				'external'   => $preview['external'],
				'selection'  => array_intersect_key( $preview['selection'], array_flip( [ 'targets', 'token', 'typed_token' ] ) ),
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			];
			$grant  = $this->service->issue( 'batch.apply', $intent );
			if ( is_wp_error( $grant ) ) {
				return $grant; }
			$this->grants[ $key ] = array_intersect_key( $grant, array_flip( [ 'uuid', 'request_proof', 'intent' ] ) ) + [ 'preview_hash' => $preview['hash'] ];
		}
		return $this->service->apply( $this->grants[ $key ] );
	}
	public function run( int $batch_id ): string {
		throw new \LogicException( 'The owning SDK runtime task has not implemented run.' );
	}
	public function value( string $target_key ): array {
		return $this->store->value( $target_key, str_starts_with( $target_key, 'term:' ) ? 'x_sample_category_composite' : 'x_sample_composite' );
	}
	public function external_edit( string $target_key, array $patch ): void {
		throw new \LogicException( 'The owning SDK runtime task has not implemented external_edit.' );
	}
	public function history( int $batch_id ): array {
		throw new \LogicException( 'The owning SDK runtime task has not implemented history.' );
	}
	public function undo( int $batch_id, bool $force = false ): int|\WP_Error {
		throw new \LogicException( 'The owning SDK runtime task has not implemented undo.' );
	}
	public function redo( int $undo_batch_id, bool $force = false ): int|\WP_Error {
		throw new \LogicException( 'The owning SDK runtime task has not implemented redo.' );
	}
	public function fault( string $name, mixed $value = true ): void {
		throw new \LogicException( 'The owning SDK runtime task has not implemented fault.' );
	}
	public function remove_provider(): void {
				$this->register( null );
	}
	public function restore_provider( string $identity = 'org.example.sample', array $extra_actions = [] ): void {
				$this->register(
					new SampleTypedProvider(
						$this->store,
						[
							'identity'      => $identity,
							'row_actions'   => true,
							'extra_actions' => $extra_actions,
						]
					)
				);
	}
	public function recover( int $batch_id, string $mode = 'finalise_only' ): array|\WP_Error {
		throw new \LogicException( 'The owning SDK runtime task has not implemented recover.' );
	}
	public function lookup( array $request ): array|\WP_Error {
		return ( new \BulkCatalogueManager\External\V2\LookupService() )->search( $request, get_current_user_id() );
	}
	public function choices( int $count ): void {
		$this->store->choices = []; for ( $i = 1; $i <= $count; ++$i ) {
			$this->store->choices[ 'v' . $i ] = 'Value ' . $i;
		}
	}
	public function capture( array $roots, bool $descendants = false ): array|\WP_Error {
		throw new \LogicException( 'The owning SDK runtime task has not implemented capture.' );
	}
	public function members( string $typed_token ): array {
		throw new \LogicException( 'The owning SDK runtime task has not implemented members.' );
	}
	public function reparent( int $term_id, int $parent_id ): void {
		throw new \LogicException( 'The owning SDK runtime task has not implemented reparent.' );
	}
	public function delete_term( int $term_id ): void {
		throw new \LogicException( 'The owning SDK runtime task has not implemented delete_term.' );
	}
	public function revoke( int $actor, string $capability ): void {
		throw new \LogicException( 'The owning SDK runtime task has not implemented revoke.' );
	}
	public function write_count( string $target_key ): int {
		throw new \LogicException( 'The owning SDK runtime task has not implemented write_count.' );
	}
	public function finalisations( string $target_key ): int {
		return count( array_filter( $this->store->calls, static fn( array $call ): bool => $call['clear_succeeded'] && in_array( $target_key, $call['targets'], true ) ) );
	}
	public function finalisation_calls(): array {
		return $this->store->calls;
	}
	public function cache_clears(): int {
		return $this->store->clears;
	}
	public function finalisation_state( int $batch_id ): array {
		throw new \LogicException( 'The owning SDK runtime task has not implemented finalisation_state.' );
	}
	/** Test-only qualification injection through the real collector and registry. */
	private function register( ?SampleTypedProvider $provider ): void {
		$c = new \BulkCatalogueManager\External\V2\Collector(
			null,
			[
				'typed-product-subjects' => 1,
				'category-subjects'      => 1,
				'choice-search'          => 1,
				'dependent-arguments'    => 1,
				'row-actions'            => 1,
			]
		);
		if ( null !== $provider ) {
			$c->register( $provider ); }
		$c->close();
		$typed     = $c->registry();
		$r         = new \ReflectionClass( \BulkCatalogueManager\External\Providers::class );
		$providers = $r->newInstanceWithoutConstructor();
		$r->getConstructor()->invoke( $providers, $typed->legacy(), [], $typed );
		$actions = new \BulkCatalogueManager\Operations\Registry( \BulkCatalogueManager\Fields\Registry::instance(), null, $providers );
		$r       = new \ReflectionClass( \BulkCatalogueManager\Operations\Registry::class );
		$r->setStaticPropertyValue( 'instance', $actions );
		$r->setStaticPropertyValue( 'fired', true );
	}
	/** SQL boundary simulation only: all services, signing and transactions remain real. */
	private function storage_responses(): void {
		global $wpdb;
		foreach ( [ 'bulkcat_batch_items', 'bulkcat_changes' ] as $name ) {
			$wpdb->responses[ '/^SHOW COLUMNS FROM `wp_' . $name . '` LIKE/' ]                    = [
				'Type'    => 'varchar(32)',
				'Null'    => 'NO',
				'Default' => '',
			];
			$wpdb->responses[ '/^SHOW INDEX FROM `wp_' . $name . "` WHERE Key_name = 'target'/" ] = array_map(
				static fn( string $column, int $i ): array => [
					'Column_name'  => $column,
					'Seq_in_index' => $i + 1,
					'Sub_part'     => null,
				],
				[ 'batch_id', 'object_type', 'target_taxonomy', 'object_id' ],
				[ 0, 1, 2, 3 ]
			);
		}
		$wpdb->responses                      = [
			"/^UPDATE `wp_bulkcat_requests` SET result_kind = 'snapshot'/" => function (): int {
				[ , $id, $owner, $uuid ] = end( $GLOBALS['wpdb']->prepared )[1];
				if ( ! isset( $this->database->receipts[ $uuid ] ) || $owner !== $this->database->receipts[ $uuid ]['user_id'] ) {
					return 0; }
				$this->database->receipts[ $uuid ]['result_id']   = $id;
				$this->database->receipts[ $uuid ]['result_kind'] = 'snapshot';
				return 1;
			},
			'/^INSERT INTO `wp_bulkcat_batch_items` \(batch_id, object_id, object_type, target_taxonomy/' => function (): int {
				$args = end( $GLOBALS['wpdb']->prepared )[1];
				array_shift( $args );
				foreach ( array_chunk( $args, 5 ) as [ $batch, $id, $kind, $taxonomy, $operations ] ) {
					$item                           = count( $this->database->items ) + 1;
					$this->database->items[ $item ] = [
						'item_id'         => $item,
						'batch_id'        => $batch,
						'object_id'       => $id,
						'object_type'     => $kind,
						'target_taxonomy' => $taxonomy,
						'operations'      => $operations,
						'state'           => 'pending',
						'attempts'        => 0,
					];
				}
				return count( $args ) / 5;
			},
			'/^SELECT object_type, target_taxonomy, object_id FROM/' => function (): array {
				[ , $batch ] = end( $GLOBALS['wpdb']->prepared )[1];
				return array_values( array_filter( $this->database->items, static fn( array $row ): bool => $row['batch_id'] === $batch ) );
			},
			'/^SELECT \* FROM `wp_bulkcat_selections`/' => function (): ?array {
				$this->sync_snapshots();
				[ , $id, $owner ] = end( $GLOBALS['wpdb']->prepared )[1];
				$row              = $this->snapshots[ $id ] ?? null;
				return null !== $row && $row['user_id'] === $owner ? $row : null;
			},
		] + $wpdb->responses;
		$begin                                = $wpdb->responses['START TRANSACTION'];
		$rollback                             = $wpdb->responses['ROLLBACK'];
		$saved                                = null;
		$wpdb->responses['START TRANSACTION'] = function () use ( $begin, &$saved ): int {
			$this->sync_snapshots();
			$saved = [ $this->snapshots, $this->seen ];
			return $begin();
		};
		$wpdb->responses['ROLLBACK']          = function () use ( $rollback, &$saved ): int {
			$result = $rollback();
			if ( null !== $saved ) {
				[ $this->snapshots, $this->seen ] = $saved;
			} return $result;
		};
	}
	/** Fold snapshot insert/update statements; do not synthesize service outcomes. */
	public function sync_snapshots(): void {
		foreach ( array_slice( $GLOBALS['wpdb']->writes, $this->seen ) as [ $method, $table, $data, , $where ] ) {
			++$this->seen;
			if ( 'wp_bulkcat_selections' !== $table ) {
				continue; }
			if ( 'insert' === $method ) {
				$this->snapshots[ $data['id'] ] = $data; } elseif ( isset( $this->snapshots[ $where['id'] ] ) ) {
				$this->snapshots[ $where['id'] ] = $data + $this->snapshots[ $where['id'] ]; }
		}
	}
	/** Exact submitted envelope for conflict/owner/lease tests. */
	public function request( array $preview ): array {
		return $this->grants[ $preview['hash'] ]; }
}
