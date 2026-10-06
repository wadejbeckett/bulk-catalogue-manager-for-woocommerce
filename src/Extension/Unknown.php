<?php
/**
 * A value that cannot be read (docs/EXTENSIONS.md §4, §7.3): ExternalField::
 * read() returns one instead of a value, never an empty value standing for
 * "unknown". The core fails every step on that field with its reason, lists
 * the object under "cannot change" in the preview and skips it in an undo.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * An unreadable value.
 */
final class Unknown {
	/**
	 * Build through because().
	 *
	 * @param string $reason Plain sentence.
	 * @param string $code   `bulkcat_` or `x_<provider id>_` code.
	 */
	private function __construct( private readonly string $reason, private readonly string $code ) {}

	/**
	 * Why the value cannot be read. A code that does not start with
	 * `bulkcat_` or `x_` is replaced by `bulkcat_unknown_state`.
	 *
	 * @param string $reason Plain sentence shown to people.
	 * @param string $code   `bulkcat_` or `x_<provider id>_` code for the details line.
	 */
	public static function because( string $reason, string $code = 'bulkcat_unknown_state' ): self {
		$valid = 1 === preg_match( '/^(bulkcat|x)_[a-z0-9_]{1,80}$/', $code );
		return new self( $reason, $valid ? $code : 'bulkcat_unknown_state' );
	}

	/**
	 * The plain reason.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The code.
	 */
	public function code(): string {
		return $this->code;
	}
}
