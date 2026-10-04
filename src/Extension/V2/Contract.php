<?php
/**
 * Opt-in version negotiation; declarations do not qualify runtime support.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Typed contract identity. */
final class Contract {
	/** Contract version. */
	public const VERSION = 2;
	/** Supported declaration versions. */
	public const SUPPORTED = [ 2 ];
	/** Independent registration hook. */
	public const HOOK = 'bulkcat_register_typed_providers';
	/** Qualified runtime capabilities only; S6/P2/C3 have not qualified any. */
	public const CAPABILITIES = [];
}
