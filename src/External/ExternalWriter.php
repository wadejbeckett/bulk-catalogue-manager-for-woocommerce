<?php
/**
 * The external write path (docs/EXTENSIONS.md §7.3, §8.1, §8.5).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\WriteOutcome;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\CategoryProvider;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Unknown;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Query\ProductQuery;
use BulkCatalogueManager\Rest\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The external write path (docs/EXTENSIONS.md §7.3, §8.1, §8.5): what
 * Engine\Writer runs for a change whose steps are one provider's, one object
 * at a time, journalling from inside, as it does for native changes.
 *
 * For one object, with the run's user:
 * 1. load it (a missing one fails every step with `bulkcat_not_found`),
 *    check it is still the kind it was chosen as and, in an apply run, still
 *    in the catalogue (a binned one is skipped as unavailable,
 *    `bulkcat_unavailable`, as the native writer leaves it);
 * 2. the core's own edit check (Rest\Access::user_can_edit_object(), the
 *    variation's product too), then External\Computer: the action's
 *    capability, the provider's authorise(), the variation rule, the reads
 *    and each step's compute(); a refusal is skipped with its sentence;
 * 3. when nothing changes, journal as a native object does (already,
 *    skipped and failed rows, T29 and *Record unchanged rows*);
 * 4. otherwise intent rows first (Journal::intend()), holding each touched
 *    field's value before and after as envelopes, before anything is written;
 * 5. one write() per touched field, in the provider's field order:
 *    `write( subject, final, before, context )`, compare-and-set; a
 *    conflict fails that field with `bulkcat_changed_during_write`, another
 *    error with the provider's sentence, an exception with
 *    `bulkcat_external_error`; one field's failure never undoes another's;
 * 6. settle each by reading it back: applied with the value read when
 *    same( read-back, final ), else failed `bulkcat_external_not_stored`.
 *
 * The WooCommerce product is never saved, so its modified date does not
 * change. flush() is called once per run (Engine\Writer::run()) with the
 * objects written, and its exception is logged, never reported.
 *
 * A retry that finds intent rows (the run died between write and settle)
 * computes nothing: per field, a value already at the recorded new value is
 * settled applied; one still at the recorded old value is written once and
 * settled, after the same per-object checks as step 1 and 2 pass again and
 * in a Context::REPLAY run; anything else fails
 * `bulkcat_changed_during_write`. So a relative action cannot apply twice.
 *
 * Categories (docs/EXTENSIONS-v2.md §5.4): an item chosen as `product_cat`
 * is a term, never a product of the same number. It is read straight from
 * the term tables (TermSubject::load(), unfiltered, so a background run with
 * no user sees categories a term query would hide); one gone is skipped as
 * unavailable; then the recorded user's `edit_term` on it, the action's
 * capability and the provider's authorise(), in that order, with the same
 * compute, intent, compare-and-set write, read-back, settle and replay as a
 * product, journalled as `product_cat`. The term itself is never updated.
 * prime_terms() and flush_terms() replace prime() and flush(). A category
 * change is never written to a product, nor a product change to a category.
 *

 */
final class ExternalWriter {
	/**
	 * Objects written since the last flush, as keys.
	 *
	 * @var array<int, true>
	 */
	private array $written = [];

	/**
	 * Categories written since the last flush, as keys.
	 *
	 * @var array<int, true>
	 */
	private array $written_terms = [];

	/**
	 * For one provider, run, journal and batch.
	 *
	 * @param Registered   $provider The change's provider.
	 * @param Context      $context  The run (apply or undo), with its user and batch.
	 * @param Journal|null $journal  The journal, or null to journal nothing.
	 * @param int          $batch_id The batch the rows belong to.
	 */
	public function __construct(
		private readonly Registered $provider,
		private readonly Context $context,
		private readonly ?Journal $journal,
		private readonly int $batch_id
	) {}

	/**
	 * Load the values of some objects in one go, through the guard.
	 *
	 * @param list<int> $ids    Objects: products and variations, or terms for a category change.
	 * @param string    $target ActionDescriptor::TARGET_PRODUCT, or TARGET_CATEGORY for term ids.
	 */
	public function prime( array $ids, string $target = ActionDescriptor::TARGET_PRODUCT ): void {
		$ids      = array_values( array_map( 'intval', $ids ) );
		$provider = $this->provider->provider;
		try {
			if ( ActionDescriptor::TARGET_CATEGORY === $target ) {
				if ( $provider instanceof CategoryProvider ) {
					Guard::run( $this->provider->id, 'prime_terms', static fn(): mixed => $provider->prime_terms( TermSubject::TAXONOMY, $ids ) );
				}
				return;
			}
			Guard::run( $this->provider->id, 'prime', static fn(): mixed => $provider->prime( $ids ) );
		} catch ( ProviderFailure $e ) {
			unset( $e ); // Logged by the guard; each read then loads on its own.
		}
	}

	/**
	 * Call flush() once with the objects written since the last call, if any.
	 */
	public function flush(): void {
		$provider = $this->provider->provider;
		if ( [] !== $this->written_terms ) {
			$terms               = array_keys( $this->written_terms );
			$this->written_terms = [];
			sort( $terms, SORT_NUMERIC );
			try {
				if ( $provider instanceof CategoryProvider ) {
					Guard::run( $this->provider->id, 'flush_terms', fn(): mixed => $provider->flush_terms( TermSubject::TAXONOMY, $terms, $this->context ) );
				}
			} catch ( ProviderFailure $e ) {
				unset( $e ); // Logged by the guard; it never changes a reported result.
			}
		}
		if ( [] === $this->written ) {
			return;
		}
		$ids           = array_keys( $this->written );
		$this->written = [];
		try {
			Guard::run( $this->provider->id, 'flush', fn(): mixed => $provider->flush( $ids, $this->context ) );
		} catch ( ProviderFailure $e ) {
			unset( $e ); // Logged by the guard; it never changes a reported result.
		}
	}

	/**
	 * Apply one object's steps, as the file comment describes.
	 *
	 * @param int                                                     $id         Object id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised steps.
	 * @param string|null                                             $type       The kind it was chosen as, or null not to check.
	 * @param \Closure|null $guard The queued undo check after the object is loaded, or null.
	 */
	public function write( int $id, array $operations, ?string $type, ?\Closure $guard = null ): WriteOutcome {
		$categories = $this->targets_categories( $operations );
		if ( TermSubject::TAXONOMY === $type || $categories ) {
			if ( TermSubject::TAXONOMY !== $type || ! $categories ) {
				// One kind of object per change: never a category change on a product, nor the reverse.
				return $this->refuse_all( $id, $operations, static fn( string $key ): Result => Result::failed( $key, null, Refusals::mixed_sentence(), Refusals::MIXED ), (string) ( $type ?? 'product' ), self::unknown_time() );
			}
			return $this->write_term( $id, $operations, $guard );
		}
		$product = self::load( $id );
		if ( null === $product ) {
			return $this->refuse_all( $id, $operations, static fn( string $key ): Result => Result::failed( $key, null, __( 'That product could not be found. It may have been deleted; reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ), Writer::NOT_FOUND ), 'product', $this->modified( null ) );
		}
		$subject = ProductSubject::of( $product, $this->provider );
		$refusal = $this->object_refusal( $id, $product, $subject, $type );
		if ( null !== $refusal ) {
			return $this->refuse_all( $id, $operations, $refusal, Journal::object_type( $product ), $this->modified( $product ) );
		}
		$modified = null === $this->journal ? '' : $this->journal->modified_gmt( $product );

		$reason = null === $guard ? null : $guard( $id, $product );
		if ( is_string( $reason ) && '' !== $reason ) {
			return $this->refuse_all( $id, $operations, static fn( string $key ): Result => Result::skipped( $key, null, $reason, \BulkCatalogueManager\Operations\RestoreStatus::CHANGED_SINCE ), Journal::object_type( $product ), '' === $modified ? $this->modified( $product ) : $modified );
		}
		return $this->commit( $id, $subject, $operations, Journal::object_type( $product ), '' === $modified ? $this->modified( $product ) : $modified );
	}

	/**
	 * Apply one category's steps (docs/EXTENSIONS-v2.md §5.4): read it
	 * unfiltered (a deleted one is skipped as unavailable), check the user's
	 * edit_term on it, then compute (the action's capability and the
	 * provider's authorise() come first there) and write as a product is.
	 *
	 * @param int                                                     $id         Term id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised steps.
	 * @param \Closure|null                                           $guard      The queued undo check after the category is loaded, or null.
	 */
	private function write_term( int $id, array $operations, ?\Closure $guard ): WriteOutcome {
		$time = self::unknown_time();
		$term = TermSubject::load( $id );
		if ( null === $term ) {
			return $this->refuse_all( $id, $operations, static fn( string $key ): Result => Result::skipped( $key, null, self::term_gone_sentence(), Writer::UNAVAILABLE ), TermSubject::TAXONOMY, $time );
		}
		$refusal = $this->term_refusal( $id );
		if ( null !== $refusal ) {
			return $this->refuse_all( $id, $operations, $refusal, TermSubject::TAXONOMY, $time );
		}
		$reason = null === $guard ? null : $guard( $id, $term );
		if ( is_string( $reason ) && '' !== $reason ) {
			return $this->refuse_all( $id, $operations, static fn( string $key ): Result => Result::skipped( $key, null, $reason, \BulkCatalogueManager\Operations\RestoreStatus::CHANGED_SINCE ), TermSubject::TAXONOMY, $time );
		}
		return $this->commit( $id, new TermSubject( $term, $this->provider ), $operations, TermSubject::TAXONOMY, $time );
	}

	/**
	 * The sentence for a category that no longer exists.
	 */
	public static function term_gone_sentence(): string {
		return __( 'This category no longer exists, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * The core's own check of a category before the provider is asked:
	 * whether the run's user may edit it (`edit_term`); null when they may.
	 *
	 * @param int $id Term id.
	 * @return \Closure(string): Result|null
	 */
	private function term_refusal( int $id ): ?\Closure {
		if ( user_can( $this->context->user_id(), 'edit_term', $id ) ) {
			return null;
		}
		$reason = __( 'You do not have permission to change this category, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
		return static fn( string $key ): Result => Result::skipped( $key, null, $reason, Access::CANNOT_EDIT );
	}

	/**
	 * Whether a change's steps act on categories: their fields belong to
	 * category actions (a restore step names its field only).
	 *
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised steps.
	 */
	public function targets_categories( array $operations ): bool {
		return $this->provider->category_change( $operations );
	}

	/**
	 * The journal's time for an object: its modified time, or the unknown time.
	 *
	 * @param object|null $product The loaded product, or null.
	 */
	private function modified( ?object $product ): string {
		return null === $this->journal ? self::unknown_time() : $this->journal->modified_gmt( $product );
	}

	/**
	 * The time recorded for an object without one (a category).
	 */
	private static function unknown_time(): string {
		return \BulkCatalogueManager\Data\ChangeRepository::UNKNOWN_TIME;
	}

	/**
	 * Compute an object's steps and write what changed, as the file comment
	 * describes from step 3 on: the same for a product and a category.
	 *
	 * @param int                                                     $id         Object id.
	 * @param ProductSubject|TermSubject                              $subject    The object.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised steps.
	 * @param string                                                  $type       Its journal object type.
	 * @param string                                                  $modified   Its modified time, or the unknown time.
	 */
	private function commit( int $id, ProductSubject|TermSubject $subject, array $operations, string $type, string $modified ): WriteOutcome {
		$computation = ( new Computer( $this->provider, $this->context ) )->run( $subject, $operations );
		$results     = $computation->results;
		$finals      = [];
		foreach ( array_keys( $this->provider->fields() ) as $key ) {
			if ( array_key_exists( $key, $computation->finals ) ) {
				$finals[ $key ] = $computation->finals[ $key ];
			}
		}
		foreach ( array_keys( $finals ) as $key ) {
			if ( ! Envelope::fits( $computation->before[ $key ] ?? null ) ) {
				$results = self::fail_field( $results, $key, __( 'The value this product holds now is larger than an add-on value may be, so this change was not made.', 'bulk-catalogue-manager-for-woocommerce' ), Refusals::TOO_LARGE );
				unset( $finals[ $key ] );
			}
		}
		$skips  = array_map( static fn( array $step ): bool => (bool) ( $step['args']['skip_already'] ?? true ), $operations );
		$before = [];
		foreach ( $computation->before as $key => $value ) {
			$before[ $key ] = $this->provider->envelope( $subject, $key, $value );
		}
		if ( [] === $finals ) {
			$this->record( $id, $type, $modified, $this->entries( $subject, $results, $skips ), $before );
			return new WriteOutcome( $id, $results, false );
		}

		$intended = null === $this->journal ? [] : $this->journal->intend( $this->batch_id, $id, $type, $modified, $this->entries( $subject, $results, $skips ), $before );
		$failures = [];
		$stored   = [];
		foreach ( $finals as $key => $final ) {
			$put = $this->put( $subject, $key, $final, $computation->before[ $key ] ?? null );
			if ( $put['ok'] ) {
				$stored[ $key ] = $put['value'];
			} else {
				$failures[ $key ] = [ $put['reason'], $put['code'] ];
			}
		}
		foreach ( $failures as $key => [ $reason, $code ] ) {
			$results = self::fail_field( $results, $key, $reason, $code );
		}
		if ( null !== $this->journal ) {
			foreach ( $intended as $key ) {
				if ( isset( $failures[ $key ] ) ) {
					$this->journal->settle_one( $this->batch_id, $id, $key, 'failed', $before[ $key ] ?? null, $failures[ $key ][0] );
				} else {
					$this->journal->settle_one( $this->batch_id, $id, $key, 'applied', $this->provider->envelope( $subject, $key, $stored[ $key ] ) );
				}
			}
		}
		$saved = [] !== array_filter( $results, static fn( Result $result ): bool => $result->is_changed() );
		return new WriteOutcome( $id, $results, $saved, 0, $saved ? array_intersect_key( $computation->before, $stored ) : [] );
	}

	/**
	 * Finish an object a stopped run left with intent rows (§8.5): nothing is
	 * computed again. Per field: a value already at the recorded new value is
	 * settled applied (observing what landed needs no new grant); anything
	 * neither old nor new fails as changed during the write; a value still at
	 * the recorded old value is written only after the forward path's checks
	 * pass again (C1): the kind, the catalogue (in an apply run), the user's
	 * edit right, each step's capability, the provider's authorise() and the
	 * variation rule. A refusal settles the row with the forward path's
	 * Result and writes nothing. The provider sees the write, and its
	 * authorise() call, in a Context::REPLAY run.
	 *
	 * @param int                                                     $id         Object id.
	 * @param array<string, array{old: mixed, new: mixed}>            $intents    Field key => the envelopes the stopped run recorded.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The batch's normalised steps.
	 * @param string|null                                             $type       The kind it was chosen as, or null not to check.
	 */
	public function replay( int $id, array $intents, array $operations = [], ?string $type = null ): WriteOutcome {
		$category = TermSubject::TAXONOMY === $type;
		$product  = $category ? TermSubject::load( $id ) : self::load( $id );
		if ( null === $product ) {
			$subject = null;
		} else {
			$subject = $product instanceof \WP_Term ? new TermSubject( $product, $this->provider ) : ProductSubject::of( $product, $this->provider );
		}
		$results = [];
		$before  = [];
		$pending = [];
		foreach ( $intents as $key => [ 'old' => $old, 'new' => $new ] ) {
			$key   = (string) $key;
			$field = $this->provider->field( $key );
			$old_v = $this->current_format( $key, $old );
			$new_v = $this->current_format( $key, $new );
			if ( null === $subject || null === $field || $old_v instanceof \WP_Error || $new_v instanceof \WP_Error ) {
				$reason = null === $subject ? ( $category ? self::term_gone_sentence() : __( 'That product could not be found. It may have been deleted; reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ) ) : Refusals::error_sentence();
				$this->settle_failed( $id, $key, $old, $reason );
				$results[ $key ] = Result::failed( $key, null, $reason, null === $subject ? Writer::NOT_FOUND : Refusals::ERROR );
				continue;
			}
			$current = $subject->fresh( $key );
			if ( $current instanceof Unknown ) {
				$own = 1 === preg_match( '/^(bulkcat|x_' . preg_quote( $this->provider->id, '/' ) . ')_[a-z0-9_]+$/', $current->code() );
				$put = self::failure( Text::plain( $current->reason(), Text::REASON ), $own ? $current->code() : Refusals::UNKNOWN_STATE );
			} elseif ( self::same( $field, $current, $new_v ) ) {
				$put = [
					'ok'     => true,
					'value'  => $current,
					'reason' => '',
					'code'   => '',
				];
			} elseif ( self::same( $field, $current, $old_v ) ) {
				$pending[ $key ] = [ $field, $old, $old_v, $new_v ];
				$results[ $key ] = null;
				continue;
			} else {
				$put = self::failure( Refusals::conflict_sentence(), ExternalField::CONFLICT );
			}
			$results[ $key ] = $this->settle_replayed( $id, $subject, $key, $field, $old, $old_v, $new_v, $put, $before );
		}
		if ( [] !== $pending && null !== $product && null !== $subject ) {
			$context  = new RunContext( Context::REPLAY, $this->context->user_id(), $this->context->batch_id() );
			$computer = new Computer( $this->provider, $context );
			$refusal  = ( $subject instanceof TermSubject ? $this->term_refusal( $id ) : $this->object_refusal( $id, $product, $subject, $type ) ) ?? $computer->refusal( $subject, $operations );
			foreach ( $pending as $key => [ $field, $old, $old_v, $new_v ] ) {
				$refused = null === $refusal ? $computer->parent_only( $subject, $key ) : $refusal( $key );
				if ( null !== $refused ) {
					$skipped = Result::SKIPPED === $refused->state();
					if ( null !== $this->journal ) {
						$this->journal->settle_one( $this->batch_id, $id, $key, $skipped ? 'skipped' : 'failed', $old, $refused->reason() );
					}
					$results[ $key ] = $skipped ? Result::skipped( $key, $old_v, $refused->reason(), $refused->code() ) : Result::failed( $key, $old_v, $refused->reason(), $refused->code() );
					continue;
				}
				$results[ $key ] = $this->settle_replayed( $id, $subject, $key, $field, $old, $old_v, $new_v, $this->put( $subject, $key, $new_v, $old_v, $context ), $before );
			}
		}
		$results = array_values( $results );
		$saved   = [] !== array_filter( $results, static fn( Result $result ): bool => $result->is_changed() );
		return new WriteOutcome( $id, $results, $saved, 0, $before );
	}

	/**
	 * Settle one replayed field from what put() (or the landed check) answered.
	 *
	 * @param int                                                       $id      Object id.
	 * @param ProductSubject|TermSubject                                $subject The object.
	 * @param string                                                    $key     Field key.
	 * @param ExternalField                                             $field   The field.
	 * @param mixed                                                     $old     The recorded old envelope.
	 * @param mixed                                                     $old_v   The old value, in the current format.
	 * @param mixed                                                     $new_v   The new value, in the current format.
	 * @param array{ok: bool, value: mixed, reason: string, code: string} $put     The answer.
	 * @param array<string, mixed>                                      $before  Each changed field's value before, added to.
	 */
	private function settle_replayed( int $id, ProductSubject|TermSubject $subject, string $key, ExternalField $field, mixed $old, mixed $old_v, mixed $new_v, array $put, array &$before ): Result {
		if ( ! $put['ok'] ) {
			$this->settle_failed( $id, $key, $old, $put['reason'] );
			return Result::failed( $key, $old_v, $put['reason'], $put['code'] );
		}
		if ( null !== $this->journal ) {
			$this->journal->settle_one( $this->batch_id, $id, $key, 'applied', $this->provider->envelope( $subject, $key, $put['value'] ) );
		}
		if ( self::same( $field, $old_v, $new_v ) ) {
			return Result::already( $key, $new_v );
		}
		$before[ $key ] = $old_v;
		return Result::changed( $key, $old_v, $new_v );
	}

	/**
	 * The core's per-object checks, before the provider is asked anything:
	 * the object is still the kind it was chosen as (failed, CHANGED_KIND);
	 * in an apply run, it and a variation's parent are still in the catalogue
	 * (skipped as unavailable, as the native writer leaves a binned product);
	 * the user may still edit it (skipped). Null when all pass.
	 *
	 * @param int            $id      Object id.
	 * @param object         $product The loaded object.
	 * @param ProductSubject $subject It, as the provider sees it.
	 * @param string|null    $type    The kind it was chosen as, or null not to check.
	 * @return \Closure(string): Result|null
	 */
	private function object_refusal( int $id, object $product, ProductSubject $subject, ?string $type ): ?\Closure {
		if ( null !== $type && Journal::object_type( $product ) !== $type ) {
			return static fn( string $key ): Result => Result::failed( $key, null, __( 'This item has changed between a product and a variation since it was chosen, so it was left unchanged. Choose it again to change it.', 'bulk-catalogue-manager-for-woocommerce' ), Writer::CHANGED_KIND );
		}
		if ( Context::APPLY === $this->context->mode() && ( ! self::listed( $id ) || ( 'variation' === Journal::object_type( $product ) && ! self::listed( (int) $product->get_parent_id() ) ) ) ) {
			// Moved to the bin (or out of the catalogue) since Apply: never written, as an unavailable member at acceptance.
			// An undo still puts the old value back, so a product restored from the bin reads as before (EXTENSIONS §18.22).
			return static fn( string $key ): Result => Result::skipped( $key, null, BatchFactory::unavailable_reason(), Writer::UNAVAILABLE );
		}
		$allowed = Access::user_can_edit_object( $this->context->user_id(), $id, $subject->parent_id() );
		if ( true !== $allowed ) {
			$reason = $allowed instanceof \WP_Error ? $allowed->get_error_message() : __( 'You can no longer edit this product, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' );
			$code   = $allowed instanceof \WP_Error ? (string) $allowed->get_error_code() : Access::CANNOT_EDIT;
			return static fn( string $key ): Result => Result::skipped( $key, null, $reason, $code );
		}
		return null;
	}

	/**
	 * Write one field and read it back.
	 *
	 * @param ProductSubject|TermSubject $subject  The object.
	 * @param string         $key      Field key.
	 * @param mixed          $target   The value to set.
	 * @param mixed          $expected The value it should hold now.
	 * @param Context|null   $context  The run the provider sees, when not this writer's own (a replay).
	 * @return array{ok: bool, value: mixed, reason: string, code: string} The value read back, or why it failed.
	 */
	private function put( ProductSubject|TermSubject $subject, string $key, mixed $target, mixed $expected, ?Context $context = null ): array {
		$field = $this->provider->field( $key );
		if ( null === $field ) {
			return self::failure( Refusals::error_sentence(), Refusals::ERROR );
		}
		try {
			$answer = Guard::run( $this->provider->id, 'write', fn(): mixed => $field->write( $subject, $target, $expected, $context ?? $this->context ) );
		} catch ( ProviderFailure $e ) {
			return self::failure( Refusals::error_sentence(), Refusals::ERROR );
		}
		if ( true !== $answer ) {
			$code    = $answer instanceof \WP_Error ? (string) $answer->get_error_code() : '';
			$message = $answer instanceof \WP_Error ? Text::plain( str_replace( 'bulkcat_', '', $answer->get_error_message() ), Text::REASON ) : '';
			if ( ExternalField::CONFLICT === $code ) {
				return self::failure( '' === $message ? Refusals::conflict_sentence() : $message, ExternalField::CONFLICT );
			}
			$own = 1 === preg_match( '/^x_' . preg_quote( $this->provider->id, '/' ) . '_[a-z0-9_]+$/', $code );
			return self::failure( '' === $message ? __( 'The add-on refused to write this value, so this change was not made.', 'bulk-catalogue-manager-for-woocommerce' ) : $message, $own ? $code : Refusals::ERROR );
		}
		if ( $subject instanceof TermSubject ) {
			$this->written_terms[ $subject->id() ] = true;
		} else {
			$this->written[ $subject->id() ] = true;
		}
		$read = $subject->fresh( $key );
		if ( $read instanceof Unknown || ! self::same( $field, $read, $target ) ) {
			return self::failure( Refusals::not_stored_sentence(), Refusals::NOT_STORED );
		}
		return [
			'ok'     => true,
			'value'  => $read,
			'reason' => '',
			'code'   => '',
		];
	}

	/**
	 * A failed write: the plain reason and its code.
	 *
	 * @param string $reason Plain reason.
	 * @param string $code   Its code.
	 * @return array{ok: bool, value: mixed, reason: string, code: string}
	 */
	private static function failure( string $reason, string $code ): array {
		return [
			'ok'     => false,
			'value'  => null,
			'reason' => $reason,
			'code'   => '' === $code ? Refusals::ERROR : $code,
		];
	}

	/**
	 * Whether two values of a field are the same, as the field says; false when it fails.
	 *
	 * @param ExternalField $field The field.
	 * @param mixed         $a     One value.
	 * @param mixed         $b     The other.
	 */
	private static function same( ExternalField $field, mixed $a, mixed $b ): bool {
		try {
			return true === Guard::run( '', 'same', static fn(): mixed => $field->same( $a, $b ) );
		} catch ( ProviderFailure $e ) {
			return false;
		}
	}

	/**
	 * A journalled envelope's value in the field's current format, or a WP_Error.
	 *
	 * @param string $key      Field key.
	 * @param mixed  $envelope The journalled envelope.
	 */
	public function current_format( string $key, mixed $envelope ): mixed {
		return Envelope::current( $this->provider, $key, $envelope );
	}

	/**
	 * Settle one intent row failed, keeping the value it held.
	 *
	 * @param int    $id     Object id.
	 * @param string $key    Field key.
	 * @param mixed  $old    The recorded old envelope.
	 * @param string $reason Plain reason.
	 */
	private function settle_failed( int $id, string $key, mixed $old, string $reason ): void {
		if ( null !== $this->journal ) {
			$this->journal->settle_one( $this->batch_id, $id, $key, 'failed', $old, $reason );
		}
	}

	/**
	 * Every step refused the same way, journalled, and the outcome.
	 *
	 * @param int                                                     $id         Object id.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The steps.
	 * @param \Closure(string): Result                                $build      Builds a step's Result from its field.
	 * @param string                                                  $type       Its journal object type.
	 * @param string                                                  $modified   Its modified time, or the unknown time.
	 */
	private function refuse_all( int $id, array $operations, \Closure $build, string $type, string $modified ): WriteOutcome {
		$results = array_map( static fn( array $step ): Result => $build( (string) ( $step['args']['field'] ?? '' ) ), $operations );
		$skips   = array_map( static fn( array $step ): bool => (bool) ( $step['args']['skip_already'] ?? true ), $operations );
		$this->record( $id, $type, $modified, array_map( null, $results, $skips ), [] );
		return new WriteOutcome( $id, $results, false );
	}

	/**
	 * Journal an object nothing is written to.
	 *
	 * @param int                             $id       Object id.
	 * @param string                          $type     Its journal object type.
	 * @param string                          $modified Its modified time, or the unknown time.
	 * @param list<array{0: Result, 1: bool}> $entries  Results with their skip switch.
	 * @param array<string, mixed>            $before   Enveloped values before.
	 */
	private function record( int $id, string $type, string $modified, array $entries, array $before ): void {
		if ( null === $this->journal ) {
			return;
		}
		$this->journal->record( $this->batch_id, $id, $type, $modified, $entries, $before, null );
	}

	/**
	 * The Results as the journal stores them: every value an envelope, with each step's skip switch.
	 *
	 * @param ProductSubject|TermSubject $subject The object.
	 * @param list<Result>   $results One per step.
	 * @param list<bool>     $skips   Each step's skip_already.
	 * @return list<array{0: Result, 1: bool}>
	 */
	private function entries( ProductSubject|TermSubject $subject, array $results, array $skips ): array {
		$entries = [];
		foreach ( $results as $index => $result ) {
			$key       = $result->field();
			$wrap      = fn( mixed $value ): ?array => null === $value && Result::CHANGED !== $result->state() ? null : $this->provider->envelope( $subject, $key, $value );
			$old       = $wrap( $result->old() );
			$new       = $this->provider->envelope( $subject, $key, $result->new() );
			$entries[] = [
				match ( $result->state() ) {
					Result::CHANGED => Result::changed( $key, $old, $new, $result->replaces() ),
					Result::ALREADY => Result::already( $key, $old ),
					Result::SKIPPED => Result::skipped( $key, $old, $result->reason(), $result->code() ),
					default         => Result::failed( $key, $old, $result->reason(), $result->code() ),
				},
				$skips[ $index ] ?? true,
			];
		}
		return $entries;
	}

	/**
	 * The Results with every change to one field turned into a failure.
	 *
	 * @param list<Result> $results The Results.
	 * @param string       $key     Field key.
	 * @param string       $reason  Plain reason.
	 * @param string       $code    Its code.
	 * @return list<Result>
	 */
	private static function fail_field( array $results, string $key, string $reason, string $code ): array {
		return array_map(
			static fn( Result $result ): Result => $result->is_changed() && $result->field() === $key ? Result::failed( $key, $result->old(), $reason, $code ) : $result,
			$results
		);
	}

	/**
	 * Whether a post is still in the catalogue: its status is one a
	 * catalogue row may have (Query\ProductQuery::STATUSES), not the bin or
	 * an auto-draft. An unknown status (none read) counts as listed.
	 *
	 * @param int $id Post id.
	 */
	private static function listed( int $id ): bool {
		$status = (string) get_post_field( 'post_status', $id );
		return '' === $status || in_array( $status, ProductQuery::STATUSES, true );
	}

	/**
	 * Load an object, or null when it is not a product.
	 *
	 * @param int $id Object id.
	 * @throws \LogicException A programming error is not swallowed.
	 */
	private static function load( int $id ): ?object {
		try {
			$product = wc_get_product( $id );
		} catch ( \LogicException $e ) {
			throw $e;
		} catch ( \Exception $e ) {
			return null;
		}
		return is_object( $product ) ? $product : null;
	}
}
