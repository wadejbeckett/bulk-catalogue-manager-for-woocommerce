<?php
/**
 * What a preview of an external change binds besides its scope
 * (docs/EXTENSIONS.md §7.2, §12): the provider's id, version and contract,
 * the format of each field the steps name, and its state token over the
 * change's available members. Engine\Preview::hash() takes it as its
 * `external` member; Apply recomputes it, so a provider update or a change of
 * what its computation depends on asks for a new preview.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The binding.
 */
final class Binding {
	/**
	 * The binding of a change, asking the provider for its state token.
	 *
	 * @param Registered                                            $provider   The change's provider.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised steps.
	 * @param list<int>                                             $members    The change's available members, in any order.
	 * @param Context|null                                          $context    The run; the current one by default.
	 * @return array{provider: string, version: string, contract: int, formats: array<string, int>, state: string}
	 * @throws ProviderFailure When state_token() throws.
	 */
	public static function of( Registered $provider, array $operations, array $members, ?Context $context = null ): array {
		$formats = [];
		foreach ( $operations as $step ) {
			$key  = (string) ( $step['args']['field'] ?? '' );
			$meta = $provider->field_meta( $key );
			if ( null !== $meta ) {
				$formats[ $key ] = $meta['format'];
			}
		}
		$scope     = new ScopeView( $members );
		$context ??= RunContext::current();
		$state     = Guard::run( $provider->id, 'state_token', static fn(): mixed => $provider->provider->state_token( $scope, $operations, $context ) );
		$binding   = [
			'provider' => $provider->id,
			'version'  => $provider->version,
			'contract' => $provider->contract,
			'formats'  => $formats,
			'state'    => is_string( $state ) ? $state : '',
		];
		// Contract version 2: the resolved choices, only when a step has a lookup (docs/EXTENSIONS-v2.md §4.4).
		$choices = Choices::of( $provider, $operations, $context );
		if ( [] !== $choices ) {
			$binding['choices'] = $choices;
		}
		return $binding;
	}
}
