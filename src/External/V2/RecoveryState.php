<?php
/** Immutable directional recovery context. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
defined( 'ABSPATH' ) || exit;
/** Original provenance never changes when the direction changes. */
final class RecoveryState implements API\RecoveryContext {
	private readonly RunContext $context;
	public function __construct( private readonly API\FieldHistory $original, private readonly mixed $expected, private readonly mixed $target, private readonly bool $force, private readonly string $direction, int $user_id, int $batch_id ) {
		Rules::ensure( in_array( $direction, [ 'undo', 'redo' ], true ), 'Invalid recovery direction.' );
		Rules::json( $expected, 65536 );
		Rules::json( $target, 65536 );
		$this->context = new RunContext( $direction, $user_id, $batch_id );
	}
	public function original(): API\FieldHistory {
		return $this->original; }
	public function expected(): mixed {
		return $this->expected; }
	public function target(): mixed {
		return $this->target; }
	public function force(): bool {
		return $this->force; }
	public function direction(): string {
		return $this->direction; }
	public function mode(): string {
		return $this->context->mode(); }
	public function user_id(): int {
		return $this->context->user_id(); }
	public function batch_id(): int {
		return $this->context->batch_id(); }
}
