<?php
/**
 * Who may do what (SPEC §13, §11). Reading: shop managers and administrators
 * (`manage_woocommerce`), or administrators alone (`manage_options`) under the
 * administrators-only setting, the result filterable through `bcm_capability`.
 * Writing products: that, plus `edit_products`, and each object checked with
 * `edit_post` (which honours `edit_others_products`). Category writes: the read
 * capability plus `manage_product_terms`. Settings writes: `manage_options`.
 * Every route's permission callback resolves through here; none is
 * `__return_true` (SPEC §12.1), and the registrar in Routes refuses one that is.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capability resolution and the permission callbacks.
 */
final class Access {
	/**
	 * The REST namespace every route is registered under.
	 */
	public const REST_NAMESPACE = 'bcm/v1';

	/**
	 * The capability product writes need on top of the read capability.
	 */
	public const WRITE_CAPABILITY = 'edit_products';

	/**
	 * The capability category writes need on top of the read capability.
	 */
	public const TERMS_CAPABILITY = 'manage_product_terms';

	/**
	 * The taxonomy the category write routes change.
	 */
	public const TERMS_TAXONOMY = 'product_cat';

	/**
	 * The capability settings writes need.
	 */
	public const SETTINGS_CAPABILITY = 'manage_options';

	/**
	 * The code for one object the user may not edit, skipped and reported.
	 */
	public const CANNOT_EDIT = 'bcm_cannot_edit';

	/**
	 * The capability the screen and read routes need.
	 *
	 * @param Settings|null $settings Settings; the stored ones when null.
	 */
	public static function capability( ?Settings $settings = null ): string {
		$settings = $settings ?? Settings::load();
		$default  = $settings->administrators_only() ? 'manage_options' : 'manage_woocommerce';
		/**
		 * Filters the capability needed to use Bulk Catalogue Manager and its read routes.
		 *
		 * @param string $capability          The capability: manage_woocommerce, or manage_options under the administrators-only setting.
		 * @param bool   $administrators_only Whether the administrators-only setting is on.
		 */
		$filtered = apply_filters( 'bcm_capability', $default, $settings->administrators_only() );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $default;
	}

	/**
	 * The capabilities a product write route needs, all of them: the read
	 * capability and `edit_products`. Each object is checked on its own as well.
	 *
	 * @param Settings|null $settings Settings; the stored ones when null.
	 * @return list<string>
	 */
	public static function write_capabilities( ?Settings $settings = null ): array {
		return array_values( array_unique( [ self::capability( $settings ), self::WRITE_CAPABILITY ] ) );
	}

	/**
	 * The capabilities a category write route needs: the read capability and `manage_product_terms`.
	 *
	 * @param Settings|null $settings Settings; the stored ones when null.
	 * @return list<string>
	 */
	public static function terms_capabilities( ?Settings $settings = null ): array {
		return array_values( array_unique( [ self::capability( $settings ), self::TERMS_CAPABILITY ] ) );
	}

	/**
	 * Permission callback for the read routes: true, or a `bcm_forbidden`
	 * refusal with a plain sentence and a 401 or 403 status.
	 *
	 * @param mixed $request The REST request; unused, accepted for the callback signature.
	 */
	public static function can_read( mixed $request = null ): bool|\WP_Error {
		unset( $request );
		if ( current_user_can( self::capability() ) ) {
			return true;
		}
		return Refusal::forbidden( __( 'Your account does not have permission to use Bulk Catalogue Manager. Ask an administrator for access.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}

	/**
	 * Permission callback for the product write routes: the read capability
	 * and `edit_products`. Objects are checked one by one with can_edit_object().
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public static function can_write( mixed $request = null ): bool|\WP_Error {
		$read = self::can_read( $request );
		if ( true !== $read ) {
			return $read;
		}
		if ( current_user_can( self::WRITE_CAPABILITY ) ) {
			return true;
		}
		return Refusal::forbidden( __( 'Your account can see products here but not change them. Ask an administrator for permission to edit products.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}

	/**
	 * Permission callback for the category write routes: the read capability
	 * and `manage_product_terms`, then the per-term capabilities core checks:
	 * with an `id` (an edit or a move) `edit_term` on that category, when it
	 * exists (an unknown id is left to the handler's 404); without one (a
	 * create) the taxonomy's `edit_terms` capability.
	 *
	 * @param mixed $request The REST request.
	 */
	public static function can_manage_terms( mixed $request = null ): bool|\WP_Error {
		$read = self::can_read( $request );
		if ( true !== $read ) {
			return $read;
		}
		$refusal = __( 'Your account can see categories here but not change them. Ask an administrator for permission to manage categories.', 'bulk-catalogue-manager-for-woocommerce' );
		if ( ! current_user_can( self::TERMS_CAPABILITY ) ) {
			return Refusal::forbidden( $refusal );
		}
		$id = $request instanceof \WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id > 0 ) {
			$allowed = ! is_object( get_term( $id, self::TERMS_TAXONOMY ) ) || current_user_can( 'edit_term', $id );
		} else {
			$taxonomy = get_taxonomy( self::TERMS_TAXONOMY );
			$allowed  = is_object( $taxonomy ) && isset( $taxonomy->cap->edit_terms ) && current_user_can( (string) $taxonomy->cap->edit_terms );
		}
		return $allowed ? true : Refusal::forbidden( $refusal );
	}

	/**
	 * Permission callback for the settings write route: `manage_options`, whatever the read capability.
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public static function can_manage_settings( mixed $request = null ): bool|\WP_Error {
		unset( $request );
		if ( current_user_can( self::SETTINGS_CAPABILITY ) ) {
			return true;
		}
		return Refusal::forbidden( __( 'Only an administrator can change the Bulk Catalogue Manager settings. Ask an administrator to change them.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}

	/**
	 * Whether the current user may edit one product or variation: `edit_post`
	 * on the object, which WordPress maps to `edit_products`,
	 * `edit_others_products` or `edit_published_products` as it applies, and
	 * for a variation `edit_post` on its parent as well, since saving a
	 * variation resynchronises and saves the parent. Otherwise a
	 * `bcm_cannot_edit` refusal the writer records as a skipped row.
	 *
	 * @param int $object_id Product or variation id.
	 * @param int $parent_id A variation's parent product, or 0.
	 */
	public static function can_edit_object( int $object_id, int $parent_id = 0 ): bool|\WP_Error {
		$allowed = current_user_can( 'edit_post', $object_id ) && ( $parent_id < 1 || current_user_can( 'edit_post', $parent_id ) );
		return $allowed ? true : self::cannot_edit();
	}

	/**
	 * The same check for a background chunk, which runs with no current user,
	 * against the user who queued the batch (SPEC §13).
	 *
	 * @param int $user_id   The batch's recorded user id.
	 * @param int $object_id Product or variation id.
	 * @param int $parent_id A variation's parent product, or 0.
	 */
	public static function user_can_edit_object( int $user_id, int $object_id, int $parent_id = 0 ): bool|\WP_Error {
		$allowed = $user_id > 0 && user_can( $user_id, 'edit_post', $object_id ) && ( $parent_id < 1 || user_can( $user_id, 'edit_post', $parent_id ) );
		return $allowed ? true : self::cannot_edit();
	}

	/**
	 * Whether a user may assign terms of a taxonomy to products: the
	 * taxonomy's `assign_terms` capability (`assign_product_terms` for
	 * WooCommerce's), which core checks besides `edit_post`. An unknown
	 * taxonomy is refused. Otherwise a `bcm_cannot_edit` refusal naming the field.
	 *
	 * @param int|null $user_id  The batch's recorded user, or null for the current user.
	 * @param string   $taxonomy The taxonomy.
	 * @param string   $label    The field's label, such as "Categories".
	 */
	public static function can_assign_terms( ?int $user_id, string $taxonomy, string $label ): bool|\WP_Error {
		$object = get_taxonomy( $taxonomy );
		$cap    = is_object( $object ) && isset( $object->cap->assign_terms ) ? (string) $object->cap->assign_terms : '';
		if ( '' !== $cap && ( null === $user_id ? current_user_can( $cap ) : $user_id > 0 && user_can( $user_id, $cap ) ) ) {
			return true;
		}
		return Refusal::create(
			self::CANNOT_EDIT,
			sprintf(
				/* translators: %s: a field's name, such as "Categories". */
				__( 'You do not have permission to change %s on products, so this was skipped. Ask an administrator for access.', 'bulk-catalogue-manager-for-woocommerce' ),
				$label
			),
			403
		);
	}

	/**
	 * The refusal for one object the user may not edit.
	 */
	private static function cannot_edit(): \WP_Error {
		return Refusal::create(
			self::CANNOT_EDIT,
			__( 'You do not have permission to edit this product, so it was skipped. Ask an administrator for access to it.', 'bulk-catalogue-manager-for-woocommerce' ),
			403
		);
	}
}
