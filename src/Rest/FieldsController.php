<?php
/**
 * `GET /bulkcat/v1/fields` (SPEC §5.1, §6, §12.1): the field registry and the
 * action registry as the client reads them, so the client hard-codes no
 * field list and no action list. The response is `{ fields, actions,
 * groups }`: each field carries the key, label, type, group, edit kinds,
 * variation rule and notes, choices, REST schema fragment and DataViews
 * definition, and no storage key; each action its descriptor; groups the
 * five bulk action panel groups in order with their labels.
 *
 * External actions (T105, docs/EXTENSIONS.md §5.4): only when at least one
 * provider is registered, each list gains its external entries after the
 * native ones (External\Advertiser) and the payload gains `providers`; with
 * none it is byte-identical to the native payload, and so is the schema.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\External\Advertiser;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry as Actions;

defined( 'ABSPATH' ) || exit;

/**
 * The fields route.
 */
final class FieldsController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/fields';

	/**
	 * The action registry.
	 *
	 * @var Actions
	 */
	private readonly Actions $actions;

	/**
	 * Build over the registries.
	 *
	 * @param Registry     $registry Field registry.
	 * @param Actions|null $actions  Action registry; null for every action over $registry.
	 */
	public function __construct( private readonly Registry $registry, ?Actions $actions = null ) {
		$this->actions = $actions ?? new Actions( $registry );
	}

	/**
	 * Add the route through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => [],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The payload: every field's client definition in registry order, every
	 * action's descriptor in panel order, and the panel groups.
	 *
	 * @return array{fields: list<array<string, mixed>>, actions: list<array<string, mixed>>, groups: list<array{id: string, label: string}>, providers?: list<array<string, mixed>>}
	 */
	public function payload(): array {
		$payload   = [
			'fields'  => $this->registry->to_client(),
			'actions' => $this->actions->to_client(),
			'groups'  => Group::to_client(),
		];
		$providers = $this->actions->providers();
		if ( ! $providers->any() ) {
			return $payload;
		}
		$external = new Advertiser( $providers );
		return [
			'fields'    => [ ...$payload['fields'], ...$external->fields() ],
			'actions'   => [ ...$payload['actions'], ...$external->actions( get_current_user_id() ) ],
			'groups'    => [ ...$payload['groups'], ...$external->groups() ],
			'providers' => $external->providers(),
		];
	}

	/**
	 * The fields, actions and groups.
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public function get_items( mixed $request = null ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( $this->payload(), 200 );
	}

	/**
	 * The schema of the response: the fields, actions and groups lists.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$string_list = [
			'type'  => 'array',
			'items' => [ 'type' => 'string' ],
		];
		$external    = $this->actions->providers()->any() ? new Advertiser( $this->actions->providers() ) : null;
		$group_ids   = [ ...Group::ALL, ...( null === $external ? [] : array_column( $external->groups(), 'id' ) ) ];
		$schema      = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkcat-fields',
			'type'       => 'object',
			'properties' => [
				'fields'  => [
					'type'  => 'array',
					'items' => self::field_schema( $string_list, $group_ids, null !== $external ),
				],
				'actions' => [
					'type'  => 'array',
					'items' => self::action_schema( $string_list, $group_ids, null !== $external ),
				],
				'groups'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'    => [
								'type' => 'string',
								'enum' => $group_ids,
							],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
		if ( null !== $external ) {
			$schema['properties']['providers'] = [
				'description' => __( 'The add-ons that registered external actions (docs/EXTENSIONS.md); present only when one is registered.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'        => 'array',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'id'        => [ 'type' => 'string' ],
						'label'     => [ 'type' => 'string' ],
						'version'   => [ 'type' => 'string' ],
						'available' => [ 'type' => 'boolean' ],
						'reason'    => [ 'type' => 'string' ],
					],
				],
			];
		}
		return $schema;
	}

	/**
	 * The schema of one action descriptor.
	 *
	 * @param array<string, mixed> $string_list Schema of a list of strings.
	 * @param list<string>         $group_ids   Every chooser group id.
	 * @param bool                 $external    Whether external entries are listed.
	 * @return array<string, mixed>
	 */
	private static function action_schema( array $string_list, array $group_ids, bool $external ): array {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'string' ],
				'label'       => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'group'       => [
					'type' => 'string',
					'enum' => $group_ids,
				],
				'groups'      => $string_list,
				'editKinds'   => $string_list,
				'fields'      => $string_list,
				'args'        => [ 'type' => 'object' ],
			],
		];
		if ( $external ) {
			$schema['properties'] += [
				'provider'          => [ 'type' => 'string' ],
				'variations'        => [ 'type' => 'string' ],
				'available'         => [ 'type' => 'boolean' ],
				'unavailableReason' => [ 'type' => 'string' ],
				'alwaysConfirm'     => [ 'type' => 'boolean' ],
				'confirmReason'     => [ 'type' => 'string' ],
			];
		}
		return $schema;
	}

	/**
	 * The schema of one field definition.
	 *
	 * @param array<string, mixed> $string_list Schema of a list of strings.
	 * @param list<string>         $group_ids   Every chooser group id.
	 * @param bool                 $external    Whether external entries are listed.
	 * @return array<string, mixed>
	 */
	private static function field_schema( array $string_list, array $group_ids, bool $external ): array {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'key'                  => [ 'type' => 'string' ],
				'label'                => [ 'type' => 'string' ],
				'type'                 => [ 'type' => 'string' ],
				'readOnly'             => [ 'type' => 'boolean' ],
				'nullable'             => [ 'type' => 'boolean' ],
				'inline'               => [ 'type' => 'boolean' ],
				'editKinds'            => $string_list,
				'variations'           => [ 'type' => 'string' ],
				'variationNote'        => [ 'type' => 'string' ],
				'variableParentNote'   => [ 'type' => 'string' ],
				'sortable'             => [ 'type' => 'boolean' ],
				'defaultColumn'        => [
					'description' => __( 'Whether the grid shows it by default, after the product cell (T40).', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'boolean',
				],
				'defaultOrder'         => [
					'description' => __( 'Its place among the default columns, from 1; null when it is not shown by default.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => [ 'integer', 'null' ],
				],
				'schema'               => [ 'type' => 'object' ],
				'dataviews'            => [ 'type' => 'object' ],
				'choices'              => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'value'        => [ 'type' => 'string' ],
							'label'        => [ 'type' => 'string' ],
							'hidesProduct' => [
								'description' => __( 'Whether setting this choice takes the product out of shoppers\' sight, so a bulk change to it always asks first (SPEC §4.5).', 'bulk-catalogue-manager-for-woocommerce' ),
								'type'        => 'boolean',
							],
							'hidesAs'      => [
								'description' => __( 'How it hides the product: off_site (its status keeps it off the site) or hidden (hidden from the shop and from search); null when it does not.', 'bulk-catalogue-manager-for-woocommerce' ),
								'type'        => [ 'string', 'null' ],
								'enum'        => [ ...Field::HIDES, null ],
							],
						],
					],
				],
				'writableChoices'      => $string_list,
				'variationChoices'     => $string_list,
				'variationOnlyChoices' => $string_list,
				'group'                => [
					'type' => 'string',
					'enum' => $group_ids,
				],
				'source'               => [ 'type' => 'string' ],
				'maxLength'            => [ 'type' => 'integer' ],
				'switchesOffOn'        => [ 'type' => 'boolean' ],
			],
		];
		if ( $external ) {
			$schema['properties']['provider']          = [ 'type' => 'string' ];
			$schema['properties']['dataviews']['type'] = [ 'object', 'null' ];
		}
		return $schema;
	}
}
