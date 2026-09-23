<?php
/**
 * Find and replace (SPEC §6, §13): swap plain text in a text field such as
 * the name or the SKU. Plain text only — no pattern — optionally whole word
 * and optionally case sensitive.
 *
 * The text to find goes through preg_quote(), so no input ever becomes a
 * pattern; matching is UTF-8 throughout, so accented letters match their
 * capitals and no character is ever split. "Whole word" means the match is
 * not joined to a letter, mark, digit or underscore on either side. The
 * replacement is inserted literally ($1 and backslashes mean nothing). No
 * match is state already. The new text then takes the same path as setting
 * a value (BaseOperation::value_outcome()), so a name passes the post HTML filter for a
 * user without unfiltered_html and the field's length rule; only the name
 * (post_title) is written, never the slug (SPEC §5.4).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The find and replace action.
 */
final class FindReplace extends BaseOperation {
	/**
	 * Longest text to find or to put in its place.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * The action id.
	 */
	public function id(): string {
		return 'find_replace';
	}

	/**
	 * The primary group.
	 */
	protected function group(): string {
		return Group::ORGANISATION;
	}

	/**
	 * The plain label.
	 */
	protected function label(): string {
		return __( 'Find and replace', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Swaps a word or phrase for another in product names or SKUs. Plain text only, optionally matching whole words or exact capitals.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::FIND_REPLACE ];
	}

	/**
	 * The text to find, its replacement and the two matching choices.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'find'           => [
				'type'       => 'text',
				'label'      => __( 'Find', 'bulk-catalogue-manager-for-woocommerce' ),
				/* translators: %d: the most characters it takes. */
				'rule'       => sprintf( __( 'Enter the text to find, up to %d characters.', 'bulk-catalogue-manager-for-woocommerce' ), self::MAX_LENGTH ),
				'required'   => true,
				'max_length' => self::MAX_LENGTH,
			],
			'replace'        => [
				'type'        => 'text',
				'label'       => __( 'Replace with', 'bulk-catalogue-manager-for-woocommerce' ),
				/* translators: %d: the most characters it takes. */
				'rule'        => sprintf( __( 'The replacement has to be text of up to %d characters, or left empty to remove what was found.', 'bulk-catalogue-manager-for-woocommerce' ), self::MAX_LENGTH ),
				'allow_empty' => true,
				'max_length'  => self::MAX_LENGTH,
				'default'     => '',
			],
			'whole_word'     => [
				'type'    => 'boolean',
				'label'   => __( 'Match whole words only', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Match whole words only has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
				'default' => false,
			],
			'case_sensitive' => [
				'type'    => 'boolean',
				'label'   => __( 'Match capitals exactly', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'    => __( 'Match capitals exactly has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
				'default' => false,
			],
		];
	}

	/**
	 * A letter, combining mark, digit or underscore: what a whole word may not touch.
	 */
	private const WORD = '[\\p{L}\\p{M}\\p{N}_]';

	/**
	 * Refuse text that is not valid UTF-8, and a replacement identical to
	 * the text found, which changes nothing.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		unset( $field );
		if ( ! mb_check_encoding( $args['find'], 'UTF-8' ) ) {
			return $this->argument_rule( 'find' );
		}
		if ( ! mb_check_encoding( $args['replace'], 'UTF-8' ) ) {
			return $this->argument_rule( 'replace' );
		}
		if ( $args['case_sensitive'] && $args['find'] === $args['replace'] ) {
			return __( 'The replacement is the same as the text to find, so nothing would change. Enter different text and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return $args;
	}

	/**
	 * The field's text with every match replaced; already when nothing matches.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field = $this->field( $args );
		$old   = $field->read( $subject );
		$text  = is_scalar( $old ) ? (string) $old : '';

		$replaced = self::replace(
			$text,
			(string) ( $args['find'] ?? '' ),
			(string) ( $args['replace'] ?? '' ),
			! empty( $args['whole_word'] ),
			! empty( $args['case_sensitive'] )
		);
		if ( null === $replaced ) {
			return Result::failed(
				$field->key(),
				$old,
				sprintf(
					/* translators: %s: a field's name, such as "Name". */
					__( 'The %s on this product contains characters that could not be searched, so it was not changed. Edit it by hand.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				),
				'bcm_invalid_value'
			);
		}
		if ( $replaced === $text ) {
			return Result::already( $field->key(), $old );
		}
		return self::value_outcome( $field, $subject, $replaced );
	}

	/**
	 * Replace plain text in plain text: the result, or null when the text
	 * is not valid UTF-8. An empty needle matches nothing.
	 *
	 * @param string $text           Text searched.
	 * @param string $find           Plain text to find.
	 * @param string $replace        Plain text put in its place.
	 * @param bool   $whole_word     Whether a match must stand as a whole word.
	 * @param bool   $case_sensitive Whether capitals must match exactly.
	 */
	public static function replace( string $text, string $find, string $replace, bool $whole_word, bool $case_sensitive ): ?string {
		if ( '' === $find ) {
			return $text;
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) || ! mb_check_encoding( $find, 'UTF-8' ) ) {
			return null;
		}
		$pattern = preg_quote( $find, '/' );
		if ( $whole_word ) {
			$pattern = '(?<!' . self::WORD . ')' . $pattern . '(?!' . self::WORD . ')';
		}
		$result = preg_replace_callback(
			'/' . $pattern . '/u' . ( $case_sensitive ? '' : 'i' ),
			static fn(): string => $replace,
			$text
		);
		return is_string( $result ) ? $result : null;
	}

	/**
	 * An argument's own refusal sentence.
	 *
	 * @param string $name Argument name.
	 */
	private function argument_rule( string $name ): string {
		return (string) $this->arguments()[ $name ]['rule'];
	}
}
