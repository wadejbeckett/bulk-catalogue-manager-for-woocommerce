<?php
/**
 * The single write path (SPEC §8, §12.3): applies a validated, normalised
 * operation list to one object through WooCommerce CRUD. The chunk runner,
 * the single-object route, inline edits, the detail panel, term writes and
 * undo all come through here; the writer schedules nothing of its own.
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
 * An exception from save() or from the hook is not caught: nothing was
 * saved and the caller records the item as failed (SPEC §12.6).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Operations\WriteRefused;

defined( 'ABSPATH' ) || exit;

/**
 * Applies operations to one object through WooCommerce CRUD.
 */
final class Writer {
	/**
	 * The refusal code for an id that is not a product.
	 */
	public const NOT_FOUND = 'bcm_not_found';

	/**
	 * The code for a value WooCommerce's own setter refused.
	 */
	public const INVALID = 'bcm_invalid_value';

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
	 */
	public function __construct( private readonly Registry $operations, ?callable $before_save = null, private readonly ?int $user_id = null ) {
		$this->before_save = null === $before_save ? null : \Closure::fromCallable( $before_save );
	}

	/**
	 * Apply an operation list to one object.
	 *
	 * @param int                                                $id         Product or variation id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The list Registry::validate_list() returned.
	 * @throws \LogicException When the list was not validated.
	 */
	public function write( int $id, array $operations ): WriteOutcome {
		$steps   = $this->resolve( $operations );
		$product = wc_get_product( $id );
		if ( ! is_object( $product ) ) {
			return new WriteOutcome( $id, self::not_found( $steps ), false );
		}

		$validator = $this->validator ?? $this->new_validator();
		$before    = $this->before( $product, $steps );
		$results   = [];
		foreach ( $steps as [ $operation, $args ] ) {
			$checked = $validator->check( $product, $operation->compute( $product, $args ), $args );
			foreach ( $checked as $index => $result ) {
				$results[] = 0 === $index ? self::apply( $operation, $result, $product ) : $this->apply_field( $result, $product );
				if ( $result->is_changed() && ! array_key_exists( $result->field(), $before ) ) {
					// A field no step names (the sale-price clear): its value before this first change.
					$before[ $result->field() ] = $result->old();
				}
			}
		}

		$touched = array_values( array_unique( array_map( static fn( Result $r ): string => $r->field(), array_filter( $results, static fn( Result $r ): bool => $r->is_changed() ) ) ) );
		if ( [] === $touched ) {
			return new WriteOutcome( $id, $results, false );
		}

		$before = array_intersect_key( $before, array_flip( $touched ) );
		if ( null !== $this->before_save ) {
			( $this->before_save )( $product, $touched, $before );
		}
		$product->save();

		$parent_id = self::variable_parent( $product );
		if ( $parent_id > 0 ) {
			$this->sync( $parent_id );
		}
		return new WriteOutcome( $id, $results, true, $parent_id, $before );
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
			return Result::failed( $result->field(), $result->old(), $refused->error()->get_error_message(), str_starts_with( $code, 'bcm_' ) ? $code : '' );
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
			return Result::failed( $result->field(), $result->old(), $written->get_error_message(), str_starts_with( $code, 'bcm_' ) ? $code : '' );
		}
		return $result;
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
