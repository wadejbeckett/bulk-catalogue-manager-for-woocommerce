<?php
/**
 * The registry lists everything the plugin stores: every `bulkcat_` string in
 * src/ is either a registered option, meta key, table, transient prefix or
 * recurring action, or a named non-storage identifier (SPEC §15 Data lifecycle).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Data;

use BulkCatalogueManager\Data\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase {
	/**
	 * `bulkcat_` strings in src/ that are not stored data, each with its reason.
	 * Add a new hook name or code here only if it is never a storage key.
	 */
	private const NOT_STORAGE = [
		'bulkcat_variation_password_unsupported' => 'Refusal code for unsupported variation password changes or restoration; nothing is stored under it.',
		'bulkcat_cannot_publish'                 => 'Publication permission refusal, not storage.',
		'bulkcat_selection_renew_required'       => 'Selection authorization refusal.',
		'bulkcat_export_confirm_all'             => 'refusal for a whole-catalogue export without its confirmation; nothing is streamed (CSV export, ticket 10)',
		'bulkcat_export_unavailable'             => 'refusal when a catalogue export cannot be prepared (a database error before any byte); nothing is streamed',
		'bulkcat_inline_version'                 => 'refusal of a resend whose signed native payload/write semantics are no longer supported; resolution remains available',
		'bulkcat_request_conflict'               => 'refusal for an identity bound to different intent',
		'bulkcat_invalid_request'                => 'refusal for a forged or foreign request proof',
		'bulkcat_selection_expired'              => 'refusal for a removed membership payload',
		'bulkcat_request_storage'                => 'refusal when durable request storage is unavailable',
		'bulkcat_request_expired'                => 'refusal for an unaccepted grant past its deadline',
		'bulkcat_legacy_request'                 => 'refusal for a request id from an older screen that no receipt proves; nothing is created',
		'bulkcat_request_gone'                   => 'refusal for an accepted change whose batch is no longer in the history; nothing is created again',
		'bulkcat_schedule_'                      => 'prefix of the MySQL named lock that serialises queueing one batch\'s first chunk; nothing is stored',
		'bulkcat_changed_kind'                   => 'result code for a batch item whose object became the other kind (product or variation) after it was chosen; it is not written',
		'bulkcat_unavailable'                    => 'result code for a batch item whose object was moved to the bin after it was chosen; it is not written',
		'bulkcat_invalid_'                       => 'prefix of the settings error codes passed to add_settings_error()',
		'bulkcat_capability'                     => 'filter hook for the capability the screen and read routes need',
		'bulkcat_forbidden'                      => 'REST refusal code for a user without that capability',
		'bulkcat_invalid_value'                  => 'refusal code for a field value that fails its validator',
		'bulkcat_read_only'                      => 'refusal code for a write to a read-only column',
		'bulkcat_not_available'                  => 'refusal code for a field that does not apply to that object, such as a parent-only field on a variation',
		'bulkcat_cannot_edit'                    => 'refusal code for one object the user may not edit, skipped and reported',
		'bulkcat_'                               => 'the prefix Rest\\Refusal requires of every refusal code',
		'bulkcat_not_found'                      => 'REST refusal code for an id that is not a product',
		'bulkcat_invalid_selection'              => 'REST refusal code for a selection token that is altered, expired or another user\'s',
		'bulkcat_selection_too_large'            => 'REST refusal code for a selection of more than 100,000 objects',
		'bulkcat_selection_ttl'                  => 'filter hook for how long a selection token lasts',
		'bulkcat_invalid_operation'              => 'refusal code for an operation whose arguments are missing, malformed or out of range',
		'bulkcat_field_not_accepted'             => 'refusal code for an action applied to a field it does not accept',
		'bulkcat_no_operations'                  => 'refusal code for an operation list with no operations',
		'bulkcat_too_many_operations'            => 'refusal code for an operation list above twenty operations',
		'bulkcat_unknown_action'                 => 'refusal code for an action id no operation has',
		'bulkcat_catalogue'                      => 'object-cache group for hydrated catalogue pages; keys carry the last-changed values and expire, so nothing is left to remove',
		'bulkcat_batch_created'                  => 'action fired once a batch and its pending items are written; the chunk runner enqueues its first chunk there',
		'bulkcat_selection_empty'                => 'refusal code for a batch whose selection no longer holds any product or variation',
		'bulkcat_selection_changed'              => 'REST refusal code for applying a filter whose count moved since the preview',
		'bulkcat_preview_stale'                  => 'REST refusal code for applying a filter without the hash of a current preview',
		'bulkcat_selection_required'             => 'refusal code for a batch request naming neither or both of an id list and a selection token',
		'bulkcat_batch_not_recorded'             => 'refusal code for a batch the database would not take; nothing is left written',
		'bulkcat_already_done'                   => 'result code for an object a retried chunk skips because its batch already journalled it',
		'bulkcat_save_failed'                    => 'result code for changes lost because WooCommerce could not save the object',
		'bulkcat_run_chunk'                      => 'one-off Action Scheduler action in the plugin group running one chunk of a batch; not recurring, and uninstall unschedules the whole group',
		'bulkcat_batch_size'                     => 'filter hook over the configured chunk size of a background batch',
		'bulkcat_not_cancellable'                => 'REST refusal code for cancelling a batch that has already finished',
		'bulkcat_not_reversible'                 => 'refusal code for undoing a batch that is running, already undone, pruned or outside the retention window',
		'bulkcat_nothing_to_undo'                => 'REST refusal code for an undo whose plan leaves nothing to put back',
		'bulkcat_name_required'                  => 'REST refusal code for a category with an empty name',
		'bulkcat_category_exists'                => 'REST refusal code for a category name another category under the same parent has',
		'bulkcat_slug_taken'                     => 'REST refusal code for a category slug another category has',
		'bulkcat_slug_invalid'                   => 'REST refusal code for a category slug with no letter or number',
		'bulkcat_parent_missing'                 => 'REST refusal code for a parent category that does not exist',
		'bulkcat_category_cycle'                 => 'REST refusal code for moving a category inside itself or one of its descendants',
		'bulkcat_category_not_saved'             => 'REST refusal code for a category write WordPress refused',
		'bulkcat_nonce'                          => 'Heartbeat data key the screen sends and the key of the fresh REST nonce in the answer; nothing is stored',
		'bulkcat_invalid_setting'                => 'REST refusal code for a settings save naming a value a setting does not accept; nothing is saved',
		'bulkcat_invalid_view'                   => 'REST refusal code for a saved view, category or order that breaks the schema; nothing is saved',
		'bulkcat_view_name_required'             => 'REST refusal code for a saved view with no name or a name above 60 characters',
		'bulkcat_view_exists'                    => 'REST refusal code for a saved view name the same user already has',
		'bulkcat_too_many_views'                 => 'REST refusal code for saving a 51st view',
		'bulkcat_view_not_found'                 => 'REST refusal code for a view id not in the current user\'s bulkcat_views',
		'bulkcat_availability_conflict'          => 'result and refusal code for Published on a product whose status was changed elsewhere while Off (SPEC §5.5); nothing is stored under it',
		'bulkcat_changed_since'                  => 'result code of a queued undo item whose product changed after the undo was planned (SPEC §9.4); nothing is stored under it',
		'bulkcat_register_typed_providers'       => 'opt-in typed provider registration hook (docs/EXTENSIONS.md §19); no data is stored under it',
		'bulkcat_register_providers'             => 'action hook external providers register on (docs/EXTENSIONS.md §3.1); fired once per request, nothing is stored',
		'bulkcat_provider_incompatible'          => 'registration refusal for a provider that breaks the external action contract; it contributes nothing',
		'bulkcat_provider_late'                  => 'registration refusal for a provider registered after bulkcat_register_providers finished',
		'bulkcat_invalid_lookup'                 => 'bounded lookup request refusal; no storage',
		'bulkcat_choice_refresh_required'        => 'choice cursor refresh refusal; no storage',
		'bulkcat_provider_response_invalid'      => 'malformed provider response refusal; no storage',
		'bulkcat_provider_unavailable'           => 'refusal and result code for a change whose provider is missing or unavailable (409); nothing is stored under it',
		'bulkcat_mixed_change'                   => 'refusal code for a change mixing native steps with a provider\'s, or two providers\' (400)',
		'bulkcat_external_not_inline'            => 'refusal code for an external value sent to the single-product write route (400)',
		'bulkcat_changed_during_write'           => 'result code for an external write whose stored value changed underneath it (compare-and-set miss)',
		'bulkcat_unknown_state'                  => 'result code for an external value its provider could not read',
		'bulkcat_external_not_stored'            => 'result code for an external write that did not stick on read-back',
		'bulkcat_external_error'                 => 'result code for a provider call that threw or broke the contract for one object',
		'bulkcat_value_too_large'                => 'result code for an external value above 64 KB encoded',
	];

	/**
	 * Every quoted string literal in the shipped PHP that starts with `bulkcat_`,
	 * mapped to the first file it appears in.
	 *
	 * @return array<string, string>
	 */
	private static function bulkcat_literals(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge(
			(array) glob( $root . '/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE ),
			[ $root . '/uninstall.php', $root . '/bulk-catalogue-manager-for-woocommerce.php' ]
		);
		$found = [];
		foreach ( $files as $file ) {
			preg_match_all( '/[\'"](bulkcat_[A-Za-z0-9_\-]*)/', (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $literal ) {
				$found[ $literal ] ??= substr( $file, strlen( $root ) + 1 );
			}
		}
		return $found;
	}

	public function test_every_bulkcat_key_in_src_is_in_the_registry(): void {
		$registry = Registry::plugin();
		$known    = array_merge(
			$registry->options(),
			$registry->user_meta(),
			$registry->term_meta(),
			array_keys( $registry->tables() ),
			$registry->recurring_actions(),
			array_keys( self::NOT_STORAGE )
		);
		$literals = self::bulkcat_literals();
		$this->assertContains( 'bulkcat_settings', array_keys( $literals ), 'The scan must see the settings option.' );
		$this->assertContains( 'bulkcat_db_version', array_keys( $literals ), 'The scan must see the schema version option.' );

		foreach ( $literals as $literal => $file ) {
			$covered = in_array( $literal, $known, true );
			foreach ( $registry->transient_prefixes() as $prefix ) {
				$covered = $covered || str_starts_with( $literal, $prefix );
			}
			$this->assertTrue( $covered, "\"$literal\" in $file is not in src/Data/Registry.php. Register it there (and in UninstallTest), or list it in NOT_STORAGE with its reason." );
		}
	}

	public function test_the_scan_would_catch_an_unregistered_key(): void {
		$this->assertSame( 1, preg_match_all( '/[\'"](bulkcat_[A-Za-z0-9_\-]*)/', "update_user_meta( \$id, 'bulkcat_views', \$v );", $m ) );
		$this->assertSame( [ 'bulkcat_views' ], $m[1] );
		$this->assertContains( 'bulkcat_views', Registry::plugin()->user_meta(), 'T52 registers bulkcat_views.' );
	}

	public function test_the_plugin_registry_holds_its_options_and_the_prune_action(): void {
		$registry = Registry::plugin();
		$this->assertSame( [ 'bulkcat_settings', 'bulkcat_db_version', 'bulkcat_request_epoch' ], $registry->options() );
		$this->assertSame( [ 'bulkcat_prune_history', 'bulkcat_prune_selections', 'bulkcat_prune_requests' ], $registry->recurring_actions() );
		$this->assertSame( 'bulk-catalogue-manager-for-woocommerce', Registry::ACTION_GROUP, 'The full slug, so uninstall can never cancel another plugin\'s actions.' );
	}

	public function test_post_meta_is_registered_under_its_own_underscore_rule(): void {
		// T88: the Off record is protected product meta, so its name starts `_bulkcat_`.
		$this->assertSame( [ '_bulkcat_availability_off' ], Registry::plugin()->post_meta() );
		$this->assertSame( [ '_bulkcat_x' ], ( new Registry( [], [], [], [], [], [], [ '_bulkcat_x', '_bulkcat_x' ] ) )->post_meta() );
		$this->assertSame( [], ( new Registry( [], [], [], [], [], [] ) )->post_meta(), 'Post meta is optional.' );
		foreach ( [ 'bulkcat_x', '_bulkcat_', '__bulkcat_x', '_bulkcat_X', '_other_x', '_bulkcat_x; DROP' ] as $name ) {
			try {
				new Registry( [], [], [], [], [], [], [ $name ] );
				$this->fail( "\"$name\" was accepted as post meta." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( '_bulkcat_', $e->getMessage() );
			}
		}
		// The other kinds keep their bulkcat_ rule: an underscore name is refused there.
		try {
			new Registry( [], [ '_bulkcat_x' ], [], [], [], [] );
			$this->fail( 'An underscore name was accepted as an option.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'must be bulkcat_', $e->getMessage() );
		}
	}

	public function test_every_underscore_bulkcat_key_in_src_is_registered_post_meta(): void {
		$root  = dirname( __DIR__, 3 );
		$found = [];
		foreach ( (array) glob( $root . '/src/{,*/,*/*/}*.php', GLOB_BRACE ) as $file ) {
			preg_match_all( '/[\'"](_bulkcat_[A-Za-z0-9_\-]*)/', (string) file_get_contents( $file ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
			foreach ( $matches[1] as $literal ) {
				$found[ $literal ] = true;
			}
		}
		$this->assertSame( [ '_bulkcat_availability_off' ], array_keys( $found ) );
		$this->assertSame( array_keys( $found ), Registry::plugin()->post_meta() );
	}

	public function test_names_outside_bulkcat_are_refused(): void {
		$bad = [ 'woocommerce_x', 'bulkcat_', 'BULKCAT_X', 'bulkcat_x; DROP TABLE wp_users', 'bulkcat_x`', 'wp_bulkcat_x', 'bulkcat-x' ];
		foreach ( $bad as $name ) {
			foreach ( range( 0, 5 ) as $slot ) {
				$args = [ [], [], [], [], [], [] ];
				if ( 0 === $slot ) {
					$args[0] = [ $name => 'id INT' ];
				} else {
					$args[ $slot ] = [ $name ];
				}
				try {
					new Registry( ...$args );
					$this->fail( "\"$name\" was accepted in slot $slot." );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( 'must be bulkcat_', $e->getMessage() );
				}
			}
		}
	}

	public function test_valid_names_are_kept_once_in_order(): void {
		$registry = new Registry( [ 'bulkcat_t' => 'id INT' ], [ 'bulkcat_a', 'bulkcat_b', 'bulkcat_a' ], [ 'bulkcat_u' ], [ 'bulkcat_m' ], [ 'bulkcat_count_' ], [ 'bulkcat_r' ] );
		$this->assertSame( [ 'bulkcat_t' => 'id INT' ], $registry->tables() );
		$this->assertSame( [ 'bulkcat_a', 'bulkcat_b' ], $registry->options() );
		$this->assertSame( [ 'bulkcat_u' ], $registry->user_meta() );
		$this->assertSame( [ 'bulkcat_m' ], $registry->term_meta() );
		$this->assertSame( [ 'bulkcat_count_' ], $registry->transient_prefixes() );
		$this->assertSame( [ 'bulkcat_r' ], $registry->recurring_actions() );
	}
}
