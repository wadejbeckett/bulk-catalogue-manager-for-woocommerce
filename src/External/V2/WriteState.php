<?php
/** Immutable identity and absolute values for a later durable writer. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
defined( 'ABSPATH' ) || exit;
/** Merely a context: constructing it grants no execution or closure authority. */
final class WriteState implements API\WriteContext {
	private readonly RunContext $context;
	public function __construct( private readonly string $intent_id, private readonly API\FieldHistory $history, private readonly mixed $expected, private readonly mixed $target, private readonly ?API\RecoveryContext $recovery, string $mode, int $user_id, int $batch_id ) {
		Rules::hex( $intent_id, 32 );
		Rules::json( $expected, 65536 );
		Rules::json( $target, 65536 );
		Rules::ensure( in_array( $mode, [ 'apply', 'replay', 'undo', 'redo' ], true ) && $batch_id > 0, 'Invalid write context.' );
		Rules::ensure( ! in_array( $mode, [ 'undo', 'redo' ], true ) || null !== $recovery, 'Recovery requires original context.' );
		if ( null !== $recovery ) {
			Rules::ensure( HistoryCodec::encode( $history ) === HistoryCodec::encode( $recovery->original() ) && $recovery->user_id() === $user_id && $recovery->batch_id() === $batch_id && ( $recovery->direction() === $mode || 'replay' === $mode ), 'Mismatched recovery lineage.' );
		}
		$this->context = new RunContext( $mode, $user_id, $batch_id );
	}
	public function intent_id(): string {
		return $this->intent_id; }
	public function history(): API\FieldHistory {
		return $this->history; }
	public function expected(): mixed {
		return $this->expected; }
	public function target(): mixed {
		return $this->target; }
	public function recovery(): ?API\RecoveryContext {
		return $this->recovery; }
	public function mode(): string {
		return $this->context->mode(); }
	public function user_id(): int {
		return $this->context->user_id(); }
	public function batch_id(): int {
		return $this->context->batch_id(); }
}
