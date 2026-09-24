<?php
/**
 * The outcome of Operation::validate() (SPEC §6): either the arguments in
 * normalised form — defaults filled in, values in canonical form, arguments
 * the chosen mode does not use left out — or the refusal saying what to fix.
 * Compute and the batch's operations JSON take the normalised arguments, so
 * two lists that mean the same thing store and hash the same.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable validation outcome.
 */
final class Validation {
	/**
	 * Build through passed() or refused().
	 *
	 * @param array<string, mixed> $args  Normalised arguments; empty when refused.
	 * @param \WP_Error|null       $error The refusal, or null when valid.
	 */
	private function __construct( private readonly array $args, private readonly ?\WP_Error $error ) {}

	/**
	 * Valid, with the normalised arguments.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 */
	public static function passed( array $args ): self {
		return new self( $args, null );
	}

	/**
	 * Refused.
	 *
	 * @param \WP_Error $error A Rest\Refusal: `bulkcat_` code, §4.8 sentence, status 400.
	 */
	public static function refused( \WP_Error $error ): self {
		return new self( [], $error );
	}

	/**
	 * Whether the arguments are valid.
	 */
	public function ok(): bool {
		return null === $this->error;
	}

	/**
	 * The normalised arguments.
	 *
	 * @return array<string, mixed>
	 * @throws \LogicException When the arguments were refused.
	 */
	public function args(): array {
		if ( null !== $this->error ) {
			throw new \LogicException( 'Refused arguments have no normalised form.' );
		}
		return $this->args;
	}

	/**
	 * The refusal, or null when valid.
	 */
	public function error(): ?\WP_Error {
		return $this->error;
	}
}
