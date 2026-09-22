<?php
/**
 * The outcome of validating submitted settings: the accepted values in their
 * stored types, and a rejection message for each refused key, keyed by that key.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable validation result.
 */
final class Validation {
	/**
	 * Accepted values, keyed by setting.
	 *
	 * @var array<string, bool|int|string>
	 */
	private array $values;

	/**
	 * Rejection messages, keyed by the refused setting (or the option name
	 * when the whole submission was refused).
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * Build a result.
	 *
	 * @param array<string, bool|int|string> $values Accepted values.
	 * @param array<string, string>          $errors Rejections.
	 */
	public function __construct( array $values, array $errors ) {
		$this->values = $values;
		$this->errors = $errors;
	}

	/**
	 * Accepted values, keyed by setting.
	 *
	 * @return array<string, bool|int|string>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Rejection messages keyed by the setting each names.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Whether anything was refused.
	 */
	public function has_errors(): bool {
		return [] !== $this->errors;
	}
}
