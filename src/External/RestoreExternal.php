<?php
/**
 * `restore_external` (T108, docs/EXTENSIONS.md §8.4): the internal step an
 * undo item uses to put an external value back — `{ field, value }`, the
 * field's value before the batch in its current format. Like
 * `restore_status`, the action registry resolves it for stored undo items
 * but never lists it, and validate_list() refuses it from every caller but
 * the undo planner.
 *
 * External\Computer computes it (the value now against the value to put
 * back: already, or a change) and External\ExternalWriter writes it with the
 * value just read as the expected one, so Revert anyway behaves as it does
 * for native values. A person undoing needs the capability of every action
 * of the provider that changes the field.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Operations\BaseOperation;
use BulkCatalogueManager\Operations\Descriptor;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * The internal restore step.
 */
final class RestoreExternal implements Operation {
	/**
	 * The action id.
	 */
	public const ID = 'restore_external';

	/**
	 * Over the request's providers.
	 *
	 * @param Providers $providers The registered providers.
	 */
	public function __construct( private readonly Providers $providers ) {}

	/**
	 * What it is; never listed.
	 */
	public function describe(): Descriptor {
		return new Descriptor(
			self::ID,
			'organisation',
			__( 'Put an add-on value back', 'bulk-catalogue-manager-for-woocommerce' ),
			__( 'Puts back the value an add-on field had before a change, as its undo.', 'bulk-catalogue-manager-for-woocommerce' ),
			[ 'set' ],
			[],
			[],
			[
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			]
		);
	}

	/**
	 * `{ field, value }` and nothing else: a registered provider's field and a
	 * JSON-plain value within the size limit.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function validate( array $args ): Validation {
		$key      = is_string( $args['field'] ?? null ) ? $args['field'] : '';
		$provider = $this->providers->owning( $key );
		$valid    = [ 'field', 'value' ] === array_keys( $args ) || [ 'field', 'value', 'skip_already' ] === array_keys( $args );
		if ( ! $valid || null === $provider || null === $provider->field( $key ) || ! Envelope::plain( $args['value'] ) || ! Envelope::fits( $args['value'] ) ) {
			return Validation::refused( Refusal::invalid( BaseOperation::INVALID, __( 'This undo step could not be read, so nothing was put back. Preview the undo again.', 'bulk-catalogue-manager-for-woocommerce' ), [ 'action' => self::ID ] ) );
		}
		return Validation::passed(
			[
				'field' => $key,
				'value' => $args['value'],
			]
		);
	}

	/**
	 * Never used: External\Computer computes it.
	 *
	 * @param object               $subject The object.
	 * @param array<string, mixed> $args    Arguments.
	 * @throws \LogicException Always.
	 */
	public function compute( object $subject, array $args ): Result {
		throw new \LogicException( 'External restore steps are computed by External\\Computer.' );
	}

	/**
	 * Nothing follows it.
	 *
	 * @param object               $subject The object.
	 * @param Result               $checked The checked Result.
	 * @param array<string, mixed> $args    Arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		unset( $subject, $checked, $args );
		return [];
	}

	/**
	 * Never used: External\ExternalWriter writes it.
	 *
	 * @param Result $result  The result.
	 * @param object $subject The object.
	 * @throws \LogicException Always.
	 */
	public function apply( Result $result, object $subject ): void {
		throw new \LogicException( 'External restore steps are written by External\\ExternalWriter.' );
	}
}
