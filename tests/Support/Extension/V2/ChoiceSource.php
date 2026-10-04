<?php
/** Neutral in-memory lookup boundary, never a native fixture. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;
trait ChoiceSource {
	public function search( API\Lookup $lookup, API\Scope $scope, API\Context $context ): API\ChoicePage|\WP_Error {
		$this->store->lookup_calls[] = [ 'search', $lookup->limit, $lookup->cursor ];
		$fault                       = $this->store->faults['lookup'] ?? '';
		if ( 'throw' === $fault ) {
			throw new \RuntimeException( '/private/error.php secret SQL' );}
		if ( 'error' === $fault ) {
			return new \WP_Error( 'private', '/private/error.php' );}
		$choices = array_filter( $this->store->choices, fn( $label, $value )=> ! in_array( $value, $this->store->faults['denied_choices'] ?? [], true ) && ( '' === $lookup->query || str_contains( $label, $lookup->query ) ), ARRAY_FILTER_USE_BOTH );
		$offset  = (int) ( $lookup->cursor ?? 0 );
		$slice   = array_slice( $choices, $offset, 'oversize' === $fault ? 51 : ( 'ignore_limit' === $fault ? 2 : $lookup->limit ), true );
		$items   = [];
		foreach ( $slice as $value => $label ) {
			$items[] = new API\Choice( (string) $value, $label );}
		return new API\ChoicePage( $items, $offset + count( $items ) < count( $choices ) ? (string) ( $offset + count( $items ) ) : null, hash( 'sha256', (string) wp_json_encode( [ $choices,$this->store->faults['policy_version'] ?? 1 ] ) ) );
	}
	public function resolve( string $argument, array $values, array $dependencies, API\Scope $scope, API\Context $context ): array|\WP_Error {
		$this->store->lookup_calls[] = [ 'resolve', count( $values ) ];
		$fault                       = $this->store->faults['lookup'] ?? '';
		if ( 'wrong_resolve' === $fault ) {
			return [ new API\ResolvedChoice( 'other', 'Wrong', true ) ];}
		$items = [];
		foreach ( $values as $value ) {
			$label     = $this->store->choices[ $value ] ?? null;
			$available = null !== $label && ! in_array( $value, $this->store->faults['denied_choices'] ?? [], true );
			$items[]   = new API\ResolvedChoice( $value, $available ? $label : null, $available, $available ? '' : 'Unavailable' );}
		if ( 'extra_resolve' === $fault ) {
			$items[] = $items[0];
		}return $items;
	}
}
