<?php
/**
 * A provider (docs/EXTENSIONS.md §2, §4): a separately installed plugin's
 * object declaring the values it owns (external fields) and the actions
 * that change them (external actions). The core previews, applies,
 * journals and reverses those changes; the provider holds their logic and
 * their storage.
 *
 * Every method is called inside the core's guard: an exception becomes a
 * refusal of that request or a failed result for that object, logged to
 * the WooCommerce log and never shown to the browser.
 *
 * PHP 8.1 has no `true` type, so the methods documented as returning
 * `true|\WP_Error` are declared `bool|\WP_Error`; the core treats `false`
 * as a refusal with a generic sentence.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * A provider of external fields and actions, contract version 1.
 */
interface Provider {
	/**
	 * The provider id: `[a-z][a-z0-9]{2,19}`, no underscore, unique on the site.
	 */
	public function id(): string;

	/**
	 * The provider's name, translated plain text (at most 80 characters).
	 */
	public function label(): string;

	/**
	 * The provider plugin's own version, recorded with every batch it makes.
	 */
	public function version(): string;

	/**
	 * The contract version it implements; one of Contract::SUPPORTED.
	 */
	public function contract(): int;

	/**
	 * Whether its dependencies and configuration let it work now: true, or a
	 * WP_Error whose message is the plain reason shown beside its actions.
	 *
	 * @return bool|\WP_Error True, or the reason it is unavailable.
	 */
	public function availability(): bool|\WP_Error;

	/**
	 * The values it owns, at most five.
	 *
	 * @return list<ExternalField>
	 */
	public function fields(): array;

	/**
	 * The actions it offers, at most twenty, in the order the chooser lists them.
	 *
	 * @return list<ExternalAction>
	 */
	public function actions(): array;

	/**
	 * Chooser groups of its own, at most two; may be empty.
	 *
	 * @return list<ChooserGroup>
	 */
	public function groups(): array;

	/**
	 * Whether a user may have this provider act on one object. Called after
	 * the core's own edit check and the action's capability, so it can only
	 * narrow them (docs/EXTENSIONS.md §9).
	 *
	 * @param int     $user_id The user the work is done for.
	 * @param Subject $subject The object.
	 * @return bool|\WP_Error True, or the plain reason the object is skipped.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error;

	/**
	 * A deterministic fingerprint of everything outside the per-object values
	 * that the steps' computation depends on (offered choices, configuration,
	 * cheap aggregate markers). Bound into the preview hash; Apply recomputes
	 * it and asks for a new preview when it differs (§7.2). Writes nothing.
	 *
	 * @param Scope                                                    $scope   The available members of the change.
	 * @param list<array{action: string, args: array<string, mixed>}> $steps   The normalised steps.
	 * @param Context                                                  $context The run.
	 */
	public function state_token( Scope $scope, array $steps, Context $context ): string;

	/**
	 * Load whatever read() and compute() need for these objects in bulk,
	 * before they are read. Writes nothing; may do nothing.
	 *
	 * @param list<int> $object_ids Products and variations.
	 */
	public function prime( array $object_ids ): void;

	/**
	 * Called once after a run that wrote (a chunk, an undo chunk), with the
	 * objects written, even when the run threw: clear the provider's own caches.
	 *
	 * @param list<int> $object_ids Objects written.
	 * @param Context   $context    The run.
	 */
	public function flush( array $object_ids, Context $context ): void;
}
