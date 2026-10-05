<?php
/**
 * Saves through WordPress's own category form, recorded in History (1.3.4).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's own product category edit form (term.php, opened normally or
 * as All fields in the Categories tab) saves through WordPress, WooCommerce
 * and any plugin that adds fields to it, not through this plugin. So that
 * History still shows it, the category is read just before WordPress
 * updates it (`edit_terms`) and again after everything hooked to the save
 * has run (`edited_product_cat`, last), and the difference is recorded as
 * one category change by the user who saved the form: name, slug,
 * description, parent, display type, image and WooCommerce's order as their
 * usual journal properties (which undo can put back), and every other term
 * meta key that changed as one "Other fields" row. Those keys belong to
 * other plugins: their values are kept in the journal for the record, but
 * History never shows a key, only how many fields changed, and undo leaves
 * them alone.
 *
 * Only the form's own POST is recorded (`action=editedtag` on the admin
 * side, not Ajax), for the category it names: this plugin's own writes are
 * journalled where they are made, and quick edit and background work are
 * not this form.
 */
final class NativeForm {
	/**
	 * Journal property of other plugins' fields: before and after, by key.
	 */
	public const OTHER_PROP = 'term_other_fields';

	/**
	 * The step argument naming where the change was made.
	 */
	public const FORM = 'native';

	/**
	 * Term meta keys recorded under their own journal properties.
	 */
	private const OWN_META = [ 'display_type', 'thumbnail_id', CategoryTree::ORDER_META ];

	/**
	 * The category read before the save, by term id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $before = [];

	/**
	 * `edit_terms`: before WordPress updates a category from its form, read it.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function before( int $term_id, string $taxonomy = '' ): void {
		if ( Counts::TAXONOMY !== $taxonomy || ! self::is_form_save( $term_id ) ) {
			return;
		}
		self::$before[ $term_id ] = self::read( $term_id );
	}

	/**
	 * `edited_product_cat`, last: record what the save changed.
	 *
	 * @param int $term_id Term id.
	 */
	public static function after( int $term_id ): void {
		if ( ! isset( self::$before[ $term_id ] ) ) {
			return;
		}
		$before = self::$before[ $term_id ];
		unset( self::$before[ $term_id ] );
		( new Writer() )->record_native( $term_id, $before, self::read( $term_id ), get_current_user_id() );
		HistoryText::reset();
	}

	/**
	 * Whether this request is WordPress's category edit form saving this category.
	 *
	 * @param int $term_id Term id.
	 */
	private static function is_form_save( int $term_id ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read only to recognise the form; WordPress has checked its nonce (check_admin_referer( 'update-tag_' . $tag_ID )) before it updates the category.
		$action   = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_POST['taxonomy'] ) ) : '';
		$tag_id   = isset( $_POST['tag_ID'] ) ? (int) $_POST['tag_ID'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return is_admin() && ! wp_doing_ajax() && 'editedtag' === $action && Counts::TAXONOMY === $taxonomy && $tag_id === $term_id;
	}

	/**
	 * A category's values by journal property, read unfiltered: TermUndo's
	 * own properties and the other plugins' term meta.
	 *
	 * @param int $term_id Term id.
	 * @return array<string, mixed>
	 */
	private static function read( int $term_id ): array {
		$values = array_intersect_key( TermUndo::current( $term_id ), array_flip( [ 'term_name', 'term_slug', 'term_description', 'term_parent', 'term_display_type', 'term_thumbnail_id', Writer::ORDER_PROP ] ) );
		$meta   = get_term_meta( $term_id );
		$other  = [];
		foreach ( is_array( $meta ) ? $meta : [] as $key => $list ) {
			if ( ! in_array( (string) $key, self::OWN_META, true ) ) {
				$other[ (string) $key ] = array_values( array_map( 'strval', (array) $list ) );
			}
		}
		ksort( $other );
		$values[ self::OTHER_PROP ] = $other;
		return $values;
	}
}
