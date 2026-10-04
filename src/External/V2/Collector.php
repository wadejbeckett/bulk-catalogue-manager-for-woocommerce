<?php
/**
 * Unified internal family collector; the literal v1 registrar remains final and unchanged.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Extension\Provider as LegacyProvider;
use BulkCatalogueManager\External\Collector as LegacyCollector;
use BulkCatalogueManager\External\Inspector as LegacyInspector;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\Guard;
use BulkCatalogueManager\External\Text;
defined( 'ABSPATH' ) || exit;
/** Collect one family per ID; field/action defects remain within their facet. */
final class Collector {
	/** Whether the two registration hooks are still open. */
	private bool $open = true;
	/** Families by immutable provider ID. */
	private array $families = [];
	/** Sanitized registration diagnostics. */
	private array $refusals = [];
	/** Legacy registration retains its original whole-provider checks. */
	private readonly LegacyCollector $legacy;
	/**
	 * Capability injection is internal for qualification tests, never a public filter.
	 * @param LegacyCollector|null $legacy Completed old-hook registrations.
	 * @param array $capabilities Exact qualified capability versions.
	 */
	public function __construct( ?LegacyCollector $legacy = null, private readonly array $capabilities = API\Contract::CAPABILITIES ) {
		$this->legacy = $legacy ?? new LegacyCollector();
		foreach ( $this->legacy->registered() as $registered ) {
			$this->keep_legacy( $registered );
		}
		$this->refusals = $this->legacy->refusals();
	}
	/**
	 * Exact named version; unknown entries can never become support.
	 * @param string $name Name.
	 * @param int $version Version.
	 */
	public function supports_capability( string $name, int $version = 1 ): bool {
		return 1 === $version && in_array( $name, [ 'typed-product-subjects', 'aggregate-recovery', 'durable-finalisation', 'choice-search', 'dependent-arguments', 'row-actions', 'category-subjects' ], true ) && 1 === ( $this->capabilities[ $name ] ?? null );
	}
	/**
	 * Register one V2 family and inspect its facets independently.
	 * @param API\Provider $provider Provider.
	 */
	public function register( API\Provider $provider ): bool|\WP_Error {
		if ( ! $this->open ) {
			return Refusals::late();
		}
		try {
			$id = $provider->id();
			Rules::provider( $id );
			$identity = $provider->identity();
			Rules::identity( $identity );
			Rules::ensure( ! isset( $this->families[ $id ] ), 'Duplicate provider family.' );
			$label   = Text::plain( $provider->label(), 80 );
			$version = Text::plain( $provider->version(), 40 );
			Rules::text( $label, 80 );
			Rules::text( $version, 40 );
		} catch ( \Throwable $error ) {
			return $this->refuse( $error );
		}
		$family = [
			'id'               => $id,
			'identity'         => $identity,
			'contract'         => 2,
			'label'            => $label,
			'version'          => $version,
			'provider'         => $provider,
			'legacy'           => null,
			'legacy_reason'    => '',
			'legacy_discovery' => [],
			'typed'            => Inspector::inspect( $provider, $id, $this->capabilities ),
		];
		try {
			$legacy    = $provider->legacy();
			$discovery = $provider->legacy_discovery();
			Rules::ensure( array_is_list( $discovery ) && count( $discovery ) <= 20 && count( array_unique( $discovery ) ) === count( $discovery ), 'Invalid legacy discovery list.' );
			if ( null !== $legacy ) {
				Rules::ensure( $legacy->id() === $id, 'Legacy facet belongs to a different provider.' );
				$checked = LegacyInspector::inspect( $legacy, [] );
				if ( $checked instanceof \WP_Error ) {
					$family['legacy_reason'] = $checked->get_error_message();
				} else {
					foreach ( $discovery as $action ) {
						Rules::ensure( is_string( $action ) && null !== $checked->action( $action ), 'Unknown legacy discovery action.' );
					}
					$family['legacy']           = $checked;
					$family['legacy_discovery'] = $discovery;
				}
			} else {
				Rules::ensure( [] === $discovery, 'Discovery requires a legacy facet.' );
			}
		} catch ( \Throwable $error ) {
			Guard::log( 'Legacy facet failed: ' . $error->getMessage(), 'warning' );
			$family['legacy_reason'] = Inspector::unavailable();
		}
		$this->families[ $id ] = $family;
		return true;
	}
	/**
	 * Direct-tool registration preserves order and enforces cross-contract duplicates.
	 * @param LegacyProvider $provider Legacy provider.
	 */
	public function register_legacy( LegacyProvider $provider ): bool|\WP_Error {
		if ( ! $this->open ) {
			return Refusals::late();
		}
		try {
			$id = $provider->id();
			Rules::ensure( ! isset( $this->families[ $id ] ), 'Duplicate provider family.' );
			$checked = LegacyInspector::inspect( $provider, array_keys( $this->families ) );
			if ( $checked instanceof \WP_Error ) {
				$this->refusals[] = [
					'label'  => Text::plain( $provider->label(), 80 ),
					'reason' => $checked->get_error_message(),
				];
				return $checked;
			}
			$this->keep_legacy( $checked );
			return true;
		} catch ( \Throwable $error ) {
			return $this->refuse( $error );
		}
	}
	/**
	 * Keep an original registration without fabricating a v2 origin.
	 * @param Registered $registered Legacy registration.
	 */
	private function keep_legacy( Registered $registered ): void {
		$this->families[ $registered->id ] = [
			'id'               => $registered->id,
			'identity'         => null,
			'contract'         => 1,
			'label'            => $registered->label,
			'version'          => $registered->version,
			'provider'         => null,
			'legacy'           => $registered,
			'legacy_reason'    => '',
			'legacy_discovery' => array_keys( $registered->all_described() ),
			'typed'            => null,
		];
	}
	/** Close the complete collection. */
	public function close(): void {
		$this->open = false;
		$this->legacy->close();
	}
	/** Immutable registry at this point in collection. */
	public function registry(): Registry {
		return new Registry( $this->families );
	}
	/** Sanitized failures for the existing manager notice. */
	public function refusals(): array {
		return $this->refusals;
	}
	/**
	 * Log technical detail, retain a plain user diagnostic.
	 * @param \Throwable $error Diagnostic.
	 */
	private function refuse( \Throwable $error ): \WP_Error {
		Guard::log( 'Typed registration failed: ' . $error->getMessage(), 'warning' );
		$reason           = Inspector::unavailable();
		$this->refusals[] = [
			'label'  => Refusals::an_add_on(),
			'reason' => $reason,
		];
		return Refusals::incompatible( $reason );
	}
}
