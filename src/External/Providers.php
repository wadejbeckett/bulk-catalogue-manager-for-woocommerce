<?php
/**
 * The providers of one request (docs/EXTENSIONS.md §3.1): fired once, the
 * first time the shared action registry is built on or after `init`
 * (Operations\Registry::instance()), and fixed for the rest of the request.
 * With no provider the core does one do_action() without callbacks and
 * nothing else changes.
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

	/**
	 * Keep what registration produced.
	 *
	 * @param array<string, Registered>                  $registered Accepted providers by id, in registration order.
	 * @param list<array{label: string, reason: string}> $refusals   Refused providers.
	 */
	private function __construct( private readonly array $registered, private readonly array $refusals ) {}

	/**
	 * No providers.
	 */
	public static function none(): self {
		return new self( [], [] );
	}

	/**
	 * Fire `bulkcat_register_providers` once and keep what registered. An
	 * add-on callback that throws is caught here: the error goes to the
	 * WooCommerce log, the screen shows one plain refusal, and whatever
	 * registered before the failure is kept. It never reaches the caller,
	 * so a faulty add-on cannot take down a request.
	 */
	public static function fire(): self {
		$collector = new Collector();
		try {
			/**
			 * Register external action providers (docs/EXTENSIONS.md §3.1).
			 *
			 * @param Registrar $registrar Call register() with each provider.
			 */
			do_action( 'bulkcat_register_providers', new Registrar( $collector ) );
		} catch ( \Throwable $error ) {
			$collector->failed( $error );
		} finally {
			$collector->close();
		}
		return new self( $collector->registered(), $collector->refusals() );
	}

	/**
	 * Register the given providers directly, in order, outside the action
	 * (tests and tools): the same checks, the same refusals.
	 *
	 * @param list<Provider>    $providers The providers.
	 * @param list<string>|null $features  Contract version 2 features to accept; Contract::FEATURES when null.
	 */
	public static function of( array $providers, ?array $features = null ): self {
		$collector = new Collector( $features );
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
		return [] !== $this->registered;
	}

	/**
	 * Every registered provider, in registration order.
	 *
	 * @return list<Registered>
	 */
	public function all(): array {
		return array_values( $this->registered );
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
		foreach ( $providers->all() as $provider ) {
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
