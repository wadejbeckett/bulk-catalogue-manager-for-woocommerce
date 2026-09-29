<?php
/**
 * Status as the global Off/On switch (SPEC §5.5): the one decision the Status
 * field's set takes on a product, given what the product holds now.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Availability;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Operations\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The decision table, as one pure function of the product and the step.
 *
 * "Off" is a product with its own record (OffRecord) and status draft;
 * "changed outside" is one with its own record and any other status; "no
 * record" covers none, a copied one and an unreadable one. Given the plain
 * Result the Status field would give (Operations\BaseOperation::
 * value_outcome()), decide() returns the Status step's Result — with its
 * outcome code as its note and the statuses involved as its context — and
 * the record Result that follows it, or null:
 *
 * - Draft: no record and not a draft saves the status and drafts (`off`);
 *   a genuine draft is already Draft (`already_draft`); Off is already Off
 *   (`already_off`); changed outside saves the current status again and
 *   drafts (`off_recapture`).
 * - Published: Off restores exactly the saved status and clears the record
 *   (`on`, `on_restores` for Private or Pending review; a saved scheduled
 *   status writes publish and WordPress's date rule schedules it again,
 *   `on_scheduled`, or publishes it when its time has passed,
 *   `on_schedule_passed`); changed outside is left alone (`conflict`,
 *   skipped with CONFLICT_CODE) unless Keep current status asks to clear the
 *   record (`conflict_kept`); no record is an ordinary change
 *   (`publishes_not_off`, or no code when already Published).
 * - Pending review or Private: with a record, an ordinary change that clears
 *   it (`record_dropped`); without, an ordinary change with no code.
 *
 * Only a product's own Status is a switch; a variation keeps the field's
 * ordinary rules and never carries a record. A plain Result that is failed
 * or skipped is returned as it is, with no record. The manager never writes
 * `future`: the Status field cannot take it, and a restore of a scheduled
 * status writes publish.
 */
final class StatusSwitch {
	/**
	 * WordPress schedules a publish only when its date is at least this far ahead (wp_insert_post()).
	 */
	public const SCHEDULE_LEAD = 60;

	/**
	 * Whether a field is the Status field: the product's post status.
	 *
	 * @param Field $field A registry field.
	 */
	public static function governs( Field $field ): bool {
		return Storage::POST === $field->storage()->kind() && 'post_status' === $field->storage()->key();
	}

	/**
	 * Whether a set of this field on this object goes through the switch: the
	 * Status field on a product, never on a variation.
	 *
	 * @param Field  $field   The field set.
	 * @param object $subject A product or variation.
	 */
	public static function applies( Field $field, object $subject ): bool {
		return self::governs( $field ) && ! ( method_exists( $subject, 'is_type' ) && true === $subject->is_type( 'variation' ) );
	}

	/**
	 * Decide one Status step on one product.
	 *
	 * @param object $subject The product, as it is now.
	 * @param Result $plain   What the Status field alone would do (value_outcome()).
	 * @param bool   $keep    Keep current status: for a product changed elsewhere while Off, clear the record instead of refusing.
	 * @return array{status: Result, record: Result|null}
	 */
	public static function decide( object $subject, Result $plain, bool $keep = false ): array {
		if ( ! in_array( $plain->state(), [ Result::CHANGED, Result::ALREADY ], true ) ) {
			return self::decision( $plain );
		}
		$key     = $plain->field();
		$current = (string) $plain->old();
		$target  = (string) $plain->new();
		$record  = OffRecord::for_product( $subject );
		$own     = null === $record ? '' : $record->to_json();
		if ( ! in_array( $current, [ 'publish', 'future', 'draft', 'pending', 'private' ], true ) ) {
			// Anything else (the bin) is left to the field's ordinary rules; T83 never writes a binned member.
			return self::decision( $plain );
		}

		if ( 'draft' === $target ) {
			if ( null === $record ) {
				return 'draft' === $current
					? self::decision( $plain->with_note( Outcome::ALREADY_DRAFT ) )
					: self::decision( $plain->with_note( Outcome::OFF, [ 'saved' => $current ] ), self::saving( $subject, $own, $current ) );
			}
			if ( 'draft' === $current ) {
				return self::decision( $plain->with_note( Outcome::ALREADY_OFF, [ 'saved' => $record->status ] ) );
			}
			return self::decision(
				$plain->with_note(
					Outcome::OFF_RECAPTURE,
					[
						'saved'   => $current,
						'current' => $current,
					]
				),
				self::saving( $subject, $own, $current )
			);
		}

		if ( 'publish' === $target ) {
			if ( null === $record ) {
				if ( 'publish' === $current ) {
					return self::decision( $plain );
				}
				$context = [ 'current' => $current ];
				if ( 'future' === $current ) {
					$context['scheduled_gmt'] = self::scheduled_gmt( $subject );
				}
				return self::decision( $plain->with_note( Outcome::PUBLISHES_NOT_OFF, $context ) );
			}
			$clear = Result::changed( OffRecord::FIELD, $own, '' );
			if ( 'draft' !== $current ) {
				$context = [
					'saved'   => $record->status,
					'current' => $current,
				];
				return $keep
					? self::decision( Result::already( $key, $current )->with_note( Outcome::CONFLICT_KEPT, $context ), $clear )
					: self::decision( Result::skipped( $key, $current, Outcome::conflict_reason(), Outcome::CONFLICT_CODE )->with_note( Outcome::CONFLICT, $context ) );
			}
			$context = [
				'saved'    => $record->status,
				'restores' => $record->status,
			];
			return match ( $record->status ) {
				'publish'          => self::decision( Result::changed( $key, $current, 'publish' )->with_note( Outcome::ON, $context ), $clear ),
				'private', 'pending' => self::decision( Result::changed( $key, $current, $record->status )->with_note( Outcome::ON_RESTORES, $context ), $clear ),
				default            => self::decision(
					Result::changed( $key, $current, 'publish' )->with_note(
						self::ahead( $subject ) ? Outcome::ON_SCHEDULED : Outcome::ON_SCHEDULE_PASSED,
						$context + [ 'scheduled_gmt' => self::scheduled_gmt( $subject ) ]
					),
					$clear
				),
			};
		}

		// Pending review or Private: ordinary statuses; on a product with a record they clear it.
		if ( null === $record ) {
			return self::decision( $plain );
		}
		return self::decision( $plain->with_note( Outcome::RECORD_DROPPED, [ 'saved' => $record->status ] ), Result::changed( OffRecord::FIELD, $own, '' ) );
	}

	/**
	 * After a save that set the status, put on the object the status WordPress
	 * actually stored (SPEC §5.5, §9.3): WooCommerce never reads post_status
	 * back, so without this a publish WordPress kept or made scheduled would
	 * read as publish and be journalled so. The stored value goes on the
	 * object through the field's own setter, then becomes the object's saved
	 * data (apply_changes()); nothing is saved again.
	 *
	 * @param Field  $field   The Status field (governs()).
	 * @param object $product The saved product or variation.
	 */
	public static function read_back( Field $field, object $product ): void {
		$id     = (int) $product->get_id();
		$setter = (string) $field->setter();
		if ( $id < 1 || ! self::governs( $field ) || '' === $setter ) {
			return;
		}
		clean_post_cache( $id );
		$stored = get_post_field( 'post_status', $id );
		if ( ! is_string( $stored ) || '' === $stored || $stored === (string) $field->read( $product ) ) {
			return;
		}
		$product->{$setter}( $stored );
		if ( method_exists( $product, 'apply_changes' ) ) {
			$product->apply_changes();
		}
	}

	/**
	 * The decision's two parts.
	 *
	 * @param Result      $status The Status step's Result.
	 * @param Result|null $record The record Result, or null.
	 * @return array{status: Result, record: Result|null}
	 */
	private static function decision( Result $status, ?Result $record = null ): array {
		return [
			'status' => $status,
			'record' => $record,
		];
	}

	/**
	 * The record Result saving a status now, or null when the record would not change.
	 *
	 * @param object $subject The product.
	 * @param string $own     Its own record now, or ''.
	 * @param string $status  The status to save.
	 */
	private static function saving( object $subject, string $own, string $status ): ?Result {
		$new = OffRecord::create( (int) $subject->get_id(), $status, Stamp::at(), Stamp::batch() )->to_json();
		return $new === $own ? null : Result::changed( OffRecord::FIELD, $own, $new );
	}

	/**
	 * Whether the product's date is far enough ahead for WordPress to keep a
	 * publish scheduled.
	 *
	 * @param object $subject The product.
	 */
	private static function ahead( object $subject ): bool {
		$time = self::date_of( $subject );
		return null !== $time && $time - Stamp::now() >= self::SCHEDULE_LEAD;
	}

	/**
	 * The product's scheduled time, GMT, `Y-m-d H:i:s`, or '' when it has none.
	 *
	 * @param object $subject The product.
	 */
	private static function scheduled_gmt( object $subject ): string {
		$time = self::date_of( $subject );
		return null === $time ? '' : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * The product's date (its publication date) as a Unix time, or null.
	 *
	 * @param object $subject The product.
	 */
	private static function date_of( object $subject ): ?int {
		$date = $subject->get_date_created( 'edit' );
		return is_object( $date ) && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : null;
	}
}
