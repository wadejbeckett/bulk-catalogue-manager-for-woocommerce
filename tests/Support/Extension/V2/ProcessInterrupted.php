<?php
/**
 * Neutral typed contract fixture; test-only, excluded from every package.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support\Extension\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\Operations\Result;

/** Process interruption is distinct from a provider refusal. */
final class ProcessInterrupted extends \RuntimeException {}
