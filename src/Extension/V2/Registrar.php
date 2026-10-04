<?php
/**
 * The opt-in typed registration boundary.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Typed registrar; the literal v1 Registrar is untouched. */
final class Registrar {
	/**
	 * Bind this request's collector.
	 * @param \BulkCatalogueManager\External\V2\Collector $collector Internal collector.
	 */
	public function __construct( private readonly \BulkCatalogueManager\External\V2\Collector $collector ) {}
	/** Declaration version. */
	public function contract(): int {
		return Contract::VERSION;
	}
	/**
	 * Exact negotiation, with no downgrade.
	 * @param int $version Requested version.
	 */
	public function supports( int $version ): bool {
		return 2 === $version;
	}
	/**
	 * Whether a runtime capability has qualified at this exact version.
	 * @param string $name Capability name.
	 * @param int $version Capability version.
	 */
	public function supports_capability( string $name, int $version = 1 ): bool {
		return $this->collector->supports_capability( $name, $version );
	}
	/**
	 * Register one stable provider family.
	 * @param Provider $provider Provider family.
	 */
	public function register( Provider $provider ): bool|\WP_Error {
		return $this->collector->register( $provider );
	}
}
