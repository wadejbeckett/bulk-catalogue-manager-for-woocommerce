<?php
/**
 * The recorded actor and execution mode of pure typed work.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\Context;
defined( 'ABSPATH' ) || exit;
/** Immutable context, shared with the later writer and recovery tasks. */
final class RunContext implements Context {
	/**
	 * Retain the authoritative context rather than ambient user state.
	 * @param string $mode Operation mode.
	 * @param int $user_id Recorded actor.
	 * @param int $batch_id Batch, or zero before acceptance.
	 */
	public function __construct( private readonly string $mode, private readonly int $user_id, private readonly int $batch_id ) {
		Rules::ensure( in_array( $mode, [ 'preview','apply','replay','undo','redo','finalise' ], true ) && $user_id > 0 && $batch_id >= 0, 'Invalid typed context.' );
	}
	/** Operation mode. */
	public function mode(): string {
		return $this->mode;}
	/** Recorded actor. */
	public function user_id(): int {
		return $this->user_id;}
	/** Recorded batch. */
	public function batch_id(): int {
		return $this->batch_id;}
}
