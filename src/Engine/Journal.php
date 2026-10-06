<?php
/**
 * The journal (SPEC §9.3, §12.3, §12.6; T33 and T29): what the writer did to
 * one object, written to `bulkcat_changes` from inside the writer's own code
 * path — never from a hook after the fact — so every saved change has its
 * before state beside it and undo can put it back.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the per-field journal rows for one object.
 *
 * The writer calls it per object:
 * - already_done() before loading the object: an object that already has
 *   journal rows for this batch was dealt with by an earlier run of the same
 *   chunk, so it is skipped and a retried percentage increase cannot apply
 *   twice — unless some of those rows are intent rows (intents()), when the
 *   writer replays their recorded values instead (Writer, §12.3);
 * - modified_gmt() straight after loading it, before any value is set: the
 *   object's post_modified_gmt, read through the registry's `date_modified`
 *   field, which every row records as the time the object had when the
 *   plugin wrote it;
 * - intend() just before save(), writing every row with each changed field
 *   as an intent row holding the value about to be saved, then settle()
 *   after save() returns or throws, turning each intent row into the row
 *   below — so a run killed after WooCommerce saved but before the journal
 *   caught up leaves a record of exactly what it was writing; or record()
 *   instead, when nothing is saved, with every Result and the switch *Skip
 *   rows where the value is already correct* of the step that produced it.
 *
 * record() writes at most one row per field, and none for a field no step
 * named. For each field, in the order the steps first reached it:
 * - a change that was saved writes one `applied` row: the value before the
 *   first step (the writer's before state, read through the registry) and
 *   the value after save(), read through the registry again (a term list
 *   from the stored terms, Field::read_stored()); a later step's
 *   refusal on the same field goes in its message;
 * - otherwise a failed step writes a `failed` row, and a skipped step a
 *   `skipped` row, each with its plain reason;
 * - otherwise every step found the value already correct (T29). With the
 *   step's skip switch on (the default) nothing is written — the row is not
 *   journalled and counts as "already at the target value". With it off, a
 *   `skipped` row saying so is written only when the setting *Record
 *   unchanged rows in the audit log* is on. The two controls are distinct:
 *   the switch travels per action in the batch's operations, the setting is
 *   the shop's.
 *
 * Inline edits, detail-panel saves and term writes are one-item batches with
 * their own `source` on the batch row; they come through the same writer
 * and this same code, so they journal identically.
 *
 * Values are stored through ChangeRepository, which encodes them with
 * ValueCodec, so '' and '0' and null come back as themselves.
 */
final class Journal {
	/**
	 * The code on a step skipped because this batch already journalled the object.
	 */
	public const ALREADY_DONE = 'bulkcat_already_done';

	/**
	 * Build over the change repository.
	 *
	 * @param ChangeRepository $changes          The journal table.
	 * @param Fields           $fields           The field registry every value is read through.
	 * @param bool             $record_unchanged The setting *Record unchanged rows in the audit log*.
	 */
	public function __construct(
		private readonly ChangeRepository $changes,
		private readonly Fields $fields,
		private readonly bool $record_unchanged
	) {}

	/**
	 * A journal following the shop's settings.
	 *
	 * @param Fields                $fields   The field registry.
	 * @param Settings|null         $settings The settings; loaded when omitted.
	 * @param ChangeRepository|null $changes  The journal table; the plugin's own when omitted.
	 */
	public static function from_settings( Fields $fields, ?Settings $settings = null, ?ChangeRepository $changes = null ): self {
		return new self( $changes ?? new ChangeRepository(), $fields, ( $settings ?? Settings::load() )->record_unchanged_rows() );
	}

	/**
	 * Whether the batch already journalled this object, so a retry skips it.
	 *
	 * @param int $batch_id  Batch.
	 * @param int $object_id Product, variation or term id.
	 */
	public function already_done( int $batch_id, int $object_id ): bool {
		return $this->changes->has_object( $batch_id, $object_id );
	}

	/**
	 * The object's post_modified_gmt as it is now, `Y-m-d H:i:s`, read
	 * through the registry; ChangeRepository::UNKNOWN_TIME when there is none.
	 *
	 * @param object|null $product The loaded object, or null when it could not be loaded.
	 */
	public function modified_gmt( ?object $product ): string {
		$field = $this->fields->get( 'date_modified' );
		$value = null === $product || null === $field ? null : $field->read( $product );
		if ( ! is_string( $value ) || '' === $value ) {
			return ChangeRepository::UNKNOWN_TIME;
		}
		try {
			return ( new \DateTimeImmutable( $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return ChangeRepository::UNKNOWN_TIME;
		}
	}

	/**
	 * The journal's object type for a loaded object.
	 *
	 * @param object|null $product The loaded object, or null.
	 */
	public static function object_type( ?object $product ): string {
		return null !== $product && method_exists( $product, 'is_type' ) && true === $product->is_type( 'variation' ) ? 'variation' : 'product';
	}

	/**
	 * The fields this batch was writing to an object when a run stopped
	 * between its intent rows and their settlement (§12.3), with the value
	 * before and the value being written; empty when there are none.
	 *
	 * @param int $batch_id  Batch.
	 * @param int $object_id Object.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	public function intents( int $batch_id, int $object_id ): array {
		return $this->changes->intents( $batch_id, $object_id );
	}

	/**
	 * Write one object's rows before save() (§12.3): exactly the rows
	 * record() would write, in the same order, except that a field with a
	 * change gets an intent row (Schema::CHANGE_INTENT) holding the value
	 * about to be saved. settle() turns those into their final rows once
	 * save() has returned or thrown; a run killed in between leaves them for
	 * the retry.
	 *
	 * @param int                             $batch_id     Batch.
	 * @param int                             $object_id    Object id.
	 * @param string                          $object_type  product, variation or term.
	 * @param string                          $modified_gmt What modified_gmt() answered before the write.
	 * @param list<array{0: Result, 1: bool}> $entries      Every Result in order, with its step's skip switch.
	 * @param array<string, mixed>            $before       Each named field's value before the first step.
	 * @return list<string> The fields given an intent row.
	 */
	public function intend( int $batch_id, int $object_id, string $object_type, string $modified_gmt, array $entries, array $before ): array {
		$intended = [];
		foreach ( self::by_field( $entries ) as $key => $steps ) {
			$changes = self::in_state( array_column( $steps, 0 ), Result::CHANGED );
			if ( [] !== $changes ) {
				$old = array_key_exists( $key, $before ) ? $before[ $key ] : $changes[0]->old();
				$this->changes->append( $batch_id, $object_id, $object_type, $key, $old, end( $changes )->new(), $modified_gmt, Schema::CHANGE_INTENT );
				$intended[] = $key;
				continue;
			}
			$row = $this->row( $key, $steps, $before, null );
			if ( null !== $row ) {
				[ $old, $new_value, $state, $message ] = $row;
				$this->changes->append( $batch_id, $object_id, $object_type, $key, $old, $new_value, $modified_gmt, $state, $message );
			}
		}
		return $intended;
	}

	/**
	 * Settle the intent rows intend() wrote, once save() has returned (the
	 * saved object) or thrown (null, the entries then reporting the failure):
	 * each becomes the row record() would have written.
	 *
	 * @param int                             $batch_id  Batch.
	 * @param int                             $object_id Object id.
	 * @param list<array{0: Result, 1: bool}> $entries   Every Result in order, with its step's skip switch.
	 * @param array<string, mixed>            $before    Each named field's value before the first step.
	 * @param object|null                     $saved     The saved object, or null.
	 * @param list<string>                    $intended  What intend() returned.
	 * @return int Rows settled.
	 */
	public function settle( int $batch_id, int $object_id, array $entries, array $before, ?object $saved, array $intended ): int {
		$settled  = 0;
		$by_field = self::by_field( $entries );
		foreach ( $intended as $key ) {
			$row = isset( $by_field[ $key ] ) ? $this->row( $key, $by_field[ $key ], $before, $saved ) : null;
			if ( null !== $row ) {
				[ , $new_value, $state, $message ] = $row;
				$settled                          += $this->changes->settle( $batch_id, $object_id, $key, $state, $new_value, $message );
			}
		}
		return $settled;
	}

	/**
	 * Settle one intent row directly (a retry, Writer::replay()).
	 *
	 * @param int    $batch_id  Batch.
	 * @param int    $object_id Object id.
	 * @param string $key       Field key.
	 * @param string $state     applied or failed.
	 * @param mixed  $new_value The value after the write.
	 * @param string $message   Reason, for a failure.
	 */
	public function settle_one( int $batch_id, int $object_id, string $key, string $state, mixed $new_value, string $message = '' ): int {
		return $this->changes->settle( $batch_id, $object_id, $key, $state, $new_value, $message );
	}

	/**
	 * Write one object's rows.
	 *
	 * @param int                           $batch_id     Batch.
	 * @param int                           $object_id    Object id.
	 * @param string                        $object_type  product, variation or term.
	 * @param string                        $modified_gmt What modified_gmt() answered before the write.
	 * @param list<array{0: Result, 1: bool}> $entries    Every Result in order, with its step's skip switch.
	 * @param array<string, mixed>          $before       Each named field's value before the first step.
	 * @param object|null                   $saved        The object after save(), or null when it was not saved.
	 * @return int The number of rows written.
	 */
	public function record( int $batch_id, int $object_id, string $object_type, string $modified_gmt, array $entries, array $before, ?object $saved ): int {
		$written = 0;
		foreach ( self::by_field( $entries ) as $key => $steps ) {
			$row = $this->row( $key, $steps, $before, $saved );
			if ( null === $row ) {
				continue;
			}
			[ $old, $new_value, $state, $message ] = $row;
			$this->changes->append( $batch_id, $object_id, $object_type, $key, $old, $new_value, $modified_gmt, $state, $message );
			++$written;
		}
		return $written;
	}

	/**
	 * The one row for a field, or null when it writes none.
	 *
	 * @param string                          $key    Field key.
	 * @param list<array{0: Result, 1: bool}> $steps  The field's Results, in order, with their skip switch.
	 * @param array<string, mixed>            $before Before values.
	 * @param object|null                     $saved  The saved object, or null.
	 * @return array{0: mixed, 1: mixed, 2: string, 3: string}|null [ old, new, result, message ].
	 */
	private function row( string $key, array $steps, array $before, ?object $saved ): ?array {
		$results = array_column( $steps, 0 );
		$first   = $results[0];
		$old     = array_key_exists( $key, $before ) ? $before[ $key ] : $first->old();
		$changes = self::in_state( $results, Result::CHANGED );
		$refused = array_merge( self::in_state( $results, Result::FAILED ), self::in_state( $results, Result::SKIPPED ) );

		if ( [] !== $changes && null !== $saved ) {
			$field = $this->fields->get( $key );
			// What WordPress stored, not the object's list (ticket 133 bug 2: WooCommerce keeps a removed term's tail on it).
			$new_value = null !== $field ? $field->read_stored( $saved ) : end( $changes )->new();
			return [ $old, $new_value, 'applied', [] !== $refused ? $refused[0]->reason() : '' ];
		}
		$failed = self::in_state( $results, Result::FAILED );
		if ( [] !== $failed ) {
			return [ $old, $old, 'failed', $failed[0]->reason() ];
		}
		$skipped = self::in_state( $results, Result::SKIPPED );
		if ( [] !== $skipped ) {
			return [ $old, $old, 'skipped', $skipped[0]->reason() ];
		}

		// Every step found the value already correct (T29).
		$asked_to_record = [] !== array_filter( array_column( $steps, 1 ), static fn( bool $skip ): bool => ! $skip );
		if ( ! $asked_to_record || ! $this->record_unchanged ) {
			return null;
		}
		return [ $old, $old, 'skipped', __( 'This was already at the value asked for, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) ];
	}

	/**
	 * Entries grouped by field, in the order the steps first reached each.
	 *
	 * @param list<array{0: Result, 1: bool}> $entries Every Result with its step's skip switch.
	 * @return array<string, list<array{0: Result, 1: bool}>>
	 */
	private static function by_field( array $entries ): array {
		$by_field = [];
		foreach ( $entries as [ $result, $skip_already ] ) {
			$by_field[ $result->field() ][] = [ $result, $skip_already ];
		}
		return $by_field;
	}

	/**
	 * The Results in one state.
	 *
	 * @param list<Result> $results Results.
	 * @param string       $state   One of Result::STATES.
	 * @return list<Result>
	 */
	private static function in_state( array $results, string $state ): array {
		return array_values( array_filter( $results, static fn( Result $r ): bool => $state === $r->state() ) );
	}
}
