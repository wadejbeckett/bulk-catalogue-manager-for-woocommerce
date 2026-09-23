<?php
/**
 * Add, remove or replace terms (SPEC §6, §12.3): categories and tags, one
 * class in three modes. Add and remove leave every other term alone;
 * replace sets exactly the given list and says so in bold in the preview.
 * Each term has to exist in the field's taxonomy, and one operation names
 * at most 100.
 *
 * compute() works out the whole resulting list from the object's current
 * terms (Field::read()), so add and remove touch only the named terms of the
 * named taxonomy: add appends the new ids in the order given, remove drops
 * them and keeps the rest in their order, replace takes the given list. No
 * ancestor is added: choosing a child category adds that child only. A list
 * that ends up holding the same terms, in any order, is already correct.
 * Terms belong to the product, so a variation fails with the field's reason
 * (SPEC §8) rather than being skipped silently, and a term id that is not in
 * the field's taxonomy (deleted since the form was filled in, say) fails the
 * row with its id.
 *
 * apply() writes the full computed list with wp_set_object_terms() and
 * append off (SPEC §12.3), never SQL, then sets the same list through the
 * field's setter so the object in memory agrees with what is stored: a later
 * step in the same batch computes from the new list, and WooCommerce's own
 * save writes the same list again and fires its product hooks.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Rest\Refusal;

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
	 * The list the object would end with.
	 *
	 * @param object               $subject A product or variation.
	 * @param array<string, mixed> $args    Normalised arguments.
	 */
	public function compute( object $subject, array $args ): Result {
		$field   = $this->field( $args );
		$key     = $field->key();
		$current = self::ids( $field->read( $subject ) );

		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return Result::failed( $key, $current, $reason, 'bcm_not_available' );
		}

		$terms = self::ids( $args['terms'] ?? [] );
		if ( [] === $terms || count( $terms ) > Values::MAX_TERMS ) {
			return Result::failed( $key, $current, $field->rule_text(), 'bcm_invalid_value' );
		}
		$missing = $this->missing_term( $field, $terms );
		if ( null !== $missing ) {
			return Result::failed(
				$key,
				$current,
				sprintf(
					/* translators: 1: a field's name, such as "Categories", 2: a term id. */
					__( '%1$s: the one with ID %2$d does not exist, so nothing was changed. Choose from the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label(),
					$missing
				),
				'bcm_invalid_value'
			);
		}

		$result = match ( $this->mode ) {
			self::ADD    => array_values( array_unique( array_merge( $current, $terms ) ) ),
			self::REMOVE => array_values( array_diff( $current, $terms ) ),
			default      => $terms,
		};
		if ( self::same_terms( $current, $result ) ) {
			return Result::already( $key, $current );
		}
		if ( count( $result ) > Values::MAX_TERMS ) {
			return Result::failed(
				$key,
				$current,
				sprintf(
					/* translators: 1: a field's name, such as "Categories", 2: the most one product may hold here. */
					__( '%1$s would go over %2$d on this product, so nothing was changed. Remove some first, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label(),
					Values::MAX_TERMS
				),
				'bcm_invalid_value'
			);
		}
		return Result::changed( $key, $current, $result, self::REPLACE === $this->mode );
	}

	/**
	 * Write the computed list with wp_set_object_terms(), append off, then
	 * set it on the object through the field's setter. Nothing for a result
	 * that is not a change. Never saves.
	 *
	 * @param Result $result  What compute() returned for this object.
	 * @param object $subject The same product or variation.
	 * @throws \LogicException When the result is for a field this action does not change.
	 * @throws WriteRefused    When the object cannot take the terms or the write fails.
	 */
	public function apply( Result $result, object $subject ): void {
		if ( ! $result->is_changed() ) {
			return;
		}
		$field = $this->fields->get( $result->field() );
		if ( null === $field || ! $this->describe()->accepts( $result->field() ) ) {
			throw new \LogicException( esc_html( sprintf( 'Action "%s" cannot apply a result for field "%s".', $this->id(), $result->field() ) ) );
		}

		$reason = $field->unavailable_reason( $subject );
		if ( null !== $reason ) {
			throw new WriteRefused( Refusal::invalid( 'bcm_not_available', $reason ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WriteRefused escapes the message it carries.
		}
		// Terms deleted since compute(): core would skip an unknown id silently.
		$ids   = self::ids( $result->new() );
		$valid = $field->validate( $ids );
		if ( $valid instanceof \WP_Error ) {
			throw new WriteRefused( $valid ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WriteRefused escapes the message it carries.
		}

		// Integers, never strings: core reads a string as a term name or slug.
		$written = wp_set_object_terms( (int) $subject->get_id(), $ids, $field->storage()->key(), false );
		if ( $written instanceof \WP_Error ) {
			$refusal = Refusal::invalid(
				'bcm_invalid_value',
				sprintf(
					/* translators: %s: a field's name, such as "Categories". */
					__( '%s could not be saved on this product. Try again in a moment.', 'bulk-catalogue-manager-for-woocommerce' ),
					$field->label()
				),
				[ 'detail' => $written->get_error_message() ]
			);
			throw new WriteRefused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WriteRefused escapes the message it carries.
		}
		$subject->{$field->setter()}( $ids );
	}

	/**
	 * The first term id not in the field's taxonomy, or null.
	 *
	 * @param Field $field The chosen field.
	 * @param int[] $terms Term ids.
	 */
	private function missing_term( Field $field, array $terms ): ?int {
		foreach ( $terms as $id ) {
			if ( ! term_exists( $id, $field->storage()->key() ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Positive integer ids, first occurrence kept, in order.
	 *
	 * @param mixed $value A list of ids.
	 * @return int[]
	 */
	private static function ids( mixed $value ): array {
		$ids = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			$id = Values::integer( $item );
			if ( null !== $id && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Whether two lists hold the same terms, in any order.
	 *
	 * @param int[] $a One list.
	 * @param int[] $b The other.
	 */
	private static function same_terms( array $a, array $b ): bool {
		sort( $a );
		sort( $b );
		return $a === $b;
	}
}
