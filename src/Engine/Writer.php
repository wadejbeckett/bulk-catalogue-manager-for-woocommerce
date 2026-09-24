<?php
/**
 * The single write path (SPEC §8, §12.3): applies a validated, normalised
 * operation list to one object through WooCommerce CRUD. The chunk runner,
 * the single-object route, inline edits, the detail panel, term writes and
 * undo all come through here; the writer schedules nothing of its own.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Operations\WriteRefused;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Applies operations to one object through WooCommerce CRUD.
 *
 * For one object it:
 * 1. loads it with wc_get_product() — an id that is not a product fails
 *    every operation with a plain reason and writes nothing;
 * 2. reads, through the field registry, the value of every field the list
 *    names, before anything is set (the before state the journal needs);
 * 3. for each operation in list order, computes a Result against the live
 *    object — so a later step sees an earlier step's value — passes it
 *    through Operations\Validator::check() (permission, the field's own
 *    refusal, the per-run SKU claim and the sale/regular price pair; the
 *    same check the preview runs), and applies every Result the check
 *    returns: the step's through the operation's apply() — typed CRUD
 *    setters through Field::write(), term lists through
 *    wp_set_object_terms() — and the sale-price clear `clear_invalid_sale`
 *    adds after a regular price change through that field's write(). A
 *    refused or skipped Result is reported and not applied. A WriteRefused
 *    (or a WooCommerce setter's WC_Data_Exception) becomes a failed Result
 *    with the refusal's sentence, and the list carries on;
 * 4. saves once, with save(), only when at least one Result is still a
 *    change. A failure after earlier changes therefore still saves those
 *    changes and reports the failure beside them; an object whose only
 *    change was refused is not saved. Nothing here calls update_post_meta()
 *    or any other meta or SQL write for a product field;
 * 5. after a variation is saved, resynchronises its variable parent's price
 *    range and stock summary through WooCommerce's own data store with
 *    WC_Product_Variable::sync() — never by writing parent meta.
 *
 * Parent resynchronisation happens once per saved variation, immediately.
 * Inside run(), it is deferred to the end of the run and done once per
 * parent, so a chunk writing twenty variations of one product syncs that
 * product once, after the last of them (T34 wraps each chunk in run()).
 *
 * The before-save hook (T33's journal) is called once per object that is
 * about to be saved, immediately before save(), with the object, the keys
 * of the fields a change touched and each of those fields' value before the
 * first operation ran. The object then already holds the new, unsaved
 * values, so the before state comes from the third argument; its dates are
 * still the stored ones. Nothing is called for an object that is not saved.
 *
 * Permission is checked for the injected user id (background work: the
 * batch's recorded user) or, without one, the current user (request paths).
 * One Validator serves one run(), so a SKU given to one object in a chunk
 * clashes with the same SKU on another object of that chunk; a write()
 * outside run() is its own run.
 *
 * The journal (T33, T29). A writer built with a Journal and a batch id
 * journals every object from inside write(), not from a hook afterwards:
 * before loading the object it asks Journal::already_done() and skips an
 * object this batch already journalled (every step reported skipped with
 * Journal::ALREADY_DONE, nothing loaded, computed or saved), so a retried
 * chunk cannot apply a percentage increase twice; straight after loading it
 * records the object's post_modified_gmt; just before save() it writes the
 * object's rows through Journal::intend(), each changed field as an intent
 * row holding the value about to be saved, and settles them through
 * Journal::settle() once save() returns or throws — or, when nothing is
 * saved, it writes one row per field the steps named through
 * Journal::record() — with each Result and its step's `skip_already`
 * switch. A retry that finds intent rows (the run was killed between
 * WooCommerce's save and the settlement) computes nothing: replay() writes
 * each recorded absolute value, or leaves it when the object already holds
 * it, and settles the rows (§12.3). An `already` Result is never saved; whether it is journalled
 * follows the switch and the shop's *Record unchanged rows* setting (see
 * Journal). Inline edits, detail-panel saves and term writes use the same
 * writer for their one-item batch, so they journal identically.
 *
 * An exception from save() does not stop the caller: the object's changes
 * are reported failed with a plain reason (code SAVE_FAILED), nothing is
 * resynchronised, and the journal writes failed rows. Term lists are written
 * by wp_set_object_terms() before save(), so after a failed save a term
 * change may have stuck although it is reported failed. An exception from
 * the before-save hook or from the journal itself is not caught.
 */
final class Writer {
	/**
	 * The refusal code for an id that is not a product.
	 */
	public const NOT_FOUND = 'bulkcat_not_found';

	/**
	 * The code for a value WooCommerce's own setter refused.
	 */
	public const INVALID = 'bulkcat_invalid_value';

	/**
	 * The code for a change lost because WooCommerce could not save the object.
	 */
	public const SAVE_FAILED = 'bulkcat_save_failed';

	/**
	 * Called with ( object, touched field keys, before values ) just before save().
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $before_save;

	/**
	 * How many run() calls are open.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Variable parents waiting for their resynchronisation at the end of the run, as keys.
	 *
	 * @var array<int, true>
	 */
	private array $pending = [];

	/**
	 * The open run's validator, or null outside run().
	 *
	 * @var Validator|null
	 */
	private ?Validator $validator = null;

	/**
	 * Build over the operation registry.
	 *
	 * @param Registry      $operations  Resolves each step's action and the field registry.
	 * @param callable|null $before_save Journal hook: fn( object $product, list<string> $fields, array<string, mixed> $before ): void.
	 * @param int|null      $user_id     The batch's recorded user for background work, or null to check the current user.
	 * @param Journal|null  $journal     The journal every object is recorded in, or null to journal nothing.
	 * @param int           $batch_id    The batch the journal rows belong to; required with a journal.
	 * @throws \LogicException When a journal is given without a batch.
	 */
	public function __construct(
		private readonly Registry $operations,
		?callable $before_save = null,
		private readonly ?int $user_id = null,
		private readonly ?Journal $journal = null,
		private readonly int $batch_id = 0
	) {
		if ( null !== $journal && $batch_id < 1 ) {
			throw new \LogicException( esc_html( 'A journalling writer needs the batch its rows belong to.' ) );
		}
		$this->before_save = null === $before_save ? null : \Closure::fromCallable( $before_save );
	}

	/**
	 * A writer that journals every object in one batch: a bulk batch's chunk,
	 * or an inline edit, detail-panel save or term write as a one-item batch.
	 *
	 * @param Registry $operations The operation registry.
	 * @param Journal  $journal    The journal.
	 * @param int      $batch_id   The batch.
	 * @param int|null $user_id    The batch's recorded user for background work, or null for the current user.
	 */
	public static function for_batch( Registry $operations, Journal $journal, int $batch_id, ?int $user_id = null ): self {
		return new self( $operations, null, $user_id, $journal, $batch_id );
	}

	/**
	 * Apply an operation list to one object.
	 *
	 * @param int                                                $id         Product or variation id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list Registry::validate_list() returned.
	 * @throws \LogicException When the list was not validated.
	 */
	public function write( int $id, array $operations ): WriteOutcome {
		// Background work acts for the batch's user: their unfiltered_html decides, as in their own edit.
		return null === $this->user_id ? $this->write_one( $id, $operations ) : Field::acting_as( $this->user_id, fn(): WriteOutcome => $this->write_one( $id, $operations ) );
	}

	/**
	 * Apply an operation list to one object, as write() describes.
	 *
	 * @param int                                                $id         Product or variation id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list Registry::validate_list() returned.
	 * @throws \LogicException When the list was not validated.
	 */
	private function write_one( int $id, array $operations ): WriteOutcome {
		$steps = $this->resolve( $operations );
		if ( null !== $this->journal && $this->journal->already_done( $this->batch_id, $id ) ) {
			$intents = $this->journal->intents( $this->batch_id, $id );
			return [] === $intents ? new WriteOutcome( $id, self::done_before( $steps ), false ) : $this->replay( $id, $intents );
		}

		$product = wc_get_product( $id );
		if ( ! is_object( $product ) ) {
			$results = self::not_found( $steps );
			$this->journal( $id, null, '', self::entries( $results, $steps ), [], null );
			return new WriteOutcome( $id, $results, false );
		}

		$modified  = null === $this->journal ? '' : $this->journal->modified_gmt( $product );
		$validator = $this->validator ?? $this->new_validator();
		$before    = $this->before( $product, $steps );
		$entries   = [];
		foreach ( $steps as [ $operation, $args ] ) {
			$skip_already = (bool) ( $args['skip_already'] ?? true );
			$checked      = $validator->check( $product, $operation->compute( $product, $args ), $args );
			foreach ( $checked as $index => $result ) {
				$entries[] = [ 0 === $index ? self::apply( $operation, $result, $product ) : $this->apply_field( $result, $product ), $skip_already ];
				if ( $result->is_changed() && ! array_key_exists( $result->field(), $before ) ) {
					// A field no step names (the sale-price clear): its value before this first change.
					$before[ $result->field() ] = $result->old();
				}
			}
		}
		$results = array_column( $entries, 0 );

		$touched = array_values( array_unique( array_map( static fn( Result $r ): string => $r->field(), array_filter( $results, static fn( Result $r ): bool => $r->is_changed() ) ) ) );
		if ( [] === $touched ) {
			$this->journal( $id, $product, $modified, $entries, $before, null );
			return new WriteOutcome( $id, $results, false );
		}

		$changed_before = array_intersect_key( $before, array_flip( $touched ) );
		if ( null !== $this->before_save ) {
			( $this->before_save )( $product, $touched, $changed_before );
		}
		// Intent rows first (§12.3): a run killed after WooCommerce saved leaves the value it wrote on record.
		$intended = null === $this->journal ? [] : $this->journal->intend( $this->batch_id, $id, Journal::object_type( $product ), $modified, $entries, $before );
		try {
			$product->save();
		} catch ( \Exception $e ) {
			$entries = self::save_failed( $entries );
			$this->settle( $id, $entries, $before, null, $intended );
			return new WriteOutcome( $id, array_column( $entries, 0 ), false );
		}

		$parent_id = self::variable_parent( $product );
		if ( $parent_id > 0 ) {
			$this->sync( $parent_id );
		}
		$this->settle( $id, $entries, $before, $product, $intended );
		return new WriteOutcome( $id, $results, true, $parent_id, $changed_before );
	}

	/**
	 * Finish an object a killed run left with intent rows (§12.3): nothing
	 * is computed again. Each recorded value is compared, through the
	 * field's canonical form, with what the object holds now: a value
	 * already there (the killed run's save landed) is left alone, any other
	 * is written as the recorded absolute value through Field::write() and
	 * saved once. Each intent row is then settled — applied with the value
	 * read back, or failed with the reason — and the variable parent, if
	 * any, resynchronised. The object counts as changed and saved when any
	 * recorded value now stands, whichever run saved it.
	 *
	 * @param int                                          $id      Object id.
	 * @param array<string, array{old: mixed, new: mixed}> $intents Field key => the values the killed run recorded.
	 */
	private function replay( int $id, array $intents ): WriteOutcome {
		$product = wc_get_product( $id );
		$fields  = $this->operations->fields();
		$results = [];
		$written = false;
		if ( ! is_object( $product ) ) {
			$product = null;
		}
		foreach ( $intents as $key => [ 'old' => $old, 'new' => $value ] ) {
			$field = $fields->get( $key );
			if ( null === $product || null === $field ) {
				$results[ $key ] = self::not_found( [ [ null, [ 'field' => $key ] ] ] )[0];
				continue;
			}
			if ( ! Planner::same( $field, $field->read( $product ), $value ) ) {
				$refused = $field->write( $product, $value );
				if ( $refused instanceof \WP_Error ) {
					$code            = (string) $refused->get_error_code();
					$results[ $key ] = Result::failed( $key, $old, $refused->get_error_message(), str_starts_with( $code, 'bulkcat_' ) ? $code : '' );
					continue;
				}
				$written = true;
			}
			$results[ $key ] = Planner::same( $field, $old, $value ) ? Result::already( $key, $value ) : Result::changed( $key, $old, $value );
		}

		if ( $written ) {
			try {
				$product->save();
			} catch ( \Exception $e ) {
				$entries = self::save_failed( array_map( static fn( Result $r ): array => [ $r, true ], array_values( $results ) ) );
				$results = array_combine( array_keys( $results ), array_column( $entries, 0 ) );
			}
		}
		$saved     = [] !== array_filter( $results, static fn( Result $r ): bool => $r->is_changed() );
		$parent_id = $saved ? self::variable_parent( $product ) : 0;
		if ( $parent_id > 0 ) {
			$this->sync( $parent_id );
		}
		foreach ( $results as $key => $result ) {
			$field = $fields->get( $key );
			if ( Result::FAILED === $result->state() ) {
				$this->journal->settle_one( $this->batch_id, $id, $key, 'failed', $intents[ $key ]['old'], $result->reason() );
			} else {
				$this->journal->settle_one( $this->batch_id, $id, $key, 'applied', null !== $field && null !== $product ? $field->read( $product ) : $result->new() );
			}
		}
		$before = [];
		foreach ( $results as $key => $result ) {
			if ( $result->is_changed() ) {
				$before[ $key ] = $result->old();
			}
		}
		return new WriteOutcome( $id, array_values( $results ), $saved, $parent_id, $before );
	}

	/**
	 * Settle the intent rows of an object that was saved, or failed to save.
	 *
	 * @param int                             $id       Object id.
	 * @param list<array{0: Result, 1: bool}> $entries  Every Result with its step's skip switch.
	 * @param array<string, mixed>            $before   Before values.
	 * @param object|null                     $saved    The saved object, or null.
	 * @param list<string>                    $intended The fields given an intent row.
	 */
	private function settle( int $id, array $entries, array $before, ?object $saved, array $intended ): void {
		if ( null !== $this->journal && [] !== $intended ) {
			$this->journal->settle( $this->batch_id, $id, $entries, $before, $saved, $intended );
		}
	}

	/**
	 * Run a unit of work (a chunk) with parent resynchronisation deferred to
	 * its end, once per parent, even when the work throws.
	 *
	 * @param callable $work fn( Writer $writer ): mixed.
	 * @return mixed What the work returned.
	 */
	public function run( callable $work ): mixed {
		if ( 0 === $this->depth ) {
			$this->validator = $this->new_validator();
		}
		++$this->depth;
		try {
			return $work( $this );
		} finally {
			--$this->depth;
			if ( 0 === $this->depth ) {
				$parents         = array_keys( $this->pending );
				$this->pending   = [];
				$this->validator = null;
				foreach ( $parents as $parent_id ) {
					\WC_Product_Variable::sync( $parent_id );
				}
			}
		}
	}

	/**
	 * Resolve each step's operation, refusing a list that was not validated.
	 *
	 * @param array<mixed> $operations The normalised list.
	 * @return list<array{0: Operation, 1: array<string, mixed>}>
	 * @throws \LogicException When a step is malformed or names an unknown action.
	 */
	private function resolve( array $operations ): array {
		$steps = [];
		foreach ( $operations as $entry ) {
			$operation = is_array( $entry ) && is_string( $entry['action'] ?? null ) ? $this->operations->get( $entry['action'] ) : null;
			$args      = is_array( $entry ) ? ( $entry['args'] ?? null ) : null;
			if ( null === $operation || ! is_array( $args ) || ! is_string( $args['field'] ?? null ) ) {
				throw new \LogicException( esc_html( 'The writer takes only a list Registry::validate_list() returned.' ) );
			}
			$steps[] = [ $operation, $args ];
		}
		return $steps;
	}

	/**
	 * Each named field's current value, read through the registry before anything is set.
	 *
	 * @param object                                               $product The loaded object.
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps   Resolved steps.
	 * @return array<string, mixed>
	 */
	private function before( object $product, array $steps ): array {
		$before = [];
		foreach ( $steps as [ , $args ] ) {
			$field = $this->operations->fields()->get( $args['field'] );
			if ( null !== $field && ! array_key_exists( $field->key(), $before ) ) {
				$before[ $field->key() ] = $field->read( $product );
			}
		}
		return $before;
	}

	/**
	 * Apply a computed result; a refusal at write time becomes a failed result.
	 *
	 * @param Operation $operation The step's operation.
	 * @param Result    $result    What it computed.
	 * @param object    $product   The live object.
	 */
	private static function apply( Operation $operation, Result $result, object $product ): Result {
		if ( ! $result->is_changed() ) {
			return $result;
		}
		try {
			$operation->apply( $result, $product );
			return $result;
		} catch ( WriteRefused $refused ) {
			$code = (string) $refused->error()->get_error_code();
			return Result::failed( $result->field(), $result->old(), $refused->error()->get_error_message(), str_starts_with( $code, 'bulkcat_' ) ? $code : '' );
		} catch ( \WC_Data_Exception $refused ) {
			$reason = trim( $refused->getMessage() );
			return Result::failed(
				$result->field(),
				$result->old(),
				'' !== $reason ? $reason : __( 'WooCommerce did not accept this value. Check it and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				self::INVALID
			);
		}
	}

	/**
	 * A validator for one run: the injected user's, or the current user's.
	 */
	private function new_validator(): Validator {
		$fields = $this->operations->fields();
		return null === $this->user_id ? Validator::for_request( $fields ) : Validator::for_user( $fields, $this->user_id );
	}

	/**
	 * Apply a Result the validator added (the sale-price clear) through its field.
	 *
	 * @param Result $result  The added Result.
	 * @param object $product The live object.
	 * @throws \LogicException When the Result names a field the registry does not have.
	 */
	private function apply_field( Result $result, object $product ): Result {
		if ( ! $result->is_changed() ) {
			return $result;
		}
		$field = $this->operations->fields()->get( $result->field() );
		if ( null === $field ) {
			throw new \LogicException( esc_html( sprintf( 'Field "%s" is not in the registry.', $result->field() ) ) );
		}
		$written = $field->write( $product, $result->new() );
		if ( $written instanceof \WP_Error ) {
			$code = (string) $written->get_error_code();
			return Result::failed( $result->field(), $result->old(), $written->get_error_message(), str_starts_with( $code, 'bulkcat_' ) ? $code : '' );
		}
		return $result;
	}

	/**
	 * Journal one object, when this writer journals.
	 *
	 * @param int                             $id       Object id.
	 * @param object|null                     $product  The loaded object, or null.
	 * @param string                          $modified Its post_modified_gmt before the write ('' when not loaded).
	 * @param list<array{0: Result, 1: bool}> $entries  Every Result with its step's skip switch.
	 * @param array<string, mixed>            $before   Before values.
	 * @param object|null                     $saved    The saved object, or null.
	 */
	private function journal( int $id, ?object $product, string $modified, array $entries, array $before, ?object $saved ): void {
		if ( null === $this->journal ) {
			return;
		}
		if ( '' === $modified ) {
			$modified = $this->journal->modified_gmt( $product );
		}
		$this->journal->record( $this->batch_id, $id, Journal::object_type( $product ), $modified, $entries, $before, $saved );
	}

	/**
	 * Pair each Result with its step's skip switch, one Result per step.
	 *
	 * @param list<Result>                                        $results One per step.
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps   Resolved steps.
	 * @return list<array{0: Result, 1: bool}>
	 */
	private static function entries( array $results, array $steps ): array {
		return array_map( static fn( Result $r, array $step ): array => [ $r, (bool) ( $step[1]['skip_already'] ?? true ) ], $results, $steps );
	}

	/**
	 * Each change turned into a failure, because save() threw.
	 *
	 * @param list<array{0: Result, 1: bool}> $entries Results with their skip switch.
	 * @return list<array{0: Result, 1: bool}>
	 */
	private static function save_failed( array $entries ): array {
		$reason = __( 'WooCommerce could not save this product, so this change was not made. Try again, or open the product to see why.', 'bulk-catalogue-manager-for-woocommerce' );
		return array_map(
			static fn( array $entry ): array => [ $entry[0]->is_changed() ? Result::failed( $entry[0]->field(), $entry[0]->old(), $reason, self::SAVE_FAILED ) : $entry[0], $entry[1] ],
			$entries
		);
	}

	/**
	 * A skipped result for every step of an object this batch already journalled.
	 *
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps Resolved steps.
	 * @return list<Result>
	 */
	private static function done_before( array $steps ): array {
		$reason = __( 'This product was already updated by this change, so it was not changed again.', 'bulk-catalogue-manager-for-woocommerce' );
		return array_map( static fn( array $step ): Result => Result::skipped( $step[1]['field'], null, $reason, Journal::ALREADY_DONE ), $steps );
	}

	/**
	 * A failed result for every step of an object that could not be loaded.
	 *
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps Resolved steps.
	 * @return list<Result>
	 */
	private static function not_found( array $steps ): array {
		$reason = __( 'That product could not be found. It may have been deleted; reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		return array_map( static fn( array $step ): Result => Result::failed( $step[1]['field'], null, $reason, self::NOT_FOUND ), $steps );
	}

	/**
	 * The variable parent of a variation, or 0.
	 *
	 * @param object $product The saved object.
	 */
	private static function variable_parent( object $product ): int {
		if ( ! method_exists( $product, 'is_type' ) || true !== $product->is_type( 'variation' ) ) {
			return 0;
		}
		return max( 0, (int) $product->get_parent_id( 'edit' ) );
	}

	/**
	 * Resynchronise a parent now, or at the end of the open run.
	 *
	 * @param int $parent_id Variable parent id.
	 */
	private function sync( int $parent_id ): void {
		if ( $this->depth > 0 ) {
			$this->pending[ $parent_id ] = true;
			return;
		}
		\WC_Product_Variable::sync( $parent_id );
	}
}
