<?php
/**
 * The providers of one request (docs/EXTENSIONS.md §3.1): fired once, the
 * first time the shared action registry is built on or after `init`
 * (Operations\Registry::instance()), and fixed for the rest of the request.
 * With no providers the two registration hooks have no callbacks; native
 * descriptors and request execution are unchanged.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Provider;
use BulkCatalogueManager\Extension\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * The registered providers.
 */
final class Providers {
	/**
	 * A field key, action id or group id's provider: `x_<provider id>_…`.
	 */
	private const OWNER = '/^x_([a-z][a-z0-9]{2,19})_[a-z0-9]/';

	/** Discovery snapshots retain their per-request availability cache. */
	private ?array $discovery = null;

	/**
	 * Keep what registration produced.
	 *
	 * @param array<string, Registered>                  $registered Accepted providers by id, in registration order.
	 * @param list<array{label: string, reason: string}> $refusals   Refused providers.
	 * @param V2\Registry|null $typed Provider-family registry.
	 */
	private function __construct( private readonly array $registered, private readonly array $refusals, private readonly ?V2\Registry $typed = null ) {}

	/**
	 * No providers.
	 */
	public static function none(): self {
		return new self( [], [] );
	}

	/**
	 * Fire `bulkcat_register_providers` once and keep what registered.
	 */
	public static function fire(): self {
		$collector = new Collector();
		/**
		 * Register external action providers (docs/EXTENSIONS.md §3.1).
		 *
		 * @param Registrar $registrar Call register() with each provider.
		 */
		do_action( 'bulkcat_register_providers', new Registrar( $collector ) );
		$collector->close();
		$typed = new V2\Collector( $collector );
		/**
		 * Register opt-in typed provider families after the literal v1 hook.
		 *
		 * @param \BulkCatalogueManager\Extension\V2\Registrar $registrar Typed registrar.
		 */
		do_action( 'bulkcat_register_typed_providers', new \BulkCatalogueManager\Extension\V2\Registrar( $typed ) );
		$typed->close();
		$registry = $typed->registry();
		return new self( $registry->legacy(), $typed->refusals(), $registry );
	}

	/**
	 * Register the given providers directly, in order, outside the action
	 * (tests and tools): the same checks, the same refusals.
	 *
	 * @param list<Provider> $providers The providers.
	 */
	public static function of( array $providers ): self {
		$collector = new Collector();
		$registrar = new Registrar( $collector );
		foreach ( $providers as $provider ) {
			$registrar->register( $provider );
		}
		$collector->close();
		return new self( $collector->registered(), $collector->refusals() );
	}

	/**
	 * Whether any provider is registered.
	 */
	public function any(): bool {
		return [] !== $this->registered || ( null !== $this->typed && [] !== $this->typed->families() );
	}

	/**
	 * Legacy discovery facets, in registration order; hidden actions retain explicit resolution.
	 *
	 * @return list<Registered>
	 */
	public function all(): array {
		if ( null === $this->typed ) {
			return $this->resolvable();
		}
		if ( null !== $this->discovery ) {
			return $this->discovery;
		}
		$visible = [];
		foreach ( $this->registered as $id => $provider ) {
			$ids = $this->typed->family( $id )['legacy_discovery'];
			if ( [] === $ids ) {
				continue;
			}
			if ( array_keys( $provider->all_described() ) === $ids ) {
				$visible[] = $provider;
				continue;
			}
			$described = array_intersect_key( $provider->all_described(), array_flip( $ids ) );
			$fields    = array_flip( array_column( $described, 'field' ) );
			$groups    = array_column( $described, 'group' );
			$visible[] = new Registered( $provider->provider, $provider->id, $provider->label, $provider->version, $provider->contract, array_intersect_key( $provider->fields(), $fields ), array_intersect_key( $provider->fields_meta(), $fields ), array_intersect_key( $provider->actions(), $described ), $described, array_values( array_filter( $provider->groups(), static fn( array $group ): bool => in_array( $group['id'], $groups, true ) ) ) );
		}
		$this->discovery = $visible;
		return $this->discovery;
	}

	/** Every valid legacy facet for explicit requests and stored work, including hidden IDs. */
	public function resolvable(): array {
		return array_values( $this->registered );
	}

	/** Typed family declarations; no typed runtime dispatch is enabled by S1. */
	public function typed(): V2\Registry {
		return $this->typed ?? new V2\Registry();
	}

	/**
	 * A registered provider, or null.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?Registered {
		return $this->registered[ $id ] ?? null;
	}

	/**
	 * Refused providers, for the screen's notice.
	 *
	 * @return list<array{label: string, reason: string}>
	 */
	public function refusals(): array {
		return $this->refusals;
	}

	/**
	 * Every registered provider's field keys, provider by provider (T108: the audit log's field filter).
	 *
	 * @param Providers $providers The providers.
	 * @return list<string>
	 */
	public static function field_keys( self $providers ): array {
		$keys = [];
		foreach ( $providers->resolvable() as $provider ) {
			$keys = [ ...$keys, ...array_keys( $provider->fields() ) ];
		}
		return $keys;
	}

	/**
	 * Whether an id is external: a field key, action id or group id starting `x_`.
	 *
	 * @param string $identifier The id.
	 */
	public static function is_external( string $identifier ): bool {
		return str_starts_with( $identifier, 'x_' );
	}

	/**
	 * The provider id an external identifier names, or null.
	 *
	 * @param string $identifier A field key, action id or group id.
	 */
	public static function owner( string $identifier ): ?string {
		return 1 === preg_match( self::OWNER, $identifier, $match ) ? $match[1] : null;
	}

	/**
	 * The registered provider owning an external identifier, or null.
	 *
	 * @param string $identifier A field key, action id or group id.
	 */
	public function owning( string $identifier ): ?Registered {
		$owner = self::owner( $identifier );
		return null === $owner ? null : $this->get( $owner );
	}
}
