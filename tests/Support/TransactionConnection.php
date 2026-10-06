<?php
/**
 * Pure PHP connection metadata; native races separately prove mysqli and locks.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Support;

final class TransactionConnection {
	public int $errno     = 0;
	public int $thread_id = 41;
	public bool $closed   = false;
}
