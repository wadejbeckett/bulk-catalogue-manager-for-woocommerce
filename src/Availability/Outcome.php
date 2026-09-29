<?php
/**
 * The outcomes of a Status change on a product (SPEC §5.5, §4.5, §9.1): one
 * code per row of the Off/On table, the list that always confirms, and the
 * plain words the server itself says about them.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome codes and their server-side wording.
 *
 * A code rides on the Status step's Result as its note (Operations\Result::
 * note()), with a small context naming the statuses involved. The preview
 * counts them (its `availability` block), the grid and the bulk panel word
 * them, and the shared fixture tests/fixtures/status-switch.json holds every
 * row, so the grid's mirror of StatusSwitch cannot drift.
 */
final class Outcome {
	public const OFF                = 'off';
	public const OFF_RECAPTURE      = 'off_recapture';
	public const ALREADY_OFF        = 'already_off';
	public const ALREADY_DRAFT      = 'already_draft';
	public const ON                 = 'on';
	public const ON_RESTORES        = 'on_restores';
	public const ON_SCHEDULED       = 'on_scheduled';
	public const ON_SCHEDULE_PASSED = 'on_schedule_passed';
	public const PUBLISHES_NOT_OFF  = 'publishes_not_off';
	public const CONFLICT           = 'conflict';
	public const CONFLICT_KEPT      = 'conflict_kept';
	public const RECORD_DROPPED     = 'record_dropped';

	/**
	 * Every code, in the order the preview counts them.
	 */
	public const CODES = [
		self::OFF,
		self::OFF_RECAPTURE,
		self::ALREADY_OFF,
		self::ALREADY_DRAFT,
		self::ON,
		self::ON_RESTORES,
		self::ON_SCHEDULED,
		self::ON_SCHEDULE_PASSED,
		self::PUBLISHES_NOT_OFF,
		self::CONFLICT,
		self::CONFLICT_KEPT,
		self::RECORD_DROPPED,
	];

	/**
	 * The outcomes that always confirm, whatever the count, in the bulk panel,
	 * the grid's in-place Status edit and the drawer alike (SPEC §4.5).
	 */
	public const CONFIRMS = [
		self::OFF,
		self::OFF_RECAPTURE,
		self::ON_RESTORES,
		self::ON_SCHEDULED,
		self::ON_SCHEDULE_PASSED,
		self::PUBLISHES_NOT_OFF,
		self::RECORD_DROPPED,
		self::CONFLICT,
	];

	/**
	 * The code on a Status step left alone because the product was changed
	 * elsewhere while Off.
	 */
	public const CONFLICT_CODE = 'bulkcat_availability_conflict';

	/**
	 * Why a product changed elsewhere while Off was left alone (SPEC §4.8).
	 */
	public static function conflict_reason(): string {
		return __( 'This product was changed by someone else while it was Off, so it was left alone. Choose Keep current status to keep its status and clear its Off record.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * A status by its plain name, including "Scheduled", which the Status
	 * field cannot be set to but a record may have saved.
	 *
	 * @param string $status A post status.
	 */
	public static function status_label( string $status ): string {
		return match ( $status ) {
			'publish' => __( 'Published', 'bulk-catalogue-manager-for-woocommerce' ),
			'future'  => __( 'Scheduled', 'bulk-catalogue-manager-for-woocommerce' ),
			'pending' => __( 'Pending review', 'bulk-catalogue-manager-for-woocommerce' ),
			'private' => __( 'Private', 'bulk-catalogue-manager-for-woocommerce' ),
			'draft'   => __( 'Draft', 'bulk-catalogue-manager-for-woocommerce' ),
			default   => $status,
		};
	}
}
