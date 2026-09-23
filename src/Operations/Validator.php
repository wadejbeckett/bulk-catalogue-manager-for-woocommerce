<?php
/**
 * Row validation (SPEC §4.8, §5.4, §8, §13): the check every computed
 * Result passes between compute() and apply(), in preview and in the writer
 * alike, so what the preview reports is what the write does.
 *
 * In order, for one object and one step:
 *
 * 1. Permission. The object is authorised before anything else: in a
 *    request with current_user_can( 'edit_post', $id ), in background work
 *    with user_can() against the batch's recorded user. A refusal turns any
 *    Result into skipped with the permission sentence, so the row is
 *    reported, never dropped.
 * 2. The registry's validator. A change goes through Field::refusal(), the
 *    dry run of Field::write(), so the verdict is the refusal the write
 *    would give: a parent-only field on a variation ("Categories belong to
 *    the product, not to each variation."), a price on a variable parent,
 *    stock quantity where the object does not manage stock, a value the
 *    field refuses (a price below zero among them), and a SKU another
 *    object holds (wc_product_has_unique_sku(), products and variations
 *    together). The row fails with the field's sentence, except stock
 *    quantity on unmanaged stock and a value below zero, which are skipped
 *    (SPEC §5.4, §8).
 * 3. The cross-field rules. A SKU another object in this same run has
 *    already been given fails that row. A sale price not below the regular
 *    price the object would end with is skipped, not cleared — unless the
 *    step's `clear_invalid_sale` is on, when the sale price is cleared: a
 *    sale price change becomes the clear, and a regular price change is
 *    followed by a second Result clearing the sale price.
 *
 * Only a change is checked against the field; already, skipped and failed
 * Results pass through (after the permission check). Every refusal keeps
 * its sentence in the Result's reason and its `bcm_` code in the separate
 * code field for the details line, never in the sentence (SPEC §4.8).
 *
 * One Validator serves one run (a preview or a batch), because it
 * remembers the SKUs it has let through.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Rest\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The row validator.
 */
final class Validator {
	/**
	 * SKUs let through in this run: SKU => object id.
	 *
	 * @var array<string, int>
	 */
	private array $claimed = [];

	/**
	 * Build over the field registry.
	 *
	 * @param Fields   $fields  Field registry.
	 * @param int|null $user_id The batch's recorded user for background work, or null to check the current user.
	 */
	private function __construct( private readonly Fields $fields, private readonly ?int $user_id ) {}

	/**
	 * A validator for a request: objects are checked against the current user.
	 *
	 * @param Fields $fields Field registry.
	 */
	public static function for_request( Fields $fields ): self {
		return new self( $fields, null );
	}

	/**
	 * A validator for background work, which runs with no current user:
	 * objects are checked against the user who queued the batch (SPEC §13).
	 *
	 * @param Fields $fields  Field registry.
	 * @param int    $user_id The batch's recorded user id.
	 */
	public static function for_user( Fields $fields, int $user_id ): self {
		return new self( $fields, $user_id );
	}

	/**
	 * Whether the user may edit this object: null, or the refusal to record.
	 *
	 * @param object $subject A product or variation.
	 */
	public function authorise( object $subject ): ?\WP_Error {
		$id      = self::id_of( $subject );
		$allowed = null === $this->user_id ? Access::can_edit_object( $id ) : Access::user_can_edit_object( $this->user_id, $id );
		return true === $allowed ? null : $allowed;
	}

	/**
	 * Check one computed Result for one object: the Results to apply, in
	 * order. The first is the verdict on this Result; a second, clearing the
	 * sale price, follows only when a regular price change leaves the sale
	 * price not below it and the step asked to clear it.
	 *
	 * @param object               $subject The product or variation the Result was computed for.
	 * @param Result               $result  What the operation computed.
	 * @param array<string, mixed> $args    The step's normalised arguments (for `clear_invalid_sale`).
	 * @return list<Result>
	 * @throws \LogicException When the Result names a field the registry does not have.
	 */
	public function check( object $subject, Result $result, array $args = [] ): array {
		$denied = $this->authorise( $subject );
		if ( null !== $denied ) {
			return [ Result::skipped( $result->field(), $result->old(), $denied->get_error_message(), (string) $denied->get_error_code() ) ];
		}
		if ( ! $result->is_changed() ) {
			return [ $result ];
		}
		$field = $this->fields->get( $result->field() );
		if ( null === $field ) {
			throw new \LogicException( esc_html( sprintf( 'Field "%s" is not in the registry.', $result->field() ) ) );
		}

		$refusal = $field->refusal( $subject, $result->new() );
		if ( null !== $refusal ) {
			return [ self::refused( $field, $result, $refusal ) ];
		}

		$clash = $this->claim( $field, $subject, $result );
		if ( null !== $clash ) {
			return [ $clash ];
		}
		return $this->check_prices( $field, $subject, $result, ! empty( $args['clear_invalid_sale'] ) );
	}

	/**
	 * The Result for a refusal of the registry's validator: skipped for
	 * stock quantity where stock is not managed and for a value below zero,
	 * failed otherwise; the sentence and code as the field gave them.
	 *
	 * @param Field     $field   The field.
	 * @param Result    $result  The computed change.
	 * @param \WP_Error $refusal What Field::refusal() returned.
	 */
	private static function refused( Field $field, Result $result, \WP_Error $refusal ): Result {
		$code    = (string) $refusal->get_error_code();
		$message = $refusal->get_error_message();
		$skip    = ( 'bcm_not_available' === $code && $field->requires_managed_stock() )
			|| ( 'bcm_invalid_value' === $code && self::below_zero( $field, $result->new() ) );
		return $skip
			? Result::skipped( $field->key(), $result->old(), $message, $code )
			: Result::failed( $field->key(), $result->old(), $message, $code );
	}

	/**
	 * A unique value (the SKU) another object in this run was already given
	 * fails this row; otherwise the value is claimed for this object.
	 *
	 * @param Field  $field   The field.
	 * @param object $subject The object.
	 * @param Result $result  The computed change.
	 */
	private function claim( Field $field, object $subject, Result $result ): ?Result {
		$value = $result->new();
		if ( ! $field->unique() || ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$id     = self::id_of( $subject );
		$holder = $this->claimed[ $value ] ?? null;
		if ( null !== $holder && $holder !== $id ) {
			return Result::failed( $field->key(), $result->old(), Field::clash_reason( $value ), 'bcm_invalid_value' );
		}
		$this->claimed[ $value ] = $id;
		return null;
	}

	/**
	 * The §5.4 price pair: a sale price has to be below the regular price
	 * the object would end with. Otherwise the row is skipped, or with
	 * `clear_invalid_sale` the sale price is cleared.
	 *
	 * @param Field  $field   The field changed.
	 * @param object $subject The object.
	 * @param Result $result  The computed change, which passed the field.
	 * @param bool   $clear   Whether the step asked to clear a sale price not below the regular.
	 * @return list<Result>
	 */
	private function check_prices( Field $field, object $subject, Result $result, bool $clear ): array {
		$sale    = $this->price_field( Field::COPY_FROM_REGULAR );
		$regular = $this->price_field( Field::COPY_FROM_SALE );
		if ( null === $sale || null === $regular ) {
			return [ $result ];
		}
		if ( $field->key() === $sale->key() ) {
			$verdict = Fields::check_prices( (string) $regular->read( $subject ), (string) $result->new() );
			if ( true === $verdict ) {
				return [ $result ];
			}
			if ( $clear ) {
				return [ Values::is_empty( $result->old() ) ? Result::already( $field->key(), $result->old() ) : Result::changed( $field->key(), $result->old(), '' ) ];
			}
			return [ Result::skipped( $field->key(), $result->old(), $verdict->get_error_message(), (string) $verdict->get_error_code() ) ];
		}
		if ( $field->key() === $regular->key() ) {
			$current = (string) $sale->read( $subject );
			$verdict = Fields::check_prices( (string) $result->new(), $current );
			if ( true === $verdict ) {
				return [ $result ];
			}
			if ( $clear && null === $sale->refusal( $subject, '' ) ) {
				return [ $result, Result::changed( $sale->key(), $current, '' ) ];
			}
			return [ Result::skipped( $field->key(), $result->old(), $verdict->get_error_message(), (string) $verdict->get_error_code() ) ];
		}
		return [ $result ];
	}

	/**
	 * The editable price field allowing a copy kind: copy from regular
	 * finds the sale price, copy from sale the regular price.
	 *
	 * @param string $kind Field::COPY_FROM_REGULAR or Field::COPY_FROM_SALE.
	 */
	private function price_field( string $kind ): ?Field {
		foreach ( $this->fields->editable() as $field ) {
			if ( Field::MONEY === $field->type() && $field->accepts( $kind ) ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Whether a value is a number below zero on a field that takes zero or more.
	 *
	 * @param Field $field The field.
	 * @param mixed $value The refused value.
	 */
	private static function below_zero( Field $field, mixed $value ): bool {
		if ( Field::MONEY !== $field->type() && Field::DECIMAL !== $field->type() ) {
			return false;
		}
		$number = Values::decimal( $value );
		return null !== $number && str_starts_with( $number, '-' ) && 0.0 !== (float) $number;
	}

	/**
	 * The object's id.
	 *
	 * @param object $subject A product or variation.
	 */
	private static function id_of( object $subject ): int {
		return (int) $subject->get_id();
	}
}
