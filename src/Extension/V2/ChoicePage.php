<?php
/**
 * ChoicePage for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ChoicePage immutable contract value. */
final class ChoicePage {
	/**
	 * Validate and retain the complete contract value.
	 * @param array $items Contract value.
	 * @param ?string $next_cursor Contract value.
	 * @param ?string $state_token Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly array $items, public readonly ?string $next_cursor, public readonly ?string $state_token = null ) {
		Rules::ensure( array_is_list( $items ) && count( $items ) <= 50, 'Invalid choice page.' );
		$seen = [];
		foreach ( array_keys( $items ) as $position ) {
			Rules::ensure( null === \ReflectionReference::fromArrayElement( $items, $position ), 'Mutable references are not contract values.' );
		}
		foreach ( $items as $item ) {
			Rules::ensure( $item instanceof Choice && ! isset( $seen[ $item->value ] ), 'Invalid or duplicate choice.' );
			$seen[ $item->value ] = true;
		}
		Rules::ensure( ( null === $next_cursor || ( strlen( $next_cursor ) <= 2048 && 1 === preg_match( '//u', $next_cursor ) ) ) && ( null === $state_token || ( strlen( $state_token ) <= 1024 && 1 === preg_match( '//u', $state_token ) ) ), 'Invalid choice cursor or state.' );
	}
}
