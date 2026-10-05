<?php
/**
 * The test provider's directory of recipients: provider-owned ids `r0001`
 * to `r5000` (by default) that mean nothing to the core. It answers
 * lookups and resolutions for every lookup argument of PicksAction.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\LookupItem;
use BulkCatalogueManager\Extension\LookupPage;

/**
 * The directory.
 */
final class PicksDirectory {
	/**
	 * Every lookup call, for assertions: [ argument, query, page, mode, user ].
	 *
	 * @var list<array{0: string, 1: string, 2: int, 3: string, 4: int}>
	 */
	public static array $lookups = [];

	/**
	 * Every resolve call, for assertions: [ argument, ids, mode ].
	 *
	 * @var list<array{0: string, 1: list<string>, 2: string}>
	 */
	public static array $resolved = [];

	/**
	 * Forget recorded calls.
	 */
	public static function reset(): void {
		self::$lookups  = [];
		self::$resolved = [];
	}

	/**
	 * The id of recipient n.
	 *
	 * @param int $n From 1.
	 */
	public static function id( int $n ): string {
		return sprintf( 'r%04d', $n );
	}

	/**
	 * Every recipient that exists, offered or not: id => [ label, detail ].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function all(): array {
		$size    = max( 0, (int) PicksFaults::get( 'size', 5000 ) );
		$renamed = (array) PicksFaults::get( 'renamed', [] );
		$all     = [];
		for ( $n = 1; $n <= $size; $n++ ) {
			$id         = self::id( $n );
			$all[ $id ] = [ (string) ( $renamed[ $id ] ?? sprintf( 'Recipient %04d', $n ) ), 'Team ' . chr( 65 + ( $n % 5 ) ) ];
		}
		return $all;
	}

	/**
	 * Whether a user may choose a recipient now.
	 *
	 * @param string $id      The recipient id.
	 * @param int    $user_id The user.
	 */
	public static function allowed( string $id, int $user_id ): bool {
		if ( PicksFaults::has( 'withdrawn', $id ) ) {
			return false;
		}
		return ! PicksFaults::has( 'restricted', $id ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * One page of the recipients matching a search.
	 *
	 * @param string  $argument The argument.
	 * @param string  $query    The search.
	 * @param int     $page     The page.
	 * @param Context $context  The run.
	 */
	public static function lookup( string $argument, string $query, int $page, Context $context ): LookupPage|\WP_Error {
		self::$lookups[] = [ $argument, $query, $page, $context->mode(), $context->user_id() ];
		if ( PicksFaults::get( 'lookup_error', false ) ) {
			return new \WP_Error( 'x_picks_directory_down', 'The recipient directory cannot be searched right now.' );
		}
		$needle  = strtolower( $query );
		$matches = [];
		foreach ( self::all() as $id => [ $label, $detail ] ) {
			if ( ! self::allowed( $id, $context->user_id() ) ) {
				continue;
			}
			if ( '' === $needle || str_contains( strtolower( $label ), $needle ) || str_contains( strtolower( $detail ), $needle ) || str_contains( $id, $needle ) ) {
				$matches[] = new LookupItem( $id, $label, $detail );
			}
		}
		$offset = ( max( 1, $page ) - 1 ) * LookupPage::SIZE;
		return new LookupPage( array_slice( $matches, $offset, LookupPage::SIZE ), count( $matches ) > $offset + LookupPage::SIZE );
	}

	/**
	 * The ids that may be chosen now, with their labels.
	 *
	 * @param string       $argument The argument.
	 * @param list<string> $ids      The ids.
	 * @param Context      $context  The run.
	 * @return list<LookupItem>
	 */
	public static function resolve( string $argument, array $ids, Context $context ): array {
		self::$resolved[] = [ $argument, $ids, $context->mode() ];
		$all              = self::all();
		$items            = [];
		foreach ( $ids as $id ) {
			if ( isset( $all[ $id ] ) && self::allowed( $id, $context->user_id() ) ) {
				$items[] = new LookupItem( $id, $all[ $id ][0], $all[ $id ][1] );
			}
		}
		return $items;
	}

	/**
	 * A recipient's current label, offered or not, or a plain stand-in.
	 *
	 * @param string $id The recipient id.
	 */
	public static function label( string $id ): string {
		$all = self::all();
		return $all[ $id ][0] ?? sprintf( 'A recipient no longer listed (%s)', $id );
	}

	/**
	 * A fingerprint of everything the choices depend on, for state_token().
	 */
	public static function fingerprint(): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					PicksFaults::get( 'size', 5000 ),
					PicksFaults::get( 'withdrawn', [] ),
					PicksFaults::get( 'restricted', [] ),
					PicksFaults::get( 'renamed', [] ),
					(string) PicksFaults::get( 'state', '' ),
				]
			)
		);
	}
}
