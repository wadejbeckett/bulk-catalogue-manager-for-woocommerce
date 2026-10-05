<?php
/**
 * Lookup choices on the core's side (docs/EXTENSIONS-v2.md §4): the lookup
 * arguments of an action, one sanitised page from a provider, and the
 * resolution of chosen ids at grant issuance, preview and acceptance.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\LookupItem;
use BulkCatalogueManager\Extension\LookupPage;
use BulkCatalogueManager\Extension\LookupSource;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Lookups and resolution.
 */
final class Choices {
	/**
	 * The refusal for a chosen id that no longer resolves.
	 */
	public const UNAVAILABLE = 'bulkcat_choice_unavailable';

	/**
	 * Longest detail line kept, in characters.
	 */
	public const DETAIL = 120;

	/**
	 * The names of an action's lookup arguments.
	 *
	 * @param array<string, mixed> $described The action as kept.
	 * @return list<string>
	 */
	public static function lookups( array $described ): array {
		$names = [];
		foreach ( (array) ( $described['arguments'] ?? [] ) as $name => $spec ) {
			if ( 'lookup' === ( $spec['type'] ?? '' ) ) {
				$names[] = (string) $name;
			}
		}
		return $names;
	}

	/**
	 * One plain item, or null when it breaks the contract.
	 *
	 * @param mixed $item What the provider gave.
	 * @return array{id: string, label: string, detail: string}|null
	 */
	public static function item( mixed $item ): ?array {
		if ( ! $item instanceof LookupItem || 1 !== preg_match( LookupItem::ID, $item->id() ) ) {
			return null;
		}
		$label = Text::plain( $item->label(), Text::LABEL );
		return '' === $label ? null : [
			'id'     => $item->id(),
			'label'  => $label,
			'detail' => Text::plain( $item->detail(), self::DETAIL ),
		];
	}

	/**
	 * One page from the provider, kept to the contract's bounds.
	 *
	 * @param Registered $provider The provider.
	 * @param string     $action   The action id.
	 * @param string     $argument The lookup argument.
	 * @param string     $query    The search.
	 * @param int        $page     The page.
	 * @param Context    $context  The run, mode lookup.
	 * @return array{items: list<array{id: string, label: string, detail: string}>, hasMore: bool}|\WP_Error
	 */
	public static function page( Registered $provider, string $action, string $argument, string $query, int $page, Context $context ): array|\WP_Error {
		$source = $provider->action( $action );
		if ( ! $source instanceof LookupSource ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		try {
			$answer = Guard::run( $provider->id, 'lookup', static fn(): mixed => $source->lookup( $argument, $query, $page, $context ) );
		} catch ( ProviderFailure $e ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		if ( $answer instanceof \WP_Error ) {
			$sentence = Text::plain( $answer->get_error_message(), Text::REASON );
			return Refusal::create( 'bulkcat_lookup_failed', '' === $sentence ? __( 'The add-on could not list its choices right now. Try again shortly.', 'bulk-catalogue-manager-for-woocommerce' ) : $sentence, 409 );
		}
		if ( ! $answer instanceof LookupPage ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		$items = [];
		$seen  = [];
		foreach ( $answer->items() as $item ) {
			$plain = self::item( $item );
			if ( null === $plain || isset( $seen[ $plain['id'] ] ) ) {
				continue;
			}
			$seen[ $plain['id'] ] = true;
			$items[]              = $plain;
		}
		$more = $answer->has_more() || count( $items ) > LookupPage::SIZE;
		return [
			'items'   => array_slice( $items, 0, LookupPage::SIZE ),
			'hasMore' => $more && $page < LookupPage::MAX_PAGE,
		];
	}

	/**
	 * Resolve every lookup argument of one validated step: id => label by
	 * argument, or the refusal naming the first argument with an id that
	 * does not resolve now.
	 *
	 * @param Registered           $provider  The provider.
	 * @param array<string, mixed> $described The action as kept.
	 * @param array<string, mixed> $args      Normalised arguments.
	 * @param Context              $context   The run.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	public static function resolve( Registered $provider, array $described, array $args, Context $context ): array|\WP_Error {
		$names = self::lookups( $described );
		if ( [] === $names ) {
			return [];
		}
		$source = $provider->action( (string) $described['id'] );
		if ( ! $source instanceof LookupSource ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		$resolved = [];
		foreach ( $names as $name ) {
			if ( ! array_key_exists( $name, $args ) ) {
				continue;
			}
			$ids = array_values( array_map( 'strval', (array) $args[ $name ] ) );
			if ( [] === $ids ) {
				$resolved[ $name ] = [];
				continue;
			}
			try {
				$answer = Guard::run( $provider->id, 'resolve', static fn(): mixed => $source->resolve( $name, $ids, $context ) );
			} catch ( ProviderFailure $e ) {
				return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
			}
			$labels = [];
			foreach ( is_array( $answer ) ? $answer : [] as $item ) {
				$plain = self::item( $item );
				if ( null !== $plain && in_array( $plain['id'], $ids, true ) ) {
					$labels[ $plain['id'] ] = $plain['label'];
				}
			}
			if ( count( $labels ) !== count( $ids ) ) {
				return Refusal::invalid(
					self::UNAVAILABLE,
					(string) $described['arguments'][ $name ]['rule'],
					[
						'action'   => (string) $described['id'],
						'argument' => $name,
					]
				);
			}
			$ordered = [];
			foreach ( $ids as $id ) {
				$ordered[ $id ] = $labels[ $id ];
			}
			$resolved[ $name ] = $ordered;
		}
		return $resolved;
	}

	/**
	 * The resolved labels of every step with lookups, for the preview
	 * binding and the manifest: action => argument => id => label. Steps
	 * whose ids no longer resolve carry what does resolve, so the binding
	 * differs and Apply is refused as stale.
	 *
	 * @param Registered                                              $provider   The provider.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations Validated steps.
	 * @param Context                                                 $context    The run.
	 * @return array<string, array<string, array<string, string>>>
	 */
	public static function of( Registered $provider, array $operations, Context $context ): array {
		$choices = [];
		foreach ( $operations as $step ) {
			$described = $provider->described( (string) ( $step['action'] ?? '' ) );
			if ( null === $described || [] === self::lookups( $described ) ) {
				continue;
			}
			$resolved = self::resolve( $provider, $described, (array) ( $step['args'] ?? [] ), $context );
			if ( $resolved instanceof \WP_Error ) {
				$resolved = [ '_refused' => [ $resolved->get_error_code() => (string) ( $resolved->get_error_data()['argument'] ?? '' ) ] ];
			}
			$choices[ $described['id'] ] = array_merge( $choices[ $described['id'] ] ?? [], $resolved );
		}
		return $choices;
	}
}
