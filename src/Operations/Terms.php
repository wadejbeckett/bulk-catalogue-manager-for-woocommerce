<?php
/**
 * Add, remove or replace terms (SPEC §6, §12.3): categories and tags, one
 * class in three modes. Add and remove leave every other term alone;
 * replace sets exactly the given list and says so in bold in the preview.
 * Each term has to exist in the field's taxonomy, and one operation names
 * at most 100. compute() and the term write land in T27.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * The term actions.
 */
final class Terms extends BaseOperation {
	public const ADD     = 'add_terms';
	public const REMOVE  = 'remove_terms';
	public const REPLACE = 'replace_terms';

	/**
	 * Every mode.
	 */
	public const MODES = [ self::ADD, self::REMOVE, self::REPLACE ];

	/**
	 * Build one mode over the field registry.
	 *
	 * @param Fields $fields Field registry.
	 * @param string $mode   One of MODES.
	 * @throws \InvalidArgumentException For an unknown mode.
	 */
	public function __construct( Fields $fields, private readonly string $mode ) {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown term action "%s".', $mode ) ) );
		}
		parent::__construct( $fields );
	}

	/**
	 * The action id: the mode.
	 */
	public function id(): string {
		return $this->mode;
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
		return match ( $this->mode ) {
			self::ADD    => __( 'Add categories or tags', 'bulk-catalogue-manager-for-woocommerce' ),
			self::REMOVE => __( 'Remove categories or tags', 'bulk-catalogue-manager-for-woocommerce' ),
			default      => __( 'Replace categories or tags', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * The plain description.
	 */
	protected function description(): string {
		return match ( $this->mode ) {
			self::ADD    => __( 'Adds the categories or tags you choose, leaving every other one in place.', 'bulk-catalogue-manager-for-woocommerce' ),
			self::REMOVE => __( 'Takes away the categories or tags you choose, leaving every other one in place.', 'bulk-catalogue-manager-for-woocommerce' ),
			default      => __( 'Sets exactly the categories or tags you choose, taking away any others.', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * The edit kind: add, remove, or set for replace.
	 *
	 * @return string[]
	 */
	protected function edit_kinds(): array {
		return match ( $this->mode ) {
			self::ADD    => [ Field::ADD ],
			self::REMOVE => [ Field::REMOVE ],
			default      => [ Field::SET ],
		};
	}

	/**
	 * Term list fields allowing the mode's edit kind.
	 *
	 * @param Field $field A registry field.
	 */
	protected function accepts_field( Field $field ): bool {
		return Field::TERMS === $field->type() && parent::accepts_field( $field );
	}

	/**
	 * The terms.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function arguments(): array {
		return [
			'terms' => [
				'type'      => 'ids',
				'label'     => __( 'Categories or tags', 'bulk-catalogue-manager-for-woocommerce' ),
				/* translators: %d: the most terms one change may name. */
				'rule'      => sprintf( __( 'Choose between 1 and %d categories or tags, then try again.', 'bulk-catalogue-manager-for-woocommerce' ), Values::MAX_TERMS ),
				'required'  => true,
				'min_items' => 1,
				'max_items' => Values::MAX_TERMS,
			],
		];
	}

	/**
	 * Every term has to exist in the field's own taxonomy.
	 *
	 * @param array<string, mixed> $args  Arguments.
	 * @param Field                $field The chosen field.
	 * @return array<string, mixed>|string
	 */
	protected function normalise( array $args, Field $field ): array|string {
		$valid = $field->validate( $args['terms'] );
		return $valid instanceof \WP_Error ? $valid->get_error_message() : $args;
	}

	/**
	 * Implemented in T27.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		unset( $subject, $args );
		$this->not_yet( 'T27' );
	}
}
