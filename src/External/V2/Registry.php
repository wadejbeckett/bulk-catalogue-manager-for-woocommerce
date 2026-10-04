<?php
/**
 * Immutable provider-family snapshot with separate contract facets.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\Action;
use BulkCatalogueManager\External\Registered;
defined( 'ABSPATH' ) || exit;
/** Internal lookup; this does not publish REST or authorize runtime execution. */
final class Registry {
	/**
	 * Keep identity and mutable display metadata separately in each family.
	 * @param array $families Families by immutable provider ID.
	 */
	public function __construct( private readonly array $families = [] ) {}
	/**
	 * One family, including its independent diagnostics.
	 * @param string $id Provider ID.
	 */
	public function family( string $id ): ?array {
		return $this->families[ $id ] ?? null;
	}
	/** Families in registration order. */
	public function families(): array {
		return $this->families;
	}
	/**
	 * A usable typed action, never a legacy action or an unsupported descriptor.
	 * @param string $id Action ID.
	 */
	public function action( string $id ): ?Action {
		foreach ( $this->families as $family ) {
			if ( isset( $family['typed']['actions'][ $id ] ) ) {
				return $family['typed']['actions'][ $id ];
			}
		}
		return null;
	}
	/**
	 * Accepted action metadata or a minimal disabled diagnostic.
	 * @param string $id Action ID.
	 */
	public function described( string $id ): ?array {
		foreach ( $this->families as $family ) {
			if ( isset( $family['typed']['described'][ $id ] ) ) {
				return $family['typed']['described'][ $id ];
			}
		}
		return null;
	}
	/** All valid legacy facets, including hidden ones for explicit/stored resolution. */
	public function legacy(): array {
		$result = [];
		foreach ( $this->families as $id => $family ) {
			if ( $family['legacy'] instanceof Registered ) {
				$result[ $id ] = $family['legacy'];
			}
		}
		return $result;
	}
}
