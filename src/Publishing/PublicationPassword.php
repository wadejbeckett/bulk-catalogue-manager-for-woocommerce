<?php
/**
 * Native publication lifecycle.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Publishing;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Operations\Result;

defined( 'ABSPATH' ) || exit;

/** Protected journal preimages, never a public password editor. */
final class PublicationPassword {
	public const FIELD = 'publication_password';
	/** Internal opaque string with the real WooCommerce API. */
	public static function field(): Field {
		return new Field(
			key: self::FIELD,
			label: __( 'Publication password', 'bulk-catalogue-manager-for-woocommerce' ),
			type: Field::TEXT,
			storage: Storage::post( 'post_password' ),
			edit_kinds: [ Field::SET ],
			variation_rule: Field::PER_VARIATION,
			getter: 'get_post_password',
			setter: 'set_post_password',
			nullable: true,
			adapter: Field::ADAPTER_PUBLICATION_PASSWORD,
			internal: true
		);
	}
	/** Refuse native variation password loss or restoration unsupported by its datastore.
	 * @param object $product Loaded object.
	 * @param string $requested Status this save requests.
	 * @param bool $restoring Whether an internal password write is requested.
	 */
	public static function variation_refusal( object $product, string $requested, bool $restoring = false ): ?\WP_Error {
		if ( ! $product->is_type( 'variation' ) || ( ! $restoring && ( 'private' !== $requested || '' === self::field()->read( $product ) ) ) ) {
			return null;
		}
		return \BulkCatalogueManager\Rest\Refusal::create(
			'bulkcat_variation_password_unsupported',
			__( 'WooCommerce cannot safely restore publication passwords for variations. This change was left alone to protect the stored password.', 'bulk-catalogue-manager-for-woocommerce' ),
			400
		);
	}
	/** Safe public rendering.
	 * @param mixed $value Protected value.
	 */
	public static function display( mixed $value ): string {
		return '' === $value || null === $value ? '' : __( 'Password protected', 'bulk-catalogue-manager-for-woocommerce' );
	}
	/** Password clear coupled to a permitted Private transition, including legacy work.
	 * @param object $product Loaded product before setters.
	 * @param Result $status Checked status result.
	 * @return list<Result>
	 */
	public static function clearing( object $product, Result $status ): array {
		$before = self::field()->read( $product );
		return $status->is_changed() && 'private' === $status->new() && '' !== $before ? [ Result::changed( self::FIELD, $before, '' ) ] : [];
	}
	/** Read the native stored value after save, like status readback.
	 * @param object $product Saved product.
	 */
	public static function read_back( object $product ): void {
		$stored = (string) get_post_field( 'post_password', (int) $product->get_id(), 'raw' );
		if ( self::field()->read( $product ) !== $stored ) {
			$product->set_post_password( $stored );
		}
	}
	/** Remove protected internal Undo arguments at public operation boundaries.
	 * @param array $operations Stored operations.
	 * @return array
	 */
	public static function operations( array $operations ): array {
		foreach ( $operations as &$step ) {
			if ( 'restore_status' === ( $step['action'] ?? '' ) ) {
				unset( $step['args']['password'], $step['args']['expected_password'] );
			}
		}
		unset( $step );
		return $operations;
	}
}
