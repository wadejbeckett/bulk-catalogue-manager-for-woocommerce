<?php
/**
 * Action contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Action contract. */
interface Action {
	/**
	 * Describe.
	 */
	public function describe(): ActionDescriptor;
	/**
	 * Availability.
	 * @param Scope $scope Contract value.
	 * @param array $args Contract value.
	 * @param Context $context Contract value.
	 */
	public function availability( Scope $scope, array $args, Context $context ): bool|\WP_Error;
	/**
	 * Validate.
	 * @param Scope $scope Contract value.
	 * @param array $args Contract value.
	 * @param Context $context Contract value.
	 */
	public function validate( Scope $scope, array $args, Context $context ): Snapshot|\WP_Error;
	/**
	 * Compute.
	 * @param Subject $subject Contract value.
	 * @param ValidatedStep $step Contract value.
	 * @param Context $context Contract value.
	 */
	public function compute( Subject $subject, ValidatedStep $step, Context $context ): Computation;
}
