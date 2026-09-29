<?php
/**
 * The refusals and plain sentences of the external action contract
 * (docs/EXTENSIONS.md §13), in one place so every path says the same thing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Codes and sentences.
 */
final class Refusals {
	/**
	 * A provider refused at registration; the reason names the first problem.
	 */
	public const INCOMPATIBLE = 'bulkcat_provider_incompatible';

	/**
	 * A registration after the action finished.
	 */
	public const LATE = 'bulkcat_provider_late';

	/**
	 * A change whose provider is not registered, or not available now (409).
	 */
	public const UNAVAILABLE = 'bulkcat_provider_unavailable';

	/**
	 * A change mixing native steps with a provider's, or two providers' (400).
	 */
	public const MIXED = 'bulkcat_mixed_change';

	/**
	 * An external value sent to the single-product write route (400).
	 */
	public const NOT_INLINE = 'bulkcat_external_not_inline';

	/**
	 * The object result for a value that cannot be read, without a code of its own.
	 */
	public const UNKNOWN_STATE = 'bulkcat_unknown_state';

	/**
	 * The object result for a write that did not stick.
	 */
	public const NOT_STORED = 'bulkcat_external_not_stored';

	/**
	 * The object result for a provider call that threw or broke the contract.
	 */
	public const ERROR = 'bulkcat_external_error';

	/**
	 * The object result for a value above the size limit.
	 */
	public const TOO_LARGE = 'bulkcat_value_too_large';

	/**
	 * The name shown for a provider whose label could not be read.
	 */
	public static function an_add_on(): string {
		return __( 'An add-on', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * A provider refused at registration.
	 *
	 * @param string $reason Plain sentence naming the first problem.
	 */
	public static function incompatible( string $reason ): \WP_Error {
		return Refusal::create( self::INCOMPATIBLE, $reason, 400 );
	}

	/**
	 * A registration after the action finished.
	 */
	public static function late(): \WP_Error {
		return Refusal::create( self::LATE, __( 'Add-ons can only be registered while bulkcat_register_providers runs, so this one was not added. Register it from that action.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
	}

	/**
	 * A registered provider that is not available now: its own reason, or a generic one.
	 *
	 * @param string $label  The provider's label.
	 * @param string $reason Its reason, or ''.
	 */
	public static function unavailable( string $label, string $reason = '' ): \WP_Error {
		$reason = Text::plain( $reason, Text::REASON );
		return Refusal::create(
			self::UNAVAILABLE,
			'' !== $reason ? $reason : sprintf(
				/* translators: %s: the name of an add-on, such as "Example add-on". */
				__( '%s is not available right now, so its actions cannot be used. Check the add-on, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				$label
			),
			409,
			[ 'reason' => 'provider_unavailable' ]
		);
	}

	/**
	 * A change whose provider is not registered at all.
	 */
	public static function missing(): \WP_Error {
		return Refusal::create(
			self::UNAVAILABLE,
			__( 'This change uses an add-on that is not active now. Turn the add-on on again, or choose another change.', 'bulk-catalogue-manager-for-woocommerce' ),
			409,
			[ 'reason' => 'provider_unavailable' ]
		);
	}

	/**
	 * A change mixing kinds of step (version 1 keeps one kind per change).
	 */
	public static function mixed(): \WP_Error {
		return Refusal::invalid( self::MIXED, self::mixed_sentence() );
	}

	/**
	 * The sentence for a mixed change, shown in place in the panel too.
	 */
	public static function mixed_sentence(): string {
		return __( 'This action can\'t be combined with other kinds of change. Make it a separate change.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * An external value sent to the single-product write route.
	 */
	public static function not_inline(): \WP_Error {
		return Refusal::invalid( self::NOT_INLINE, __( 'Values that belong to an add-on cannot be edited in the list. Use Make a change to these instead.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}

	/**
	 * The sentence for a user without an action's capability.
	 */
	public static function no_permission(): string {
		return __( 'You do not have permission to use this action.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * A refusal for a user without an action's capability.
	 */
	public static function forbidden(): \WP_Error {
		return Refusal::create( Refusal::FORBIDDEN, self::no_permission(), 403 );
	}

	/**
	 * The sentence for a provider call that failed.
	 */
	public static function error_sentence(): string {
		return __( 'The add-on could not work out this change, so it was not made. Try again, or ask the add-on\'s author.', 'bulk-catalogue-manager-for-woocommerce' );
	}
}
