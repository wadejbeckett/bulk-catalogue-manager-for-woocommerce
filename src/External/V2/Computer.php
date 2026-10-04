<?php
/**
 * The single ordered typed computation and provenance aggregation service.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\{Guard, Refusals, Text};
use BulkCatalogueManager\Operations\Result;
defined( 'ABSPATH' ) || exit;
/** Pure provider computation; no writes, restoration or finalisation. */
final class Computer {
	/**
	 * Use the same provider family bound by the caller.
	 * @param API\Provider $provider Bound provider.
	 */
	public function __construct( private readonly API\Provider $provider ) {}
	/**
	 * Compute validated steps, preserving every returned origin and result.
	 * @param API\Subject $subject Native subject authorized by the caller.
	 * @param array $steps Ordered ValidatedSteps.
	 * @param API\Context $context Recorded context.
	 * @return array Field-keyed before/after/contributions/results.
	 */
	public function run( API\Subject $subject, array $steps, API\Context $context ): array {
		Rules::ensure( array_is_list( $steps ) && count( $steps ) >= 1 && count( $steps ) <= 20, 'Invalid computation steps.' );
		$actions     = [];
		$fields      = [];
		$descriptors = [];
		foreach ( $this->provider->actions() as $action ) {
			$d                     = $action->describe();
			$actions[ $d->id ]     = $action;
			$descriptors[ $d->id ] = $d;}
		foreach ( $this->provider->fields() as $field ) {
			$fields[ $field->key() ] = $field;}
		Rules::ensure( count( $fields ) >= 1 && count( $fields ) <= 5 && count( $actions ) >= 1 && count( $actions ) <= 20, 'Invalid provider field/action bounds.' );
		$last = -1;
		foreach ( $steps as $step ) {
			Rules::ensure( $step instanceof API\ValidatedStep && $step->index > $last, 'Invalid step order.' );
			Rules::key( $step->action, $this->provider->id() );
			Rules::key( $step->field, $this->provider->id() );
			$last = $step->index;
			$d    = $descriptors[ $step->action ] ?? null;
			Rules::ensure( null !== $d && $d->field === $step->field && isset( $fields[ $step->field ] ), 'Unowned step.' );
			if ( ! user_can( $context->user_id(), $d->capability ) || ! in_array( $subject->reference()->kind, $d->target_kinds, true ) ) {
				return $this->denied( $steps );}
		}
		if ( true !== Guard::run( $this->provider->id(), 'authorise', fn()=> $this->provider->authorise( $context->user_id(), $subject ) ) ) {
			return $this->denied( $steps );}
		$result = [];
		$values = [];
		foreach ( $steps as $step ) {
			$key   = $step->field;
			$field = $fields[ $key ];
			if ( ! array_key_exists( $key, $values ) ) {
				$values[ $key ] = Guard::run( $this->provider->id(), 'read', fn()=> $field->read( $subject ) );
				Rules::json( $values[ $key ], 65536 );
				$result[ $key ] = [
					'before'        => $values[ $key ],
					'after'         => $values[ $key ],
					'contributions' => [],
					'results'       => [],
				];
			}
			$current  = $values[ $key ];
			$computed = null;
			try {
				$view     = new OverlaySubject( $subject, $values );
				$computed = Guard::run( $this->provider->id(), 'compute', fn()=> $actions[ $step->action ]->compute( $view, $step, $context ) );
				Rules::ensure( $computed instanceof API\Computation && $computed->result->field() === $key, 'Invalid computed field.' );
				$r = $computed->result;
				Rules::ensure( '' === $r->code() || 1 === preg_match( '/^(bulkcat_|x_' . preg_quote( $this->provider->id(), '/' ) . '_)[a-z0-9_]+$/D', $r->code() ), 'Unowned result code.' );
				if ( $r->is_changed() ) {
					Rules::json( $r->new(), 65536 );
					Rules::ensure( ! $field->same( $current, $r->new() ), 'Changed result is already current.' );
				}
				$r = match ( $r->state() ) {
					Result::CHANGED=>Result::changed( $key, $current, $r->new(), $r->replaces() ),
					Result::ALREADY=>Result::already( $key, $current ),
					Result::SKIPPED=>Result::skipped( $key, $current, Text::plain( $r->reason(), 255 ), $r->code() ),
					default=>Result::failed( $key, $current, Text::plain( $r->reason(), 255 ), $r->code() ),
				};
				$notes = array_map( static fn( string $note ): string=>Text::plain( $note, Text::NOTE ), $computed->result->notes() );
				if ( [] !== $notes ) {
					$r = $r->with_notes( array_slice( $notes, 0, Text::NOTES ) );}
				$warning = $computed->result->warning();
				if ( null !== $warning ) {
					$r = $r->with_warning( $warning['code'], Text::plain( $warning['sentence'], 255 ), $warning['confirm'] );}
			} catch ( \Throwable $error ) {
				$r = Result::failed( $key, $current, Refusals::error_sentence(), Refusals::ERROR );
			}
			if ( $computed instanceof API\Computation && $computed->result->field() === $key ) {
				$result[ $key ]['contributions'][] = new API\Contribution( $step, $r->state(), $computed->origin );
			}
			$result[ $key ]['results'][] = $r;
			if ( $r->is_changed() ) {
				$values[ $key ]          = $r->new();
				$result[ $key ]['after'] = $values[ $key ];}
		}
		Rules::ensure( count( $result ) <= 5, 'Too many aggregate fields.' );
		foreach ( $result as $key => $row ) {
			if ( [] !== $row['contributions'] ) {
				// The existing immutable DTO enforces the complete 512 KiB envelope bound.
				new API\FieldHistory( $this->provider->id(), $this->provider->identity(), 2, $key, $fields[ $key ]->format(), $subject->reference(), $row['before'], $row['after'], $row['contributions'] );
			}
		}
		return $result;
	}
	/**
	 * No field read or fabricated provider origin on a denied computation.
	 * @param array $steps Validated steps.
	 * @return array Sanitized per-field failures.
	 */
	private function denied( array $steps ): array {
		$out = [];
		foreach ( $steps as $step ) {
			$out[ $step->field ]            ??= [
				'before'        => null,
				'after'         => null,
				'contributions' => [],
				'results'       => [],
			];
			$out[ $step->field ]['results'][] = Result::skipped( $step->field, null, Refusals::no_permission(), 'bulkcat_forbidden' );
		}return $out;
	}
}
