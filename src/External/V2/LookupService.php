<?php
/** Bounded authorized display lookup; preview remains authoritative. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\{Refusals, Guard};
use BulkCatalogueManager\Operations\Registry as Actions;
defined( 'ABSPATH' ) || exit;
final class LookupService {
	public function __construct( private readonly ?Registry $registry = null, private readonly ?Cursor $cursor = null, private readonly ?Discovery $discovery = null ) {}
	/** Search one page. Source IDs and provider continuations never come directly from the browser. */
	public function search( array $request, int $actor ): array|\WP_Error {
		return $this->run( $request, $actor, false ); }
	/** Resolve only selected identities, independently of the current search page. */
	public function resolve( array $request, int $actor ): array|\WP_Error {
		return $this->run( $request, $actor, true ); }
	private function run( array $request, int $actor, bool $resolve ): array|\WP_Error {
		try {
			Rules::json( $request, 65536 );
			$keys = [ 'provider', 'action', 'argument', 'selection', 'dependencies', ...( $resolve ? [ 'values' ] : [ 'query', 'cursor', 'limit' ] ) ];
			Rules::ensure( count( $request ) === count( $keys ) && [] === array_diff( array_keys( $request ), $keys ), 'Invalid lookup keys.' );
			Rules::ensure( is_string( $request['provider'] ) && is_string( $request['action'] ) && is_string( $request['argument'] ) && is_array( $request['selection'] ) && is_array( $request['dependencies'] ), 'Invalid lookup identity.' );
			Rules::provider( $request['provider'] );
			Rules::key( $request['action'], $request['provider'] );
			Rules::name( $request['argument'] );
			if ( $resolve ) {
				$values = $request['values'];
				Rules::ensure( is_array( $values ) && array_is_list( $values ) && count( $values ) >= 1 && count( $values ) <= 100, 'Invalid resolve count.' );
				foreach ( $values as $value ) {
					Rules::ensure( is_string( $value ), 'Invalid value.' );
					Rules::opaque( $value );}
				Rules::ensure( count( array_unique( $values, SORT_STRING ) ) === count( $values ), 'Duplicate values.' );
			} else {
				Rules::ensure( is_string( $request['query'] ) && is_int( $request['limit'] ) && $request['limit'] >= 1 && $request['limit'] <= 50 && ( null === $request['cursor'] || is_string( $request['cursor'] ) ), 'Invalid page.' );
				Rules::text( $request['query'], 128, true );
			}
			$scope = TypedScope::from_request( $request['selection'], $actor );
			if ( is_wp_error( $scope ) ) {
					return Refusals::invalid_lookup();}
			$access = Discovery::native_access( $scope, $actor );
			if ( true !== $access ) {
				return $access;}
			$family = ( $this->registry ?? Actions::instance()->providers()->typed() )->family( $request['provider'] );
			$d      = $family['typed']['described'][ $request['action'] ]['descriptor'] ?? null;
			if ( ! $d instanceof API\ActionDescriptor || ! isset( $family['typed']['actions'][ $d->id ] ) ) {
					return Refusals::missing();}
			$action = $family['typed']['actions'][ $d->id ];
			$spec   = $d->arguments[ $request['argument'] ] ?? null;
			Rules::ensure( $action instanceof API\ChoiceSource && is_array( $spec ) && 'lookup' === $spec['type'], 'Not a lookup.' );
			$dependency_names = Discovery::dependencies( $d, $request['argument'] );
			Rules::ensure( [] === array_diff( array_keys( $request['dependencies'] ), $dependency_names ), 'Invalid dependencies.' );
			$normal = Discovery::form_arguments( $d, $request['dependencies'], $dependency_names );
			if ( is_wp_error( $normal ) ) {
				return Refusals::invalid_lookup();}
			$dependencies = array_intersect_key( $normal, array_flip( $dependency_names ) );
			$when         = $spec['active_when'] ?? null;
			Rules::ensure( null === $when || in_array( $normal[ $when['argument'] ] ?? null, $when['values'], true ), 'Inactive lookup.' );
			$access = ( $this->discovery ?? new Discovery() )->authorize( $family, $d, $scope, $actor, $normal );
			if ( true !== $access ) {
				return $access;}
		} catch ( \Throwable $error ) {
			return Refusals::invalid_lookup();}
		try {
			$context = new RunContext( 'preview', $actor, 0 );
			if ( $resolve ) {
				$items = Guard::run( $family['id'], 'choice resolve', fn()=>$action->resolve( $request['argument'], $values, $dependencies, $scope, $context ) );
				Rules::ensure( is_array( $items ) && array_is_list( $items ) && count( $items ) === count( $values ), 'Invalid resolved choices.' );
				$indexed = [];
				foreach ( $items as $item ) {
					Rules::ensure( $item instanceof API\ResolvedChoice && in_array( $item->value, $values, true ) && ! isset( $indexed[ $item->value ] ), 'Unexpected resolved identity.' );
					$indexed[ $item->value ] = [
						'value'     => $item->value,
						'label'     => $item->available ? $item->label : null,
						'available' => $item->available,
						'reason'    => $item->available ? '' : Refusals::choice_unavailable(),
					];}
				return [ 'items' => array_map( static fn( $value )=>$indexed[ $value ], $values ) ];
			}
			$binding = [
				'actor'           => $actor,
				'provider'        => $family['id'],
				'identity'        => $family['identity'],
				'action'          => $d->id,
				'argument'        => $request['argument'],
				'source'          => $spec['source'],
				'scope_hash'      => $scope->signature(),
				'dependency_hash' => hash( 'sha256', (string) wp_json_encode( $dependencies ) ),
				'query_hash'      => hash( 'sha256', $request['query'] ),
			];
			$signer  = $this->cursor ?? new Cursor();
			$claims  = null;
			if ( null !== $request['cursor'] ) {
				$claims = $signer->read( $request['cursor'], $binding );
				if ( is_wp_error( $claims ) ) {
						return $claims;}
			}
			$lookup = new API\Lookup( $request['argument'], $spec['source'], $dependencies, $request['query'], $claims['payload'] ?? null, $request['limit'] );
			$page   = Guard::run( $family['id'], 'choice search', fn()=>$action->search( $lookup, $scope, $context ) );
			Rules::ensure( $page instanceof API\ChoicePage && count( $page->items ) <= $request['limit'], 'Invalid choice page.' );
			if ( null !== $claims && $claims['state_token'] !== $page->state_token ) {
				return Refusals::choice_refresh();}
			return [
				'items'       => array_map(
					static fn( API\Choice $item )=>[
						'value' => $item->value,
						'label' => $item->label,
					],
					$page->items
				),
				'next_cursor' => null === $page->next_cursor ? null : $signer->issue( $binding, $page->state_token, $page->next_cursor ),
				'state_token' => $page->state_token,
			];
		} catch ( \Throwable $error ) {
			Guard::log( 'Choice response failed: ' . $error->getMessage(), 'warning' );
			return Refusals::invalid_provider_response();}
	}
}
