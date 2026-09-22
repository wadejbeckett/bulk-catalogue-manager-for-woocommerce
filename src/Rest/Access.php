<?php
/**
 * Who may use the screen and its read routes (SPEC §13, §11): shop managers
 * and administrators (`manage_woocommerce`), or administrators alone
 * (`manage_options`) under the administrators-only setting, the result
 * filterable through `bcm_capability`. Every route's permission callback
 * resolves through here; none is `__return_true` (SPEC §12.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capability resolution and the read permission callback.
 */
final class Access {
	/**
	 * The REST namespace every route is registered under.
	 */
	public const REST_NAMESPACE = 'bcm/v1';

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
		$message = is_user_logged_in()
			? __( 'Your account does not have permission to use Bulk Catalogue Manager. Ask an administrator for access.', 'bulk-catalogue-manager-for-woocommerce' )
			: __( 'You need to log in to use Bulk Catalogue Manager. Log in and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		return new \WP_Error( 'bcm_forbidden', $message, [ 'status' => rest_authorization_required_code() ] );
	}
}
