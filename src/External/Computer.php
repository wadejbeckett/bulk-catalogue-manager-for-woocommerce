<?php
/**
 * One object of an external change, computed without writing
 * (docs/EXTENSIONS.md §7.3 steps 2–5): the preview and the writer both come
 * here, so what is previewed is what is written.
 *
 * In order, after the core's own edit check (the caller's): each step's
 * capability for the run's user (user_can()); the provider's authorise();
 * the variation rule (a parent-only field on a variation is skipped with the
 * core's sentence); each named field read once (an Unknown fails every step
 * on its field, never read as empty); each step computed in turn, a later
 * step seeing an earlier step's value through Subject::value(). A result is
 * checked before it counts: it names its step's field, its code starts
 * `bulkcat_` or the provider's own `x_<id>_`, a change is not same() as the
 * old value, and a new value is JSON-plain and at most 64 KB; a violation,
 * or an exception, fails that step with `bulkcat_external_error`
 * (`bulkcat_value_too_large` for size) and is logged. Reasons, notes and
 * warnings are made plain text.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Unknown;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Computes one object.
 */
final class Computer {
	/**
	 * For one provider and run.
	 *
	 * @param Registered $provider The change's provider.
	 * @param Context    $context  The run.
	 */
	public function __construct( private readonly Registered $provider, private readonly Context $context ) {}

	/**
	 * Compute every step on one object.
	 *
	 * @param ProductSubject|TermSubject                               $subject The object.
	 * @param list<array{action: string, args: array<string, mixed>}> $steps   The normalised steps.
	 */
	public function run( ProductSubject|TermSubject $subject, array $steps ): Computation {
		$refusal = $this->refusal( $subject, $steps );
		if ( null !== $refusal ) {
			return new Computation( self::all( $steps, $refusal ), [], [] );
		}

		$results = [];
		$before  = [];
		$finals  = [];
		foreach ( $steps as $index => $step ) {
			$key  = (string) $step['args']['field'];
			$meta = $this->provider->field_meta( $key );
			if ( null === $meta ) {
				$results[ $index ] = Result::failed( $key, null, Refusals::error_sentence(), Refusals::ERROR );
				continue;
			}
			$parent_only = $this->parent_only( $subject, $key );
			if ( null !== $parent_only ) {
				$results[ $index ] = $parent_only;
				continue;
			}
			$current = $subject->value( $key );
			if ( $current instanceof Unknown ) {
				$results[ $index ] = Result::failed( $key, null, self::sentence( $current->reason(), Refusals::error_sentence() ), $this->code( $current->code(), Refusals::UNKNOWN_STATE ) );
				continue;
			}
			if ( ! array_key_exists( $key, $before ) ) {
				$before[ $key ] = $subject->read( $key );
			}
			$result            = $this->step( $subject, $step, $key, $current );
			$results[ $index ] = $result;
			if ( $result->is_changed() ) {
				$subject->overlay( $key, $result->new() );
				$finals[ $key ] = $result->new();
			}
		}
		ksort( $results );
		return new Computation( array_values( $results ), $before, $finals );
	}

	/**
	 * The refusal every step of an object takes before anything is read, or
	 * null: each step's capability for the run's user (skipped, forbidden),
	 * then the provider's authorise(). The forward write and a replay of an
	 * interrupted one (ExternalWriter::replay()) both ask here.
	 *
	 * @param ProductSubject|TermSubject                               $subject The object.
	 * @param list<array{action: string, args: array<string, mixed>}> $steps   The normalised steps.
	 * @return \Closure(string): Result|null
	 */
	public function refusal( ProductSubject|TermSubject $subject, array $steps ): ?\Closure {
		$user = $this->context->user_id();
		foreach ( $steps as $step ) {
			if ( ! $this->may( $user, $step ) ) {
				return static fn( string $key ): Result => Result::skipped( $key, null, Refusals::no_permission(), Refusal::FORBIDDEN );
			}
		}
		return $this->authorise( $subject );
	}

	/**
	 * The variation rule: a parent-only field on a variation is skipped with
	 * the core's sentence; null when the field applies to the object.
	 *
	 * @param ProductSubject|TermSubject $subject The object.
	 * @param string                     $key     The field.
	 */
	public function parent_only( ProductSubject|TermSubject $subject, string $key ): ?Result {
		$meta = $this->provider->field_meta( $key );
		if ( null !== $meta && 'variation' === $subject->type() && ExternalField::PARENT_ONLY === $meta['rule'] ) {
			return Result::skipped( $key, null, Advertiser::parent_only( $meta['label'] ), 'bulkcat_not_available' );
		}
		return null;
	}

	/**
	 * Whether a user may make a step: its action's capability, or, for
	 * `restore_external`, the capability of every action of the provider
	 * that changes its field (T108).
	 *
	 * @param int                                               $user The user.
	 * @param array{action: string, args: array<string, mixed>} $step The step.
	 */
	public function may( int $user, array $step ): bool {
		if ( RestoreExternal::ID !== $step['action'] ) {
			$described = $this->provider->described( (string) $step['action'] );
			// An action the provider no longer offers may not be made (EXT-5): fail closed.
			return null !== $described && user_can( $user, $described['capability'] );
		}
		foreach ( $this->provider->all_described() as $described ) {
			if ( ( $step['args']['field'] ?? '' ) === $described['field'] && ! user_can( $user, $described['capability'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The provider's answer for the object, or the Result every step takes when it refuses.
	 *
	 * @param ProductSubject|TermSubject $subject The object.
	 * @return \Closure(string): Result|null
	 */
	private function authorise( ProductSubject|TermSubject $subject ): ?\Closure {
		try {
			$answer = Guard::run( $this->provider->id, 'authorise', fn(): mixed => $this->provider->provider->authorise( $this->context->user_id(), $subject ) );
		} catch ( ProviderFailure $e ) {
			return static fn( string $key ): Result => Result::failed( $key, null, Refusals::error_sentence(), Refusals::ERROR );
		}
		if ( true === $answer ) {
			return null;
		}
		$reason = self::sentence(
			$answer instanceof \WP_Error ? $answer->get_error_message() : '',
			$subject instanceof TermSubject
				? __( 'The add-on does not allow a change to this category, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' )
				: __( 'The add-on does not allow a change to this product, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' )
		);
		$code   = $this->code( $answer instanceof \WP_Error ? (string) $answer->get_error_code() : '', 'bulkcat_cannot_edit' );
		return static fn( string $key ): Result => Result::skipped( $key, null, $reason, $code );
	}

	/**
	 * One step computed and checked.
	 *
	 * @param ProductSubject|TermSubject                        $subject The object.
	 * @param array{action: string, args: array<string, mixed>} $step    The step.
	 * @param string                                            $key     Its field.
	 * @param mixed                                             $current The field's value so far.
	 */
	private function step( ProductSubject|TermSubject $subject, array $step, string $key, mixed $current ): Result {
		$field = $this->provider->field( $key );
		if ( RestoreExternal::ID === $step['action'] ) {
			// T108: the core computes an undo step itself: the value now against the value to put back.
			$target = $step['args']['value'] ?? null;
			if ( null === $field ) {
				return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
			}
			try {
				$same = true === Guard::run( $this->provider->id, 'same', static fn(): mixed => $field->same( $current, $target ) );
			} catch ( ProviderFailure $e ) {
				return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
			}
			return $same || $current === $target ? Result::already( $key, $current ) : Result::changed( $key, $current, $target );
		}
		$action = $this->provider->action( (string) $step['action'] );
		if ( null === $action || null === $field ) {
			return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
		}
		try {
			$result = Guard::run( $this->provider->id, 'compute', fn(): mixed => $action->compute( $subject, $step['args'], $this->context ) );
			if ( ! $result instanceof Result || $result->field() !== $key ) {
				Guard::log( sprintf( 'Add-on "%s" computed a result for another field than "%s"; the step failed.', $this->provider->id, $key ) );
				return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
			}
			if ( '' !== $result->code() && $result->code() !== $this->code( $result->code(), '' ) ) {
				Guard::log( sprintf( 'Add-on "%s" used the code "%s", which is not its own; the step failed.', $this->provider->id, $result->code() ) );
				return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
			}
			if ( $result->is_changed() ) {
				if ( ! Envelope::plain( $result->new() ) ) {
					return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
				}
				if ( ! Envelope::fits( $result->new() ) ) {
					return Result::failed( $key, $current, __( 'The new value is larger than an add-on value may be, so this change was not made.', 'bulk-catalogue-manager-for-woocommerce' ), Refusals::TOO_LARGE );
				}
				if ( true === Guard::run( $this->provider->id, 'same', static fn(): mixed => $field->same( $current, $result->new() ) ) ) {
					Guard::log( sprintf( 'Add-on "%s" reported a change on "%s" to the value it already holds; the step failed.', $this->provider->id, $key ) );
					return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
				}
			}
			return self::clean( $result, $current );
		} catch ( ProviderFailure | \InvalidArgumentException $e ) {
			return Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
		}
	}

	/**
	 * A provider's Result with its text made plain and the old value the core read.
	 *
	 * @param Result $result  What the provider computed.
	 * @param mixed  $current The value the core read, or the one an earlier step computed.
	 * @throws \InvalidArgumentException When the parts are inconsistent.
	 */
	private static function clean( Result $result, mixed $current ): Result {
		$key   = $result->field();
		$clean = match ( $result->state() ) {
			Result::CHANGED => Result::changed( $key, $current, $result->new(), $result->replaces() ),
			Result::ALREADY => Result::already( $key, $current ),
			Result::SKIPPED => Result::skipped( $key, $current, self::sentence( $result->reason(), Refusals::error_sentence() ), $result->code() ),
			default         => Result::failed( $key, $current, self::sentence( $result->reason(), Refusals::error_sentence() ), $result->code() ),
		};
		$notes = array_values( array_filter( array_map( static fn( string $note ): string => Text::plain( $note, Text::NOTE ), $result->notes() ) ) );
		if ( [] !== $notes ) {
			$clean = $clean->with_notes( array_slice( $notes, 0, Text::NOTES ) );
		}
		$warning = $result->warning();
		if ( null !== $warning ) {
			$sentence = self::sentence( $warning['sentence'], '' );
			if ( '' !== $sentence ) {
				$clean = $clean->with_warning( $warning['code'], $sentence, $warning['confirm'] );
			}
		}
		return $clean;
	}

	/**
	 * A provider's sentence as plain text within the reason limit, or the fallback.
	 *
	 * @param string $sentence What the provider said.
	 * @param string $fallback What to say instead when it said nothing usable.
	 */
	private static function sentence( string $sentence, string $fallback ): string {
		$plain = Text::plain( str_replace( 'bulkcat_', '', $sentence ), Text::REASON );
		return '' === $plain ? $fallback : $plain;
	}

	/**
	 * A code the step may carry: the core's, or this provider's own; the fallback otherwise.
	 *
	 * @param string $code     The code given.
	 * @param string $fallback What to use instead.
	 */
	private function code( string $code, string $fallback ): string {
		$own = 1 === preg_match( '/^x_' . preg_quote( $this->provider->id, '/' ) . '_[a-z0-9_]+$/', $code );
		return $own || 1 === preg_match( '/^bulkcat_[a-z0-9_]+$/', $code ) ? $code : $fallback;
	}

	/**
	 * One Result per step, each built from its field key.
	 *
	 * @param list<array{action: string, args: array<string, mixed>}> $steps The steps.
	 * @param \Closure(string): Result                                $build  Builds a step's Result from its field key.
	 * @return list<Result>
	 */
	private static function all( array $steps, \Closure $build ): array {
		return array_map( static fn( array $step ): Result => $build( (string) $step['args']['field'] ), $steps );
	}
}
