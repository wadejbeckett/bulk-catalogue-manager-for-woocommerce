<?php
/**
 * Provider contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Provider contract. */
interface Provider {
	/**
	 * Id.
	 */
	public function id(): string;
	/**
	 * Identity.
	 */
	public function identity(): string;
	/**
	 * Label.
	 */
	public function label(): string;
	/**
	 * Version.
	 */
	public function version(): string;
	/**
	 * Availability.
	 */
	public function availability(): bool|\WP_Error;
	/**
	 * Fields.
	 */
	public function fields(): array;
	/**
	 * Actions.
	 */
	public function actions(): array;
	/**
	 * Groups.
	 */
	public function groups(): array;
	/**
	 * Legacy.
	 */
	public function legacy(): ?\BulkCatalogueManager\Extension\Provider;
	/**
	 * Legacy discovery.
	 */
	public function legacy_discovery(): array;
	/**
	 * Authorise.
	 * @param int $user_id Contract value.
	 * @param Subject $subject Contract value.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error;
	/**
	 * State token.
	 * @param Scope $scope Contract value.
	 * @param array $steps Contract value.
	 * @param Context $context Contract value.
	 */
	public function state_token( Scope $scope, array $steps, Context $context ): string;
	/**
	 * Prime.
	 * @param array $targets Contract value.
	 * @param Context $context Contract value.
	 */
	public function prime( array $targets, Context $context ): void;
	/**
	 * Finalise.
	 * @param FinalisationGroup $group Contract value.
	 * @param Context $context Contract value.
	 */
	public function finalise( FinalisationGroup $group, Context $context ): bool|\WP_Error;
}
