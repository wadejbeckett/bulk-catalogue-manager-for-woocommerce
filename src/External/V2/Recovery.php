<?php
/** Pure provider-owned restoration, with non-forceable core checks. @package BulkCatalogueManager */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2 as API;
use BulkCatalogueManager\External\{Guard, Refusals};
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\Access;
defined( 'ABSPATH' ) || exit;
/** The core never interprets a provider's domain keys. */
final class Recovery {
	/** Authorize before the caller reads any current provider value. */
	public static function authorize( API\Field $field, API\Subject $subject, API\RecoveryContext $context ): bool|\WP_Error {
		try {
			$h      = $context->original();
			$family = Actions::instance()->providers()->typed()->family( $h->provider );
			if ( null === $family || $family['identity'] !== $h->identity || ( $family['typed']['fields'][ $h->field ] ?? null ) !== $field || $subject->reference()->key() !== $h->target->key() || ! in_array( $h->target->kind, $field->target_kinds(), true ) ) {
				return Refusals::incompatible( Inspector::unavailable() ); }
			if ( true !== Access::user_can_family( $context->user_id(), 'term' === $h->target->kind ? 'category' : 'product' ) || true !== Permission::check( $context->user_id(), $h->target ) ) {
				return Refusals::forbidden(); }
			foreach ( $h->contributions as $c ) {
				$d = $family['typed']['described'][ $c->step->action ]['descriptor'] ?? null;
				if ( null === $d || $d->field !== $h->field || ! in_array( $h->target->kind, $d->target_kinds, true ) ) {
					return Refusals::incompatible( Inspector::unavailable() ); }
				if ( ! user_can( $context->user_id(), $d->capability ) ) {
					return Refusals::forbidden(); }
			}
			$p = $family['provider'];
			if ( $p->identity() !== $h->identity || true !== Guard::run( $h->provider, 'availability', fn() => $p->availability() ) ) {
				return Refusals::missing(); }
			if ( true !== Guard::run( $h->provider, 'authorise', fn() => $p->authorise( $context->user_id(), $subject ) ) ) {
				return Refusals::forbidden(); }
			return true;
		} catch ( \Throwable $error ) {
			return Refusals::incompatible( Inspector::unavailable() ); }
	}
	/** Ask the field to merge only its original effective domains into fresh current. */
	public static function plan( API\Field $field, API\Subject $subject, mixed $current, API\RecoveryContext $context ): API\RestoreDecision|\WP_Error {
		$allowed = self::authorize( $field, $subject, $context );
		if ( true !== $allowed ) {
			return $allowed; }
		try {
			$h = $context->original();
			Rules::json( $current, 65536 );
			Rules::ensure( $field->format() >= $h->format, 'Unsupported value format.' );
			foreach ( [ $h->before, $h->after ] as $value ) {
				$upgraded = Guard::run( $h->provider, 'upgrade', fn() => $field->upgrade( $value, $h->format ) );
				Rules::json( $upgraded, 65536 );
			}
			foreach ( $h->contributions as $c ) {
				foreach ( [ $c->step->validation, $c->origin ] as $snapshot ) {
					Rules::ensure( Guard::run( $h->provider, 'upgrade_context', fn() => $field->upgrade_context( $snapshot ) ) instanceof API\Snapshot, 'Unsupported context format.' );
				}
			}
			// The original DTO stays exact. The field interprets its supported historical formats.
			$result = Guard::run( $h->provider, 'restore', fn() => $field->restore( $subject, $current, $context ) );
			Rules::ensure( $result instanceof API\RestoreDecision, 'Invalid restoration response.' );
			return $result;
		} catch ( \Throwable $error ) {
			return Refusals::incompatible( Inspector::unavailable() ); }
	}
}
