<?php
/**
 * FieldHistory for the opt-in typed contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Extension\V2;

use BulkCatalogueManager\External\V2\Rules;

defined( 'ABSPATH' ) || exit;

/** FieldHistory immutable contract value. */
final class FieldHistory {
	/**
	 * Validate and retain the complete contract value.
	 * @param string $provider Contract value.
	 * @param string $identity Contract value.
	 * @param int $contract Contract value.
	 * @param string $field Contract value.
	 * @param int $format Contract value.
	 * @param TargetRef $target Contract value.
	 * @param mixed $before Contract value.
	 * @param mixed $after Contract value.
	 * @param array $contributions Contract value.
	 * @throws \InvalidArgumentException When a bound or shape is invalid.
	 */
	public function __construct( public readonly string $provider, public readonly string $identity, public readonly int $contract, public readonly string $field, public readonly int $format, public readonly TargetRef $target, public readonly mixed $before, public readonly mixed $after, public readonly array $contributions ) {
		Rules::provider( $provider );
		Rules::identity( $identity );
		Rules::key( $field, $provider );
		Rules::ensure( 2 === $contract && $format > 0 && array_is_list( $contributions ) && count( $contributions ) >= 1 && count( $contributions ) <= 20, 'Invalid field history.' );
		Rules::json( $before, 65536 );
		Rules::json( $after, 65536 );
		$last = -1;
		$wire = [];
		foreach ( array_keys( $contributions ) as $position ) {
			Rules::ensure( null === \ReflectionReference::fromArrayElement( $contributions, $position ), 'Mutable references are not contract values.' );
		}
		foreach ( $contributions as $contribution ) {
				Rules::ensure( $contribution instanceof Contribution && $contribution->step->field === $field && $contribution->step->index > $last, 'Invalid contribution order or field.' );
				Rules::key( $contribution->step->action, $provider );
				$last   = $contribution->step->index;
				$wire[] = [
					'step'   => [
						'index'      => $last,
						'action'     => $contribution->step->action,
						'field'      => $field,
						'args'       => $contribution->step->args,
						'validation' => [
							'version' => $contribution->step->validation->version,
							'data'    => $contribution->step->validation->data,
						],
					],
					'state'  => $contribution->state,
					'origin' => [
						'version' => $contribution->origin->version,
						'data'    => $contribution->origin->data,
					],
				];
		}
		Rules::json(
			[
				'provider'      => $provider,
				'identity'      => $identity,
				'contract'      => $contract,
				'field'         => $field,
				'format'        => $format,
				'target'        => $target->to_array(),
				'before'        => $before,
				'after'         => $after,
				'contributions' => $wire,
			],
			524288
		);
	}
}
