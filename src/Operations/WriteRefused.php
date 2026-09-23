<?php
/**
 * Thrown by Operation::apply() when the field refuses the computed value at
 * the moment of writing — for example a SKU another product took after the
 * preview. The writer records the object as failed with the refusal's
 * sentence and carries on with the rest of the batch (SPEC §12.6).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * A write the field refused.
 */
final class WriteRefused extends \RuntimeException {
	/**
	 * Wrap the field's refusal.
	 *
	 * @param \WP_Error $error The refusal from Field::write().
	 */
	public function __construct( private readonly \WP_Error $error ) {
		parent::__construct( esc_html( $error->get_error_message() ) );
	}

	/**
	 * The field's refusal: its `bcm_` code and plain sentence.
	 */
	public function error(): \WP_Error {
		return $this->error;
	}
}
