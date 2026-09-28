<?php
/**
 * Independent bounded cleanup of immutable membership and durable request receipts.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\Registry;
defined( 'ABSPATH' ) || exit;

/** Action Scheduler callbacks use only the public scheduling API. */
final class Cleanup {
	/** Register once on activation or an admin upgrade, in the plugin's group. */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		foreach ( [ 'bulkcat_prune_selections', 'bulkcat_prune_requests' ] as $hook ) {
			if ( false === as_next_scheduled_action( $hook, [], Registry::ACTION_GROUP ) ) {
				as_schedule_recurring_action( time() + 3600, 3600, $hook, [], Registry::ACTION_GROUP, true );
			}
		}
	}

	/** One payload transaction; scheduler records unexpected database failures. */
	public static function selections(): void {
		( new RequestService() )->prune_selections();
	}

	/** One receipt transaction, independently scheduled. */
	public static function requests(): void {
		( new RequestService() )->prune_requests();
	}
}
