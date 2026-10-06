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

use BulkCatalogueManager\Publishing\StatusPermission;
use BulkCatalogueManager\Publishing\StatusTransition;
use BulkCatalogueManager\Publishing\PublicationPassword;
use BulkCatalogueManager\Availability\Stamp;
use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\External\ExternalWriter;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\RestoreStatus;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\StockCoupling;
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
 * Off/On (T89, SPEC §5.5). A step's checked Result is followed by what its
 * operation's follow() returns — the Status switch's Off record — asked
 * through Validator::follow() before anything is applied, so both see the
 * object as it was; the record is applied through its field like the
 * sale-price clear, saved in the same save() and journalled as its own row.
 * If the step itself is refused at write time, nothing follows it. Every
 * object is written inside Availability\Stamp::within( the batch ), so a
 * record an Off writes names the batch writing it. After a save that set the
 * status, the writer reads back the status WordPress stored
 * (StatusSwitch::read_back()), since WooCommerce never does: a publish
 * WordPress kept or made scheduled is then `future` on the object, in the
 * journal and in the outcome, in write() and in replay() alike.
 *
 * An exception from save() does not stop the caller, and does not by itself
 * mean nothing was stored: WooCommerce fires hooks after it has written the
 * values. The exception is logged, the object is loaded afresh and each
 * changed field read back (save_threw()): a field holding the value asked
 * for is reported changed and journalled applied; one still holding its
 * value before is reported failed, not made (code SAVE_FAILED); anything
 * else, or an object that cannot be read, is reported failed as possibly
 * made (code SAVE_UNCERTAIN), its row holding the value read. Term lists are
 * written by wp_set_object_terms() before save(), so they read back as
 * stored. An exception from the before-save hook or from the journal itself
 * is not caught.
 *
 * External changes (T107, docs/EXTENSIONS.md §7.3): a list whose steps are
 * one provider's is written through External\ExternalWriter, one per
 * provider per writer, which journals from inside the same way and never
 * saves the product; its flush() runs once at the end of run() (or after a
 * write() outside one), even when the run throws. A list mixing native
 * steps with a provider's, or two providers', fails every step with
 * `bulkcat_mixed_change` and writes nothing, and a provider that is not
 * registered fails every step with `bulkcat_provider_unavailable`.
 */
final class Writer {
	/**
	 * The refusal code for an id that is not a product.
	 */
	public const NOT_FOUND = 'bulkcat_not_found';

	/**
	 * The code for an object that is no longer the kind it was chosen as (T83).
	 */
	public const CHANGED_KIND = 'bulkcat_changed_kind';

	/**
	 * Code of an object moved to the bin after it was chosen: left alone as unavailable.
	 */
	public const UNAVAILABLE = 'bulkcat_unavailable';

	/**
	 * The code for a value WooCommerce's own setter refused.
	 */
	public const INVALID = 'bulkcat_invalid_value';

	/**
	 * The code for a change lost because WooCommerce could not save the object.
	 */
	public const SAVE_FAILED = 'bulkcat_save_failed';

	/**
	 * The code for a change WooCommerce threw on while saving, whose stored
	 * value is neither the value before nor the value asked for, or could not
	 * be read back: it may or may not have been made.
	 */
	public const SAVE_UNCERTAIN = 'bulkcat_save_uncertain';

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
	 * External writers by provider id (T107), flushed at the end of each run.
	 *
	 * @var array<string, ExternalWriter>
	 */
	private array $externals = [];

	/**
	 * A check each loaded object must pass before anything is computed for
	 * it (T111: an undo item planned again as it stands), or null.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $guard = null;

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
	 * Check each object before it is written (T111, SPEC §9.4): the check
	 * gets the id and the object just loaded, after the retry guard (an
	 * object this batch already journalled is finished as before, never
	 * checked), and answers null to go on or the plain reason the object was
	 * changed since; then the object is left alone whole, as a step's own
	 * "changed since" leaves it (RestoreStatus): nothing is computed or
	 * written for it, and every step is journalled skipped with that reason.
	 *
	 * @param \Closure|null $guard fn( int $id, object $product ): ?string, or null for none.
	 */
	public function guarded( ?\Closure $guard ): self {
		$this->guard = $guard;
		return $this;
	}

	/**
	 * Apply an operation list to one object.
	 *
	 * @param int                                                $id         Product or variation id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list Registry::validate_list() returned.
	 * @param string|null                                        $type       The kind the object was chosen as (`product` or `variation`, a batch item's object_type), or null not to check. An object that is now the other kind fails every step with CHANGED_KIND and is not written (T83).
	 * @throws \LogicException When the list was not validated.
	 */
	public function write( int $id, array $operations, ?string $type = null ): WriteOutcome {
		// A record an Off writes names the batch writing it (T89).
		$write = fn(): WriteOutcome => Stamp::within( $this->batch_id, null, fn(): WriteOutcome => $this->write_one( $id, $operations, $type ) );
		// Background work acts for the batch's user: their unfiltered_html decides, as in their own edit.
		try {
			return null === $this->user_id ? $write() : Field::acting_as( $this->user_id, $write );
		} finally {
			if ( 0 === $this->depth ) {
				$this->flush_external();
			}
		}
	}

	/**
	 * Load the values of a chunk's objects for an external change (T107).
	 *
	 * @param list<array<string, mixed>> $operations The batch's operation list.
	 * @param list<int>                  $ids        The chunk's objects.
	 */
	public function prime_external( array $operations, array $ids ): void {
		$kinds = $this->operations->kinds( $operations );
		if ( 1 === count( $kinds ) && '' !== $kinds[0] ) {
			$external = $this->external_writer( $kinds[0], self::external_mode( $operations ) );
			// A category change primes terms through prime_terms(), never prime() (docs/EXTENSIONS-v2.md §5.4).
			$external?->prime( $ids, $external->targets_categories( $operations ) ? \BulkCatalogueManager\Extension\ActionDescriptor::TARGET_CATEGORY : \BulkCatalogueManager\Extension\ActionDescriptor::TARGET_PRODUCT );
		}
	}

	/**
	 * The writer for one provider's changes, or null when it is not registered.
	 *
	 * @param string $provider_id Provider id.
	 */
	private function external_writer( string $provider_id, string $mode = RunContext::APPLY ): ?ExternalWriter {
		$slot = $provider_id . ':' . $mode;
		if ( ! isset( $this->externals[ $slot ] ) ) {
			$provider = $this->operations->providers()->get( $provider_id );
			if ( null === $provider ) {
				return null;
			}
			$this->externals[ $slot ] = new ExternalWriter( $provider, new RunContext( $mode, $this->user_id ?? get_current_user_id(), $this->batch_id ), $this->journal, $this->batch_id );
		}
		return $this->externals[ $slot ];
	}

	/**
	 * The run an external list writes in: undo for restore steps (T108), else apply.
	 *
	 * @param array<mixed> $operations The list.
	 */
	private static function external_mode( array $operations ): string {
		foreach ( $operations as $step ) {
			if ( \BulkCatalogueManager\External\RestoreExternal::ID !== ( $step['action'] ?? '' ) ) {
				return RunContext::APPLY;
			}
		}
		return RunContext::UNDO;
	}

	/**
	 * Flush every external writer of the run.
	 */
	private function flush_external(): void {
		foreach ( $this->externals as $external ) {
			$external->flush();
		}
	}

	/**
	 * Write an object of an external change, or refuse a list mixing kinds (T107).
	 *
	 * @param int                                                     $id         Object id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list.
	 * @param string|null                                             $type       The kind it was chosen as.
	 * @param list<string>                                            $kinds      The list's kinds of change.
	 */
	private function write_external( int $id, array $operations, ?string $type, array $kinds ): WriteOutcome {
		$external = 1 === count( $kinds ) ? $this->external_writer( $kinds[0], self::external_mode( $operations ) ) : null;
		if ( null === $external ) {
			$reason  = 1 === count( $kinds ) ? Refusals::missing()->get_error_message() : Refusals::mixed_sentence();
			$code    = 1 === count( $kinds ) ? Refusals::UNAVAILABLE : Refusals::MIXED;
			$results = array_map( static fn( array $step ): Result => Result::failed( (string) ( $step['args']['field'] ?? '' ), null, $reason, $code ), $operations );
			$this->journal( $id, null, '', array_map( static fn( Result $r ): array => [ $r, true ], $results ), [], null );
			return new WriteOutcome( $id, $results, false );
		}
		if ( null !== $this->journal && $this->journal->already_done( $this->batch_id, $id ) ) {
			$intents = $this->journal->intents( $this->batch_id, $id );
			return [] === $intents
				? new WriteOutcome( $id, self::done_before( array_map( static fn( array $step ): array => [ null, $step['args'] ], $operations ) ), false )
				: $external->replay( $id, $intents, $operations, $type );
		}
		return $external->write( $id, $operations, $type, $this->guard );
	}

	/**
	 * Apply an operation list to one object, as write() describes.
	 *
	 * @param int                                                $id         Product or variation id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list Registry::validate_list() returned.
	 * @param string|null                                        $type       The kind the object was chosen as, or null not to check.
	 * @throws \LogicException When the list was not validated.
	 */
	private function write_one( int $id, array $operations, ?string $type = null ): WriteOutcome {
		$kinds = $this->operations->kinds( $operations );
		if ( [ '' ] !== $kinds && [] !== $kinds ) {
			return $this->write_external( $id, $operations, $type, $kinds );
		}
		$steps = $this->resolve( $operations );
		if ( null !== $this->journal && $this->journal->already_done( $this->batch_id, $id ) ) {
			$intents = $this->journal->intents( $this->batch_id, $id );
			return [] === $intents ? new WriteOutcome( $id, self::done_before( $steps ), false ) : $this->replay( $id, $intents, $type );
		}

		$product = wc_get_product( $id );
		if ( ! is_object( $product ) ) {
			$results = self::not_found( $steps );
			$this->journal( $id, null, '', self::entries( $results, $steps ), [], null );
			return new WriteOutcome( $id, $results, false );
		}
		if ( null !== $type && Journal::object_type( $product ) !== $type ) {
			// Chosen as one kind, now another: never written under the kind it was chosen as.
			$results = self::changed_kind( $steps );
			$this->journal( $id, null, '', self::entries( $results, $steps ), [], null );
			return new WriteOutcome( $id, $results, false );
		}
		if ( is_callable( [ $product, 'get_status' ] ) && 'trash' === $product->get_status( 'edit' ) ) {
			// Moved to the bin since it was chosen (WooCommerce bins a variable product's variations with it):
			// unavailable, as a member binned before Apply is (T83); skipped, never written, never failed.
			$before  = $this->before( $product, $steps );
			$results = array_map( static fn( array $step ): Result => Result::skipped( (string) $step[1]['field'], $before[ $step[1]['field'] ] ?? null, BatchFactory::unavailable_reason(), self::UNAVAILABLE ), $steps );
			$this->journal( $id, $product, '', self::entries( $results, $steps ), $before, null );
			return new WriteOutcome( $id, $results, false );
		}

		$modified  = null === $this->journal ? '' : $this->journal->modified_gmt( $product );
		$validator = $this->validator ?? $this->new_validator();
		$before    = $this->before( $product, $steps );
		$reason    = null === $this->guard ? null : ( $this->guard )( $id, $product );
		if ( is_string( $reason ) && '' !== $reason ) {
			return $this->left_alone( $id, $product, $steps, $reason, $modified, $before );
		}
		$native_before = clone $product;
		$entries       = [];
		foreach ( $steps as [ $operation, $args ] ) {
			$skip_already = (bool) ( $args['skip_already'] ?? true );
			$checked      = $validator->check( $product, $operation->compute( $product, $args ), $args );
			if ( Result::SKIPPED === $checked[0]->state() && RestoreStatus::CHANGED_SINCE === $checked[0]->code() ) {
				// One product, one outcome (Main Build's F1): a step changed since leaves the whole object alone,
				// what earlier steps set on it unsaved.
				return $this->left_alone( $id, $product, $steps, $checked[0]->reason(), $modified, $before );
			}
			// What follows the step (the Off record, T89) is decided before anything is applied.
			$follow = $validator->follow( $operation, $product, $checked[0], $args );
			foreach ( $checked as $index => $result ) {
				$applied   = 0 === $index ? self::apply( $operation, $result, $product ) : $this->apply_field( $result, $product );
				$entries[] = [ $applied, $skip_already ];
				if ( 0 === $index && $result->is_changed() && ! $applied->is_changed() ) {
					// The step itself was refused at write time: nothing follows it.
					$follow = [];
				}
				if ( $result->is_changed() && ! array_key_exists( $result->field(), $before ) ) {
					// A field no step names (the sale-price clear): its value before this first change.
					$before[ $result->field() ] = $result->old();
				}
			}
			foreach ( $follow as $result ) {
				$entries[] = [ $this->apply_field( $result, $product ), $skip_already ];
				if ( $result->is_changed() && ! array_key_exists( $result->field(), $before ) ) {
					// A field no step names (the Off record): its value before this first change.
					$before[ $result->field() ] = $result->old();
				}
			}
		}
		// What WooCommerce's save changes with Manage stock: journalled, never set here, read back once saved.
		foreach ( StockCoupling::follow( $this->operations->fields(), $native_before, $product, array_column( $entries, 0 ) ) as $result ) {
			$entries[] = [ $result, true ];
			if ( ! array_key_exists( $result->field(), $before ) ) {
				$before[ $result->field() ] = $result->old();
			}
		}
		$results = array_column( $entries, 0 );
		foreach ( $results as $result ) {
			if ( Result::FAILED === $result->state() && in_array( $result->field(), [ PublicationPassword::FIELD, \BulkCatalogueManager\Availability\OffRecord::FIELD ], true ) ) {
				$entries = self::denied_entries( $entries, \BulkCatalogueManager\Rest\Refusal::create( $result->code(), $result->reason(), 400 ) );
				$this->journal( $id, $product, $modified, $entries, $before, null );
				return new WriteOutcome( $id, array_column( $entries, 0 ), false );
			}
		}

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
		// Check against the loaded pre-setter status: the dirty target is never a resave exception.
		$denied = $this->publication_permission( $native_before, $results );
		if ( null !== $denied ) {
			$entries = self::denied_entries( $entries, $denied );
			$this->settle( $id, $entries, $before, null, $intended );
			return new WriteOutcome( $id, array_column( $entries, 0 ), false );
		}
		try {
			if ( null !== $this->status_field( array_keys( $before ) ) || isset( $before[ PublicationPassword::FIELD ] ) ) {
				StatusTransition::save( $product, $this->user_id );
			} else {
				$product->save();
			}
		} catch ( \Exception $e ) {
			return $this->save_threw( $id, $e, $entries, $before, $intended, $changed_before );
		}

		$status = $this->status_field( $touched );
		if ( null !== $status || in_array( PublicationPassword::FIELD, $touched, true ) ) {
			// WooCommerce does not read post_status back; the journal and the outcome report what WordPress stored.
			if ( null !== $status ) {
				StatusSwitch::read_back( $status, $product );
			}
			PublicationPassword::read_back( $product );
			$results = $this->stored( $results, $product );
		}
		$parent_id = self::variable_parent( $product );
		if ( $parent_id > 0 ) {
			$this->sync( $parent_id );
		}
		$this->settle( $id, $entries, $before, $product, $intended );
		// A status WordPress kept, with nothing else changed, is no change to report.
		$saved = [] !== array_filter( $results, static fn( Result $r ): bool => $r->is_changed() );
		return new WriteOutcome( $id, $results, $saved, $saved ? $parent_id : 0, $saved ? $changed_before : [] );
	}

	/**
	 * Finish an object whose save() threw (SILENT-FAILURES-1). WooCommerce
	 * fires hooks after it has stored the values (woocommerce_update_product,
	 * woocommerce_after_product_object_save and others), so a throw does not
	 * mean nothing was stored. The exception is logged; the object is loaded
	 * afresh and each changed field read back: a field holding the value
	 * asked for is reported and journalled applied, so undo sees it and a
	 * retry does not apply it again; one still holding its value before is
	 * reported not made; anything else, or a product that cannot be read, is
	 * reported as possibly made, with the value read on record.
	 *
	 * @param int                             $id             Object id.
	 * @param \Exception                      $error          What save() threw.
	 * @param list<array{0: Result, 1: bool}> $entries        Every Result with its step's skip switch.
	 * @param array<string, mixed>            $before         Before values.
	 * @param list<string>                    $intended       The fields given an intent row.
	 * @param array<string, mixed>            $changed_before Each changed field's value before the write.
	 */
	private function save_threw( int $id, \Exception $error, array $entries, array $before, array $intended, array $changed_before ): WriteOutcome {
		$targets = [];
		foreach ( $entries as [ $result ] ) {
			if ( $result->is_changed() ) {
				$targets[ $result->field() ] = [
					'old' => array_key_exists( $result->field(), $before ) ? $before[ $result->field() ] : $result->old(),
					'new' => $result->new(),
				];
			}
		}
		[ $stored, $found ] = $this->read_back_after( $id, $error, $targets );
		$entries            = array_map(
			static function ( array $entry ) use ( $found ): array {
				$result = $entry[0];
				if ( ! $result->is_changed() || 'landed' === $found[ $result->field() ][0] ) {
					return $entry;
				}
				return [ self::save_failure( $result->field(), $result->old(), 'unchanged' === $found[ $result->field() ][0] ), $entry[1] ];
			},
			$entries
		);
		$landed             = array_keys( array_filter( $found, static fn( array $f ): bool => 'landed' === $f[0] ) );
		$uncertain          = array_keys( array_filter( $found, static fn( array $f ): bool => 'uncertain' === $f[0] ) );
		$this->settle( $id, $entries, $before, $stored, array_values( array_diff( $intended, $uncertain ) ) );
		if ( null !== $this->journal ) {
			foreach ( array_intersect( $intended, $uncertain ) as $key ) {
				$by_field = array_values( array_filter( array_column( $entries, 0 ), static fn( Result $r ): bool => $r->field() === $key && Result::FAILED === $r->state() ) );
				$this->journal->settle_one( $this->batch_id, $id, $key, 'failed', $found[ $key ][1], $by_field[0]->reason() );
			}
		}
		$results = array_column( $entries, 0 );
		if ( [] === $landed || null === $stored ) {
			return new WriteOutcome( $id, $results, false );
		}
		if ( null !== $this->status_field( $landed ) || in_array( PublicationPassword::FIELD, $landed, true ) ) {
			$results = $this->stored( $results, $stored );
		}
		$parent_id = self::variable_parent( $stored );
		if ( $parent_id > 0 ) {
			$this->sync( $parent_id );
		}
		return new WriteOutcome( $id, $results, true, $parent_id, array_intersect_key( $changed_before, array_flip( $landed ) ) );
	}

	/**
	 * After save() threw: log it, load the object afresh and say, per field,
	 * whether it holds the value asked for (`landed`), its value before
	 * (`unchanged`) or anything else or nothing readable (`uncertain`), with
	 * the value read (the value before, when nothing could be read).
	 *
	 * @param int                                          $id      Object id.
	 * @param \Exception                                   $error   What save() threw.
	 * @param array<string, array{old: mixed, new: mixed}> $targets Field key => its value before and the value asked for.
	 * @return array{0: object|null, 1: array<string, array{0: string, 1: mixed}>} The fresh object, or null, and each field's verdict with the value read.
	 */
	private function read_back_after( int $id, \Exception $error, array $targets ): array {
		// Class and place only: a save hook's text may echo the values being saved.
		\BulkCatalogueManager\Rest\Refusal::log( $error, sprintf( 'Saving product %d threw; reading its stored values back', $id ), false );
		$stored = null;
		try {
			clean_post_cache( $id );
			$loaded = wc_get_product( $id );
			$stored = is_object( $loaded ) ? $loaded : null;
		} catch ( \Exception $reload ) {
			\BulkCatalogueManager\Rest\Refusal::log( $reload, sprintf( 'Reading product %d back', $id ), false );
		}
		$found = [];
		foreach ( $targets as $key => [ 'old' => $old, 'new' => $target ] ) {
			$field         = $this->operations->fields()->get( $key );
			$found[ $key ] = [ 'uncertain', $old ];
			if ( null === $stored || null === $field ) {
				continue;
			}
			try {
				$now = $field->read( $stored );
			} catch ( \Exception $unread ) {
				continue;
			}
			// A Publish WordPress kept as Scheduled stands for the Publish asked for.
			if ( Planner::same( $field, $now, $target ) || ( StatusSwitch::governs( $field ) && 'publish' === $target && 'future' === $now && 'future' !== $old ) ) {
				$found[ $key ] = [ 'landed', $now ];
			} elseif ( Planner::same( $field, $now, $old ) ) {
				$found[ $key ] = [ 'unchanged', $now ];
			} else {
				$found[ $key ] = [ 'uncertain', $now ];
			}
		}
		return [ $stored, $found ];
	}

	/**
	 * The failed Result of a change whose save threw: not made when the
	 * stored value is still the value before, else possibly made.
	 *
	 * @param string $key       Field key.
	 * @param mixed  $old       Its value before.
	 * @param bool   $unchanged Whether the stored value is still the value before.
	 */
	private static function save_failure( string $key, mixed $old, bool $unchanged ): Result {
		return $unchanged
			? Result::failed( $key, $old, __( 'WooCommerce could not save this product, so this change was not made. Try again, or open the product to see why.', 'bulk-catalogue-manager-for-woocommerce' ), self::SAVE_FAILED )
			: Result::failed( $key, $old, __( 'WooCommerce reported an error while saving this product, so this change may not have been made as asked. Open the product to check it before trying again.', 'bulk-catalogue-manager-for-woocommerce' ), self::SAVE_UNCERTAIN );
	}

	/**
	 * The Status field when a save's changes include it, else null.
	 *
	 * @param list<string> $touched The fields a change touched.
	 */
	private function status_field( array $touched ): ?Field {
		foreach ( $touched as $key ) {
			$field = $this->operations->fields()->get( $key );
			if ( null !== $field && StatusSwitch::governs( $field ) ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * The Results with each saved status change reporting the status the
	 * object now holds, as WordPress stored it: a status WordPress kept as it
	 * was reads as already, with the step's note.
	 *
	 * @param list<Result> $results The Results.
	 * @param object       $saved   The saved object, read back.
	 * @return list<Result>
	 */
	private function stored( array $results, object $saved ): array {
		$fields = $this->operations->fields();
		return array_map(
			static function ( Result $result ) use ( $fields, $saved ): Result {
				$field = $fields->get( $result->field() );
				if ( ! $result->is_changed() || null === $field || ( ! StatusSwitch::governs( $field ) && PublicationPassword::FIELD !== $field->key() ) ) {
					return $result;
				}
				$now = $field->read( $saved );
				if ( isset( $result->context()['effective_status'] ) ) {
					$context                     = $result->context();
					$context['effective_status'] = (string) $now;
					$context['scheduled_gmt']    = (string) get_post_field( 'post_date_gmt', (int) $saved->get_id(), 'raw' );
					$result                      = $result->with_note( $result->note(), $context );
				}
				if ( $now === $result->new() ) {
					return $result;
				}
				if ( $now === $result->old() ) {
					// WordPress kept the status (a Published on a never-Off scheduled product stays scheduled): already.
					return Result::already( $result->field(), $now )->with_note( $result->note(), $result->context() );
				}
				return Result::changed( $result->field(), $result->old(), $now, $result->replaces() )->with_note( $result->note(), $result->context() );
			},
			$results
		);
	}

	/**
	 * Finish an object a killed run left with intent rows (§12.3): nothing
	 * is computed again. Each recorded value is compared, through the
	 * field's canonical form, with what the object holds now: a value
	 * already there (the killed run's save landed) is left alone. A value
	 * still pending passes the forward path's per-object checks first (C2):
	 * on a product moved to the bin since it is skipped as unavailable; one
	 * the user may no longer edit, or whose terms they may no longer assign,
	 * is refused; one holding neither the recorded value before nor the
	 * target was changed since and is never written over (an undo chunk
	 * alike). Any other is written as the recorded absolute value through
	 * Field::write() and saved once. Each intent row is then settled —
	 * applied with the value read back, or skipped or failed with the
	 * reason — and the variable parent, if
	 * any, resynchronised. The object counts as changed and saved when any
	 * recorded value now stands, whichever run saved it.
	 *
	 * @param int                                          $id      Object id.
	 * @param array<string, array{old: mixed, new: mixed}> $intents Field key => the values the killed run recorded.
	 */
	private function replay( int $id, array $intents, ?string $type = null ): WriteOutcome {
		$product   = wc_get_product( $id );
		$fields    = $this->operations->fields();
		$results   = [];
		$pending   = [];
		$uncertain = [];
		$written   = false;
		if ( ! is_object( $product ) ) {
			$product = null;
		}
		$native_before = null === $product ? null : clone $product;
		$status        = $this->status_field( array_keys( $intents ) );
		$publication   = null !== $status || isset( $intents[ PublicationPassword::FIELD ] ) || isset( $intents[ \BulkCatalogueManager\Availability\OffRecord::FIELD ] );
		foreach ( $intents as $key => ['old' => $old, 'new' => $value] ) {
			$field = $fields->get( $key );
			if ( null === $product || null === $field ) {
				$results[ $key ] = self::not_found( [ [ null,[ 'field' => $key ] ] ] )[0];
				continue;
			}
			if ( null !== $type && Journal::object_type( $product ) !== $type ) {
				$results[ $key ] = self::changed_kind( [ [ null,[ 'field' => $key ] ] ] )[0];
				continue;
			}
			$current = $field->read( $product );
			// Native Publish may have landed as Scheduled. Its stored outcome does not
			// expire at the minute boundary. An unchanged Future preimage is still pending.
			$scheduled = StatusSwitch::governs( $field ) && 'publish' === $value && 'future' === $current && $old !== $current;
			if ( Planner::same( $field, $current, $value ) || $scheduled ) {
				$results[ $key ] = Planner::same( $field, $old, $current ) ? Result::already( $key, $current ) : Result::changed( $key, $old, $current );
			} else {
				$pending[ $key ] = Result::changed( $key, $old, $value );
				$results[ $key ] = $pending[ $key ];
			}
		}
		// Authorise the entire still-pending coupled group before any setter. Landed facts need no new grant.
		$denied = null;
		if ( [] !== $pending && null !== $product ) {
			$validator = $this->new_validator();
			$denied    = $validator->authorise( $native_before );
			$previous  = null;
			if ( $publication && null !== $status && isset( $intents[ $status->key() ] ) ) {
				// A partial save does not turn the original Draft transition into a resave exemption.
				$previous = (string) $intents[ $status->key() ]['old'];
				$denied ??= StatusPermission::check( $this->user_id, $native_before, (string) $intents[ $status->key() ]['new'], $previous );
			}
			$denied ??= PublicationPassword::variation_refusal( $native_before, null === $status ? '' : (string) $intents[ $status->key() ]['new'], isset( $intents[ PublicationPassword::FIELD ] ) );
			// The forward path's per-object checks (C2): a still-pending value is written only on an object
			// still in the catalogue, still at the value the killed run read, and still the user's to change.
			$binned = is_callable( [ $product, 'get_status' ] ) && 'trash' === $product->get_status( 'edit' );
			foreach ( $pending as $key => $result ) {
				$field   = $fields->get( $key );
				$refused = null;
				if ( $binned ) {
					// Moved to the bin since: unavailable, as the forward path leaves it.
					$results[ $key ] = Result::skipped( $key, $result->old(), BatchFactory::unavailable_reason(), self::UNAVAILABLE );
				} elseif ( null !== $denied ) {
					$results[ $key ] = Result::failed( $key, $result->old(), $denied->get_error_message(), (string) $denied->get_error_code() );
				} elseif ( ! Planner::same( $field, $field->read( $product ), $result->old() ) ) {
					// Neither the value before nor the target: someone changed it since. Never written over.
					$results[ $key ] = Result::failed( $key, $result->old(), __( 'This product changed while the interrupted change was waiting, so its remaining values were left alone. Review it before trying again.', 'bulk-catalogue-manager-for-woocommerce' ), RestoreStatus::CHANGED_SINCE );
				} else {
					$refused = $validator->term_refusal( $field );
				}
				if ( null !== $refused ) {
					$results[ $key ] = Result::skipped( $key, $result->old(), $refused->get_error_message(), (string) $refused->get_error_code() );
				}
			}
			// Refuse the coupled pending group together when any of its members conflicts.
			$failure = array_values( array_filter( $results, static fn( Result $r ): bool => Result::FAILED === $r->state() ) );
			if ( $publication && [] !== $failure ) {
				foreach ( $pending as $key => $result ) {
					$results[ $key ] = Result::failed( $key, $result->old(), $failure[0]->reason(), $failure[0]->code() );
				}
			}
			$derived = [];
			foreach ( $pending as $key => $result ) {
				if ( ! $results[ $key ]->is_changed() ) {
					continue;
				}
				if ( isset( $intents[ StockCoupling::FIELD ] ) && in_array( $key, StockCoupling::COUPLED, true ) && null !== $fields->get( $key )->refusal( $product, $result->new() ) ) {
					// WooCommerce's save sets it with Manage stock (a quantity cleared with it): read back once saved.
					$derived[] = $key;
					continue;
				}
				$results[ $key ] = $this->apply_field( $result, $product );
				$written         = $written || $results[ $key ]->is_changed();
			}
			$failures = array_values( array_filter( $results, static fn( Result $r ): bool => Result::FAILED === $r->state() ) );
			if ( $publication && [] !== $failures ) {
				foreach ( $pending as $key => $result ) {
					$results[ $key ] = Result::failed( $key, $result->old(), $failures[0]->reason(), $failures[0]->code() );
				}
				$written = false;
			}
			if ( $written ) {
				$denied = $this->new_validator()->authorise( $native_before );
				if ( $publication && null !== $status && isset( $intents[ $status->key() ] ) ) {
					$denied ??= StatusPermission::check( $this->user_id, $native_before, (string) $intents[ $status->key() ]['new'], $previous );
				}
				$denied ??= PublicationPassword::variation_refusal( $native_before, null === $status ? '' : (string) $intents[ $status->key() ]['new'], isset( $intents[ PublicationPassword::FIELD ] ) );
				if ( null !== $denied ) {
					foreach ( $pending as $key => $result ) {
						$results[ $key ] = Result::failed( $key, $result->old(), $denied->get_error_message(), (string) $denied->get_error_code() );
					}
					$written = false;
				} else {
					try {
						if ( $publication ) {
							StatusTransition::save( $product, $this->user_id );
						} else {
							$product->save();
						}
						if ( null !== $status || isset( $intents[ PublicationPassword::FIELD ] ) ) {
							if ( null !== $status ) {
								StatusSwitch::read_back( $status, $product );
							}PublicationPassword::read_back( $product );
						}
					} catch ( \Exception $e ) {
						// SILENT-FAILURES-1: what WooCommerce stored before the throw stands as stored.
						$sent               = array_filter( $pending, static fn( Result $r, string $key ): bool => $r->is_changed() && $results[ $key ]->is_changed(), ARRAY_FILTER_USE_BOTH );
						[ $stored, $found ] = $this->read_back_after(
							$id,
							$e,
							array_map(
								static fn( Result $r ): array => [
									'old' => $r->old(),
									'new' => $r->new(),
								],
								$sent
							)
						);
						$written            = false;
						foreach ( $sent as $key => $result ) {
							[ $verdict, $now ] = $found[ $key ];
							if ( 'landed' === $verdict ) {
								$results[ $key ] = Result::changed( $key, $result->old(), $now );
								$written         = true;
								continue;
							}
							$results[ $key ] = self::save_failure( $key, $result->old(), 'unchanged' === $verdict );
							if ( 'uncertain' === $verdict ) {
								$uncertain[ $key ] = $now;
							}
						}
						$product = $written ? $stored : $product;
					}
				}
			}
		}
		if ( $written ) {
			foreach ( $derived ?? [] as $key ) {
				$field           = $fields->get( $key );
				$now             = $field->read( $product );
				$results[ $key ] = Planner::same( $field, $intents[ $key ]['old'], $now ) ? Result::already( $key, $now ) : Result::changed( $key, $intents[ $key ]['old'], $now );
			}
			$results = array_combine( array_keys( $results ), $this->stored( array_values( $results ), $product ) );
		} else {
			foreach ( $derived ?? [] as $key ) {
				$refusal         = $fields->get( $key )->refusal( $product, $intents[ $key ]['new'] );
				$results[ $key ] = Result::failed( $key, $intents[ $key ]['old'], null === $refusal ? '' : $refusal->get_error_message(), null === $refusal ? '' : (string) $refusal->get_error_code() );
			}
		}
		$before = [];
		foreach ( $results as $key => $result ) {
			$failed = in_array( $result->state(), [ Result::FAILED,Result::SKIPPED ], true );
			$this->journal->settle_one( $this->batch_id, $id, $key, $failed ? ( Result::SKIPPED === $result->state() ? 'skipped' : 'failed' ) : 'applied', $failed ? ( array_key_exists( $key, $uncertain ) ? $uncertain[ $key ] : $intents[ $key ]['old'] ) : $result->new(), $failed ? $result->reason() : '' );
			if ( $result->is_changed() ) {
				$before[ $key ] = $result->old();
			}
		}
		$saved  = [] !== $before;
		$parent = $written ? self::variable_parent( $product ) : 0;
		if ( $parent > 0 ) {
			$this->sync( $parent );
		}
		return new WriteOutcome( $id, array_values( $results ), $saved, $parent, $before );
	}

	/** Final permission check uses the immutable pre-setter native state.
	 * @param object $before Loaded state before setters.
	 * @param list<Result> $results Computed writes.
	 */
	private function publication_permission( object $before, array $results ): ?\WP_Error {
		if ( null === $this->status_field( array_map( static fn( Result $r ): string => $r->field(), $results ) ) ) {
			return null;
		}
		$denied = $this->new_validator()->authorise( $before );
		foreach ( $results as $result ) {
			$field = $this->operations->fields()->get( $result->field() );
			if ( null !== $field && StatusSwitch::governs( $field ) && in_array( $result->state(), [ Result::CHANGED,Result::ALREADY ], true ) ) {
				$denied ??= StatusPermission::check( $this->user_id, $before, (string) $result->new() );
				$denied ??= PublicationPassword::variation_refusal( $before, (string) $result->new() );
			}
		}
		return $denied;
	}
	/** Fail unsaved intents, never settle a permission refusal as applied.
	 * @param array $entries Journal entries.
	 * @param \WP_Error $denied Refusal.
	 * @return array
	 */
	private static function denied_entries( array $entries, \WP_Error $denied ): array {
		return array_map( static fn( array $entry ): array => [ $entry[0]->is_changed() ? Result::failed( $entry[0]->field(), $entry[0]->old(), $denied->get_error_message(), (string) $denied->get_error_code() ) : $entry[0],$entry[1] ], $entries );
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
				// T107: an external change's flush(), once for the run, whatever happened in it.
				$this->flush_external();
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
		try {
			$written = $field->write( $product, $result->new() );
		} catch ( \WC_Data_Exception $error ) {
			return Result::failed( $result->field(), $result->old(), __( 'WooCommerce did not accept this value. Check it and try again.', 'bulk-catalogue-manager-for-woocommerce' ), self::INVALID );
		}
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
	 * Leave an object alone whole because it was changed since (T111, F1):
	 * every step skipped with the one reason and code
	 * RestoreStatus::CHANGED_SINCE, journalled with the values it holds,
	 * nothing saved.
	 *
	 * @param int                                                $id       The object.
	 * @param object                                             $product  It, as loaded.
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps    Resolved steps.
	 * @param string                                             $reason   The plain reason.
	 * @param string                                             $modified Its modified time, for the journal.
	 * @param array<string, mixed>                               $before   Each step's field value as loaded.
	 */
	private function left_alone( int $id, object $product, array $steps, string $reason, string $modified, array $before ): WriteOutcome {
		$results = array_map( static fn( array $step ): Result => Result::skipped( (string) $step[1]['field'], $before[ $step[1]['field'] ] ?? null, $reason, RestoreStatus::CHANGED_SINCE ), $steps );
		$this->journal( $id, $product, $modified, self::entries( $results, $steps ), $before, null );
		return new WriteOutcome( $id, $results, false );
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
	 * A failed result for every step of an object that is no longer the kind
	 * it was chosen as.
	 *
	 * @param list<array{0: Operation, 1: array<string, mixed>}> $steps Resolved steps.
	 * @return list<Result>
	 */
	private static function changed_kind( array $steps ): array {
		$reason = __( 'This item has changed between a product and a variation since it was chosen, so it was left unchanged. Choose it again to change it.', 'bulk-catalogue-manager-for-woocommerce' );
		return array_map( static fn( array $step ): Result => Result::failed( $step[1]['field'], null, $reason, self::CHANGED_KIND ), $steps );
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
