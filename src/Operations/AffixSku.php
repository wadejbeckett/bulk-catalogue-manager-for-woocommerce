<?php
/**
 * Add a prefix or suffix (SPEC §6): put the same text at the start or the
 * end of each SKU. Accepts the fields allowing a prefix or a suffix; the
 * chosen position has to be one the chosen field allows. compute() lands in
 * T25.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The prefix or suffix action.
 */
final class AffixSku extends BaseOperation {
	/**
	 * The action id.
	 */
	public function id(): string {
		return 'affix_sku';
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
		return __( 'Add a prefix or suffix', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return __( 'Adds the same text to the start or the end of each SKU.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The edit kinds it performs.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return [ Field::PREFIX, Field::SUFFIX ];
	}

	/**
	 * Where the text goes, and the text.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'position' => [
				'type'     => 'choice',
				'label'    => __( 'Where', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'     => __( 'Choose whether the text goes at the start or the end, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required' => true,
				'choices'  => [
					Field::PREFIX => __( 'At the start', 'bulk-catalogue-manager-for-woocommerce' ),
					Field::SUFFIX => __( 'At the end', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			],
			'text'     => [
				'type'       => 'text',
				'label'      => __( 'Text to add', 'bulk-catalogue-manager-for-woocommerce' ),
				'rule'       => __( 'Enter the text to add, up to 100 characters, with no spaces at either end.', 'bulk-catalogue-manager-for-woocommerce' ),
				'required'   => true,
				'max_length' => 100,
			],
		];
	}

	/**
	 * The position has to be one the field allows, and the text has to
	 * survive the field's own sanitiser unchanged.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		if ( ! $field->accepts( $args['position'] ) ) {
			return $this->field_refusal( $field->key() )->get_error_message();
		}
		if ( trim( $args['text'] ) !== $args['text'] || sanitize_text_field( $args['text'] ) !== $args['text'] ) {
			return __( 'Enter the text to add, up to 100 characters, with no spaces at either end.', 'bulk-catalogue-manager-for-woocommerce' );
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
