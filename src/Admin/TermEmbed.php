<?php
/**
 * All fields in the Categories tab (SPEC §4.7): WordPress's own product
 * category edit screen (term.php) shown inside the details pane, so every
 * field another plugin adds to that form appears and saves through WordPress
 * itself. The plugin names no other plugin: it only hides the admin's menu,
 * bar, footer and notices from that one page, and only when its flag is in
 * the URL and the current user may edit that category.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The embedded category edit form.
 */
final class TermEmbed {

	/** The URL flag the details pane adds to the form's address. */
	public const FLAG = 'bulkcat_embed';

	/** The stylesheet handle (no file: one inline block). */
	public const HANDLE = 'bulkcat-term-embed';

	/** The only taxonomy embedded. */
	private const TAXONOMY = 'product_cat';

	/**
	 * The category being edited when this request is the embedded form, else 0.
	 */
	public static function embedded_term(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A display flag on a GET page: it only hides the admin's chrome, and the form keeps WordPress's own nonce and capability checks.
		if ( ! isset( $_GET[ self::FLAG ], $_GET['taxonomy'], $_GET['tag_ID'] ) ) {
			return 0;
		}
		$taxonomy = sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) );
		$raw      = sanitize_text_field( wp_unslash( (string) $_GET['tag_ID'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::TAXONOMY !== $taxonomy || ! ctype_digit( $raw ) ) {
			return 0;
		}
		$id = (int) $raw;
		return $id > 0 && current_user_can( 'edit_term', $id ) ? $id : 0;
	}

	/**
	 * `admin_enqueue_scripts`: on the embedded form only, the stylesheet that
	 * hides the admin's chrome.
	 *
	 * @param string $hook_suffix The admin page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'term.php' !== $hook_suffix || 0 === self::embedded_term() ) {
			return;
		}
		wp_register_style( self::HANDLE, false, [], null, 'all' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- No file: one inline block.
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() );
	}

	/**
	 * `product_cat_pre_edit_form`: in the embedded form only, a warning
	 * before the first save of a category through WordPress's own form
	 * (1.3.4). That form saves every field other plugins add to it exactly
	 * as shown, and a field a plugin has never stored for this category (a
	 * category made elsewhere, such as in this tab) is shown empty or
	 * unticked: pressing Update stores it so. WooCommerce stores the display
	 * type with every save through its own forms, so a category without one
	 * has never been saved through them. The warning is no `.notice`, which
	 * the embedded page hides.
	 *
	 * @param object $tag      The category.
	 * @param string $taxonomy Its taxonomy.
	 */
	public static function warn( object $tag, string $taxonomy = '' ): void {
		$id = (int) ( $tag->term_id ?? 0 );
		if ( self::TAXONOMY !== $taxonomy || 0 === $id || self::embedded_term() !== $id ) {
			return;
		}
		$meta = get_term_meta( $id );
		if ( is_array( $meta ) && array_key_exists( 'display_type', $meta ) ) {
			return;
		}
		printf(
			'<div class="bulkcat-embed-warning" role="note"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'This category has not been saved with this form before.', 'bulk-catalogue-manager-for-woocommerce' ),
			esc_html__( 'Fields other plugins add below start empty or unticked for it, and Update saves them exactly as shown. A visibility box left unticked can hide the category from customers. Check those fields before you press Update.', 'bulk-catalogue-manager-for-woocommerce' )
		);
	}

	/**
	 * `admin_body_class`: mark the embedded page.
	 *
	 * @param string $classes The admin body classes.
	 */
	public static function body_class( string $classes ): string {
		if ( 'term.php' !== ( $GLOBALS['pagenow'] ?? '' ) || 0 === self::embedded_term() ) {
			return $classes;
		}
		return trim( $classes . ' bulkcat-embedded' );
	}

	/**
	 * The address of the category edit form, without a category or the flag;
	 * the details pane adds both.
	 */
	public static function edit_url(): string {
		return admin_url( 'term.php?taxonomy=' . self::TAXONOMY . '&post_type=product' );
	}

	/**
	 * The stylesheet: the page as the form alone. WordPress's own notice
	 * ("Category updated.") stays; the Delete link goes, because deleting a
	 * category belongs to the workspace, which records it.
	 */
	private static function css(): string {
		return implode(
			"\n",
			[
				'html.wp-toolbar { padding-top: 0 !important; }',
				'html, body.bulkcat-embedded { background: #fff; min-width: 0 !important; }',
				'#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter, #screen-meta, #screen-meta-links,',
				'.notice:not(#message), .update-nag, .woocommerce-layout__header, #woocommerce-embedded-root,',
				'.wrap > h1, .wrap > .page-title-action, #delete-link { display: none !important; }',
				'#wpcontent, #wpfooter { margin-left: 0 !important; }',
				'#wpcontent { padding: 0 16px !important; }',
				'#wpbody { padding-top: 0 !important; margin-top: 0 !important; }',
				'#wpbody-content { padding-bottom: 16px !important; float: none; }',
				'.wrap { margin: 0 !important; }',
				'#edittag { max-width: none; }',
				'.bulkcat-embed-warning { margin: 12px 0; padding: 8px 12px; border-left: 4px solid #dba617; background: #fcf9e8; color: #1d2327; }',
				'.bulkcat-embed-warning p { margin: 0; }',
			]
		);
	}
}
