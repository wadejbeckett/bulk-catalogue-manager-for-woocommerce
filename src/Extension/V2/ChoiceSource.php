<?php
/**
 * ChoiceSource contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** ChoiceSource contract. */
interface ChoiceSource {
	/**
	 * Search.
	 * @param Lookup $lookup Contract value.
	 * @param Scope $scope Contract value.
	 * @param Context $context Contract value.
	 */
	public function search( Lookup $lookup, Scope $scope, Context $context ): ChoicePage|\WP_Error;
	/**
	 * Resolve.
	 * @param string $argument Contract value.
	 * @param array $values Contract value.
	 * @param array $dependencies Contract value.
	 * @param Scope $scope Contract value.
	 * @param Context $context Contract value.
	 */
	public function resolve( string $argument, array $values, array $dependencies, Scope $scope, Context $context ): array|\WP_Error;
}
