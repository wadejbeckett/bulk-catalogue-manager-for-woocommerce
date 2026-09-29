<?php
/**
 * External entries of `GET /fields` (docs/EXTENSIONS.md §5.1, §5.3, §5.4):
 * each registered provider's fields, actions, chooser groups and one
 * provider row, built from what it said when it registered. Nothing here
 * is sent when no provider is registered, so the payload then stays
 * byte-identical to the native one. Native entries never gain a key.
 *
 * An action is available when its provider is and the current user holds
 * its capability (checked with user_can()); otherwise it is listed disabled
 * with the provider's reason, or "You do not have permission to use this
 * action." Browser code treats an entry without `provider` as native.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\ExternalField;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the external `/fields` entries.
 */
final class Advertiser {
	/**
	 * Over the request's providers.
	 *
	 * @param Providers $providers The registered providers.
	 */
	public function __construct( private readonly Providers $providers ) {}

	/**
	 * The external field entries, provider by provider.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function fields(): array {
		$entries = [];
		foreach ( $this->providers->all() as $provider ) {
			foreach ( $provider->fields_meta() as $key => $meta ) {
				$kinds = [];
				$group = '';
				foreach ( $provider->all_described() as $described ) {
					if ( $described['field'] === $key ) {
						$kinds[] = $described['edit_kind'];
						$group   = '' === $group ? $described['group'] : $group;
					}
				}
				$entries[] = [
					'key'           => $key,
					'label'         => $meta['label'],
					'type'          => 'external',
					'source'        => 'external',
					'provider'      => $provider->id,
					'readOnly'      => false,
					'nullable'      => false,
					'inline'        => false,
					'sortable'      => false,
					'editKinds'     => array_values( array_unique( $kinds ) ),
					'variations'    => $meta['rule'],
					'variationNote' => ExternalField::PARENT_ONLY === $meta['rule'] ? self::parent_only( $meta['label'] ) : '',
					'group'         => $group,
					'defaultColumn' => false,
					'defaultOrder'  => null,
					'schema'        => new \stdClass(),
					'dataviews'     => null,
					'choices'       => [],
				];
			}
		}
		return $entries;
	}

	/**
	 * The external action entries for the current user, after the native ones.
	 *
	 * @param int $user_id The user asking.
	 * @return list<array<string, mixed>>
	 */
	public function actions( int $user_id ): array {
		$entries = [];
		foreach ( $this->providers->all() as $provider ) {
			foreach ( $provider->all_described() as $described ) {
				$reason = $provider->reason();
				if ( '' === $reason && ! user_can( $user_id, $described['capability'] ) ) {
					$reason = Refusals::no_permission();
				}
				$meta      = $provider->field_meta( $described['field'] );
				$entries[] = [
					'id'                => $described['id'],
					'label'             => $described['label'],
					'description'       => $described['description'],
					'group'             => $described['group'],
					'groups'            => [ $described['group'] ],
					'editKinds'         => [ $described['edit_kind'] ],
					'fields'            => [ $described['field'] ],
					'args'              => Arguments::schema( $described, $meta ?? [] ),
					'provider'          => $provider->id,
					'variations'        => $described['rule'],
					'available'         => '' === $reason,
					'unavailableReason' => $reason,
					'alwaysConfirm'     => $described['always_confirm'],
					'confirmReason'     => $described['confirm_reason'],
				];
			}
		}
		return $entries;
	}

	/**
	 * The providers' own chooser groups, in registration order.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function groups(): array {
		$groups = [];
		foreach ( $this->providers->all() as $provider ) {
			$groups = [ ...$groups, ...$provider->groups() ];
		}
		return $groups;
	}

	/**
	 * One row per provider: id, label, version, availability and its reason.
	 *
	 * @return list<array{id: string, label: string, version: string, available: bool, reason: string}>
	 */
	public function providers(): array {
		return array_map(
			static fn( Registered $provider ): array => [
				'id'        => $provider->id,
				'label'     => $provider->label,
				'version'   => $provider->version,
				'available' => $provider->available(),
				'reason'    => $provider->reason(),
			],
			$this->providers->all()
		);
	}

	/**
	 * The note on an add-on's parent-only field, and the reason a variation
	 * is skipped for it: the label is quoted, so the sentence reads right
	 * whether the label is singular or plural ("Gift tags" as well as "Colour").
	 *
	 * @param string $label The field's label.
	 */
	public static function parent_only( string $label ): string {
		return sprintf(
			/* translators: %s: the name of an add-on's value, such as "Colour" or "Labels"; it is shown in quotation marks. */
			__( '“%s” is kept on the product, not on each variation.', 'bulk-catalogue-manager-for-woocommerce' ),
			$label
		);
	}
}
