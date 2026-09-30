<?php
/**
 * What the registrar hands registrations to (docs/EXTENSIONS.md §3): it
 * accepts providers while the `bulkcat_register_providers` action runs and
 * refuses any after it closes. Each provider is checked (Inspector); a
 * refused one contributes nothing, its reason goes to the WooCommerce log
 * and is kept for the one notice the manager screen shows.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Collects registrations.
 */
final class Collector {
	/**
	 * Whether registrations are still accepted.
	 *
	 * @var bool
	 */
	private bool $open = true;

	/**
	 * Accepted providers by id, in registration order.
	 *
	 * @var array<string, Registered>
	 */
	private array $registered = [];

	/**
	 * Refused providers: their label (or "An add-on") and the plain reason.
	 *
	 * @var list<array{label: string, reason: string}>
	 */
	private array $refusals = [];

	/**
	 * Accept or refuse a provider.
	 *
	 * @param Provider $provider The provider.
	 * @return bool|\WP_Error True, or the refusal.
	 */
	public function accept( Provider $provider ): bool|\WP_Error {
		if ( ! $this->open ) {
			$refusal = Refusals::late();
			Guard::log( 'An add-on registered after bulkcat_register_providers finished and was not added.', 'warning' );
			return $refusal;
		}
		$checked = Inspector::inspect( $provider, array_keys( $this->registered ) );
		if ( $checked instanceof Registered ) {
			$this->registered[ $checked->id ] = $checked;
			return true;
		}
		$label  = self::label( $provider );
		$reason = sprintf(
			/* translators: 1: the name of an add-on, such as "Example add-on", or "An add-on"; 2: what is wrong with it. */
			__( '%1$s could not be added to Bulk Catalogue Manager: %2$s Update the add-on, or ask its author. Everything else works as usual.', 'bulk-catalogue-manager-for-woocommerce' ),
			$label,
			$checked->get_error_message()
		);
		$this->refusals[] = [
			'label'  => $label,
			'reason' => $reason,
		];
		Guard::log( $reason, 'warning' );
		return Refusals::incompatible( $reason );
	}

	/**
	 * Stop accepting registrations.
	 */
	public function close(): void {
		$this->open = false;
	}

	/**
	 * Accepted providers by id, in registration order.
	 *
	 * @return array<string, Registered>
	 */
	public function registered(): array {
		return $this->registered;
	}

	/**
	 * Refused providers.
	 *
	 * @return list<array{label: string, reason: string}>
	 */
	public function refusals(): array {
		return $this->refusals;
	}

	/**
	 * A provider's label for a notice, or "An add-on" when it cannot be read.
	 *
	 * @param Provider $provider The provider.
	 */
	private static function label( Provider $provider ): string {
		try {
			$label = Text::plain( Guard::run( '', 'label', static fn(): mixed => $provider->label() ), Text::LABEL );
		} catch ( ProviderFailure $e ) {
			$label = '';
		}
		return '' === $label ? Refusals::an_add_on() : $label;
	}
}
