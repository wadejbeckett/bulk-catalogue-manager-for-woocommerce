<?php
/**
 * The provider manifest of a batch (docs/EXTENSIONS.md §8.2, §8.3, §11): a
 * snapshot, written with the batch in its acceptance transaction, of each
 * provider its steps name — label, version, contract, the labels and value
 * formats of the fields and the labels of the actions its steps use — in
 * `bulkcat_batches.extensions`. History, audit and export read it instead of
 * the provider, so they stay readable after it has gone; each chunk and each
 * undo checks it against the providers registered now.
 *
 *     { "example": { "label": "…", "version": "1.0.0", "contract": 1,
 *                   "fields": { "x_example_labels": { "label": "…", "format": 1 } },
 *                   "actions": { "x_example_add": "Add labels" } } }
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and reads manifests.
 */
final class Manifest {
	/**
	 * The manifest of a change's steps, all of one provider.
	 *
	 * @param Registered                                          $provider   The provider.
	 * @param array<mixed>                                        $operations The normalised steps.
	 * @param array<string, array<string, array<string, string>>> $choices    Resolved lookup labels, action => argument => id => label (the binding's `choices`; docs/EXTENSIONS-v2.md §4.5).
	 * @return array<string, array<string, mixed>>
	 */
	public static function build( Registered $provider, array $operations, array $choices = [] ): array {
		$fields  = [];
		$actions = [];
		foreach ( $operations as $step ) {
			$key  = (string) ( $step['args']['field'] ?? '' );
			$meta = $provider->field_meta( $key );
			if ( null !== $meta ) {
				$fields[ $key ] = [
					'label'  => $meta['label'],
					'format' => $meta['format'],
				];
			}
			$described = $provider->described( (string) ( $step['action'] ?? '' ) );
			if ( null !== $described ) {
				$actions[ $described['id'] ] = $described['label'];
			}
		}
		$entry = [
			'label'    => $provider->label,
			'version'  => $provider->version,
			'contract' => $provider->contract,
			'fields'   => $fields,
			'actions'  => $actions,
		];
		unset( $choices['_refused'] );
		$kept = [];
		foreach ( $choices as $action => $arguments ) {
			foreach ( is_array( $arguments ) ? $arguments : [] as $argument => $labels ) {
				if ( '_refused' !== $argument && is_array( $labels ) ) {
					$kept[ (string) $action ][ (string) $argument ] = array_map( static fn( mixed $label ): string => Text::plain( $label, Text::LABEL ), $labels );
				}
			}
		}
		if ( [] !== $kept ) {
			$entry['choices'] = $kept;
		}
		return [ $provider->id => $entry ];
	}

	/**
	 * A manifest copied for an undo: the same entries, each registered
	 * provider's version refreshed to its version now.
	 *
	 * @param array<string, mixed> $manifest  The source batch's manifest.
	 * @param Providers            $providers The providers now.
	 * @return array<string, mixed>
	 */
	public static function refreshed( array $manifest, Providers $providers ): array {
		foreach ( $manifest as $id => $entry ) {
			$provider = $providers->get( (string) $id );
			if ( null !== $provider && is_array( $entry ) ) {
				$manifest[ $id ]['version'] = $provider->version;
			}
		}
		return $manifest;
	}

	/**
	 * The first provider a manifest names that cannot work on it now, and
	 * why (Blocked): not registered (missing); or registered but not
	 * available (its own reason), of a contract this core does not support,
	 * unable to read a recorded format (a field gone, or a format newer
	 * than its own), or, for a forward chunk, no longer offering an action
	 * the change used (EXT-5) (unavailable). Null when every one can.
	 *
	 * @param array<string, mixed> $manifest  A batch's manifest.
	 * @param Providers            $providers The providers now.
	 * @param bool                 $formats   Whether a format it cannot read blocks it too (a chunk); an undo skips such objects one by one instead.
	 * @param bool                 $actions   Whether an action the change used that the provider no longer offers blocks it too (a forward chunk, EXT-5); an undo writes values back through the core's own step and needs none.
	 */
	public static function blocked( array $manifest, Providers $providers, bool $formats = true, bool $actions = false ): ?Blocked {
		foreach ( $manifest as $id => $entry ) {
			$label    = self::label( $manifest, (string) $id );
			$provider = $providers->get( (string) $id );
			if ( null === $provider ) {
				return Blocked::missing( $label );
			}
			if ( ! $provider->available() ) {
				return Blocked::unavailable( $label, $provider->reason() );
			}
			$other = Blocked::unavailable(
				$label,
				sprintf(
					/* translators: %s: the name of an add-on, such as "Example add-on". */
					__( 'This change was made with another version of %s, which the one active now cannot read.', 'bulk-catalogue-manager-for-woocommerce' ),
					$label
				)
			);
			if ( ! in_array( (int) ( $entry['contract'] ?? 0 ), Contract::SUPPORTED, true ) ) {
				return $other;
			}
			foreach ( $formats ? (array) ( $entry['fields'] ?? [] ) : [] as $key => $field ) {
				$meta = $provider->field_meta( (string) $key );
				if ( null === $meta || (int) ( $field['format'] ?? 0 ) > $meta['format'] ) {
					return $other;
				}
			}
			foreach ( $actions ? (array) ( $entry['actions'] ?? [] ) : [] as $action => $action_label ) {
				if ( null === $provider->described( (string) $action ) ) {
					// EXT-5: removed or renamed by an update after Apply; the job stops with this one reason instead of failing every object.
					return Blocked::unavailable(
						$label,
						sprintf(
							/* translators: 1: the name of an add-on, such as "Example add-on"; 2: the name of the action the change used, such as "Add labels". */
							__( 'The version of %1$s active now no longer offers the action this change used (%2$s).', 'bulk-catalogue-manager-for-woocommerce' ),
							$label,
							Text::plain( $action_label, Text::LABEL )
						)
					);
				}
			}
		}
		return null;
	}

	/**
	 * What a batch report tells the screen about its add-ons (T108): each
	 * provider's id and label, and the labels of the actions and fields its
	 * steps used, so History reads them after the add-on has gone.
	 *
	 * Contract version 2: `choices` (action => argument => id => label) when
	 * the manifest kept lookup labels, holding only the ids the given steps
	 * show, so a reader who may not see a step's arguments sees none of
	 * their labels (docs/EXTENSIONS-v2.md §4.5).
	 *
	 * @param array<string, mixed>     $manifest   A batch's manifest.
	 * @param array<mixed>|null        $operations The steps as disclosed to this reader.
	 * @return array{providers: list<array{id: string, label: string}>, actions: array<string, string>, fields: array<string, string>, choices?: array<string, array<string, array<string, string>>>}
	 */
	public static function client( array $manifest, ?array $operations = null ): array {
		$client = [
			'providers' => [],
			'actions'   => [],
			'fields'    => [],
		];
		foreach ( $manifest as $entry ) {
			if ( is_array( $entry ) && is_array( $entry['choices'] ?? null ) ) {
				$client['choices'] = self::shown( $entry['choices'], $operations ?? [] ) + ( $client['choices'] ?? [] );
			}
		}
		foreach ( $manifest as $id => $entry ) {
			$client['providers'][] = [
				'id'    => (string) $id,
				'label' => self::label( $manifest, (string) $id ),
			];
			foreach ( (array) ( $entry['actions'] ?? [] ) as $action => $label ) {
				$client['actions'][ (string) $action ] = Text::plain( $label, Text::LABEL );
			}
			foreach ( (array) ( $entry['fields'] ?? [] ) as $key => $field ) {
				$client['fields'][ (string) $key ] = Text::plain( $field['label'] ?? '', Text::LABEL );
			}
		}
		return $client;
	}

	/**
	 * The kept labels of the ids the steps show.
	 *
	 * @param array<mixed> $choices    action => argument => id => label.
	 * @param array<mixed> $operations Disclosed steps.
	 * @return array<string, array<string, array<string, string>>>
	 */
	private static function shown( array $choices, array $operations ): array {
		$shown = [];
		foreach ( $operations as $step ) {
			$action = is_array( $step ) ? (string) ( $step['action'] ?? '' ) : '';
			foreach ( (array) ( $choices[ $action ] ?? [] ) as $argument => $labels ) {
				if ( ! is_array( $labels ) || ! array_key_exists( $argument, (array) ( $step['args'] ?? [] ) ) ) {
					continue;
				}
				foreach ( (array) $step['args'][ $argument ] as $id ) {
					if ( is_string( $id ) && isset( $labels[ $id ] ) ) {
						$shown[ $action ][ (string) $argument ][ $id ] = Text::plain( $labels[ $id ], Text::LABEL );
					}
				}
			}
		}
		return $shown;
	}

	/**
	 * A provider's label from a manifest, or "An add-on".
	 *
	 * @param array<string, mixed> $manifest A manifest.
	 * @param string               $id       Provider id.
	 */
	public static function label( array $manifest, string $id ): string {
		$label = Text::plain( $manifest[ $id ]['label'] ?? '', Text::LABEL );
		return '' === $label ? Refusals::an_add_on() : $label;
	}

	/**
	 * A field's label from a manifest, or null when it names none.
	 *
	 * @param array<string, mixed>|null $manifest A manifest, or null.
	 * @param string                    $key      Field key.
	 */
	public static function field_label( ?array $manifest, string $key ): ?string {
		$owner = Providers::owner( $key );
		$label = null === $owner || null === $manifest ? '' : Text::plain( $manifest[ $owner ]['fields'][ $key ]['label'] ?? '', Text::LABEL );
		return '' === $label ? null : $label;
	}

	/**
	 * An action's label from a manifest, or null when it names none.
	 *
	 * @param array<string, mixed>|null $manifest A manifest, or null.
	 * @param string                    $id       Action id.
	 */
	public static function action_label( ?array $manifest, string $id ): ?string {
		$owner = Providers::owner( $id );
		$label = null === $owner || null === $manifest ? '' : Text::plain( $manifest[ $owner ]['actions'][ $id ] ?? '', Text::LABEL );
		return '' === $label ? null : $label;
	}

	/**
	 * The format a manifest recorded for a field, or 0.
	 *
	 * @param array<string, mixed>|null $manifest A manifest, or null.
	 * @param string                    $key      Field key.
	 */
	public static function format( ?array $manifest, string $key ): int {
		$owner = Providers::owner( $key );
		return null === $owner || null === $manifest ? 0 : (int) ( $manifest[ $owner ]['fields'][ $key ]['format'] ?? 0 );
	}
}
