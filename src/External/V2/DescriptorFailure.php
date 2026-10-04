<?php
/**
 * Internal diagnostic preserving action identity when descriptor validation fails.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

defined( 'ABSPATH' ) || exit;
/** Never expose the underlying exception text to a browser. */
final class DescriptorFailure extends \InvalidArgumentException {
	/**
	 * Retain already validated identity and label for disabled discovery.
	 * @param string $id Immutable action ID.
	 * @param string $label Display label.
	 * @param \Throwable $previous Validation diagnostic.
	 */
	public function __construct( public readonly string $id, public readonly string $label, \Throwable $previous ) {
		parent::__construct( 'Invalid typed action descriptor.', 0, $previous );
	}
}
