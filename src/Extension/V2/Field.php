<?php
/**
 * Field contract, opt-in version 2.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** Field contract. */
interface Field {
	/**
	 * Key.
	 */
	public function key(): string;
	/**
	 * Label.
	 */
	public function label(): string;
	/**
	 * Target kinds.
	 */
	public function target_kinds(): array;
	/**
	 * Variation rule.
	 */
	public function variation_rule(): string;
	/**
	 * Format.
	 */
	public function format(): int;
	/**
	 * Read.
	 * @param Subject $subject Contract value.
	 */
	public function read( Subject $subject ): mixed;
	/**
	 * Same.
	 * @param mixed $a Contract value.
	 * @param mixed $b Contract value.
	 */
	public function same( mixed $a, mixed $b ): bool;
	/**
	 * Describe.
	 * @param mixed $value Contract value.
	 * @param Subject $subject Contract value.
	 */
	public function describe( mixed $value, Subject $subject ): string;
	/**
	 * Describe change.
	 * @param mixed $old Contract value.
	 * @param mixed $new_value Contract value.
	 * @param Subject $subject Contract value.
	 */
	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string;
	/**
	 * Upgrade.
	 * @param mixed $value Contract value.
	 * @param int $from_format Contract value.
	 */
	public function upgrade( mixed $value, int $from_format ): mixed;
	/**
	 * Upgrade context.
	 * @param Snapshot $snapshot Contract value.
	 */
	public function upgrade_context( Snapshot $snapshot ): Snapshot|\WP_Error;
	/**
	 * Write.
	 * @param Subject $subject Contract value.
	 * @param mixed $target Contract value.
	 * @param mixed $expected Contract value.
	 * @param WriteContext $context Contract value.
	 */
	public function write( Subject $subject, mixed $target, mixed $expected, WriteContext $context ): bool|\WP_Error;
	/**
	 * Reconcile.
	 * @param Subject $subject Contract value.
	 * @param mixed $current Contract value.
	 * @param WriteContext $context Contract value.
	 */
	public function reconcile( Subject $subject, mixed $current, WriteContext $context ): ReconcileDecision|\WP_Error;
	/**
	 * Restore.
	 * @param Subject $subject Contract value.
	 * @param mixed $current Contract value.
	 * @param RecoveryContext $context Contract value.
	 */
	public function restore( Subject $subject, mixed $current, RecoveryContext $context ): RestoreDecision|\WP_Error;
}
