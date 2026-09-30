<?php
/**
 * Provider strings as plain text (docs/EXTENSIONS.md §3.4, §14): tags and
 * control characters stripped, white space collapsed, and anything longer
 * than its limit cut with an ellipsis. Every label, description, reason,
 * note, warning and described value a provider supplies passes through here
 * before the core stores or sends it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text limits and cleaning.
 */
final class Text {
	/**
	 * A label: a provider's, a field's, an action's, a group's or a choice's.
	 */
	public const LABEL = 80;

	/**
	 * An action's description, or a confirmation reason.
	 */
	public const DESCRIPTION = 300;

	/**
	 * A skip or failure reason: the journal's `message` column.
	 */
	public const REASON = 255;

	/**
	 * One preview note.
	 */
	public const NOTE = 200;

	/**
	 * Most notes under one preview row.
	 */
	public const NOTES = 3;

	/**
	 * A described value.
	 */
	public const VALUE = 1000;

	/**
	 * Plain text cut to a limit: tags removed, control characters and runs
	 * of white space folded to single spaces, trimmed, and a string longer
	 * than the limit cut to it with an ellipsis as its last character.
	 *
	 * @param mixed $text  What the provider gave.
	 * @param int   $limit Most characters kept.
	 */
	public static function plain( mixed $text, int $limit ): string {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = (string) $text;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- The fallback outside WordPress only.
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $text ) );
		if ( $limit < 1 ) {
			return '';
		}
		return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…' : $text;
	}
}
