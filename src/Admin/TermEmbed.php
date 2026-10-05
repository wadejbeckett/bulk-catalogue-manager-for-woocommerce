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
			]
		);
	}
}
