<?php
/**
 * Find and replace (SPEC §6, §13): swap plain text in a text field such as
 * the name or the SKU. Plain text only — no pattern — optionally whole word
 * and optionally case sensitive. compute() lands in T25, which quotes the
 * needle so no input ever becomes a pattern and leaves the slug alone.
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
	 * Refuse a replacement identical to the text found, which changes nothing.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		unset( $field );
		if ( $args['case_sensitive'] && $args['find'] === $args['replace'] ) {
			return __( 'The replacement is the same as the text to find, so nothing would change. Enter different text and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return $args;
	}

	/**
	 * Implemented in T25.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		unset( $subject, $args );
		$this->not_yet( 'T25' );
	}
}
