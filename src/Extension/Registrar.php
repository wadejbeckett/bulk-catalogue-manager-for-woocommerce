<?php
/**
 * What a provider registers with (docs/EXTENSIONS.md §3): the core passes one
 * to the `bulkcat_register_providers` action, once per request, and accepts
 * registrations only while that action runs.
 *
 *     add_action( 'bulkcat_register_providers', static function ( Registrar $registrar ): void {
 *         if ( $registrar->supports( 1 ) ) {
 *             $registrar->register( new My_Provider() );
 *         }
 *     } );
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

use BulkCatalogueManager\External\Collector;

defined( 'ABSPATH' ) || exit;

/**
 * The registrar, contract version 1.
 */
final class Registrar {
	/**
	 * Built by the core only.
	 *
	 * @param Collector $collector Where accepted providers go.
	 */
	public function __construct( private readonly Collector $collector ) {}

	/**
	 * The newest contract version this core implements.
	 */
	public function contract(): int {
		return Contract::VERSION;
	}

	/**
	 * Whether this core accepts providers written for a contract version.
	 *
	 * @param int $version A contract version.
	 */
	public function supports( int $version ): bool {
		return in_array( $version, Contract::SUPPORTED, true );
	}

	/**
	 * Register a provider: true, or a WP_Error naming why the whole provider
	 * was refused (`bulkcat_provider_incompatible`, or `bulkcat_provider_late`
	 * after the action has finished). A refused provider contributes nothing.
	 *
	 * @param Provider $provider The provider.
	 * @return bool|\WP_Error True, or the refusal.
	 */
	public function register( Provider $provider ): bool|\WP_Error {
		return $this->collector->accept( $provider );
	}
}
